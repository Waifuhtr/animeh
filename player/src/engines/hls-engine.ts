import Hls, { Events as HlsEvents, ErrorTypes, type ErrorData, type HlsConfig } from 'hls.js'
import { Emitter } from '../core/emitter.ts'
import { ErrorCode, PlayerError, toPlayerError } from '../core/errors.ts'
import type { EngineEvents, MediaEngine } from '../core/engine.ts'
import type {
  AudioTrackInfo,
  MediaSourceDescriptor,
  NetworkSnapshot,
  QualityLevel,
  SubtitleTrackInfo,
} from '../core/types.ts'
import { bufferProfileFor, formatQualityLabel, pickStartLevel, type BufferProfile } from '../net/policy.ts'

export interface HlsEngineOptions {
  network: () => NetworkSnapshot
  estimateBps: () => number
  /** Viewport height in CSS pixels, so we never fetch more pixels than we show. */
  viewportHeight?: () => number
  /** User's saved quality ceiling, or null for auto. */
  preferredHeight?: () => number | null
}

/**
 * HLS playback.
 *
 * hls.js does the transport and adaptation; everything visible — controls,
 * state, quality menu, subtitle rendering — stays ours. What this class adds
 * on top is the buffering and bitrate policy from the network profile, and a
 * recovery ladder that treats a dropped connection as an expected event rather
 * than a failure.
 */
export class HlsEngine implements MediaEngine {
  readonly kind = 'hls' as const
  readonly events = new Emitter<EngineEvents>()

  #hls: Hls | null = null
  #video: HTMLVideoElement | null = null
  #options: HlsEngineOptions
  #qualities: QualityLevel[] = []
  #audioTracks: AudioTrackInfo[] = []
  #subtitleTracks: SubtitleTrackInfo[] = []
  #destroyed = false
  #loading = false
  /** Consecutive fatal network errors, used to space out recovery attempts. */
  #networkErrorCount = 0
  #mediaErrorCount = 0
  #recoveryTimer: ReturnType<typeof setTimeout> | null = null

  constructor(options: HlsEngineOptions) {
    this.#options = options
  }

  static isSupported(): boolean {
    return Hls.isSupported()
  }

  attach(video: HTMLVideoElement): void {
    this.#video = video
  }

  async load(source: MediaSourceDescriptor): Promise<void> {
    const video = this.#video
    if (!video) throw new Error('attach() must be called before load()')
    if (!Hls.isSupported()) {
      throw new PlayerError({
        code: ErrorCode.MEDIA_UNSUPPORTED,
        message: 'hls.js is not supported in this browser',
      })
    }

    const profile = bufferProfileFor(this.#options.network())
    const hls = new Hls(this.#buildConfig(profile, source))
    this.#hls = hls
    this.#bindEvents(hls, profile)

    hls.attachMedia(video)
    hls.loadSource(source.url)
    this.#setLoading(true)
  }

  /**
   * Translate our buffer profile into hls.js configuration.
   *
   * The shape of the trade-off: a weak link gets a deeper forward buffer and a
   * lower ceiling, so what little bandwidth there is goes into staying ahead
   * rather than into pixels the viewer cannot receive reliably.
   */
  #buildConfig(profile: BufferProfile, source: MediaSourceDescriptor): Partial<HlsConfig> {
    const estimate = this.#options.estimateBps()
    const headers = source.headers

    return {
      // Parsing off the main thread keeps the UI responsive during a switch.
      enableWorker: true,
      lowLatencyMode: false,
      // Progressive fetch lets the parser start before a segment finishes
      // downloading, which shortens startup on a slow link.
      progressive: false,

      maxBufferLength: profile.forwardSec,
      maxMaxBufferLength: Math.max(profile.forwardSec * 2, 120),
      maxBufferSize: profile.maxSizeMB * 1000 * 1000,
      backBufferLength: profile.backSec,
      maxStarvationDelay: profile.starvationDelaySec,

      // Seed the estimator with what we already measured, so the first segment
      // request is not a blind guess.
      abrEwmaDefaultEstimate: estimate,
      // Trust only part of the measured bandwidth; headroom is what keeps a
      // switch from immediately causing the stall it was meant to avoid.
      abrBandWidthFactor: 0.9,
      // Switching up is riskier than switching down, so demand more evidence.
      abrBandWidthUpFactor: 0.7,
      // A short-lived spike should not pull the whole ladder up.
      abrEwmaFastVoD: 3,
      abrEwmaSlowVoD: 12,

      // Never fetch a rendition taller than the box we are painting into.
      capLevelToPlayerSize: true,
      // We choose the opening rendition ourselves once the ladder is known.
      startLevel: -1,
      testBandwidth: true,

      // Retry budgets. Manifests are cheap and worth chasing; a segment that
      // will not load is better replaced by a lower-bitrate one.
      manifestLoadPolicy: {
        default: {
          maxTimeToFirstByteMs: 10_000,
          maxLoadTimeMs: 20_000,
          timeoutRetry: { maxNumRetry: 3, retryDelayMs: 500, maxRetryDelayMs: 4_000, backoff: 'exponential' },
          errorRetry: { maxNumRetry: 4, retryDelayMs: 500, maxRetryDelayMs: 8_000, backoff: 'exponential' },
        },
      },
      playlistLoadPolicy: {
        default: {
          maxTimeToFirstByteMs: 10_000,
          maxLoadTimeMs: 20_000,
          timeoutRetry: { maxNumRetry: 2, retryDelayMs: 500, maxRetryDelayMs: 4_000, backoff: 'exponential' },
          errorRetry: { maxNumRetry: 3, retryDelayMs: 500, maxRetryDelayMs: 8_000, backoff: 'exponential' },
        },
      },
      fragLoadPolicy: {
        default: {
          maxTimeToFirstByteMs: 12_000,
          maxLoadTimeMs: 60_000,
          timeoutRetry: { maxNumRetry: 3, retryDelayMs: 500, maxRetryDelayMs: 4_000, backoff: 'exponential' },
          errorRetry: { maxNumRetry: 4, retryDelayMs: 500, maxRetryDelayMs: 8_000, backoff: 'exponential' },
        },
      },

      ...(headers
        ? {
            xhrSetup: (xhr: XMLHttpRequest) => {
              for (const [key, value] of Object.entries(headers)) xhr.setRequestHeader(key, value)
            },
          }
        : {}),
    }
  }

  #bindEvents(hls: Hls, profile: BufferProfile): void {
    hls.on(HlsEvents.MANIFEST_PARSED, () => {
      this.#qualities = hls.levels.map((level, index) => ({
        id: index,
        height: level.height,
        width: level.width,
        bitrate: level.bitrate,
        codec: level.videoCodec,
        frameRate: level.frameRate,
        label: formatQualityLabel(level.height, level.frameRate),
      }))
      this.events.emit('qualitiesChanged', this.#qualities)

      // Open on a rendition the measured bandwidth can actually sustain.
      const startLevel = pickStartLevel(this.#qualities, this.#options.estimateBps(), profile, {
        viewportHeight: this.#options.viewportHeight?.(),
        pixelRatio: globalThis.devicePixelRatio,
        preferredHeight: this.#options.preferredHeight?.() ?? null,
      })
      if (startLevel >= 0) hls.startLevel = startLevel

      this.events.emit('ready', undefined)
      this.#setLoading(false)
    })

    hls.on(HlsEvents.LEVEL_SWITCHED, (_event, data) => {
      this.events.emit('qualitySwitched', { id: data.level, auto: hls.autoLevelEnabled })
    })

    hls.on(HlsEvents.AUDIO_TRACKS_UPDATED, (_event, data) => {
      this.#audioTracks = data.audioTracks.map((track, index) => ({
        id: String(track.id ?? index),
        language: track.lang ?? 'und',
        label: track.name || track.lang || `Ses ${index + 1}`,
        default: track.default,
      }))
      this.events.emit('audioTracksChanged', this.#audioTracks)
    })

    hls.on(HlsEvents.SUBTITLE_TRACKS_UPDATED, (_event, data) => {
      this.#subtitleTracks = data.subtitleTracks.map((track, index) => ({
        id: String(track.id ?? index),
        language: track.lang ?? 'und',
        label: track.name || track.lang || `Altyazı ${index + 1}`,
        // HLS carries WebVTT; ASS arrives as a sidecar the controller loads.
        format: 'vtt',
        origin: 'embedded',
        default: track.default,
      }))
      this.events.emit('subtitleTracksChanged', this.#subtitleTracks)
    })

    hls.on(HlsEvents.FRAG_LOADING, () => this.#setLoading(true))
    hls.on(HlsEvents.FRAG_LOADED, (_event, data) => {
      this.#setLoading(false)
      const stats = data.frag.stats
      const durationMs = stats.loading.end - stats.loading.first
      if (durationMs > 0 && stats.loaded > 0) {
        this.events.emit('throughput', { bytes: stats.loaded, durationMs })
      }
      // A fragment arriving means the stream is healthy again.
      if (this.#networkErrorCount > 0 || this.#mediaErrorCount > 0) {
        this.#networkErrorCount = 0
        this.#mediaErrorCount = 0
        this.events.emit('recovered', undefined)
      }
    })

    hls.on(HlsEvents.ERROR, (_event, data) => this.#handleError(data))
  }

  /**
   * Recovery ladder.
   *
   * hls.js retries individual requests itself; what reaches here is what it has
   * already given up on. Network faults get `startLoad` with backoff, media
   * faults get the decoder reset, and only a repeated failure of both is
   * treated as fatal — a phone changing cell towers should not end the episode.
   */
  #handleError(data: ErrorData): void {
    const hls = this.#hls
    if (!hls || this.#destroyed) return

    if (!data.fatal) {
      // Non-fatal errors are frequent and mostly self-healing; surface them for
      // the debug overlay without disturbing playback.
      this.events.emit(
        'error',
        new PlayerError({
          code: classifyHlsError(data),
          message: `${data.details}: ${data.error?.message ?? 'non-fatal'}`,
          fatal: false,
          context: { details: data.details, type: data.type },
        }),
      )
      return
    }

    // Nothing was ever parsed, so there is no stream to save. hls.js has
    // already exhausted the manifest retry budget configured above by the time
    // it calls a manifest failure fatal, and `startLoad` only resumes level and
    // fragment loading — it cannot re-fetch a manifest. Retrying here left a
    // dead address sitting in "buffering" indefinitely instead of failing, so
    // this is reported straight away and the caller can try another address.
    if (this.#qualities.length === 0) {
      this.#emitFatal(data, 'Stream could not be opened')
      return
    }

    switch (data.type) {
      case ErrorTypes.NETWORK_ERROR: {
        this.#networkErrorCount++
        if (this.#networkErrorCount > 5) {
          this.#emitFatal(data, 'Repeated network failures while loading the stream')
          return
        }
        const delay = Math.min(8_000, 500 * 2 ** (this.#networkErrorCount - 1))
        this.#scheduleRecovery(delay, () => hls.startLoad())
        return
      }

      case ErrorTypes.MEDIA_ERROR: {
        this.#mediaErrorCount++
        if (this.#mediaErrorCount > 3) {
          this.#emitFatal(data, 'Decoder could not be recovered')
          return
        }
        // The first attempt flushes and re-appends; a second adds a codec swap.
        if (this.#mediaErrorCount === 1) hls.recoverMediaError()
        else hls.swapAudioCodec(), hls.recoverMediaError()
        return
      }

      default:
        this.#emitFatal(data, data.details)
    }
  }

  #scheduleRecovery(delayMs: number, action: () => void): void {
    if (this.#recoveryTimer !== null) return
    this.#recoveryTimer = setTimeout(() => {
      this.#recoveryTimer = null
      if (this.#destroyed) return
      try {
        action()
      } catch (err) {
        this.events.emit('error', toPlayerError(err, ErrorCode.NETWORK_ERROR))
      }
    }, delayMs)
  }

  #emitFatal(data: ErrorData, message: string): void {
    this.events.emit(
      'error',
      new PlayerError({
        code: classifyHlsError(data),
        message: `${message} (${data.details})`,
        fatal: true,
        context: { details: data.details, type: data.type, url: data.url },
      }),
    )
  }

  getQualities(): QualityLevel[] {
    return this.#qualities
  }

  getActiveQualityId(): number | null {
    const level = this.#hls?.currentLevel
    return level === undefined || level < 0 ? null : level
  }

  /** `null` hands control back to the adaptive algorithm. */
  setQuality(id: number | null): void {
    if (!this.#hls) return
    this.#hls.currentLevel = id ?? -1
  }

  /**
   * Restrict automatic adaptation to renditions at or below `height`.
   * Adaptation keeps running underneath the cap rather than being pinned.
   */
  setQualityCeiling(height: number | null): void {
    const hls = this.#hls
    if (!hls) return
    if (height === null) {
      hls.autoLevelCapping = -1
      return
    }
    // Highest level that fits under the cap; fall back to the lowest so a cap
    // below every rendition still plays rather than stalling.
    let capped = -1
    let lowest = 0
    let lowestHeight = Number.POSITIVE_INFINITY
    hls.levels.forEach((level, index) => {
      if (level.height <= height && (capped < 0 || level.height > hls.levels[capped]!.height)) {
        capped = index
      }
      if (level.height < lowestHeight) {
        lowestHeight = level.height
        lowest = index
      }
    })
    hls.autoLevelCapping = capped >= 0 ? capped : lowest
  }

  getAudioTracks(): AudioTrackInfo[] {
    return this.#audioTracks
  }

  setAudioTrack(id: string): void {
    if (!this.#hls) return
    const index = this.#audioTracks.findIndex((track) => track.id === id)
    if (index >= 0) this.#hls.audioTrack = index
  }

  getSubtitleTracks(): SubtitleTrackInfo[] {
    return this.#subtitleTracks
  }

  selectSubtitleTrack(id: string | null): void {
    if (!this.#hls) return
    // Our own renderer draws subtitles, so hls.js's native track stays off.
    this.#hls.subtitleTrack = id === null ? -1 : this.#subtitleTracks.findIndex((t) => t.id === id)
    this.#hls.subtitleDisplay = false
  }

  async recover(): Promise<void> {
    const hls = this.#hls
    if (!hls || this.#destroyed) return
    this.#networkErrorCount = 0
    this.#mediaErrorCount = 0
    hls.startLoad()
  }

  destroy(): void {
    this.#destroyed = true
    if (this.#recoveryTimer !== null) {
      clearTimeout(this.#recoveryTimer)
      this.#recoveryTimer = null
    }
    this.#hls?.destroy()
    this.#hls = null
    this.events.removeAll()
  }

  #setLoading(value: boolean): void {
    if (this.#loading === value) return
    this.#loading = value
    this.events.emit('loadingChanged', value)
  }
}

function classifyHlsError(data: ErrorData): ErrorCode {
  const status = data.response?.code
  if (status === 401 || status === 403) return ErrorCode.AUTH_ERROR
  switch (data.type) {
    case ErrorTypes.NETWORK_ERROR:
      return data.details.includes('manifest') ? ErrorCode.MANIFEST_ERROR : ErrorCode.NETWORK_ERROR
    case ErrorTypes.MEDIA_ERROR:
      return ErrorCode.MEDIA_DECODE_ERROR
    case ErrorTypes.MUX_ERROR:
      return ErrorCode.CONTAINER_ERROR
    default:
      return ErrorCode.VIDEO_ERROR
  }
}
