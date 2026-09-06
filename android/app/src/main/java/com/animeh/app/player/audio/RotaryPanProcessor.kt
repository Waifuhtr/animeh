package com.animeh.app.player.audio

import androidx.annotation.OptIn
import androidx.media3.common.C
import androidx.media3.common.audio.AudioProcessor
import androidx.media3.common.audio.BaseAudioProcessor
import androidx.media3.common.util.UnstableApi
import java.nio.ByteBuffer
import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.sin

/**
 * The effect the internet calls "8D audio", written out honestly.
 *
 * There is no eighth dimension and no standard to implement. What every one of
 * those uploads actually is, is a stereo mix run through a slow panner: the
 * sound is moved from one ear to the other and back on a cycle of a few
 * seconds, which on headphones reads as the music circling your head. This is
 * that, done as a Media3 [AudioProcessor] so it applies to whatever is playing
 * rather than to a file somebody re-uploaded.
 *
 * Three decisions worth stating, because each of them is the difference
 * between an effect and an annoyance:
 *
 * **Constant power, not constant amplitude.** Panning by simply scaling one
 * channel down makes the middle of the sweep quieter than the ends, which is
 * heard as the whole episode pulsing in volume. The sine/cosine pair keeps the
 * total energy the same wherever the image is.
 *
 * **It never pans fully.** [DEPTH] holds the moving image partway towards the
 * hard-panned extreme, so the ear the sound is leaving never goes silent.
 * Anime is dialogue over music; a line of speech that disappears into one ear
 * is a line somebody has to rewind for.
 *
 * **Off means a copy, not a bypass.** Media3 decides which processors are in
 * the chain when the sink is configured, so a processor that reports itself
 * inactive stays out until the next format change — the switch would appear to
 * do nothing until the next episode. Staying in the chain and copying its
 * input through costs a memcpy on a buffer of PCM and makes the switch
 * immediate, which is the better trade by a wide margin.
 */
@OptIn(UnstableApi::class)
class RotaryPanProcessor : BaseAudioProcessor() {

    /** Whether the sweep is applied. Safe to change during playback. */
    @Volatile
    var enabled: Boolean = false

    /**
     * Sweeps per second.
     *
     * A full circle every six seconds or so is what the uploads use; faster
     * than about one every two seconds stops reading as movement and starts
     * reading as a tremolo.
     */
    @Volatile
    var speedHz: Float = DEFAULT_SPEED_HZ

    /** Where in the sweep the next frame is, in radians. */
    private var phase = 0.0

    /** Radians per frame, recomputed whenever the format changes. */
    private var step = 0.0

    override fun onConfigure(inputAudioFormat: AudioProcessor.AudioFormat): AudioProcessor.AudioFormat {
        // Only interleaved 16-bit stereo. Everything this app plays decodes to
        // that, and refusing anything else is better than guessing at a layout
        // and writing noise into somebody's ears.
        if (inputAudioFormat.encoding != C.ENCODING_PCM_16BIT || inputAudioFormat.channelCount != 2) {
            return AudioProcessor.AudioFormat.NOT_SET
        }

        phase = 0.0

        return inputAudioFormat
    }

    override fun onFlush() {
        // A seek should not land in the middle of a sweep that belongs to
        // where the playhead used to be.
        phase = 0.0
    }

    override fun queueInput(inputBuffer: ByteBuffer) {
        val remaining = inputBuffer.remaining()
        if (remaining == 0) return

        val output = replaceOutputBuffer(remaining)

        if (!enabled) {
            output.put(inputBuffer)
            output.flip()
            return
        }

        val rate = inputAudioFormat.sampleRate
        step = if (rate > 0) 2.0 * PI * speedHz / rate else 0.0

        var position = inputBuffer.position()
        val limit = inputBuffer.limit()

        // Whole frames only: four bytes, two channels of sixteen bits. A
        // partial frame at the end is left in the buffer for the next call,
        // which is what the interface allows and what keeps the channels from
        // swapping places if it ever happens.
        var untilRecalculated = 0
        var gainL = 1.0
        var gainR = 1.0

        while (limit - position >= BYTES_PER_FRAME) {
            if (untilRecalculated == 0) {
                // 0 at the far left of the sweep, PI/2 at the far right; the
                // pair of gains taken from it always sums to one in power.
                //
                // Recomputed every so many frames rather than every frame: a
                // sweep this slow moves by nothing in a millisecond, and two
                // trig calls per sample at 48kHz is real work for a result
                // nobody could hear.
                val angle = (sin(phase) * DEPTH + 1.0) * PI / 4.0
                // Scaled back up by root two, so a centred image is unity
                // rather than three decibels down on the original.
                gainL = cos(angle) * ROOT_TWO
                gainR = sin(angle) * ROOT_TWO
                untilRecalculated = FRAMES_PER_STEP
            }
            --untilRecalculated

            // Little-endian sixteen-bit, which is what `ENCODING_PCM_16BIT`
            // means on every Android device.
            output.putShort(clip(inputBuffer.getShort(position) * gainL))
            output.putShort(clip(inputBuffer.getShort(position + 2) * gainR))

            phase += step
            if (phase > TWO_PI) phase -= TWO_PI

            position += BYTES_PER_FRAME
        }

        inputBuffer.position(position)
        output.flip()
    }

    /** Back to a sample, without wrapping around on a loud passage. */
    private fun clip(value: Double): Short =
        value.coerceIn(Short.MIN_VALUE.toDouble(), Short.MAX_VALUE.toDouble()).toInt().toShort()

    private companion object {
        /**
         * How far towards a hard pan the image is allowed to travel, 0 to 1.
         *
         * At 1 the quiet ear reaches silence. Three quarters is plainly
         * circular and still leaves dialogue in both ears throughout.
         */
        const val DEPTH = 0.75

        const val DEFAULT_SPEED_HZ = 0.16f

        const val ROOT_TWO = 1.41421356

        const val TWO_PI = 2.0 * PI

        /** Two channels of sixteen bits. */
        const val BYTES_PER_FRAME = 4

        /**
         * How many frames one pair of gains is used for.
         *
         * At 48kHz this is under a millisecond and a half of audio, against a
         * sweep that takes six seconds — far below anything the ear resolves
         * as a step.
         */
        const val FRAMES_PER_STEP = 64
    }
}
