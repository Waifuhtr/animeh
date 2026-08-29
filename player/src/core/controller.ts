import { Emitter } from './emitter.ts'
import { ErrorCode, PlayerError, toPlayerError } from './errors.ts'
import { sniffContainer, type MediaEngine } from './engine.ts'
import { HlsEngine } from '../engines/hls-engine.ts'
import { MkvEngine } from '../engines/mkv-engine.ts'
import { ProgressiveEngine } from '../engines/progressive-engine.ts'
import { NetworkMonitor } from '../net/network.ts'
import { ThroughputEstimator } from '../net/throughput.ts'
import { bufferProfileFor } from '../net/policy.ts'
import { SubtitleEngine, type SubtitleEngineOptions } from '../subtitles/engine.ts'
import { Telemetry } from '../telemetry/telemetry.ts'
import {
  LocalResumeStore,
  isCompleted,
  isWorthSaving,
  type ResumeStore,
} from './resume.ts'
import { fetchBytes } from '../net/fetcher.ts'
import type { FontReport } from '../fonts/registry.ts'
import type {
  AudioTrackInfo,
  BufferRange,
  ExternalSubtitle,
  MediaSourceDescriptor,
  PlaybackStats,
  PlayerPhase,
  PlayerSnapshot,
  QualityLevel,
  SubtitleTrackInfo,
} from './types.ts'

export interface PlayerEvents {
  /** Fires on every state change; the UI renders from this and nothing else. */
  snapshot: PlayerSnapshot
  error: PlayerError
  fontReport: FontReport
  ended: void
  /** The viewer asked for the next or previous episode. */
  navigate: 'next' | 'previous'
}

export interface AnimehPlayerOptions {
  video: HTMLVideoElement
  subtitleCanvas: HTMLCanvasElement
  subtitles: SubtitleEngineOptions
  resumeStore?: ResumeStore
  /** Saved preference: a height ceiling, or null for auto. */
  preferredHeight?: number | null
  /** How often to persist the resume position. */
  saveIntervalMs?: number
}

const DEFAULT_SAVE_INTERVAL_MS = 5_000
/** Ignore a stored resume point this close to the start. */
const RESUME_EPSILON_SEC = 5

/**
 * The player.
 *
 * Holds the one authoritative view of playback state and owns every decision
 * about it. The media engine below reports what the transport is doing; the UI
 * above renders snapshots and sends intents back. Neither talks to the other,
 * which is what lets the same state model carry over to the Android port where
 * the engine is Media3 and the UI is Compose.
 */
export class AnimehPlayer {
  readonly events = new Emitter<PlayerEvents>()
  readonly telemetry = new Telemetry()
  readonly subtitles: SubtitleEngine

  #video: HTMLVideoElement
  #options: AnimehPlayerOptions
  #engine: MediaEngine | null = null
  #network = new NetworkMonitor()
  #estimator = new ThroughputEstimator()
  #resumeStore: ResumeStore

  #source: MediaSourceDescriptor | null = null
  #phase: PlayerPhase = 'idle'
  #locked = false
  #autoQuality = true
  #selectedQualityId: number | null = null
  #activeQualityId: number | null = null
  #qualities: QualityLevel[] = []
  #audioTracks: AudioTrackInfo[] = []
  #subtitleTracks: SubtitleTrackInfo[] = []
  #activeSubtitleId: string | null = null
  #activeAudioId: string | null = null
  #error: PlayerError | null = null
  #engineLoading = false
  #saveTimer: ReturnType<typeof setInterval> | null = null
  #destroyed = false
  #detachers: (() => void)[] = []
  /** Set while a programmatic seek is in flight, to suppress resume writes. */
  #seeking = false
  /** Saved height ceiling for automatic adaptation; null means no cap. */
  #preferredHeight: number | null

  constructor(options: AnimehPlayerOptions) {
    this.#options = options
    this.#video = options.video
    this.#resumeStore = options.resumeStore ?? new LocalResumeStore()
    this.#preferredHeight = options.preferredHeight ?? null
    this.#network.bindEstimator(this.#estimator)
    this.subtitles = new SubtitleEngine(options.subtitles)
    this.subtitles.attach(options.video, options.subtitleCanvas)
    this.subtitles.events.on('fontReport', (report) => this.events.emit('fontReport', report))
    this.subtitles.events.on('error', (error) => this.#reportError(error))
    this.#bindVideo()
    this.#bindNetwork()
  }

  /* ── Loading ──────────────────────────────────────────────────────────── */

  async load(source: MediaSourceDescriptor): Promise<void> {
    await this.#teardownEngine()
    this.#source = source
    this.#error = null
    this.#qualities = []
    this.#audioTracks = []
    this.#subtitleTracks = []
    this.#activeSubtitleId = null
    this.#autoQuality = true
    this.#selectedQualityId = null
    this.telemetry.reset()
    this.telemetry.markLoadStart()
    this.#setPhase('loading')

    const kind = source.type && source.type !== 'auto' ? source.type : sniffContainer(source.url)
    const engine = this.#createEngine(kind)
    this.#engine = engine
    this.#bindEngine(engine)
    engine.attach(this.#video)

    if (source.fonts?.length) this.subtitles.registerServerFonts(source.fonts)

    try {
      await engine.load(source)
    } catch (err) {
      this.#reportError(toPlayerError(err, ErrorCode.VIDEO_ERROR, { url: source.url }))
      return
    }

    const start = await this.#resolveStartPosition(source)
    if (start > 0) this.seek(start)

    // External subtitles win over embedded ones: they are what the backend
    // curated for this episode, and they are already fetched by the time the
    // container's own tracks would be discovered.
    const preferred = source.subtitles?.find((track) => track.default) ?? source.subtitles?.[0]
    if (preferred) void this.setSubtitleTrack(`ext:${preferred.id}`)

    this.#startSaveTimer()
    this.#emit()
  }

  #createEngine(kind: 'hls' | 'mkv' | 'mp4'): MediaEngine {
    switch (kind) {
      case 'hls':
        return new HlsEngine({
          network: () => this.#network.snapshot(),
          estimateBps: () => this.#network.estimateBps(),
          viewportHeight: () => this.#video.clientHeight || globalThis.innerHeight,
          preferredHeight: () => this.#preferredHeight,
        })
      case 'mkv':
        return new MkvEngine({
          targetBufferSec: bufferProfileFor(this.#network.snapshot()).forwardSec,
          estimateBps: () => this.#network.estimateBps(),
        })
      case 'mp4':
        // Browser-native playback. Routing this to MkvEngine — which is a
        // Matroska demuxer — made it read an MP4's `ftyp` box as an EBML id
        // and fail on the first byte.
        return new ProgressiveEngine()
    }
  }

  /** Stored progress wins over the caller's hint, unless it is near the start. */
  async #resolveStartPosition(source: MediaSourceDescriptor): Promise<number> {
    const episodeId = source.episode?.episodeId
    if (episodeId) {
      const record = await this.#resumeStore.get(episodeId)
      if (record && !record.completed && record.positionSec > RESUME_EPSILON_SEC) {
        return record.positionSec
      }
    }
    return source.startPosition ?? 0
  }

  /* ── Engine wiring ────────────────────────────────────────────────────── */

  #bindEngine(engine: MediaEngine): void {
    engine.events.on('qualitiesChanged', (qualities) => {
      this.#qualities = qualities
      this.#emit()
    })
    engine.events.on('qualitySwitched', ({ id, auto }) => {
      if (this.#activeQualityId !== id) this.telemetry.markQualitySwitch()
      this.#activeQualityId = id
      this.#autoQuality = auto
      this.#emit()
    })
    engine.events.on('audioTracksChanged', (tracks) => {
      this.#audioTracks = tracks
      this.#activeAudioId ??= tracks.find((track) => track.default)?.id ?? tracks[0]?.id ?? null
      this.#emit()
    })
    engine.events.on('subtitleTracksChanged', (tracks) => {
      this.#subtitleTracks = [...this.#externalTracks(), ...tracks]
      // A release that ships its own typesetting should show it without the
      // viewer having to go looking; only fall back to the container's tracks
      // when the backend supplied no sidecar.
      if (this.#activeSubtitleId === null && this.#externalTracks().length === 0) {
        const preferred = tracks.find((track) => track.default) ?? tracks[0]
        if (preferred) void this.setSubtitleTrack(preferred.id)
      }
      this.#emit()
    })
    engine.events.on('subtitleHeader', ({ trackId, header, format }) => {
      if (this.#activeSubtitleId !== trackId) return
      if (format !== 'ass' && format !== 'ssa') return
      void this.subtitles.setSource({ kind: 'header', format, header })
    })
    engine.events.on('subtitleBlock', ({ trackId, payload, startMs, durationMs }) => {
      if (this.#activeSubtitleId !== trackId) return
      this.subtitles.pushBlock(payload, startMs, durationMs)
    })
    engine.events.on('subtitleData', ({ trackId, content }) => {
      if (this.#activeSubtitleId !== trackId) return
      void this.subtitles.setSource({ kind: 'script', format: 'ass', content })
    })
    engine.events.on('fontsFound', (fonts) => {
      void this.subtitles.addEmbeddedFonts(fonts)
    })
    engine.events.on('throughput', ({ bytes, durationMs }) => {
      this.#estimator.sample(bytes, durationMs)
      this.telemetry.addBytes(bytes)
      this.telemetry.setThroughput(this.#estimator.bps)
    })
    engine.events.on('loadingChanged', (loading) => {
      this.#engineLoading = loading
      this.#emit()
    })
    engine.events.on('error', (error) => this.#reportError(error))
    engine.events.on('recovered', () => {
      if (this.#phase === 'reconnecting') {
        this.#error = null
        this.#setPhase(this.#video.paused ? 'paused' : 'playing')
      }
    })
    engine.events.on('ready', () => this.#emit())
  }

  /* ── Media element wiring ─────────────────────────────────────────────── */

  #bindVideo(): void {
    const video = this.#video
    const on = <K extends keyof HTMLMediaElementEventMap>(
      type: K,
      handler: (event: HTMLMediaElementEventMap[K]) => void,
    ) => {
      video.addEventListener(type, handler)
      this.#detachers.push(() => video.removeEventListener(type, handler))
    }

    on('loadedmetadata', () => this.#emit())
    on('canplay', () => {
      if (this.#phase === 'loading') this.#setPhase('ready')
    })
    on('playing', () => {
      this.telemetry.markFirstFrame()
      this.telemetry.markStallEnd()
      this.#setPhase('playing')
    })
    on('pause', () => {
      if (this.#phase !== 'ended' && this.#phase !== 'error') this.#setPhase('paused')
      void this.#saveProgress()
    })
    on('waiting', () => {
      this.telemetry.markStallStart()
      // Distinguish "the network went away" from "the buffer ran dry": the
      // first needs recovery, the second just needs patience.
      this.#setPhase(this.#network.snapshot().online ? 'buffering' : 'reconnecting')
    })
    on('seeking', () => {
      this.#seeking = true
      this.#setPhase('seeking')
    })
    on('seeked', () => {
      this.#seeking = false
      this.#setPhase(video.paused ? 'paused' : 'playing')
    })
    on('timeupdate', () => this.#emit())
    on('progress', () => this.#emit())
    on('ratechange', () => this.#emit())
    on('volumechange', () => this.#emit())
    on('ended', () => {
      this.#setPhase('ended')
      void this.#saveProgress(true)
      this.events.emit('ended', undefined)
    })
    on('error', () => {
      const mediaError = video.error
      if (!mediaError) return
      this.#reportError(mediaErrorToPlayerError(mediaError))
    })
  }

  #bindNetwork(): void {
    this.#network.events.on('online', (online) => {
      if (online && this.#phase === 'reconnecting') void this.#engine?.recover()
      if (!online && this.#phase === 'playing') this.#setPhase('reconnecting')
      this.#emit()
    })
    this.#network.events.on('change', () => this.#emit())
  }

  /* ── Commands ─────────────────────────────────────────────────────────── */

  async play(): Promise<void> {
    if (this.#locked) return
    try {
      await this.#video.play()
    } catch (err) {
      // Autoplay rejection is a policy decision, not a failure: leave the
      // player paused and let the UI show its play button.
      if (err instanceof DOMException && err.name === 'NotAllowedError') {
        this.#setPhase('paused')
        return
      }
      this.#reportError(toPlayerError(err, ErrorCode.VIDEO_ERROR))
    }
  }

  pause(): void {
    if (this.#locked) return
    this.#video.pause()
  }

  togglePlay(): void {
    if (this.#video.paused) void this.play()
    else this.pause()
  }

  seek(positionSec: number): void {
    if (this.#locked) return
    const duration = this.#video.duration
    const target = Math.max(0, Number.isFinite(duration) ? Math.min(positionSec, duration - 0.5) : positionSec)

    // A Matroska source has to fetch from the right cluster before the media
    // element has anything to seek into, so the engine is told first.
    const engine = this.#engine
    if (engine instanceof MkvEngine && engine.seekable) engine.seekTo(target)

    this.#video.currentTime = target
  }

  seekBy(deltaSec: number): void {
    this.seek(this.#video.currentTime + deltaSec)
  }

  setVolume(volume: number): void {
    this.#video.volume = Math.min(1, Math.max(0, volume))
    if (this.#video.volume > 0) this.#video.muted = false
  }

  toggleMute(): void {
    this.#video.muted = !this.#video.muted
  }

  setPlaybackRate(rate: number): void {
    this.#video.playbackRate = rate
  }

  /** `null` returns control to the adaptive algorithm. */
  setQuality(id: number | null): void {
    this.#autoQuality = id === null
    this.#selectedQualityId = id
    this.#engine?.setQuality(id)
    this.#emit()
  }

  /**
   * Cap automatic adaptation by height, or `null` to lift the cap.
   *
   * Unlike `setQuality` this leaves the player in auto mode — it narrows what
   * auto is allowed to choose, which is what a "max 720p" setting means.
   */
  setPreferredHeight(height: number | null): void {
    this.#preferredHeight = height
    this.#engine?.setQualityCeiling?.(height)
    this.#emit()
  }

  get preferredHeight(): number | null {
    return this.#preferredHeight
  }

  setAudioTrack(id: string): void {
    this.#activeAudioId = id
    this.#engine?.setAudioTrack(id)
    this.#emit()
  }

  /**
   * Choose a subtitle track. `ext:` ids are sidecar files the backend
   * supplied; everything else belongs to the container.
   */
  async setSubtitleTrack(id: string | null): Promise<void> {
    this.#activeSubtitleId = id
    if (id === null) {
      this.#engine?.selectSubtitleTrack(null)
      await this.subtitles.setSource(null)
      this.#emit()
      return
    }

    if (id.startsWith('ext:')) {
      this.#engine?.selectSubtitleTrack(null)
      const external = this.#source?.subtitles?.find((track) => `ext:${track.id}` === id)
      if (external) await this.#loadExternalSubtitle(external)
    } else {
      this.#engine?.selectSubtitleTrack(id)
    }
    this.#emit()
  }

  async #loadExternalSubtitle(track: ExternalSubtitle): Promise<void> {
    try {
      const result = await fetchBytes(track.url, { retries: 2, headers: this.#source?.headers })
      const content = new TextDecoder('utf-8').decode(result.data)
      await this.subtitles.setSource({ kind: 'script', format: track.format, content })
    } catch (err) {
      // Subtitles failing is a degradation; the episode still plays.
      this.#reportError(
        toPlayerError(err, ErrorCode.SUBTITLE_ERROR, { url: track.url, trackId: track.id }),
      )
    }
  }

  setLocked(locked: boolean): void {
    this.#locked = locked
    this.#emit()
  }

  toggleLock(): void {
    this.setLocked(!this.#locked)
  }

  async toggleFullscreen(container: HTMLElement): Promise<void> {
    try {
      if (document.fullscreenElement) await document.exitFullscreen()
      else await container.requestFullscreen()
    } catch {
      // Denied or unsupported; the control simply does nothing.
    }
    this.#emit()
  }

  requestNext(): void {
    this.events.emit('navigate', 'next')
  }

  requestPrevious(): void {
    this.events.emit('navigate', 'previous')
  }

  /** Retry after a fatal error, from where playback stopped. */
  async retry(): Promise<void> {
    const source = this.#source
    if (!source) return
    const position = this.#video.currentTime
    await this.load({ ...source, startPosition: position })
    await this.play()
  }

  /* ── State ────────────────────────────────────────────────────────────── */

  get snapshot(): PlayerSnapshot {
    const video = this.#video
    return {
      phase: this.#phase,
      position: video.currentTime,
      duration: Number.isFinite(video.duration) ? video.duration : 0,
      bufferAhead: bufferAhead(video),
      buffered: bufferedRanges(video),
      volume: video.volume,
      muted: video.muted,
      playbackRate: video.playbackRate,
      fullscreen: document.fullscreenElement !== null,
      locked: this.#locked,
      qualities: this.#qualities,
      selectedQualityId: this.#selectedQualityId,
      activeQualityId: this.#activeQualityId ?? this.#engine?.getActiveQualityId() ?? null,
      autoQuality: this.#autoQuality,
      audioTracks: this.#audioTracks,
      activeAudioTrackId: this.#activeAudioId,
      subtitleTracks: this.#subtitleTracks,
      activeSubtitleTrackId: this.#activeSubtitleId,
      network: this.#network.snapshot(),
      error: this.#error,
      episode: this.#source?.episode ?? null,
      loading: this.#engineLoading,
    }
  }

  stats(): PlaybackStats {
    const quality = this.#video.getVideoPlaybackQuality?.()
    return this.telemetry.snapshot(quality?.droppedVideoFrames ?? 0)
  }

  subscribe(listener: (snapshot: PlayerSnapshot) => void): () => void {
    const off = this.events.on('snapshot', listener)
    listener(this.snapshot)
    return off
  }

  async destroy(): Promise<void> {
    this.#destroyed = true
    this.#stopSaveTimer()
    for (const detach of this.#detachers) detach()
    this.#detachers = []
    this.#network.destroy()
    await this.subtitles.destroy()
    await this.#teardownEngine()
    this.events.removeAll()
  }

  /* ── Internals ────────────────────────────────────────────────────────── */

  #externalTracks(): SubtitleTrackInfo[] {
    return (this.#source?.subtitles ?? []).map((track) => ({
      id: `ext:${track.id}`,
      language: track.language,
      label: track.label,
      format: track.format,
      origin: 'external' as const,
      default: track.default,
    }))
  }

  #setPhase(phase: PlayerPhase): void {
    if (this.#phase === phase) return
    this.#phase = phase
    this.#emit()
  }

  #emit(): void {
    if (this.#destroyed) return
    this.events.emit('snapshot', this.snapshot)
  }

  #reportError(error: PlayerError): void {
    this.telemetry.recordError(error)
    this.events.emit('error', error)
    if (!error.fatal) return

    this.#error = error
    // A retriable fault gets a recovery attempt before the viewer sees
    // anything; only a hard failure surfaces as an error screen.
    if (error.retriable && this.#engine) {
      this.#setPhase('reconnecting')
      void this.#engine.recover().catch(() => this.#setPhase('error'))
      return
    }
    this.#setPhase('error')
  }

  async #teardownEngine(): Promise<void> {
    this.#stopSaveTimer()
    const engine = this.#engine
    this.#engine = null
    engine?.destroy()
  }

  #startSaveTimer(): void {
    this.#stopSaveTimer()
    const interval = this.#options.saveIntervalMs ?? DEFAULT_SAVE_INTERVAL_MS
    this.#saveTimer = setInterval(() => void this.#saveProgress(), interval)
  }

  #stopSaveTimer(): void {
    if (this.#saveTimer !== null) {
      clearInterval(this.#saveTimer)
      this.#saveTimer = null
    }
  }

  async #saveProgress(force = false): Promise<void> {
    const episode = this.#source?.episode
    if (!episode) return
    if (this.#seeking && !force) return

    const position = this.#video.currentTime
    const duration = Number.isFinite(this.#video.duration) ? this.#video.duration : 0
    if (!force && !isWorthSaving(position)) return

    await this.#resumeStore.save({
      episodeId: episode.episodeId,
      animeId: episode.animeId,
      positionSec: position,
      durationSec: duration,
      completed: isCompleted(position, duration),
      updatedAt: Date.now(),
    })
  }
}

/* ── Helpers ────────────────────────────────────────────────────────────── */

function bufferedRanges(video: HTMLVideoElement): BufferRange[] {
  const ranges: BufferRange[] = []
  const buffered = video.buffered
  for (let i = 0; i < buffered.length; i++) {
    ranges.push({ start: buffered.start(i), end: buffered.end(i) })
  }
  return ranges
}

function bufferAhead(video: HTMLVideoElement): number {
  const buffered = video.buffered
  const position = video.currentTime
  for (let i = 0; i < buffered.length; i++) {
    if (buffered.start(i) <= position + 0.25 && buffered.end(i) > position) {
      return buffered.end(i) - position
    }
  }
  return 0
}

function mediaErrorToPlayerError(error: MediaError): PlayerError {
  switch (error.code) {
    case MediaError.MEDIA_ERR_ABORTED:
      return new PlayerError({
        code: ErrorCode.VIDEO_ERROR,
        message: 'Playback aborted',
        fatal: false,
      })
    case MediaError.MEDIA_ERR_NETWORK:
      return new PlayerError({
        code: ErrorCode.NETWORK_ERROR,
        message: `Network error during playback: ${error.message}`,
      })
    case MediaError.MEDIA_ERR_DECODE:
      return new PlayerError({
        code: ErrorCode.MEDIA_DECODE_ERROR,
        message: `Decoder failure: ${error.message}`,
      })
    case MediaError.MEDIA_ERR_SRC_NOT_SUPPORTED:
      return new PlayerError({
        code: ErrorCode.MEDIA_UNSUPPORTED,
        message: `Source not supported: ${error.message}`,
        retriable: false,
      })
    default:
      return new PlayerError({ code: ErrorCode.VIDEO_ERROR, message: error.message })
  }
}
