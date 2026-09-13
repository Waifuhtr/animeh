package com.animeh.app.data.repository

import android.content.Context
import androidx.annotation.OptIn
import androidx.media3.common.MediaItem
import androidx.media3.common.MimeTypes
import androidx.media3.common.util.UnstableApi
import androidx.media3.effect.Presentation
import androidx.media3.transformer.Composition
import androidx.media3.transformer.EditedMediaItem
import androidx.media3.transformer.Effects
import androidx.media3.transformer.ExportException
import androidx.media3.transformer.ExportResult
import androidx.media3.transformer.Transformer
import android.net.Uri
import android.os.Handler
import android.os.Looper
import com.google.common.collect.ImmutableList
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import java.io.File
import javax.inject.Inject
import javax.inject.Singleton
import kotlin.coroutines.resume

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
 * Encoder settings are deliberately left alone. Media3 derives a bitrate from
 * the output resolution, and asking for a specific one means a second API whose
 * failure mode is a device-specific export error rather than a slightly larger
 * file. The resolution does the work.
 *
 * Everything here is best-effort: a device whose encoder refuses, a codec
 * nobody expected, a file the muxer will not take — all of them fall back to
 * uploading the original, which is exactly what happened before this existed.
 */
@OptIn(UnstableApi::class)
@Singleton
class ShortsCompressor @Inject constructor(
    @ApplicationContext private val context: Context,
) {

    /**
     * A smaller file, or null to upload what was picked.
     *
     * Null is not a failure the caller has to report: it means "this one is
     * already fine, or could not be re-encoded", and both end the same way.
     *
     * @param onProgress fraction 0f..1f while encoding.
     */
    suspend fun shrink(
        source: Uri,
        widthPx: Int,
        heightPx: Int,
        onProgress: (Float) -> Unit = {},
    ): File? {
        val height = targetHeight(widthPx, heightPx) ?: return null

        val target = File(workspace(), "tok-${System.currentTimeMillis()}.mp4")

        // Transformer must be built and driven from one thread with a Looper,
        // and it calls its listener back on that same thread. The main thread
        // is the one guaranteed to have one; the encoding itself happens on
        // Media3's own threads, so nothing here blocks the UI.
        val exported = withContext(Dispatchers.Main) {
            runCatching { export(source, target, height, onProgress) }.getOrDefault(false)
        }

        if (!exported || !target.isFile || target.length() <= 0) {
            target.delete()
            return null
        }

        return target
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
        height: Int,
        onProgress: (Float) -> Unit,
    ): Boolean = suspendCancellableCoroutine { waiting ->
        val edited = EditedMediaItem.Builder(MediaItem.fromUri(source))
            .setEffects(
                Effects(
                    ImmutableList.of(),
                    // Proportional: the width follows, so a portrait clip stays
                    // portrait and a landscape one stays landscape.
                    ImmutableList.of(Presentation.createForHeight(height)),
                )
            )
            .build()

        val transformer = Transformer.Builder(context)
            // H.264 rather than H.265: every phone decodes it, and the feed is
            // watched on whatever somebody has.
            .setVideoMimeType(MimeTypes.VIDEO_H264)
            .addListener(
                object : Transformer.Listener {
                    override fun onCompleted(composition: Composition, result: ExportResult) {
                        onProgress(1f)
                        if (waiting.isActive) waiting.resume(true)
                    }

                    override fun onError(
                        composition: Composition,
                        result: ExportResult,
                        exception: ExportException,
                    ) {
                        // Not surfaced: the upload carries on with the original
                        // file, which is what it did before this step existed.
                        if (waiting.isActive) waiting.resume(false)
                    }
                }
            )
            .build()

        waiting.invokeOnCancellation {
            // This runs on whoever cancelled, which is not necessarily the
            // thread the Transformer was built on — and a Transformer touched
            // from a second thread is undefined behaviour. Posted back rather
            // than called here.
            Handler(Looper.getMainLooper()).post {
                runCatching { transformer.cancel() }
                target.delete()
            }
        }

        transformer.start(edited, target.absolutePath)
    }

    private fun workspace(): File =
        File(context.cacheDir, WORKSPACE).apply { mkdirs() }

    private companion object {
        /**
         * Largest short edge, in pixels.
         *
         * 720, which on a portrait clip means 720 wide and about 1280 tall —
         * what every app of this shape calls 720p. Above it the extra pixels
         * are invisible on a phone held at arm's length and very visible on
         * the bill.
         */
        const val MAX_SHORT_EDGE = 720

        const val WORKSPACE = "animehtok-upload"
    }
}
