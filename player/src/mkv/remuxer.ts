import type { MkvFrame, MkvTrack } from './demuxer.ts'
import { MP4_TIMESCALE, mapAudioTrack, mapVideoTrack, type Mp4TrackMapping } from './codecs.ts'
import { concat, fragment, ftyp, moov, type Mp4Sample, type TrackConfig } from './mp4.ts'

/**
 * How many frames to hold back before emitting, so decode timestamps are stable.
 *
 * Matroska stores frames in decode order but timestamps them with presentation
 * times; MP4 wants decode times plus a composition offset. The decode time of
 * the i-th frame is the i-th smallest presentation time in the stream, so it
 * can only be known once enough following frames have arrived to be sure none
 * of them sorts earlier. A reorder depth above 4 is vanishingly rare, so 24
 * frames — one second at 24 fps — is a wide margin.
 */
const VIDEO_LOOKAHEAD = 24
/** Audio never reorders; one frame of lookahead is enough to size the last sample. */
const AUDIO_LOOKAHEAD = 1

/** Target media duration per emitted fragment. */
const TARGET_FRAGMENT_SEC = 1

/**
 * Constant added to every composition offset, in seconds.
 *
 * Reordered frames decode after they are displayed, so their natural
 * composition offset (presentation minus decode time) is negative. `trun`
 * version 1 permits that, but a negative offset is also how a muxer signals
 * that it already shifted decode times down — so demuxers "helpfully" add the
 * shift back and move the whole presentation timeline. Biasing every offset
 * into positive territory removes the ambiguity entirely.
 *
 * The cost is that the media timeline sits one second ahead of the content
 * timeline; `MkvRemuxer.timelineOffsetSec` reports the difference so the
 * player can map between them. One second comfortably exceeds the reorder
 * delay of any real-world encode, and being a constant it never shifts
 * mid-stream the way a measured value could.
 */
const COMPOSITION_BIAS_SEC = 1

export interface RemuxedSegment {
  data: Uint8Array
  /** Presentation time of the first sample, in seconds. */
  startTime: number
  /** Media covered by this fragment, in seconds. */
  duration: number
}

/** Remuxes one Matroska track into a stream of fMP4 fragments. */
class TrackRemuxer {
  readonly mapping: Mp4TrackMapping
  readonly trackId: number
  readonly kind: 'video' | 'audio'

  #track: MkvTrack
  #lookahead: number
  #pending: MkvFrame[] = []
  /**
   * Presentation times of every pending frame, ascending.
   *
   * Decode times are the presentation times in sorted order, so the pool is
   * consumed from the front as samples are emitted. Ranking has to be global
   * rather than per-fragment: a reordered frame held back at one boundary can
   * carry a presentation time lower than frames already emitted, and ranking
   * each window on its own would hand it a decode time that runs backwards.
   */
  #decodePool: number[] = []
  #sequence = 1
  /** Fallback duration for the final sample of the stream. */
  #lastDuration = 0

  constructor(track: MkvTrack, trackId: number, kind: 'video' | 'audio') {
    this.#track = track
    this.trackId = trackId
    this.kind = kind
    this.mapping = kind === 'video' ? mapVideoTrack(track) : mapAudioTrack(track)
    this.#lookahead = kind === 'video' ? VIDEO_LOOKAHEAD : AUDIO_LOOKAHEAD
  }

  get trackConfig(): TrackConfig {
    return {
      id: this.trackId,
      timescale: MP4_TIMESCALE,
      kind: this.kind,
      sampleEntry: this.mapping.sampleEntry,
      width: this.#track.video?.pixelWidth,
      height: this.#track.video?.pixelHeight,
      language: this.#track.language,
    }
  }

  /** Drop buffered frames and restart fragment numbering after a seek. */
  reset(): void {
    this.#pending = []
    this.#decodePool = []
    // Sequence numbers only have to be monotonic within a fragment stream;
    // continuing rather than restarting keeps them unique across seeks.
    this.#sequence++
  }

  add(frame: MkvFrame): void {
    this.#pending.push(frame)
    insertAscending(this.#decodePool, nsToTicks(frame.timestampNs))
  }

  /** Emit whatever fragments are complete. */
  drain(): RemuxedSegment[] {
    const segments: RemuxedSegment[] = []
    for (;;) {
      const count = this.#readyCount()
      if (count === 0) break
      const segment = this.#emit(count, false)
      if (!segment) break
      segments.push(segment)
    }
    return segments
  }

  /** Emit everything left, including the final sample. */
  flush(): RemuxedSegment[] {
    const segments = this.drain()
    if (this.#pending.length > 0) {
      const segment = this.#emit(this.#pending.length, true)
      if (segment) segments.push(segment)
    }
    return segments
  }

  /** Number of leading frames whose decode timestamps are now settled. */
  #readyCount(): number {
    const available = this.#pending.length - this.#lookahead
    if (available <= 0) return 0

    // Only emit once there is a fragment's worth of media, otherwise a slow
    // feed produces a flood of tiny fragments.
    const first = this.#pending[0]!
    const last = this.#pending[available - 1]!
    const spanSec = (last.timestampNs - first.timestampNs) / 1e9
    if (spanSec < TARGET_FRAGMENT_SEC) return 0
    return available
  }

  /**
   * Build one fragment from the first `count` pending frames.
   * @param final when true the last sample has no successor to measure against
   */
  #emit(count: number, final: boolean): RemuxedSegment | null {
    if (count === 0) return null
    const frames = this.#pending
    // The decode time of the i-th sample is the i-th smallest presentation
    // time still pending. One extra value is taken so the last emitted sample
    // can be given a duration.
    const decodeTimes = this.#decodePool.slice(0, count + 1)

    const samples: Mp4Sample[] = []
    const payloads: Uint8Array[] = []
    let totalDuration = 0

    for (let i = 0; i < count; i++) {
      const frame = frames[i]!
      const dts = decodeTimes[i]!
      const nextDts = decodeTimes[i + 1]
      let duration: number
      if (nextDts !== undefined) {
        duration = nextDts - dts
      } else if (this.#track.defaultDurationNs !== null) {
        duration = nsToTicks(this.#track.defaultDurationNs)
      } else {
        duration = this.#lastDuration || nsToTicks(33_000_000)
      }
      // A zero or negative duration makes the decoder drop the sample.
      if (duration <= 0) duration = this.#lastDuration || 1
      this.#lastDuration = duration

      // Bias into positive territory; clamp defensively in case a stream
      // reorders further than the bias allows.
      const compositionOffset = Math.max(
        0,
        nsToTicks(frame.timestampNs) - dts + COMPOSITION_BIAS_TICKS,
      )
      samples.push({
        duration,
        size: frame.data.length,
        compositionOffset,
        keyframe: frame.keyframe,
      })
      payloads.push(frame.data)
      totalDuration += duration
    }

    const baseMediaDecodeTime = decodeTimes[0]!
    const data = fragment(
      this.trackId,
      this.#sequence++,
      baseMediaDecodeTime,
      samples,
      concat(...payloads),
    )

    this.#pending = frames.slice(count)
    this.#decodePool = this.#decodePool.slice(count)
    if (final) {
      this.#pending = []
      this.#decodePool = []
    }

    return {
      data,
      startTime: baseMediaDecodeTime / MP4_TIMESCALE,
      duration: totalDuration / MP4_TIMESCALE,
    }
  }
}

/** Binary insertion into an ascending array. */
function insertAscending(values: number[], value: number): void {
  let low = 0
  let high = values.length
  while (low < high) {
    const mid = (low + high) >>> 1
    if (values[mid]! < value) low = mid + 1
    else high = mid
  }
  values.splice(low, 0, value)
}

function nsToTicks(ns: number): number {
  return Math.round((ns / 1_000_000_000) * MP4_TIMESCALE)
}

const COMPOSITION_BIAS_TICKS = COMPOSITION_BIAS_SEC * MP4_TIMESCALE

export interface RemuxOutput {
  video: RemuxedSegment[]
  audio: RemuxedSegment[]
}

/**
 * Turns demuxed Matroska frames into the two fMP4 streams MSE consumes.
 *
 * Video and audio are kept in separate SourceBuffers rather than muxed
 * together: each gets its own codec string, the browser can evict them
 * independently, and an unsupported audio codec degrades to video-only instead
 * of failing the whole load.
 */
export class MkvRemuxer {
  readonly video: { mimeType: string; trackNumber: number } | null = null
  readonly audio: { mimeType: string; trackNumber: number } | null = null
  /**
   * Seconds the remuxed media timeline runs ahead of the container's own
   * timeline. Subtract it to turn `video.currentTime` into content time; add it
   * when seeking. Applied identically to video and audio, so A/V sync holds.
   */
  readonly timelineOffsetSec = COMPOSITION_BIAS_SEC
  /** Non-fatal problems worth surfacing, e.g. an undecodable audio track. */
  readonly warnings: Error[] = []

  #videoRemuxer: TrackRemuxer | null = null
  #audioRemuxer: TrackRemuxer | null = null

  constructor(videoTrack: MkvTrack | null, audioTrack: MkvTrack | null) {
    if (videoTrack) {
      try {
        this.#videoRemuxer = new TrackRemuxer(videoTrack, 1, 'video')
        this.video = { mimeType: this.#videoRemuxer.mapping.mimeType, trackNumber: videoTrack.number }
      } catch (err) {
        // No video is fatal; let the caller decide by leaving `video` null.
        this.warnings.push(err as Error)
      }
    }
    if (audioTrack) {
      try {
        this.#audioRemuxer = new TrackRemuxer(audioTrack, 2, 'audio')
        this.audio = { mimeType: this.#audioRemuxer.mapping.mimeType, trackNumber: audioTrack.number }
      } catch (err) {
        // Audio we cannot decode is a degradation, not a failure.
        this.warnings.push(err as Error)
      }
    }
  }

  /** The `ftyp` + `moov` prelude a SourceBuffer needs before any fragment. */
  initSegment(kind: 'video' | 'audio'): Uint8Array | null {
    const remuxer = kind === 'video' ? this.#videoRemuxer : this.#audioRemuxer
    if (!remuxer) return null
    return concat(ftyp(), moov([remuxer.trackConfig], MP4_TIMESCALE))
  }

  /** Feed demuxed frames; returns any fragments that became complete. */
  push(frames: MkvFrame[]): RemuxOutput {
    for (const frame of frames) {
      if (this.#videoRemuxer && frame.track === this.video?.trackNumber) {
        this.#videoRemuxer.add(frame)
      } else if (this.#audioRemuxer && frame.track === this.audio?.trackNumber) {
        this.#audioRemuxer.add(frame)
      }
    }
    return {
      video: this.#videoRemuxer?.drain() ?? [],
      audio: this.#audioRemuxer?.drain() ?? [],
    }
  }

  /** Emit trailing fragments at end of stream. */
  flush(): RemuxOutput {
    return {
      video: this.#videoRemuxer?.flush() ?? [],
      audio: this.#audioRemuxer?.flush() ?? [],
    }
  }

  /** Discard buffered frames after a seek. */
  reset(): void {
    this.#videoRemuxer?.reset()
    this.#audioRemuxer?.reset()
  }
}
