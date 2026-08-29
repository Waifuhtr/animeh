import type { Emitter } from './emitter.ts'
import type { PlayerError } from './errors.ts'
import type {
  AudioTrackInfo,
  EmbeddedFont,
  MediaSourceDescriptor,
  QualityLevel,
  SubtitleTrackInfo,
} from './types.ts'

/**
 * Events every engine speaks, regardless of container.
 *
 * The controller consumes only these; it never reaches into hls.js or the
 * Matroska demuxer directly. That is what keeps the Android port honest —
 * the same event set maps onto Media3 listeners.
 */
export interface EngineEvents {
  /** Manifest/header parsed. Tracks and qualities are now known. */
  ready: void
  qualitiesChanged: QualityLevel[]
  /** Auto mode switched levels. */
  qualitySwitched: { id: number; auto: boolean }
  audioTracksChanged: AudioTrackInfo[]
  /** Subtitle tracks found *inside* the container. */
  subtitleTracksChanged: SubtitleTrackInfo[]
  /** A complete subtitle script for a track, in its native format. */
  subtitleData: { trackId: string; content: string }
  /**
   * One subtitle event, as containers that interleave subtitles deliver them.
   * `payload` is the Matroska block body — the ASS field list minus timing,
   * which is exactly the form libass consumes.
   */
  subtitleBlock: { trackId: string; payload: string; startMs: number; durationMs: number }
  /** The static header (styles, resolution) of an embedded subtitle track. */
  subtitleHeader: { trackId: string; header: string; format: 'ass' | 'ssa' | 'srt' | 'vtt' }
  /** Fonts found *inside* the container. */
  fontsFound: EmbeddedFont[]
  /** Engine is fetching. Drives the spinner independently of media element state. */
  loadingChanged: boolean
  /** A throughput sample: bytes over ms. */
  throughput: { bytes: number; durationMs: number }
  error: PlayerError
  /** Engine recovered on its own; the controller can clear a warning. */
  recovered: void
  ended: void
}

/**
 * A container/transport strategy.
 *
 * `hls` adapts bitrate itself; `mkv` and `mp4` are single-rendition and rely
 * on the source URL already being the right quality.
 */
export interface MediaEngine {
  readonly kind: 'hls' | 'mkv' | 'mp4'
  readonly events: Emitter<EngineEvents>

  /** Bind to a media element. Called once, before `load`. */
  attach(video: HTMLVideoElement): void

  load(source: MediaSourceDescriptor): Promise<void>

  /** `null` selects automatic bitrate adaptation. */
  setQuality(id: number | null): void
  getQualities(): QualityLevel[]
  /** The level currently being fetched, auto or manual. */
  getActiveQualityId(): number | null

  getAudioTracks(): AudioTrackInfo[]
  setAudioTrack(id: string): void

  getSubtitleTracks(): SubtitleTrackInfo[]
  /** Ask the engine to start emitting `subtitleData` for this track. */
  selectSubtitleTrack(id: string | null): void

  /**
   * Re-establish the stream after a network drop, keeping the position.
   * Engines that can recover in place should do so cheaply.
   */
  recover(): Promise<void>

  destroy(): void
}

/** Guess the container from a URL when the caller said `auto`. */
export function sniffContainer(url: string): 'hls' | 'mkv' | 'mp4' {
  const path = url.split(/[?#]/, 1)[0] ?? url
  const ext = path.slice(path.lastIndexOf('.') + 1).toLowerCase()
  if (ext === 'm3u8' || ext === 'm3u') return 'hls'
  if (ext === 'mkv' || ext === 'webm' || ext === 'mka') return 'mkv'
  return 'mp4'
}
