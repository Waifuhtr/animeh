import type { PlayerError } from './errors.ts'

/* ── Source description ─────────────────────────────────────────────────── */

export type ContainerKind = 'hls' | 'mkv' | 'mp4' | 'auto'

export interface ExternalSubtitle {
  id: string
  /** BCP-47-ish tag, e.g. "tr", "en". */
  language: string
  label: string
  url: string
  format: 'ass' | 'ssa' | 'srt' | 'vtt'
  default?: boolean
}

export interface FontDescriptor {
  /** Family name as it appears in the ASS script. */
  family: string
  url: string
}

/** Everything the player needs to know about the episode it is playing. */
export interface EpisodeContext {
  animeId: string
  episodeId: string
  animeTitle: string
  episodeTitle?: string
  season?: number
  episodeNumber?: number
  /** Seconds. Enables "skip intro"/"skip outro" affordances. */
  introStart?: number
  introEnd?: number
  outroStart?: number
  hasNext?: boolean
  hasPrevious?: boolean
}

export interface MediaSourceDescriptor {
  url: string
  /**
   * Alternate addresses for the same file, tried in order when `url` fails.
   *
   * Object storage often exposes a file under more than one hostname — a
   * friendly or CDN-fronted URL and a plain S3 one — and they do not fail
   * together. The retry belongs on the client because that is where the
   * failure actually happens: a server-side reachability check can pass a
   * second before the viewer's own request is refused.
   */
  fallbackUrls?: string[]
  /** `auto` sniffs from the extension / content-type. */
  type?: ContainerKind
  /** Seconds. Where to start; overridden by a stored resume position if newer. */
  startPosition?: number
  poster?: string
  subtitles?: ExternalSubtitle[]
  /** Fonts served by the backend for this episode's subtitles. */
  fonts?: FontDescriptor[]
  episode?: EpisodeContext
  /** Extra headers for media requests (signed-URL tokens, etc). */
  headers?: Record<string, string>
}

/* ── Tracks and qualities ───────────────────────────────────────────────── */

export interface QualityLevel {
  /** Engine-assigned index, stable for the lifetime of the load. */
  id: number
  height: number
  width: number
  /** bits per second */
  bitrate: number
  codec?: string
  frameRate?: number
  /** Human label: "1080p", "720p". */
  label: string
}

export interface AudioTrackInfo {
  id: string
  language: string
  label: string
  channels?: number
  codec?: string
  default?: boolean
}

export type SubtitleOrigin = 'external' | 'embedded'

export interface SubtitleTrackInfo {
  id: string
  language: string
  label: string
  format: 'ass' | 'ssa' | 'srt' | 'vtt'
  origin: SubtitleOrigin
  default?: boolean
}

/** A font carried inside the container (MKV attachment). */
export interface EmbeddedFont {
  filename: string
  mimeType: string
  data: Uint8Array
}

/* ── Network ────────────────────────────────────────────────────────────── */

export type ConnectionKind = 'wifi' | 'cellular' | 'ethernet' | 'unknown' | 'offline'

export interface NetworkSnapshot {
  online: boolean
  kind: ConnectionKind
  /** Browser-reported effective type: "slow-2g" | "2g" | "3g" | "4g". */
  effectiveType: string | null
  /** Mbps, browser estimate. Null when unavailable. */
  downlink: number | null
  /** Round-trip estimate in ms. */
  rtt: number | null
  /** User asked for reduced data usage. */
  saveData: boolean
  /** Our own measurement, bits per second. Null until we have a sample. */
  measuredBps: number | null
}

/* ── Playback state ─────────────────────────────────────────────────────── */

/**
 * Player lifecycle phase.
 *
 * Deliberately richer than a bare playing/paused split so the UI can tell
 * "waiting on the network" apart from "the user paused", and so recovery
 * has its own visible phase.
 */
export type PlayerPhase =
  /** Nothing loaded. */
  | 'idle'
  /** Fetching manifest / init segment; no frame yet. */
  | 'loading'
  /** Enough data to start, but not started. */
  | 'ready'
  | 'playing'
  | 'paused'
  /** Ran dry mid-playback and is refilling. */
  | 'buffering'
  /** User-initiated seek in flight. */
  | 'seeking'
  /** Lost the network and is retrying with backoff. */
  | 'reconnecting'
  | 'ended'
  | 'error'

export interface BufferRange {
  start: number
  end: number
}

export interface PlayerSnapshot {
  phase: PlayerPhase
  /** Seconds. */
  position: number
  duration: number
  /** Seconds of contiguous buffer ahead of the playhead. */
  bufferAhead: number
  buffered: BufferRange[]
  volume: number
  muted: boolean
  playbackRate: number
  fullscreen: boolean
  /** Controls hidden and input ignored. */
  locked: boolean
  qualities: QualityLevel[]
  /** null while in auto mode. */
  selectedQualityId: number | null
  /** What auto mode actually picked. */
  activeQualityId: number | null
  autoQuality: boolean
  audioTracks: AudioTrackInfo[]
  activeAudioTrackId: string | null
  subtitleTracks: SubtitleTrackInfo[]
  activeSubtitleTrackId: string | null
  network: NetworkSnapshot
  error: PlayerError | null
  episode: EpisodeContext | null
  /** True while the engine is downloading ahead of the playhead. */
  loading: boolean
}

/* ── Telemetry ──────────────────────────────────────────────────────────── */

export interface PlaybackStats {
  /** ms from load() to the first decoded frame. */
  startupTimeMs: number | null
  /** Times playback stalled after it had started. */
  rebufferCount: number
  /** Total ms spent stalled. */
  rebufferMs: number
  /** EWMA of measured download throughput, bits per second. */
  throughputBps: number | null
  /** Bytes pulled over the network for this session. */
  bytesLoaded: number
  /** Quality switches made by the ABR policy. */
  qualitySwitches: number
  droppedFrames: number
  /** Errors seen, including ones we recovered from. */
  errors: { code: string; message: string; at: number }[]
}
