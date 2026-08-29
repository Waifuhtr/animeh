import { ErrorCode, PlayerError } from '../core/errors.ts'
import { EbmlReader, NEED_MORE, UNKNOWN_SIZE, readVintValue } from './ebml.ts'
import { ID, MASTER_ELEMENTS, TrackType } from './ids.ts'

/* ── Parsed structures ──────────────────────────────────────────────────── */

export interface MkvVideoInfo {
  pixelWidth: number
  pixelHeight: number
  displayWidth: number | null
  displayHeight: number | null
}

export interface MkvAudioInfo {
  samplingFrequency: number
  channels: number
  bitDepth: number | null
}

export interface MkvTrack {
  number: number
  /** Opaque 64-bit identifier; never used for arithmetic. */
  uid: bigint
  type: number
  codecId: string
  codecPrivate: Uint8Array | null
  language: string
  name: string | null
  default: boolean
  forced: boolean
  enabled: boolean
  /** Nominal frame duration in nanoseconds, when the muxer recorded one. */
  defaultDurationNs: number | null
  codecDelayNs: number
  seekPreRollNs: number
  video: MkvVideoInfo | null
  audio: MkvAudioInfo | null
}

export interface MkvAttachment {
  uid: bigint
  filename: string
  mimeType: string
  data: Uint8Array
}

export interface MkvCuePoint {
  /** Nanoseconds. */
  timeNs: number
  track: number
  /** Absolute file offset of the Cluster element. */
  clusterPosition: number
}

export interface MkvHeader {
  docType: string
  /** Absolute offset of the Segment element's payload. */
  segmentStart: number
  /** Segment payload length, or UNKNOWN_SIZE. */
  segmentSize: number
  /** Nanoseconds per timestamp unit. Default 1_000_000 (1ms). */
  timestampScale: number
  /** Total duration in nanoseconds, when Info carried one. */
  durationNs: number | null
  title: string | null
  tracks: MkvTrack[]
  cues: MkvCuePoint[]
  /** Element id → absolute file offset, from SeekHead. */
  seekIndex: Map<number, number>
  /** Absolute offset of the first Cluster, when we saw one. */
  firstClusterOffset: number | null
}

export interface MkvFrame {
  track: number
  /** Nanoseconds, absolute within the segment. */
  timestampNs: number
  /** Nanoseconds, when the block carried a BlockDuration or the track a default. */
  durationNs: number | null
  keyframe: boolean
  invisible: boolean
  data: Uint8Array
}

/** Fetches an absolute byte range. `end` is exclusive. */
export type RangeReader = (start: number, end: number) => Promise<Uint8Array>

const HEADER_PROBE_BYTES = 256 * 1024
const HEADER_MAX_BYTES = 8 * 1024 * 1024

/* ── Header parsing ─────────────────────────────────────────────────────── */

/**
 * Read everything needed to set up playback: doc type, timestamp scale, track
 * list and the seek index.
 *
 * Attachments are deliberately *not* fetched here — anime releases routinely
 * carry several megabytes of subtitle fonts, and blocking the first frame on
 * that download is exactly the stall we are trying to avoid. Call
 * `readAttachments` separately, in parallel with playback.
 */
export async function readMkvHeader(read: RangeReader, fileSize: number | null): Promise<MkvHeader> {
  let windowEnd = Math.min(HEADER_PROBE_BYTES, fileSize ?? HEADER_PROBE_BYTES)
  let bytes = await read(0, windowEnd)

  // Grow the probe until Tracks appears or we hit the ceiling. A SeekHead
  // usually saves us from ever looping, but muxers are not obliged to write one.
  for (;;) {
    const parsed = parseHeaderWindow(bytes)
    if (parsed.tracks.length > 0) {
      await fillMissingSections(parsed, read, fileSize)
      return parsed
    }
    // No Tracks yet. If SeekHead told us where it lives, jump straight there.
    const tracksOffset = parsed.seekIndex.get(ID.Tracks)
    if (tracksOffset !== undefined && tracksOffset >= bytes.length) {
      const chunk = await read(tracksOffset, Math.min(tracksOffset + 4 * 1024 * 1024, fileSize ?? tracksOffset + 4 * 1024 * 1024))
      parseTracksInto(parsed, new EbmlReader(chunk, tracksOffset))
      if (parsed.tracks.length > 0) {
        await fillMissingSections(parsed, read, fileSize)
        return parsed
      }
    }
    if (windowEnd >= (fileSize ?? HEADER_MAX_BYTES) || windowEnd >= HEADER_MAX_BYTES) {
      throw new PlayerError({
        code: ErrorCode.CONTAINER_ERROR,
        message: 'No Tracks element found in the first 8 MB of the Matroska file',
        context: { windowEnd },
      })
    }
    windowEnd = Math.min(windowEnd * 4, fileSize ?? HEADER_MAX_BYTES, HEADER_MAX_BYTES)
    bytes = await read(0, windowEnd)
  }
}

/** Pull in Cues if they live past the probe window (the usual layout). */
async function fillMissingSections(
  header: MkvHeader,
  read: RangeReader,
  fileSize: number | null,
): Promise<void> {
  await fillCues(header, read, fileSize)

  // The header walk only reaches a Cluster when the probe window happens to
  // extend that far; a file with megabytes of attached fonts pushes the first
  // cluster well past it. Cues and SeekHead both point at clusters, so use them.
  if (header.firstClusterOffset === null) {
    if (header.cues.length > 0) {
      header.firstClusterOffset = header.cues[0]!.clusterPosition
    } else {
      const seeked = header.seekIndex.get(ID.Cluster)
      if (seeked !== undefined) header.firstClusterOffset = seeked
    }
  }
}

async function fillCues(
  header: MkvHeader,
  read: RangeReader,
  fileSize: number | null,
): Promise<void> {
  if (header.cues.length > 0) return
  const cuesOffset = header.seekIndex.get(ID.Cues)
  if (cuesOffset === undefined) return

  // Cues are compact (a few tens of KB even for a long episode); one grab is
  // enough, and having them is what makes seeking cheap.
  const end = Math.min(cuesOffset + 4 * 1024 * 1024, fileSize ?? cuesOffset + 4 * 1024 * 1024)
  try {
    const chunk = await read(cuesOffset, end)
    const reader = new EbmlReader(chunk, cuesOffset)
    const element = reader.readHeader()
    if (element !== NEED_MORE && element.id === ID.Cues) {
      header.cues = parseCues(reader, element.size, header.segmentStart, header.timestampScale)
    }
  } catch {
    // Seeking degrades to "keyframe scan from the last known cluster"; not
    // worth failing the whole load over.
  }
}

/** Parse as much of the header region as the given window contains. */
export function parseHeaderWindow(bytes: Uint8Array): MkvHeader {
  const reader = new EbmlReader(bytes, 0)
  const header: MkvHeader = {
    docType: '',
    segmentStart: 0,
    segmentSize: UNKNOWN_SIZE,
    timestampScale: 1_000_000,
    durationNs: null,
    title: null,
    tracks: [],
    cues: [],
    seekIndex: new Map(),
    firstClusterOffset: null,
  }

  const ebml = reader.readHeader()
  if (ebml === NEED_MORE || ebml.id !== ID.EBML) {
    throw new PlayerError({
      code: ErrorCode.CONTAINER_ERROR,
      message: 'Not a Matroska file: missing EBML header',
    })
  }
  parseEbmlHeaderInto(header, new EbmlReader(bytes.subarray(reader.offset, reader.offset + ebml.size), reader.offset))
  reader.skip(ebml.size)

  if (header.docType && header.docType !== 'matroska' && header.docType !== 'webm') {
    throw new PlayerError({
      code: ErrorCode.CONTAINER_ERROR,
      message: `Unsupported EBML DocType "${header.docType}"`,
      context: { docType: header.docType },
    })
  }

  const segment = reader.readHeader()
  if (segment === NEED_MORE || segment.id !== ID.Segment) {
    throw new PlayerError({ code: ErrorCode.CONTAINER_ERROR, message: 'Missing Segment element' })
  }
  header.segmentStart = reader.position
  header.segmentSize = segment.size

  // Walk the Segment's direct children as far as the window allows.
  const limit =
    segment.size === UNKNOWN_SIZE ? bytes.length : Math.min(bytes.length, reader.offset + segment.size)

  while (reader.offset < limit) {
    const start = reader.position
    const element = reader.readHeader()
    if (element === NEED_MORE) break
    const payloadStart = reader.offset
    const size = element.size === UNKNOWN_SIZE ? limit - payloadStart : element.size
    const available = Math.min(size, bytes.length - payloadStart)
    const complete = available === size

    switch (element.id) {
      case ID.SeekHead:
        if (complete) parseSeekHeadInto(header, new EbmlReader(bytes.subarray(payloadStart, payloadStart + size), payloadStart))
        break
      case ID.Info:
        if (complete) parseInfoInto(header, new EbmlReader(bytes.subarray(payloadStart, payloadStart + size), payloadStart))
        break
      case ID.Tracks:
        if (complete) {
          const sub = new EbmlReader(bytes.subarray(payloadStart, payloadStart + size), payloadStart)
          header.tracks = parseTrackEntries(sub, size)
        }
        break
      case ID.Cues:
        if (complete) {
          const sub = new EbmlReader(bytes.subarray(payloadStart, payloadStart + size), payloadStart)
          header.cues = parseCues(sub, size, header.segmentStart, header.timestampScale)
        }
        break
      case ID.Cluster:
        // First cluster reached: the header region is over.
        header.firstClusterOffset = start
        return header
    }

    if (element.size === UNKNOWN_SIZE) break
    reader.skip(element.size)
  }

  return header
}

function parseEbmlHeaderInto(header: MkvHeader, reader: EbmlReader): void {
  while (reader.remaining > 0) {
    const element = reader.readHeader()
    if (element === NEED_MORE || element.size === UNKNOWN_SIZE) break
    if (element.id === ID.DocType) header.docType = reader.readString(element.size)
    else reader.skip(element.size)
  }
}

function parseSeekHeadInto(header: MkvHeader, reader: EbmlReader): void {
  while (reader.remaining > 0) {
    const element = reader.readHeader()
    if (element === NEED_MORE || element.size === UNKNOWN_SIZE) break
    if (element.id !== ID.Seek) {
      reader.skip(element.size)
      continue
    }
    const end = reader.offset + element.size
    let seekId: number | null = null
    let seekPosition: number | null = null
    while (reader.offset < end) {
      const child = reader.readHeader()
      if (child === NEED_MORE || child.size === UNKNOWN_SIZE) break
      if (child.id === ID.SeekID) {
        let value = 0
        for (let i = 0; i < child.size; i++) value = value * 256 + reader.bytes[reader.offset + i]!
        seekId = value
        reader.skip(child.size)
      } else if (child.id === ID.SeekPosition) {
        seekPosition = reader.readUint(child.size)
      } else {
        reader.skip(child.size)
      }
    }
    // SeekPosition is relative to the Segment payload start.
    if (seekId !== null && seekPosition !== null) {
      header.seekIndex.set(seekId, header.segmentStart + seekPosition)
    }
    reader.offset = end
  }
}

function parseInfoInto(header: MkvHeader, reader: EbmlReader): void {
  let durationTicks: number | null = null
  while (reader.remaining > 0) {
    const element = reader.readHeader()
    if (element === NEED_MORE || element.size === UNKNOWN_SIZE) break
    switch (element.id) {
      case ID.TimestampScale:
        header.timestampScale = reader.readUint(element.size)
        break
      case ID.Duration:
        durationTicks = reader.readFloat(element.size)
        break
      case ID.Title:
        header.title = reader.readString(element.size)
        break
      default:
        reader.skip(element.size)
    }
  }
  if (durationTicks !== null) header.durationNs = durationTicks * header.timestampScale
}

function parseTracksInto(header: MkvHeader, reader: EbmlReader): void {
  const element = reader.readHeader()
  if (element === NEED_MORE || element.id !== ID.Tracks) return
  const size = element.size === UNKNOWN_SIZE ? reader.remaining : element.size
  header.tracks = parseTrackEntries(reader, size)
}

function parseTrackEntries(reader: EbmlReader, size: number): MkvTrack[] {
  const tracks: MkvTrack[] = []
  const end = reader.offset + size
  while (reader.offset < end) {
    const element = reader.readHeader()
    if (element === NEED_MORE || element.size === UNKNOWN_SIZE) break
    if (element.id === ID.TrackEntry) {
      const entryEnd = reader.offset + element.size
      const track = parseTrackEntry(reader, element.size)
      if (track) tracks.push(track)
      reader.offset = entryEnd
    } else {
      reader.skip(element.size)
    }
  }
  return tracks
}

function parseTrackEntry(reader: EbmlReader, size: number): MkvTrack | null {
  const end = reader.offset + size
  const track: MkvTrack = {
    number: -1,
    uid: 0n,
    type: -1,
    codecId: '',
    codecPrivate: null,
    language: 'und',
    name: null,
    default: false,
    forced: false,
    enabled: true,
    defaultDurationNs: null,
    codecDelayNs: 0,
    seekPreRollNs: 0,
    video: null,
    audio: null,
  }

  while (reader.offset < end) {
    const element = reader.readHeader()
    if (element === NEED_MORE || element.size === UNKNOWN_SIZE) break
    const next = reader.offset + element.size
    switch (element.id) {
      case ID.TrackNumber:
        track.number = reader.readUint(element.size)
        break
      case ID.TrackUID:
        track.uid = reader.readBigUint(element.size)
        break
      case ID.TrackType:
        track.type = reader.readUint(element.size)
        break
      case ID.CodecID:
        track.codecId = reader.readString(element.size)
        break
      case ID.CodecPrivate:
        track.codecPrivate = reader.readBytes(element.size)
        break
      case ID.Language:
        track.language = reader.readString(element.size)
        break
      // BCP-47 supersedes the legacy ISO-639-2 field when both are present.
      case ID.LanguageBCP47:
        track.language = reader.readString(element.size)
        break
      case ID.Name:
        track.name = reader.readString(element.size)
        break
      case ID.FlagDefault:
        track.default = reader.readUint(element.size) === 1
        break
      case ID.FlagForced:
        track.forced = reader.readUint(element.size) === 1
        break
      case ID.FlagEnabled:
        track.enabled = reader.readUint(element.size) === 1
        break
      case ID.DefaultDuration:
        track.defaultDurationNs = reader.readUint(element.size)
        break
      case ID.CodecDelay:
        track.codecDelayNs = reader.readUint(element.size)
        break
      case ID.SeekPreRoll:
        track.seekPreRollNs = reader.readUint(element.size)
        break
      case ID.Video:
        track.video = parseVideoInfo(reader, element.size)
        break
      case ID.Audio:
        track.audio = parseAudioInfo(reader, element.size)
        break
      default:
        reader.skip(element.size)
    }
    reader.offset = next
  }

  return track.number >= 0 && track.codecId ? track : null
}

function parseVideoInfo(reader: EbmlReader, size: number): MkvVideoInfo {
  const end = reader.offset + size
  const info: MkvVideoInfo = { pixelWidth: 0, pixelHeight: 0, displayWidth: null, displayHeight: null }
  while (reader.offset < end) {
    const element = reader.readHeader()
    if (element === NEED_MORE || element.size === UNKNOWN_SIZE) break
    const next = reader.offset + element.size
    switch (element.id) {
      case ID.PixelWidth:
        info.pixelWidth = reader.readUint(element.size)
        break
      case ID.PixelHeight:
        info.pixelHeight = reader.readUint(element.size)
        break
      case ID.DisplayWidth:
        info.displayWidth = reader.readUint(element.size)
        break
      case ID.DisplayHeight:
        info.displayHeight = reader.readUint(element.size)
        break
      default:
        reader.skip(element.size)
    }
    reader.offset = next
  }
  return info
}

function parseAudioInfo(reader: EbmlReader, size: number): MkvAudioInfo {
  const end = reader.offset + size
  const info: MkvAudioInfo = { samplingFrequency: 8000, channels: 1, bitDepth: null }
  while (reader.offset < end) {
    const element = reader.readHeader()
    if (element === NEED_MORE || element.size === UNKNOWN_SIZE) break
    const next = reader.offset + element.size
    switch (element.id) {
      case ID.SamplingFrequency:
        info.samplingFrequency = reader.readFloat(element.size)
        break
      // SBR/HE-AAC signals the true output rate here; it is what the decoder emits.
      case ID.OutputSamplingFrequency:
        info.samplingFrequency = reader.readFloat(element.size)
        break
      case ID.Channels:
        info.channels = reader.readUint(element.size)
        break
      case ID.BitDepth:
        info.bitDepth = reader.readUint(element.size)
        break
      default:
        reader.skip(element.size)
    }
    reader.offset = next
  }
  return info
}

function parseCues(
  reader: EbmlReader,
  size: number,
  segmentStart: number,
  timestampScale: number,
): MkvCuePoint[] {
  const cues: MkvCuePoint[] = []
  const end = reader.offset + size
  while (reader.offset < end) {
    const element = reader.readHeader()
    if (element === NEED_MORE || element.size === UNKNOWN_SIZE) break
    const next = reader.offset + element.size
    if (element.id === ID.CuePoint) {
      const pointEnd = next
      let timeTicks: number | null = null
      while (reader.offset < pointEnd) {
        const child = reader.readHeader()
        if (child === NEED_MORE || child.size === UNKNOWN_SIZE) break
        const childEnd = reader.offset + child.size
        if (child.id === ID.CueTime) {
          timeTicks = reader.readUint(child.size)
        } else if (child.id === ID.CueTrackPositions) {
          let track = -1
          let clusterPosition: number | null = null
          while (reader.offset < childEnd) {
            const leaf = reader.readHeader()
            if (leaf === NEED_MORE || leaf.size === UNKNOWN_SIZE) break
            const leafEnd = reader.offset + leaf.size
            if (leaf.id === ID.CueTrack) track = reader.readUint(leaf.size)
            else if (leaf.id === ID.CueClusterPosition) clusterPosition = reader.readUint(leaf.size)
            reader.offset = leafEnd
          }
          if (timeTicks !== null && clusterPosition !== null) {
            cues.push({
              timeNs: timeTicks * timestampScale,
              track,
              clusterPosition: segmentStart + clusterPosition,
            })
          }
        }
        reader.offset = childEnd
      }
    }
    reader.offset = next
  }
  cues.sort((a, b) => a.timeNs - b.timeNs)
  return cues
}

/* ── Attachments ────────────────────────────────────────────────────────── */

/**
 * Fetch and parse the Attachments element.
 *
 * This is where subtitle fonts live in a properly muxed anime release, which
 * makes an MKV self-sufficient: the ASS script and every font it names travel
 * together, so the font registry never has to go looking.
 */
export async function readAttachments(
  header: MkvHeader,
  read: RangeReader,
  fileSize: number | null,
  signal?: AbortSignal,
): Promise<MkvAttachment[]> {
  const offset = header.seekIndex.get(ID.Attachments)
  if (offset === undefined) return []

  // Read the element header first so we fetch exactly the payload and no more.
  const probe = await read(offset, Math.min(offset + 64, fileSize ?? offset + 64))
  if (signal?.aborted) return []
  const probeReader = new EbmlReader(probe, offset)
  const element = probeReader.readHeader()
  if (element === NEED_MORE || element.id !== ID.Attachments || element.size === UNKNOWN_SIZE) {
    return []
  }

  const payloadStart = offset + element.headerSize
  const payloadEnd = payloadStart + element.size
  if (fileSize !== null && payloadEnd > fileSize) return []

  const bytes = await read(payloadStart, payloadEnd)
  if (signal?.aborted) return []
  return parseAttachments(new EbmlReader(bytes, payloadStart), element.size)
}

export function parseAttachments(reader: EbmlReader, size: number): MkvAttachment[] {
  const attachments: MkvAttachment[] = []
  const end = reader.offset + size
  while (reader.offset < end) {
    const element = reader.readHeader()
    if (element === NEED_MORE || element.size === UNKNOWN_SIZE) break
    const next = reader.offset + element.size
    if (element.id === ID.AttachedFile) {
      let filename = ''
      let mimeType = ''
      let data: Uint8Array | null = null
      let uid = 0n
      while (reader.offset < next) {
        const child = reader.readHeader()
        if (child === NEED_MORE || child.size === UNKNOWN_SIZE) break
        const childEnd = reader.offset + child.size
        switch (child.id) {
          case ID.FileName:
            filename = reader.readString(child.size)
            break
          case ID.FileMimeType:
            mimeType = reader.readString(child.size)
            break
          case ID.FileData:
            data = reader.readBytes(child.size)
            break
          case ID.FileUID:
            uid = reader.readBigUint(child.size)
            break
        }
        reader.offset = childEnd
      }
      if (data) attachments.push({ uid, filename, mimeType, data })
    }
    reader.offset = next
  }
  return attachments
}

/* ── Cluster / block parsing ────────────────────────────────────────────── */

export interface ClusterPushResult {
  frames: MkvFrame[]
  /** Bytes consumed from the start of the supplied window. */
  consumed: number
  /** True when the window ended mid-element and more bytes would help. */
  needMore: boolean
}

/**
 * Incremental cluster reader.
 *
 * Deliberately does *not* require a whole Cluster to be in memory. Muxers write
 * clusters of five seconds or more, which at 1080p is several megabytes — making
 * that the unit of buffering would mean downloading multiple MB before the first
 * frame decodes, which is precisely the startup stall this player exists to
 * avoid. Instead the reader tracks how far into the current cluster it is and
 * emits blocks as soon as each one lands.
 *
 * Usage: keep a byte buffer, append each fetched range, call `push`, then drop
 * `consumed` bytes from the front and repeat.
 */
export class ClusterStream {
  #tracks: Map<number, MkvTrack>
  #timestampScale: number
  /** Absolute offset where the current cluster's payload ends; null when outside one. */
  #clusterEnd: number | null = null
  /** True while inside a cluster whose size was declared unknown. */
  #clusterUnknownSize = false
  #clusterTicks = 0

  constructor(tracks: Map<number, MkvTrack>, timestampScale: number) {
    this.#tracks = tracks
    this.#timestampScale = timestampScale
  }

  /** Forget cluster state. Call after seeking to an unrelated offset. */
  reset(): void {
    this.#clusterEnd = null
    this.#clusterUnknownSize = false
    this.#clusterTicks = 0
  }

  /** True while positioned inside a cluster's payload. */
  get insideCluster(): boolean {
    return this.#clusterEnd !== null || this.#clusterUnknownSize
  }

  /**
   * @param bytes a window of the file
   * @param base absolute file offset `bytes[0]` corresponds to
   */
  push(bytes: Uint8Array, base: number): ClusterPushResult {
    const reader = new EbmlReader(bytes, base)
    const frames: MkvFrame[] = []
    let consumed = 0
    let needMore = false

    while (reader.remaining > 0) {
      // Inside a cluster: read block-level children until it ends.
      if (this.insideCluster) {
        const outcome = this.#readClusterChildren(reader, frames)
        consumed = reader.offset
        if (outcome === 'need-more') {
          needMore = true
          break
        }
        continue
      }

      const elementStart = reader.offset
      const element = reader.readHeader()
      if (element === NEED_MORE) {
        needMore = true
        reader.offset = elementStart
        break
      }

      if (element.id === ID.Cluster) {
        if (element.size === UNKNOWN_SIZE) {
          this.#clusterUnknownSize = true
          this.#clusterEnd = null
        } else {
          this.#clusterEnd = reader.position + element.size
          this.#clusterUnknownSize = false
        }
        this.#clusterTicks = 0
        consumed = reader.offset
        continue
      }

      // Not a cluster: Cues, Tags, Attachments and friends can be interleaved
      // or trail the clusters. Skip them whole when we can see the end.
      if (element.size === UNKNOWN_SIZE) {
        needMore = true
        reader.offset = elementStart
        break
      }
      if (reader.offset + element.size > bytes.length) {
        // We know its length but not its content; tell the caller how far to
        // jump rather than demanding the bytes be fetched.
        reader.offset = elementStart
        needMore = true
        break
      }
      reader.skip(element.size)
      consumed = reader.offset
    }

    return { frames, consumed, needMore }
  }

  /**
   * How many bytes to skip to get past an element the caller cannot use.
   * Lets a driver jump over a multi-megabyte Attachments element that sits
   * between clusters instead of downloading it twice.
   */
  peekSkippable(bytes: Uint8Array, base: number): { skipTo: number } | null {
    if (this.insideCluster) return null
    const reader = new EbmlReader(bytes, base)
    const element = reader.readHeader()
    if (element === NEED_MORE || element.size === UNKNOWN_SIZE) return null
    if (element.id === ID.Cluster) return null
    return { skipTo: reader.position + element.size }
  }

  /** Returns 'need-more' when the window runs out mid-element. */
  #readClusterChildren(reader: EbmlReader, out: MkvFrame[]): 'need-more' | 'continue' {
    for (;;) {
      // A sized cluster ends at a known offset.
      if (this.#clusterEnd !== null && reader.position >= this.#clusterEnd) {
        this.#clusterEnd = null
        return 'continue'
      }
      if (reader.remaining === 0) return 'need-more'

      const elementStart = reader.offset
      const element = reader.readHeader()
      if (element === NEED_MORE) {
        reader.offset = elementStart
        return 'need-more'
      }

      // An unknown-size cluster is terminated by the next top-level element.
      if (this.#clusterUnknownSize && isTopLevelId(element.id)) {
        reader.offset = elementStart
        this.#clusterUnknownSize = false
        this.#clusterEnd = null
        return 'continue'
      }

      if (element.size === UNKNOWN_SIZE) {
        reader.offset = elementStart
        return 'need-more'
      }

      const next = reader.offset + element.size
      if (next > reader.bytes.length) {
        reader.offset = elementStart
        return 'need-more'
      }

      switch (element.id) {
        case ID.Timestamp:
          this.#clusterTicks = reader.readUint(element.size)
          break

        case ID.SimpleBlock:
          parseBlock(
            reader.bytes.subarray(reader.offset, next),
            this.#clusterTicks,
            this.#timestampScale,
            this.#tracks,
            false,
            null,
            out,
          )
          break

        case ID.BlockGroup:
          parseBlockGroup(
            reader,
            next,
            this.#clusterTicks,
            this.#timestampScale,
            this.#tracks,
            out,
          )
          break
      }

      reader.offset = next
    }
  }
}

/** Top-level Segment children, used to detect the end of an unknown-size cluster. */
function isTopLevelId(id: number): boolean {
  return (
    id === ID.Cluster ||
    id === ID.Cues ||
    id === ID.Tracks ||
    id === ID.Info ||
    id === ID.SeekHead ||
    id === ID.Attachments ||
    id === ID.Chapters ||
    id === ID.Tags
  )
}

function parseBlockGroup(
  reader: EbmlReader,
  groupEnd: number,
  clusterTicks: number,
  timestampScale: number,
  tracks: Map<number, MkvTrack>,
  out: MkvFrame[],
): void {
  let blockBytes: Uint8Array | null = null
  let durationTicks: number | null = null
  let hasReference = false

  while (reader.offset < groupEnd) {
    const child = reader.readHeader()
    if (child === NEED_MORE || child.size === UNKNOWN_SIZE) break
    const childEnd = reader.offset + child.size
    if (childEnd > groupEnd) break
    if (child.id === ID.Block) blockBytes = reader.bytes.subarray(reader.offset, childEnd)
    else if (child.id === ID.BlockDuration) durationTicks = reader.readUint(child.size)
    else if (child.id === ID.ReferenceBlock) hasReference = true
    reader.offset = childEnd
  }

  if (blockBytes) {
    parseBlock(
      blockBytes,
      clusterTicks,
      timestampScale,
      tracks,
      // In a BlockGroup the flags byte has no keyframe bit; a block with no
      // ReferenceBlock references nothing, which is what makes it a keyframe.
      !hasReference,
      durationTicks === null ? null : durationTicks * timestampScale,
      out,
    )
  }
}

/**
 * One-shot cluster parse over a whole window.
 * Convenience wrapper around `ClusterStream` for tests and offline tooling.
 */
export function parseClusters(
  bytes: Uint8Array,
  base: number,
  timestampScale: number,
  tracks: Map<number, MkvTrack>,
): ClusterPushResult {
  return new ClusterStream(tracks, timestampScale).push(bytes, base)
}

/**
 * Decode one Block/SimpleBlock into frames, expanding lacing.
 *
 * @param forcedKeyframe set by BlockGroup, where the flags byte has no keyframe
 *   bit and ReferenceBlock is the signal instead. SimpleBlock passes false and
 *   relies on its own flag.
 */
function parseBlock(
  block: Uint8Array,
  clusterTicks: number,
  timestampScale: number,
  tracks: Map<number, MkvTrack>,
  forcedKeyframe: boolean,
  durationNs: number | null,
  out: MkvFrame[],
): void {
  const trackVint = readVintValue(block, 0)
  if (!trackVint) return
  let offset = trackVint.length
  if (offset + 3 > block.length) return

  const track = tracks.get(trackVint.value)
  // Not a track we are rendering — skip without allocating.
  if (!track) return

  const view = new DataView(block.buffer, block.byteOffset, block.byteLength)
  const relativeTicks = view.getInt16(offset)
  offset += 2
  const flags = block[offset]!
  offset += 1

  const keyframe = forcedKeyframe || (flags & 0x80) !== 0
  const invisible = (flags & 0x08) !== 0
  const lacing = (flags & 0x06) >> 1

  const timestampNs = (clusterTicks + relativeTicks) * timestampScale - track.codecDelayNs
  const sizes = readLacedSizes(block, offset, lacing)
  if (!sizes) return
  offset = sizes.dataOffset

  const frameDuration =
    durationNs ?? (track.defaultDurationNs !== null ? track.defaultDurationNs : null)

  for (let i = 0; i < sizes.sizes.length; i++) {
    const length = sizes.sizes[i]!
    if (offset + length > block.length) break
    out.push({
      track: track.number,
      // Laced frames share a block timestamp; space them by the nominal
      // frame duration so the remuxer does not emit zero-length samples.
      timestampNs: timestampNs + (frameDuration !== null ? frameDuration * i : 0),
      durationNs: frameDuration,
      keyframe,
      invisible,
      data: block.subarray(offset, offset + length),
    })
    offset += length
  }
}

interface LacedSizes {
  sizes: number[]
  dataOffset: number
}

function readLacedSizes(block: Uint8Array, offset: number, lacing: number): LacedSizes | null {
  if (lacing === 0) {
    return { sizes: [block.length - offset], dataOffset: offset }
  }
  if (offset >= block.length) return null
  const count = block[offset]! + 1
  offset += 1

  // Fixed-size lacing: every frame is the same length.
  if (lacing === 2) {
    const total = block.length - offset
    if (count === 0 || total % count !== 0) return null
    return { sizes: new Array<number>(count).fill(total / count), dataOffset: offset }
  }

  const sizes: number[] = []
  if (lacing === 1) {
    // Xiph: each size is a run of 0xFF bytes terminated by a byte < 0xFF.
    for (let i = 0; i < count - 1; i++) {
      let size = 0
      for (;;) {
        if (offset >= block.length) return null
        const byte = block[offset]!
        offset += 1
        size += byte
        if (byte !== 0xff) break
      }
      sizes.push(size)
    }
  } else {
    // EBML lacing: first size is a VINT, the rest are signed deltas.
    const first = readVintValue(block, offset)
    if (!first) return null
    offset += first.length
    sizes.push(first.value)
    let previous = first.value
    for (let i = 1; i < count - 1; i++) {
      const delta = readVintValue(block, offset)
      if (!delta) return null
      // Signed VINT: bias by half the representable range for its width.
      const bias = 2 ** (7 * delta.length - 1) - 1
      previous += delta.value - bias
      sizes.push(previous)
      offset += delta.length
    }
  }

  const used = sizes.reduce((a, b) => a + b, 0)
  const remaining = block.length - offset - used
  if (remaining < 0) return null
  sizes.push(remaining)
  return { sizes, dataOffset: offset }
}

/* ── Helpers ────────────────────────────────────────────────────────────── */

export function isVideoTrack(track: MkvTrack): boolean {
  return track.type === TrackType.VIDEO
}
export function isAudioTrack(track: MkvTrack): boolean {
  return track.type === TrackType.AUDIO
}
export function isSubtitleTrack(track: MkvTrack): boolean {
  return track.type === TrackType.SUBTITLE
}

/** Master elements, re-exported so tests can assert the descent set. */
export { MASTER_ELEMENTS }
