import { Emitter } from '../core/emitter.ts'
import { ErrorCode, PlayerError, toPlayerError } from '../core/errors.ts'
import type { EngineEvents, MediaEngine } from '../core/engine.ts'
import type {
  AudioTrackInfo,
  EmbeddedFont,
  MediaSourceDescriptor,
  QualityLevel,
  SubtitleTrackInfo,
} from '../core/types.ts'
import { fetchBytes, isAbort } from '../net/fetcher.ts'
import { formatQualityLabel } from '../net/policy.ts'
import {
  ClusterStream,
  isAudioTrack,
  isSubtitleTrack,
  isVideoTrack,
  readAttachments,
  readMkvHeader,
  type MkvHeader,
  type MkvTrack,
  type RangeReader,
} from '../mkv/demuxer.ts'
import { MkvRemuxer } from '../mkv/remuxer.ts'

/** Smallest read. Keeps the first frame close when the link is slow. */
const MIN_CHUNK_BYTES = 192 * 1024
const MAX_CHUNK_BYTES = 2 * 1024 * 1024
/** Seconds of media to keep ahead of the playhead. */
const DEFAULT_TARGET_BUFFER_SEC = 30
/** Seconds of played media to keep behind, for cheap short seeks back. */
const BACK_BUFFER_SEC = 30

export interface MkvEngineOptions {
  /** Seconds of forward buffer to maintain. */
  targetBufferSec?: number
  /** Bandwidth estimate in bits/s, used to size reads. */
  estimateBps?: () => number
}

interface AppendJob {
  buffer: SourceBuffer
  data: Uint8Array
}

/**
 * Plays Matroska in the browser.
 *
 * No browser demuxes MKV for Media Source Extensions, so the container is
 * parsed here and its streams are remuxed into fragmented MP4 on the fly. That
 * detour buys more than playback: the demuxer also surfaces the subtitle tracks
 * and the fonts the release shipped with, which is what makes a properly muxed
 * MKV self-sufficient for ASS rendering.
 *
 * Reads are range requests sized to the measured bandwidth and issued only
 * when the forward buffer runs short, so a viewer who abandons an episode
 * after a minute has not paid to download the whole file.
 */
export class MkvEngine implements MediaEngine {
  readonly kind = 'mkv' as const
  readonly events = new Emitter<EngineEvents>()

  #video: HTMLVideoElement | null = null
  #mediaSource: MediaSource | null = null
  #objectUrl: string | null = null
  #videoBuffer: SourceBuffer | null = null
  #audioBuffer: SourceBuffer | null = null

  #header: MkvHeader | null = null
  #remuxer: MkvRemuxer | null = null
  #clusterStream: ClusterStream | null = null
  #trackMap = new Map<number, MkvTrack>()

  #fileSize = 0
  #url = ''
  #headers: Record<string, string> | undefined
  /** Absolute offset of the next byte to fetch. */
  #readOffset = 0
  /** Bytes left over from the last parse, to be re-fed with the next chunk. */
  #pending = new Uint8Array(0)
  #eof = false

  #appendQueue: AppendJob[] = []
  #appending = false

  #abort: AbortController | null = null
  #pumping = false
  #destroyed = false
  #loading = false

  #qualities: QualityLevel[] = []
  #audioTracks: AudioTrackInfo[] = []
  #subtitleTracks: SubtitleTrackInfo[] = []
  #selectedSubtitleId: string | null = null
  #subtitleTrackByNumber = new Map<number, SubtitleTrackInfo>()

  #options: MkvEngineOptions
  #timer: ReturnType<typeof setInterval> | null = null

  constructor(options: MkvEngineOptions = {}) {
    this.#options = options
  }

  attach(video: HTMLVideoElement): void {
    this.#video = video
  }

  async load(source: MediaSourceDescriptor): Promise<void> {
    if (!this.#video) throw new Error('attach() must be called before load()')
    if (!('MediaSource' in globalThis)) {
      throw new PlayerError({
        code: ErrorCode.MEDIA_UNSUPPORTED,
        message: 'MediaSource Extensions unavailable; MKV cannot be played',
      })
    }

    this.#url = source.url
    this.#headers = source.headers
    this.#abort = new AbortController()
    this.#setLoading(true)

    try {
      this.#fileSize = await this.#probeSize()
      const read = this.#rangeReader()
      const header = await readMkvHeader(read, this.#fileSize || null)
      this.#header = header

      const videoTrack = header.tracks.find((t) => isVideoTrack(t) && t.enabled) ?? null
      const audioTrack = header.tracks.find((t) => isAudioTrack(t) && t.enabled) ?? null
      if (!videoTrack && !audioTrack) {
        throw new PlayerError({
          code: ErrorCode.CONTAINER_ERROR,
          message: 'Matroska file contains no playable audio or video track',
        })
      }

      const remuxer = new MkvRemuxer(videoTrack, audioTrack)
      this.#remuxer = remuxer
      if (!remuxer.video && !remuxer.audio) {
        throw (
          remuxer.warnings[0] ??
          new PlayerError({
            code: ErrorCode.MEDIA_UNSUPPORTED,
            message: 'No track in this file can be remuxed for playback',
          })
        )
      }
      // An audio codec we cannot map is a degradation, not a failure: report it
      // and keep going with video only.
      for (const warning of remuxer.warnings) {
        this.events.emit('error', toPlayerError(warning, ErrorCode.MEDIA_UNSUPPORTED))
      }

      this.#buildTrackLists(header, videoTrack, audioTrack, remuxer)
      this.#clusterStream = new ClusterStream(this.#trackMap, header.timestampScale)
      this.#readOffset = header.firstClusterOffset ?? header.segmentStart

      await this.#openMediaSource(remuxer, header)

      // Fonts and the rest of the header are fetched alongside playback, never
      // before it: an anime release can carry megabytes of subtitle fonts.
      void this.#loadAttachments()

      this.events.emit('ready', undefined)
      this.#startPump()
    } catch (err) {
      this.#setLoading(false)
      throw toPlayerError(err, ErrorCode.CONTAINER_ERROR, { url: source.url })
    }
  }

  /* ── Setup ────────────────────────────────────────────────────────────── */

  async #probeSize(): Promise<number> {
    // A one-byte range reveals the total via Content-Range, and works on
    // servers that reject HEAD.
    const result = await fetchBytes(this.#url, {
      range: { start: 0, end: 0 },
      headers: this.#headers,
      signal: this.#abort?.signal,
      retries: 2,
    })
    if (result.status !== 206) {
      throw new PlayerError({
        code: ErrorCode.CONTAINER_ERROR,
        message:
          'Server does not support range requests, which MKV streaming needs. ' +
          'Enable Range support (or serve HLS) for this source.',
        context: { url: this.#url, status: result.status },
      })
    }
    return result.totalSize ?? 0
  }

  #rangeReader(): RangeReader {
    return async (start, end) => {
      const result = await fetchBytes(this.#url, {
        range: { start, end: end - 1 },
        headers: this.#headers,
        signal: this.#abort?.signal,
        onProgress: (bytes, durationMs) => this.events.emit('throughput', { bytes, durationMs }),
      })
      return result.data
    }
  }

  #buildTrackLists(
    header: MkvHeader,
    videoTrack: MkvTrack | null,
    audioTrack: MkvTrack | null,
    remuxer: MkvRemuxer,
  ): void {
    this.#trackMap.clear()
    if (videoTrack && remuxer.video) this.#trackMap.set(videoTrack.number, videoTrack)
    if (audioTrack && remuxer.audio) this.#trackMap.set(audioTrack.number, audioTrack)
    // Subtitle tracks belong in the demux map too. The demuxer skips blocks for
    // tracks it was not given, so leaving them out silently drops every
    // subtitle event; the remuxer routes only by the video and audio track
    // numbers, so the extra frames cost nothing there.
    for (const track of header.tracks) {
      if (isSubtitleTrack(track) && subtitleFormat(track.codecId)) {
        this.#trackMap.set(track.number, track)
      }
    }

    // A single-file MKV is one rendition. Reporting it as such — rather than
    // hiding the control — lets the UI show the real resolution and grey out
    // the switcher instead of pretending adaptation is available.
    if (videoTrack?.video) {
      const height = videoTrack.video.pixelHeight
      this.#qualities = [
        {
          id: 0,
          height,
          width: videoTrack.video.pixelWidth,
          bitrate: 0,
          codec: videoTrack.codecId,
          label: formatQualityLabel(height),
        },
      ]
    }

    this.#audioTracks = header.tracks.filter(isAudioTrack).map((track) => ({
      id: String(track.number),
      language: track.language,
      label: track.name ?? languageLabel(track.language),
      channels: track.audio?.channels,
      codec: track.codecId,
      default: track.default,
    }))

    this.#subtitleTracks = []
    this.#subtitleTrackByNumber.clear()
    for (const track of header.tracks.filter(isSubtitleTrack)) {
      const format = subtitleFormat(track.codecId)
      if (!format) continue
      const info: SubtitleTrackInfo = {
        id: `mkv:${track.number}`,
        language: track.language,
        label: track.name ?? languageLabel(track.language),
        format,
        origin: 'embedded',
        default: track.default,
      }
      this.#subtitleTracks.push(info)
      this.#subtitleTrackByNumber.set(track.number, info)
    }

    this.events.emit('qualitiesChanged', this.#qualities)
    this.events.emit('audioTracksChanged', this.#audioTracks)
    this.events.emit('subtitleTracksChanged', this.#subtitleTracks)
  }

  async #openMediaSource(remuxer: MkvRemuxer, header: MkvHeader): Promise<void> {
    const mediaSource = new MediaSource()
    this.#mediaSource = mediaSource
    this.#objectUrl = URL.createObjectURL(mediaSource)
    this.#video!.src = this.#objectUrl

    await new Promise<void>((resolve, reject) => {
      const onOpen = () => {
        mediaSource.removeEventListener('sourceopen', onOpen)
        try {
          if (remuxer.video) {
            this.#videoBuffer = this.#addBuffer(mediaSource, remuxer.video.mimeType, remuxer, 'video')
          }
          if (remuxer.audio) {
            this.#audioBuffer = this.#addBuffer(mediaSource, remuxer.audio.mimeType, remuxer, 'audio')
          }
          // Duration has to be set while every SourceBuffer is idle — assigning
          // it during an append throws — so it goes in before the init segments
          // are queued.
          if (header.durationNs !== null) {
            mediaSource.duration = header.durationNs / 1e9
          }
          this.#queueInitSegments(remuxer)
          resolve()
        } catch (err) {
          reject(err)
        }
      }
      mediaSource.addEventListener('sourceopen', onOpen)
    })
  }

  #addBuffer(
    mediaSource: MediaSource,
    mimeType: string,
    remuxer: MkvRemuxer,
    kind: 'video' | 'audio',
  ): SourceBuffer {
    if (!MediaSource.isTypeSupported(mimeType)) {
      throw new PlayerError({
        code: ErrorCode.MEDIA_UNSUPPORTED,
        message: `This browser cannot decode ${mimeType}`,
        context: { mimeType, kind },
      })
    }
    const buffer = mediaSource.addSourceBuffer(mimeType)
    buffer.mode = 'segments'
    // The remuxer biases composition offsets positive to keep decode times from
    // running ahead of presentation times; undoing that bias here puts the
    // media timeline back on the container's own timeline, so nothing
    // downstream — seeking, resume points, subtitles — has to compensate.
    buffer.timestampOffset = -remuxer.timelineOffsetSec
    buffer.addEventListener('updateend', () => this.#drainAppendQueue())
    buffer.addEventListener('error', () => {
      this.events.emit(
        'error',
        new PlayerError({
          code: ErrorCode.MEDIA_DECODE_ERROR,
          message: `SourceBuffer error on the ${kind} track`,
          context: { kind, mimeType },
        }),
      )
    })

    return buffer
  }

  /** Appended once both buffers exist and the duration is settled. */
  #queueInitSegments(remuxer: MkvRemuxer): void {
    if (this.#videoBuffer) {
      const init = remuxer.initSegment('video')
      if (init) this.#enqueueAppend(this.#videoBuffer, init)
    }
    if (this.#audioBuffer) {
      const init = remuxer.initSegment('audio')
      if (init) this.#enqueueAppend(this.#audioBuffer, init)
    }
  }

  async #loadAttachments(): Promise<void> {
    const header = this.#header
    if (!header) return
    try {
      const attachments = await readAttachments(
        header,
        this.#rangeReader(),
        this.#fileSize || null,
        this.#abort?.signal,
      )
      if (this.#destroyed || attachments.length === 0) return
      const fonts: EmbeddedFont[] = attachments
        .filter((file) => looksLikeFont(file.filename, file.mimeType))
        .map((file) => ({ filename: file.filename, mimeType: file.mimeType, data: file.data }))
      if (fonts.length > 0) this.events.emit('fontsFound', fonts)
    } catch (err) {
      if (isAbort(err)) return
      // Missing fonts degrade subtitle rendering; they never stop playback.
      this.events.emit(
        'error',
        new PlayerError({
          code: ErrorCode.FONT_MISSING,
          message: `Could not read embedded fonts: ${(err as Error).message}`,
          fatal: false,
          retriable: false,
        }),
      )
    }
  }

  /* ── Streaming ────────────────────────────────────────────────────────── */

  #startPump(): void {
    if (this.#timer !== null) return
    // A short poll keeps the decision to fetch tied to how much buffer is left
    // rather than to how fast the network happens to be delivering.
    this.#timer = setInterval(() => void this.#pump(), 250)
    void this.#pump()
  }

  async #pump(): Promise<void> {
    if (this.#pumping || this.#destroyed || this.#eof) return
    if (!this.#video || !this.#clusterStream || !this.#remuxer) return
    if (this.bufferAhead() >= this.#targetBufferSec()) {
      this.#setLoading(false)
      return
    }
    if (this.#appendQueue.length > 4) return

    this.#pumping = true
    this.#setLoading(true)
    try {
      const chunkSize = this.#chunkSize()
      const end = this.#fileSize > 0 ? Math.min(this.#readOffset + chunkSize, this.#fileSize) : this.#readOffset + chunkSize
      if (this.#fileSize > 0 && this.#readOffset >= this.#fileSize) {
        this.#finish()
        return
      }

      const result = await fetchBytes(this.#url, {
        range: { start: this.#readOffset, end: end - 1 },
        headers: this.#headers,
        signal: this.#abort?.signal,
        onProgress: (bytes, durationMs) => this.events.emit('throughput', { bytes, durationMs }),
      })
      if (this.#destroyed) return
      if (result.data.length === 0) {
        this.#finish()
        return
      }

      const base = this.#readOffset - this.#pending.length
      const merged = concatBytes(this.#pending, result.data)
      const parsed = this.#clusterStream.push(merged, base)

      // A run of bytes we cannot advance through means an element larger than
      // the window; grow past it rather than spinning on the same range.
      if (parsed.consumed === 0 && parsed.frames.length === 0) {
        const skip = this.#clusterStream.peekSkippable(merged, base)
        if (skip) {
          this.#readOffset = skip.skipTo
          this.#pending = new Uint8Array(0)
          return
        }
      }

      this.#pending = merged.slice(parsed.consumed)
      this.#readOffset = base + merged.length

      const output = this.#remuxer.push(parsed.frames)
      for (const segment of output.video) {
        if (this.#videoBuffer) this.#enqueueAppend(this.#videoBuffer, segment.data)
      }
      for (const segment of output.audio) {
        if (this.#audioBuffer) this.#enqueueAppend(this.#audioBuffer, segment.data)
      }

      this.#emitSubtitleBlocks(parsed.frames)

      if (this.#fileSize > 0 && this.#readOffset >= this.#fileSize) this.#finish()
    } catch (err) {
      if (isAbort(err) || this.#destroyed) return
      this.events.emit('error', toPlayerError(err, ErrorCode.NETWORK_ERROR, { url: this.#url }))
    } finally {
      this.#pumping = false
    }
  }

  #emitSubtitleBlocks(frames: { track: number; timestampNs: number; durationNs: number | null; data: Uint8Array }[]): void {
    if (!this.#selectedSubtitleId) return
    const decoder = new TextDecoder()
    for (const frame of frames) {
      const info = this.#subtitleTrackByNumber.get(frame.track)
      if (!info || info.id !== this.#selectedSubtitleId) continue
      // Matroska subtitle blocks carry one event each, in the exact field order
      // libass consumes, so the payload is forwarded verbatim along with the
      // timing the block header supplies.
      this.events.emit('subtitleBlock', {
        trackId: info.id,
        payload: decoder.decode(frame.data),
        startMs: frame.timestampNs / 1e6,
        durationMs: (frame.durationNs ?? 0) / 1e6,
      })
    }
  }

  #finish(): void {
    if (this.#eof) return
    this.#eof = true
    const tail = this.#remuxer?.flush()
    if (tail) {
      for (const segment of tail.video) {
        if (this.#videoBuffer) this.#enqueueAppend(this.#videoBuffer, segment.data)
      }
      for (const segment of tail.audio) {
        if (this.#audioBuffer) this.#enqueueAppend(this.#audioBuffer, segment.data)
      }
    }
    this.#setLoading(false)
    this.#tryEndOfStream()
  }

  #tryEndOfStream(): void {
    if (!this.#eof || this.#appendQueue.length > 0 || this.#appending) return
    const mediaSource = this.#mediaSource
    if (!mediaSource || mediaSource.readyState !== 'open') return
    try {
      mediaSource.endOfStream()
    } catch {
      // Racing a teardown; harmless.
    }
  }

  /** Read size scaled to bandwidth: roughly two seconds of transfer. */
  #chunkSize(): number {
    const bps = this.#options.estimateBps?.() ?? 2_000_000
    const bytesPerSecond = bps / 8
    return Math.round(Math.min(MAX_CHUNK_BYTES, Math.max(MIN_CHUNK_BYTES, bytesPerSecond * 2)))
  }

  #targetBufferSec(): number {
    return this.#options.targetBufferSec ?? DEFAULT_TARGET_BUFFER_SEC
  }

  /** Seconds of contiguous buffered media ahead of the playhead. */
  bufferAhead(): number {
    const video = this.#video
    if (!video) return 0
    const buffered = video.buffered
    const position = video.currentTime
    for (let i = 0; i < buffered.length; i++) {
      // A small tolerance: the playhead often sits a frame before a range start.
      if (buffered.start(i) <= position + 0.25 && buffered.end(i) > position) {
        return buffered.end(i) - position
      }
    }
    return 0
  }

  /* ── Append queue ─────────────────────────────────────────────────────── */

  #enqueueAppend(buffer: SourceBuffer, data: Uint8Array): void {
    this.#appendQueue.push({ buffer, data })
    this.#drainAppendQueue()
  }

  #drainAppendQueue(): void {
    if (this.#destroyed) return
    if (this.#appendQueue.length === 0) {
      this.#appending = false
      this.#tryEndOfStream()
      return
    }
    const mediaSource = this.#mediaSource
    if (!mediaSource || mediaSource.readyState !== 'open') return

    const job = this.#appendQueue[0]!
    if (job.buffer.updating) return

    this.#appendQueue.shift()
    this.#appending = true
    try {
      job.buffer.appendBuffer(job.data as BufferSource)
    } catch (err) {
      this.#appending = false
      if (err instanceof DOMException && err.name === 'QuotaExceededError') {
        // The buffer is full: drop what is safely behind the playhead and
        // retry the same append rather than losing the segment.
        this.#appendQueue.unshift(job)
        this.#evictBackBuffer()
        return
      }
      this.events.emit('error', toPlayerError(err, ErrorCode.MEDIA_DECODE_ERROR))
    }
  }

  #evictBackBuffer(): void {
    const video = this.#video
    if (!video) return
    const cutoff = video.currentTime - BACK_BUFFER_SEC
    if (cutoff <= 0) return
    for (const buffer of [this.#videoBuffer, this.#audioBuffer]) {
      if (!buffer || buffer.updating) continue
      try {
        buffer.remove(0, cutoff)
      } catch {
        // Removal is best effort; a failure just means we retry later.
      }
    }
  }

  /* ── MediaEngine surface ──────────────────────────────────────────────── */

  getQualities(): QualityLevel[] {
    return this.#qualities
  }

  getActiveQualityId(): number | null {
    return this.#qualities.length > 0 ? 0 : null
  }

  /** A single-file MKV has one rendition; there is nothing to switch to. */
  setQuality(): void {}

  getAudioTracks(): AudioTrackInfo[] {
    return this.#audioTracks
  }

  /**
   * Switching audio track means re-remuxing from the new track, so it is only
   * offered when the file actually has more than one.
   */
  setAudioTrack(id: string): void {
    if (this.#audioTracks.length <= 1) return
    const target = this.#audioTracks.find((track) => track.id === id)
    if (!target) return
    this.events.emit(
      'error',
      new PlayerError({
        code: ErrorCode.MEDIA_UNSUPPORTED,
        message: 'Switching audio track mid-file is not implemented for Matroska sources',
        userMessage: 'Bu dosyada ses parçası değiştirilemiyor.',
        fatal: false,
        retriable: false,
      }),
    )
  }

  getSubtitleTracks(): SubtitleTrackInfo[] {
    return this.#subtitleTracks
  }

  selectSubtitleTrack(id: string | null): void {
    this.#selectedSubtitleId = id
    if (id === null) return
    // The styles live in CodecPrivate and are available immediately, long
    // before the events themselves arrive in the cluster stream. Emitting them
    // now lets font resolution start while the first frames are still loading.
    const header = this.getSubtitleHeader(id)
    const info = this.#subtitleTracks.find((track) => track.id === id)
    if (header && info) {
      this.events.emit('subtitleHeader', { trackId: id, header, format: info.format })
    }
  }

  /** The ASS header for an embedded track, available as soon as it is parsed. */
  getSubtitleHeader(trackId: string): string | null {
    const number = Number(trackId.replace(/^mkv:/, ''))
    const track = this.#header?.tracks.find((t) => t.number === number)
    if (!track?.codecPrivate) return null
    return new TextDecoder().decode(track.codecPrivate)
  }

  /** Seconds into the file; null when we have no seek index. */
  seekTo(positionSec: number): number | null {
    const header = this.#header
    if (!header || !this.#clusterStream || !this.#remuxer) return null

    const targetNs = positionSec * 1e9
    const cue = findCueAtOrBefore(header.cues, targetNs)
    if (!cue) return null

    this.#abort?.abort()
    this.#abort = new AbortController()
    this.#clusterStream.reset()
    this.#remuxer.reset()
    this.#pending = new Uint8Array(0)
    this.#appendQueue = []
    this.#readOffset = cue.clusterPosition
    this.#eof = false
    void this.#pump()
    return cue.timeNs / 1e9
  }

  /** True when the file carried a seek index. */
  get seekable(): boolean {
    return (this.#header?.cues.length ?? 0) > 0
  }

  async recover(): Promise<void> {
    if (this.#destroyed) return
    this.#abort?.abort()
    this.#abort = new AbortController()
    // The read offset already points past everything appended, so resuming is
    // simply a matter of letting the pump run again.
    this.#eof = false
    await this.#pump()
    this.events.emit('recovered', undefined)
  }

  destroy(): void {
    this.#destroyed = true
    this.#abort?.abort()
    if (this.#timer !== null) {
      clearInterval(this.#timer)
      this.#timer = null
    }
    this.#appendQueue = []
    try {
      if (this.#mediaSource?.readyState === 'open') this.#mediaSource.endOfStream()
    } catch {
      // Already torn down.
    }
    if (this.#objectUrl) {
      URL.revokeObjectURL(this.#objectUrl)
      this.#objectUrl = null
    }
    if (this.#video) this.#video.removeAttribute('src')
    this.events.removeAll()
  }

  #setLoading(value: boolean): void {
    if (this.#loading === value) return
    this.#loading = value
    this.events.emit('loadingChanged', value)
  }
}

/* ── Helpers ────────────────────────────────────────────────────────────── */

function findCueAtOrBefore(
  cues: { timeNs: number; clusterPosition: number }[],
  targetNs: number,
): { timeNs: number; clusterPosition: number } | null {
  if (cues.length === 0) return null
  let low = 0
  let high = cues.length - 1
  let best: { timeNs: number; clusterPosition: number } | null = null
  while (low <= high) {
    const mid = (low + high) >> 1
    const cue = cues[mid]!
    if (cue.timeNs <= targetNs) {
      best = cue
      low = mid + 1
    } else {
      high = mid - 1
    }
  }
  // Seeking before the first cue lands on the first cue rather than failing.
  return best ?? cues[0]!
}

function subtitleFormat(codecId: string): 'ass' | 'ssa' | 'srt' | 'vtt' | null {
  switch (codecId) {
    case 'S_TEXT/ASS':
      return 'ass'
    case 'S_TEXT/SSA':
      return 'ssa'
    case 'S_TEXT/UTF8':
      return 'srt'
    case 'S_TEXT/WEBVTT':
      return 'vtt'
    default:
      // Image subtitles (VobSub, PGS) need a different rendering path.
      return null
  }
}

const FONT_EXTENSIONS = /\.(ttf|otf|ttc|otc|woff2?|pfb)$/i

function looksLikeFont(filename: string, mimeType: string): boolean {
  if (FONT_EXTENSIONS.test(filename)) return true
  // Muxers are inconsistent about font mime types; several still use the
  // pre-standard `application/x-*` spellings or fall back to octet-stream.
  return /font|sfnt|truetype|opentype/i.test(mimeType)
}

const LANGUAGE_LABELS: Record<string, string> = {
  tur: 'Türkçe',
  tr: 'Türkçe',
  eng: 'İngilizce',
  en: 'İngilizce',
  jpn: 'Japonca',
  ja: 'Japonca',
  und: 'Bilinmiyor',
}

function languageLabel(language: string): string {
  return LANGUAGE_LABELS[language.toLowerCase()] ?? language.toUpperCase()
}

function concatBytes(a: Uint8Array, b: Uint8Array): Uint8Array {
  if (a.length === 0) return b
  const out = new Uint8Array(a.length + b.length)
  out.set(a)
  out.set(b, a.length)
  return out
}
