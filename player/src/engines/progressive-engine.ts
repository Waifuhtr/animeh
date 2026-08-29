import { Emitter } from '../core/emitter.ts'
import { ErrorCode, PlayerError } from '../core/errors.ts'
import type { EngineEvents, MediaEngine } from '../core/engine.ts'
import type {
  AudioTrackInfo,
  MediaSourceDescriptor,
  QualityLevel,
  SubtitleTrackInfo,
} from '../core/types.ts'
import { fetchBytes } from '../net/fetcher.ts'
import { formatQualityLabel } from '../net/policy.ts'

/**
 * Plays a single file by handing it to the browser.
 *
 * MP4 and WebM need no help from us: every browser demuxes them natively, over
 * ranged progressive download, straight into the hardware decode path. Parsing
 * them in JavaScript and remuxing for Media Source Extensions — which is what
 * `MkvEngine` does, and has to do, because no browser demuxes Matroska — would
 * be slower, use more battery, and support fewer codecs than simply setting
 * `src`.
 *
 * `MkvEngine` earns its complexity because a Matroska file carries the ASS
 * subtitles and the fonts a release was typeset with, and only demuxing it
 * ourselves gets those out. An MP4 has nothing comparable to extract.
 */
export class ProgressiveEngine implements MediaEngine {
  readonly kind = 'mp4' as const
  readonly events = new Emitter<EngineEvents>()

  #video: HTMLVideoElement | null = null
  #url = ''
  #headers: Record<string, string> | undefined
  #qualities: QualityLevel[] = []
  #audioTracks: AudioTrackInfo[] = []
  #detachers: (() => void)[] = []
  #destroyed = false
  #loading = false
  #abort: AbortController | null = null
  /** Total size from a range probe, used to derive a nominal bitrate. */
  #byteSize: number | null = null
  /** Baseline for turning buffer growth into a transfer estimate. */
  #lastBufferedEnd = 0
  #lastSampleAt = 0

  attach(video: HTMLVideoElement): void {
    this.#video = video
  }

  async load(source: MediaSourceDescriptor): Promise<void> {
    const video = this.#video
    if (!video) throw new Error('attach() must be called before load()')

    this.#url = source.url
    this.#headers = source.headers
    this.#abort = new AbortController()

    this.#bind(video)
    this.#setLoading(true)

    // Native playback needs no CORS, so this must not be gated on a probe that
    // does. It runs alongside and only enriches the quality label.
    void this.#probeSize()

    video.src = source.url
    video.load()

    await this.#waitForMetadata(video)
    if (this.#destroyed) return

    this.#publishTracks(video)
    this.events.emit('ready', undefined)
    this.#setLoading(false)
  }

  /**
   * Resolve once the browser knows what it is playing, or reject if it decides
   * it cannot play it at all.
   */
  #waitForMetadata(video: HTMLVideoElement): Promise<void> {
    return new Promise((resolve, reject) => {
      const cleanup = () => {
        video.removeEventListener('loadedmetadata', onLoaded)
        video.removeEventListener('error', onError)
      }
      const onLoaded = () => {
        cleanup()
        resolve()
      }
      const onError = () => {
        cleanup()
        const error = video.error
        // A source the browser refuses outright is the one failure worth
        // naming precisely: it means the codec or container is unsupported,
        // not that the network hiccuped.
        if (error?.code === MediaError.MEDIA_ERR_SRC_NOT_SUPPORTED) {
          reject(
            new PlayerError({
              code: ErrorCode.MEDIA_UNSUPPORTED,
              message: `Browser cannot play ${this.#url}: ${error.message || 'unsupported source'}`,
              retriable: false,
              context: { url: this.#url },
            }),
          )
          return
        }
        reject(
          new PlayerError({
            code: ErrorCode.VIDEO_ERROR,
            message: `Failed to load ${this.#url}: ${error?.message ?? 'unknown error'}`,
            context: { url: this.#url, code: error?.code },
          }),
        )
      }
      video.addEventListener('loadedmetadata', onLoaded)
      video.addEventListener('error', onError)
    })
  }

  #bind(video: HTMLVideoElement): void {
    const on = <K extends keyof HTMLMediaElementEventMap>(
      type: K,
      handler: (event: HTMLMediaElementEventMap[K]) => void,
    ) => {
      video.addEventListener(type, handler)
      this.#detachers.push(() => video.removeEventListener(type, handler))
    }

    // The browser owns the fetching, so "loading" is inferred from whether it
    // is currently waiting on data.
    on('waiting', () => this.#setLoading(true))
    on('stalled', () => this.#setLoading(true))
    on('playing', () => this.#setLoading(false))
    on('canplaythrough', () => this.#setLoading(false))
    on('progress', () => {
      // Buffered ahead of the playhead means the fetch is keeping up.
      if (this.#bufferAhead(video) > 2) this.#setLoading(false)
      this.#sampleThroughput(video)
    })
    // A seek moves the buffer somewhere unrelated; the old baseline would
    // read as a huge transfer or a negative one.
    on('seeking', () => this.#resetThroughputBaseline())
    on('ended', () => this.events.emit('ended', undefined))
    // Dimensions can change on a file with multiple sample entries.
    on('resize', () => this.#publishTracks(video))
  }

  /**
   * Estimate transfer from how fast the buffer fills.
   *
   * The browser owns the fetching here, so there are no request sizes to
   * measure. What is observable is buffered media growing, and the file's
   * average bitrate converts that into bytes. Samples are only taken while the
   * buffer is actually growing: once the browser has read far enough ahead it
   * stops fetching, and reporting that as a bandwidth collapse would be wrong.
   */
  #sampleThroughput(video: HTMLVideoElement): void {
    const bitrate = this.#nominalBitrate(video)
    if (bitrate <= 0) return

    const end = this.#bufferedEnd(video)
    const now = performance.now()

    if (this.#lastSampleAt === 0 || end < this.#lastBufferedEnd) {
      this.#lastBufferedEnd = end
      this.#lastSampleAt = now
      return
    }

    const grownSeconds = end - this.#lastBufferedEnd
    const elapsedMs = now - this.#lastSampleAt
    if (grownSeconds <= 0 || elapsedMs <= 0) return

    this.#lastBufferedEnd = end
    this.#lastSampleAt = now
    this.events.emit('throughput', {
      bytes: Math.round((grownSeconds * bitrate) / 8),
      durationMs: elapsedMs,
    })
  }

  #resetThroughputBaseline(): void {
    this.#lastBufferedEnd = 0
    this.#lastSampleAt = 0
  }

  /** Furthest buffered point, across all ranges. */
  #bufferedEnd(video: HTMLVideoElement): number {
    const buffered = video.buffered
    let furthest = 0
    for (let i = 0; i < buffered.length; i++) {
      furthest = Math.max(furthest, buffered.end(i))
    }
    return furthest
  }

  #bufferAhead(video: HTMLVideoElement): number {
    const buffered = video.buffered
    const position = video.currentTime
    for (let i = 0; i < buffered.length; i++) {
      if (buffered.start(i) <= position + 0.25 && buffered.end(i) > position) {
        return buffered.end(i) - position
      }
    }
    return 0
  }

  /**
   * Learn the file size so the quality entry can report a real bitrate.
   *
   * Best effort by design: a one-byte range request needs CORS, which native
   * playback does not, so this fails on plenty of servers that play perfectly.
   */
  async #probeSize(): Promise<void> {
    try {
      const result = await fetchBytes(this.#url, {
        range: { start: 0, end: 0 },
        headers: this.#headers,
        signal: this.#abort?.signal,
        retries: 0,
        timeoutMs: 8000,
        onProgress: (bytes, durationMs) => this.events.emit('throughput', { bytes, durationMs }),
      })
      if (this.#destroyed) return
      this.#byteSize = result.totalSize
      if (this.#video) this.#publishTracks(this.#video)
    } catch {
      // No size, no bitrate in the label. Playback is unaffected.
    }
  }

  #publishTracks(video: HTMLVideoElement): void {
    if (video.videoWidth > 0) {
      const height = video.videoHeight
      this.#qualities = [
        {
          id: 0,
          height,
          width: video.videoWidth,
          bitrate: this.#nominalBitrate(video),
          label: formatQualityLabel(height),
        },
      ]
      this.events.emit('qualitiesChanged', this.#qualities)
    }

    // `audioTracks` is only implemented in some browsers; where it is missing
    // the file still plays, it just has no track list to offer.
    const list = (video as HTMLVideoElement & { audioTracks?: AudioTrackListLike }).audioTracks
    if (list && list.length > 0) {
      const tracks: AudioTrackInfo[] = []
      for (let i = 0; i < list.length; i++) {
        const track = list[i]
        if (!track) continue
        tracks.push({
          id: track.id || String(i),
          language: track.language || 'und',
          label: track.label || track.language || `Ses ${i + 1}`,
          default: track.enabled,
        })
      }
      if (tracks.length !== this.#audioTracks.length) {
        this.#audioTracks = tracks
        this.events.emit('audioTracksChanged', tracks)
      }
    }
  }

  /** Average bitrate across the whole file, when both size and duration are known. */
  #nominalBitrate(video: HTMLVideoElement): number {
    if (this.#byteSize === null || !Number.isFinite(video.duration) || video.duration <= 0) return 0
    return Math.round((this.#byteSize * 8) / video.duration)
  }

  getQualities(): QualityLevel[] {
    return this.#qualities
  }

  getActiveQualityId(): number | null {
    return this.#qualities.length > 0 ? 0 : null
  }

  /** A single file has one rendition; there is nothing to switch to. */
  setQuality(): void {}

  getAudioTracks(): AudioTrackInfo[] {
    return this.#audioTracks
  }

  setAudioTrack(id: string): void {
    const video = this.#video
    const list = (video as (HTMLVideoElement & { audioTracks?: AudioTrackListLike }) | null)?.audioTracks
    if (!list) return
    for (let i = 0; i < list.length; i++) {
      const track = list[i]
      if (track) track.enabled = (track.id || String(i)) === id
    }
    this.#audioTracks = this.#audioTracks.map((track) => ({ ...track, default: track.id === id }))
    this.events.emit('audioTracksChanged', this.#audioTracks)
  }

  /**
   * Subtitles inside a progressive file are surfaced by the browser as text
   * tracks, which our own renderer does not use. Sidecar subtitles are loaded
   * by the controller instead.
   */
  getSubtitleTracks(): SubtitleTrackInfo[] {
    return []
  }

  selectSubtitleTrack(): void {}

  /** Re-fetch from where playback stopped. */
  async recover(): Promise<void> {
    const video = this.#video
    if (!video || this.#destroyed) return

    const position = video.currentTime
    const wasPlaying = !video.paused
    this.#resetThroughputBaseline()
    video.load()

    await new Promise<void>((resolve) => {
      const onReady = () => {
        video.removeEventListener('loadedmetadata', onReady)
        resolve()
      }
      video.addEventListener('loadedmetadata', onReady)
      // Never hang the recovery ladder on an event that may not arrive.
      setTimeout(onReady, 5000)
    })

    if (this.#destroyed) return
    if (position > 0) video.currentTime = position
    if (wasPlaying) void video.play().catch(() => undefined)
    this.events.emit('recovered', undefined)
  }

  destroy(): void {
    this.#destroyed = true
    this.#abort?.abort()
    for (const detach of this.#detachers) detach()
    this.#detachers = []

    const video = this.#video
    if (video) {
      // Clearing the source and reloading is what actually stops the browser
      // downloading the rest of the file.
      video.removeAttribute('src')
      video.load()
    }
    this.events.removeAll()
  }

  #setLoading(value: boolean): void {
    if (this.#loading === value || this.#destroyed) return
    this.#loading = value
    this.events.emit('loadingChanged', value)
  }
}

/** The parts of `AudioTrackList` we use, for browsers that implement it. */
interface AudioTrackListLike {
  readonly length: number
  [index: number]: { id: string; language: string; label: string; enabled: boolean } | undefined
}
