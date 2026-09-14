package com.animeh.app.data.repository

import android.content.Context
import androidx.annotation.OptIn
import androidx.media3.common.Effect
import androidx.media3.common.MediaItem
import androidx.media3.common.MimeTypes
import androidx.media3.common.util.UnstableApi
import androidx.media3.effect.FrameDropEffect
import androidx.media3.effect.Presentation
import androidx.media3.transformer.Composition
import androidx.media3.transformer.DefaultEncoderFactory
import androidx.media3.transformer.EditedMediaItem
import androidx.media3.transformer.Effects
import androidx.media3.transformer.ExportException
import androidx.media3.transformer.ExportResult
import androidx.media3.transformer.ProgressHolder
import androidx.media3.transformer.Transformer
import androidx.media3.transformer.VideoEncoderSettings
import android.net.Uri
import android.os.Handler
import android.os.Looper
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import java.io.File
import javax.inject.Inject
import javax.inject.Singleton
import kotlin.coroutines.resume

/**
 * The slice of a picked video the uploader chose to keep.
 *
 * Milliseconds from the start of the original file, end exclusive. Absent
 * rather than a full-length range when nothing was trimmed, so the compressor
 * can tell "keep all of it" from "keep all of it, and re-encode to prove it".
 */
data class ShortTrim(val startMs: Long, val endMs: Long) {
    val durationMs: Long get() = (endMs - startMs).coerceAtLeast(0)
}

/**
 * Shrinks a picked video before it is uploaded.
 *
 * This is the ceiling the player's buffer settings run into. A phone records at
 * 1080p and ten to twenty-five megabits per second; no amount of tuning makes
 * that stream instantly over a mobile connection, because the bytes are simply
 * there. Every app of this shape re-encodes on the way in, and this is that
 * step: the short edge capped at 720, so a 1080×1920 recording becomes
 * 720×1280 — a little under half the pixels, and roughly that share of the
 * bitrate.
 *
 * It pays three times. The viewer waits for a fraction of the bytes, the
 * uploader waits for a fraction of the upload, and the bucket is billed for a
 * fraction of the storage and a fraction of the egress.
 *
 * The bitrate is asked for rather than left to the encoder, and that is a
 * correction. Media3's default is `width × height × frameRate × 0.07 × 2` —
 * the Kush Gauge with its motion factor pinned at "medium" — and it never
 * looks at the source at all. Fed a phone recording it can ask for *more* bits
 * than the file already had: a fifty megabyte video came back a hundred and
 * sixteen. Resolution alone does not do the work, because half the pixels at
 * twice the bits per pixel is the same file.
 *
 * Three things answer that, and each is a different kind of answer. The
 * bitrate is computed here, at a motion factor of one rather than two and
 * never above what the source itself ran at. The frame rate is held at thirty,
 * because that factor is linear and a sixty frame recording was asking for
 * double. And the output is weighed against the input at the end: a re-encode
 * that did not make the file meaningfully smaller is thrown away, which makes
 * this whole class unable to do the one thing it exists to prevent.
 *
 * Everything here is best-effort: a device whose encoder refuses, a codec
 * nobody expected, a file the muxer will not take — all of them fall back to
 * uploading the original, which is exactly what happened before this existed.
 * The requested encoder settings ride on that same net, because
 * `DefaultEncoderFactory` falls back on its own when a device will not take
 * them.
 */
@OptIn(UnstableApi::class)
@Singleton
class ShortsCompressor @Inject constructor(
    @ApplicationContext private val context: Context,
) {

    /**
     * A smaller file, or null to upload what was picked.
     *
     * Null means "this one is already fine, or could not be re-encoded". For a
     * plain shrink both end the same way and the caller need not report it —
     * but a null when [trim] was asked for is a real failure, because the file
     * that would go up is not the one the uploader chose. The caller checks.
     *
     * @param onProgress fraction 0f..1f while encoding.
     */
    suspend fun shrink(
        source: Uri,
        widthPx: Int,
        heightPx: Int,
        sizeBytes: Long = 0,
        durationMs: Long = 0,
        trim: ShortTrim? = null,
        onProgress: (Float) -> Unit = {},
    ): File? {
        val height = if (thin(sizeBytes, durationMs)) null else targetHeight(widthPx, heightPx)

        // Nothing to scale and nothing to cut: the picked file is already the
        // one to send, and a re-encode would cost battery and quality for it.
        if (height == null && trim == null) return null

        val target = File(workspace(), "tok-${System.currentTimeMillis()}.mp4")

        // Decided here rather than inside the export, because this is the only
        // place that knows both numbers it needs: how big a frame is coming
        // out, and how heavy the file going in already was.
        val asked = bitrate(
            outputPixels(widthPx, heightPx, height),
            sourceBps(sizeBytes, durationMs),
        )

        // Transformer must be built and driven from one thread with a Looper,
        // and it calls its listener back on that same thread. The main thread
        // is the one guaranteed to have one; the encoding itself happens on
        // Media3's own threads, so nothing here blocks the UI.
        val exported = withContext(Dispatchers.Main) {
            runCatching { export(source, target, height, asked, trim, onProgress) }.getOrDefault(false)
        }

        if (!exported || !target.isFile || target.length() <= 0) {
            target.delete()
            return null
        }

        // A shrink that did not shrink is worse than no shrink at all: it spent
        // the uploader's minute, spent a generation of quality, and handed back
        // a bigger file. Weighed rather than trusted, because the encoder is
        // free to ignore what it was asked for and every device's is different.
        //
        // Not applied to a trim. There the re-encode is the point — the cut has
        // to be in the bytes — and a caller that asked for one treats a null as
        // the failure it would be.
        if (trim == null && sizeBytes > 0 && target.length() > sizeBytes * WORTH_KEEPING / 100) {
            target.delete()
            return null
        }

        return target
    }

    /**
     * How many pixels a frame of the output will have.
     *
     * The bitrate is computed from this, so it has to be the size coming *out*
     * rather than the size going in: the two differ by the whole point of the
     * rescale, and asking for a 1080p bitrate on a 720p frame is how a shrink
     * becomes a swell.
     *
     * Null [height] means the frames pass through untouched, so the output is
     * the input. Dimensions of zero mean the file would not say, and 720×1280
     * is assumed — the shape this is all built around, and the one the cap
     * would have produced anyway.
     */
    private fun outputPixels(widthPx: Int, heightPx: Int, height: Int?): Int {
        if (widthPx <= 0 || heightPx <= 0) return MAX_SHORT_EDGE * ASSUMED_LONG_EDGE

        if (height == null) return widthPx * heightPx

        // The width follows the height by the same factor Presentation uses.
        val width = (widthPx.toLong() * height / heightPx).toInt().coerceAtLeast(2)

        return width * height
    }

    /**
     * Bits per second to ask the encoder for.
     *
     * The Kush Gauge, which is what Media3 uses too:
     * `pixels × frameRate × 0.07 × motion`. The difference is the motion
     * factor. Media3 pins it at two — "medium motion", a quality target — and
     * for a re-encode whose entire purpose is fewer bytes, one is the honest
     * number. At 720×1280 and thirty frames that is about 1.9 Mbps, which sits
     * under [LIGHT_ENOUGH_BPS] on purpose: a file this step would refuse to
     * touch must not be a file this step would produce.
     *
     * Floored, because a very small frame computes to a bitrate no encoder
     * makes anything watchable out of — and then capped again at what the file
     * already had, which is the part that matters on the trim path. There the
     * frames are not rescaled at all, so the computed number is for the full
     * size and can sit *above* a source somebody had already compressed:
     * cutting five seconds off a clip is no good if the remaining fifty-five
     * come back at three times the bitrate. [sourceBps] of zero means the file
     * would not say, and then there is nothing to cap against.
     *
     * The cap sits outside the floor on purpose. A source that was already
     * lighter than [MIN_BITRATE] is a source that was watchable at that
     * weight, and raising it would be this class doing the exact thing it is
     * here to stop.
     */
    private fun bitrate(pixels: Int, sourceBps: Long): Int {
        val kush = (pixels.toLong() * TARGET_FPS * 7 / 100)
            .coerceIn(MIN_BITRATE.toLong(), LIGHT_ENOUGH_BPS)

        if (sourceBps <= 0) return kush.toInt()

        return minOf(kush, sourceBps).toInt()
    }

    /**
     * Whether the file is already light enough to leave alone.
     *
     * The point of the re-encode is bytes per second of video, not pixels: a
     * 1080p clip somebody has already compressed is a smaller download than a
     * 720p one straight off a camera. When a file is already under the bitrate
     * the re-encode would aim for, running it costs a minute of the uploader's
     * time and a generation of quality and gives back nothing — and waiting is
     * the thing they actually complained about.
     *
     * Unknown numbers mean no: a file that would not say how big or how long
     * it is gets re-encoded rather than trusted.
     */
    private fun thin(sizeBytes: Long, durationMs: Long): Boolean {
        val bps = sourceBps(sizeBytes, durationMs)

        return bps > 0 && bps <= LIGHT_ENOUGH_BPS
    }

    /**
     * What the picked file's video runs at, in bits per second, or zero when
     * it would not say.
     *
     * Size over duration, so it counts the audio track too and is a little
     * high for the video alone. That direction is the safe one everywhere it
     * is used: [thin] re-encodes a file it might have left alone, and the cap
     * in [bitrate] asks for slightly more than it strictly should rather than
     * starving a clip it cannot measure exactly.
     */
    private fun sourceBps(sizeBytes: Long, durationMs: Long): Long {
        if (sizeBytes <= 0 || durationMs <= 0) return 0

        return sizeBytes * 8_000 / durationMs
    }

    /**
     * How tall the output should be, or null to leave the file alone.
     *
     * The cap is on the **short** edge, not on the height. A phone video is
     * portrait, so its height is the long edge: asking for 720 tall would turn
     * a 1080×1920 clip into 405×720 — a quarter of the width of what every
     * other app calls 720p, and visibly soft. Capping the short edge gives
     * 720×1280, which is the shape intended.
     *
     * Null when the short edge is already inside the cap: re-encoding a 540p
     * clip costs battery and a little quality and gives back nothing.
     *
     * Dimensions of zero mean the file would not say, and those are re-encoded
     * rather than trusted — an unknown is more likely to be a 4K recording
     * than a small one.
     */
    private fun targetHeight(widthPx: Int, heightPx: Int): Int? {
        if (widthPx <= 0 || heightPx <= 0) return MAX_SHORT_EDGE

        val short = minOf(widthPx, heightPx)
        if (short <= MAX_SHORT_EDGE) return null

        // Encoders want even numbers; an odd height fails on some devices.
        val scaled = (heightPx.toLong() * MAX_SHORT_EDGE / short).toInt()

        return (scaled / 2) * 2
    }

    /** Delete a file this class produced, once it has been uploaded. */
    fun discard(file: File?) {
        file ?: return

        if (file.parentFile?.name == WORKSPACE) {
            file.delete()
        }
    }

    /** Drop anything an interrupted upload left behind. */
    fun sweep() {
        workspace().listFiles()?.forEach { it.delete() }
    }

    private suspend fun export(
        source: Uri,
        target: File,
        height: Int?,
        bitrate: Int,
        trim: ShortTrim?,
        onProgress: (Float) -> Unit,
    ): Boolean = suspendCancellableCoroutine { waiting ->
        // Thirty frames, whatever was recorded. The bits a frame costs are the
        // same whichever second it lands in, so sixty frames is twice the file
        // for something nobody watching a feed with their thumb on it is going
        // to notice. Frames above the target are dropped; a clip already at or
        // under it is untouched.
        val frames: Effect = FrameDropEffect.createDefaultFrameDropEffect(TARGET_FPS.toFloat())

        // Spelled out rather than built, so every element type is written down.
        // Effects takes List<Effect>, a Java List is invariant in Kotlin, and
        // the one construct here that would need inference to work that out is
        // the one construct that cannot be checked before a real build.
        val effects: List<Effect> = when (height) {
            // Nothing to rescale: the frames pass through at the size they
            // were recorded at.
            null -> listOf(frames)
            // Proportional: the width follows, so a portrait clip stays
            // portrait and a landscape one stays landscape.
            else -> listOf(Presentation.createForHeight(height), frames)
        }

        val edited = EditedMediaItem.Builder(itemFor(source, trim))
            .setEffects(Effects(listOf(), effects))
            .build()

        // Polling runs on this same looper, which is the only thread allowed to
        // ask a Transformer anything.
        val progress = ProgressHolder()
        val poller = Handler(Looper.getMainLooper())

        val transformer = Transformer.Builder(context)
            // H.264 rather than H.265: every phone decodes it, and the feed is
            // watched on whatever somebody has. It is also the less efficient
            // of the two, which is part of why the bitrate below has to be
            // asked for rather than assumed.
            .setVideoMimeType(MimeTypes.VIDEO_H264)
            // Left to itself Media3 asks for `pixels × fps × 0.07 × 2`, having
            // never looked at the file it was handed. That is how a fifty
            // megabyte video came back a hundred and sixteen.
            //
            // Fallback stays on, which is the default: a device that will not
            // take these settings encodes at what it can rather than failing,
            // and a device that fails outright still lands on the original
            // file being uploaded untouched.
            .setEncoderFactory(
                DefaultEncoderFactory.Builder(context)
                    .setRequestedVideoEncoderSettings(
                        VideoEncoderSettings.Builder().setBitrate(bitrate).build()
                    )
                    .build()
            )
            .addListener(
                object : Transformer.Listener {
                    override fun onCompleted(composition: Composition, result: ExportResult) {
                        poller.removeCallbacksAndMessages(null)
                        onProgress(1f)
                        if (waiting.isActive) waiting.resume(true)
                    }

                    override fun onError(
                        composition: Composition,
                        result: ExportResult,
                        exception: ExportException,
                    ) {
                        // Not surfaced from here: a plain shrink carries on with
                        // the original file, and a failed trim is turned into a
                        // real error by the caller, which knows one was asked
                        // for.
                        poller.removeCallbacksAndMessages(null)
                        if (waiting.isActive) waiting.resume(false)
                    }
                }
            )
            .build()

        // Re-encoding a minute of video takes tens of seconds on a mid-range
        // phone. Without this the bar sits at zero under a label saying
        // "compressing", which reads as a hang rather than as work.
        val tick = object : Runnable {
            override fun run() {
                if (!waiting.isActive) return

                if (transformer.getProgress(progress) == Transformer.PROGRESS_STATE_AVAILABLE) {
                    onProgress(progress.progress / 100f)
                }

                poller.postDelayed(this, PROGRESS_POLL_MS)
            }
        }

        waiting.invokeOnCancellation {
            // This runs on whoever cancelled, which is not necessarily the
            // thread the Transformer was built on — and a Transformer touched
            // from a second thread is undefined behaviour. Posted back rather
            // than called here.
            Handler(Looper.getMainLooper()).post {
                poller.removeCallbacksAndMessages(null)
                runCatching { transformer.cancel() }
                target.delete()
            }
        }

        transformer.start(edited, target.absolutePath)
        poller.postDelayed(tick, PROGRESS_POLL_MS)
    }

    /**
     * The source, with the uploader's cut applied.
     *
     * Clipping belongs to the MediaItem rather than to the export: Transformer
     * reads the same ClippingConfiguration the player does, so the range the
     * trim UI previewed is exactly the range that gets encoded.
     *
     * `startsAtKeyFrame` is left alone deliberately. Snapping the start to the
     * nearest key frame would be cheaper, but key frames sit seconds apart in a
     * phone recording, and a cut that lands up to two seconds away from where
     * somebody put the handle is not a cut they asked for.
     */
    private fun itemFor(source: Uri, trim: ShortTrim?): MediaItem {
        val builder = MediaItem.Builder().setUri(source)

        if (trim != null) {
            builder.setClippingConfiguration(
                MediaItem.ClippingConfiguration.Builder()
                    .setStartPositionMs(trim.startMs.coerceAtLeast(0))
                    .setEndPositionMs(trim.endMs)
                    .build()
            )
        }

        return builder.build()
    }

    private fun workspace(): File =
        File(context.cacheDir, WORKSPACE).apply { mkdirs() }

    companion object {
        /**
         * Largest short edge, in pixels.
         *
         * 720, which on a portrait clip means 720 wide and about 1280 tall —
         * what every app of this shape calls 720p. Above it the extra pixels
         * are invisible on a phone held at arm's length and very visible on
         * the bill.
         *
         * The cover frame is held to the same number, so a thumbnail is never
         * larger than the video it stands in for.
         */
        const val MAX_SHORT_EDGE = 720

        /**
         * Bitrate at or under which a file is already fine, in bits/second.
         *
         * Three megabits. A 720p short re-encodes to roughly two, so a file
         * already this light has nothing left to give — and the seconds spent
         * proving that are seconds the uploader spends staring at a bar.
         *
         * It doubles as the ceiling on what the encoder is asked for, which is
         * the rule that keeps the two halves honest: this step must never
         * produce a file it would have refused to touch.
         */
        private const val LIGHT_ENOUGH_BPS = 3_000_000L

        /**
         * Frames per second the output is held to.
         *
         * Thirty. The bitrate a frame costs does not care which second it
         * lands in, so a sixty frame recording is twice the file for a
         * difference nobody scrolling a feed is looking for.
         */
        private const val TARGET_FPS = 30

        /**
         * Floor under the computed bitrate, in bits/second.
         *
         * Eight hundred kilobits. A small frame computes to a number no
         * encoder makes anything watchable out of, and a video nobody can
         * watch is not a saving.
         */
        private const val MIN_BITRATE = 800_000

        /**
         * The long edge assumed when a file will not say how big it is.
         *
         * 1280, which with the short edge cap is the 720×1280 this is all
         * built around.
         */
        private const val ASSUMED_LONG_EDGE = 1280

        /**
         * How much of the original the result may be, as a percentage.
         *
         * Ninety. A re-encode that saved five percent still cost a minute of
         * somebody's time and a generation of quality, and that is not a trade
         * worth making — below this line it is kept, above it the original
         * goes up instead.
         */
        private const val WORTH_KEEPING = 90

        internal const val WORKSPACE = "animehtok-upload"

        /** How often the encode is asked how far along it is. */
        private const val PROGRESS_POLL_MS = 400L
    }
}
