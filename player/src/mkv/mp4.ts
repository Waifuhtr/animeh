/**
 * Minimal ISO-BMFF writer — just enough to build the fragmented MP4 that
 * Media Source Extensions accepts.
 *
 * Only the boxes a fragmented stream actually needs are here. The sample
 * tables in `moov` are all empty: in fMP4 the real timing lives in each
 * fragment's `trun`, and `moov` carries only the codec configuration.
 */

const textEncoder = new TextEncoder()

export function u8(value: number): Uint8Array {
  return new Uint8Array([value & 0xff])
}

export function u16(value: number): Uint8Array {
  return new Uint8Array([(value >> 8) & 0xff, value & 0xff])
}

export function u24(value: number): Uint8Array {
  return new Uint8Array([(value >> 16) & 0xff, (value >> 8) & 0xff, value & 0xff])
}

export function u32(value: number): Uint8Array {
  return new Uint8Array([
    (value >>> 24) & 0xff,
    (value >>> 16) & 0xff,
    (value >>> 8) & 0xff,
    value & 0xff,
  ])
}

export function i16(value: number): Uint8Array {
  return u16(value < 0 ? value + 0x10000 : value)
}

/** 64-bit big-endian, written from a JS number (safe below 2^53). */
export function u64(value: number): Uint8Array {
  const high = Math.floor(value / 2 ** 32)
  const low = value >>> 0
  return concat(u32(high), u32(low))
}

export function ascii(text: string): Uint8Array {
  return textEncoder.encode(text)
}

export function concat(...parts: Uint8Array[]): Uint8Array {
  let length = 0
  for (const part of parts) length += part.length
  const out = new Uint8Array(length)
  let offset = 0
  for (const part of parts) {
    out.set(part, offset)
    offset += part.length
  }
  return out
}

/** `size | type | payload` */
export function box(type: string, ...payload: Uint8Array[]): Uint8Array {
  const body = concat(...payload)
  return concat(u32(body.length + 8), ascii(type), body)
}

/** A box whose first four payload bytes are `version | flags`. */
export function fullBox(
  type: string,
  version: number,
  flags: number,
  ...payload: Uint8Array[]
): Uint8Array {
  return box(type, u8(version), u24(flags), ...payload)
}

/** 3x3 transformation matrix, identity. */
const IDENTITY_MATRIX = concat(
  u32(0x00010000), u32(0), u32(0),
  u32(0), u32(0x00010000), u32(0),
  u32(0), u32(0), u32(0x40000000),
)

export function ftyp(): Uint8Array {
  // `iso5` is the baseline for the fragmentation features we use; `iso6` and
  // `mp41` are listed as compatible so older parsers still recognise the file.
  return box('ftyp', ascii('iso5'), u32(1), ascii('iso5'), ascii('iso6'), ascii('mp41'))
}

export interface TrackConfig {
  /** MP4 track id, 1-based. */
  id: number
  /** Ticks per second for this track's timestamps. */
  timescale: number
  kind: 'video' | 'audio'
  /** The fully-formed sample entry box (avc1, mp4a, …). */
  sampleEntry: Uint8Array
  width?: number
  height?: number
  language?: string
}

export function moov(tracks: TrackConfig[], movieTimescale: number): Uint8Array {
  return box(
    'moov',
    mvhd(movieTimescale),
    ...tracks.map((track) => trak(track)),
    box('mvex', ...tracks.map((track) => trex(track.id))),
  )
}

function mvhd(timescale: number): Uint8Array {
  return fullBox(
    'mvhd',
    0,
    0,
    u32(0), // creation time
    u32(0), // modification time
    u32(timescale),
    // Duration 0: a fragmented file's length is not known up front, and the
    // player takes duration from the container metadata instead.
    u32(0),
    u32(0x00010000), // rate 1.0
    u16(0x0100), // volume 1.0
    u16(0), // reserved
    u32(0), u32(0), // reserved
    IDENTITY_MATRIX,
    u32(0), u32(0), u32(0), u32(0), u32(0), u32(0), // pre_defined
    u32(0xffffffff), // next track id
  )
}

function trak(track: TrackConfig): Uint8Array {
  return box('trak', tkhd(track), mdia(track))
}

function tkhd(track: TrackConfig): Uint8Array {
  // flags 0x7: track enabled, in movie, in preview.
  return fullBox(
    'tkhd',
    0,
    0x7,
    u32(0), u32(0),
    u32(track.id),
    u32(0), // reserved
    u32(0), // duration
    u32(0), u32(0), // reserved
    u16(0), // layer
    u16(0), // alternate group
    u16(track.kind === 'audio' ? 0x0100 : 0), // volume
    u16(0), // reserved
    IDENTITY_MATRIX,
    // Width/height are 16.16 fixed point and only meaningful for video.
    u32(((track.width ?? 0) << 16) >>> 0),
    u32(((track.height ?? 0) << 16) >>> 0),
  )
}

function mdia(track: TrackConfig): Uint8Array {
  return box('mdia', mdhd(track), hdlr(track), minf(track))
}

function mdhd(track: TrackConfig): Uint8Array {
  return fullBox(
    'mdhd',
    0,
    0,
    u32(0), u32(0),
    u32(track.timescale),
    u32(0), // duration
    u16(packLanguage(track.language ?? 'und')),
    u16(0), // pre_defined
  )
}

/** ISO-639-2/T packed as three 5-bit values offset from 0x60. */
function packLanguage(language: string): number {
  const code = (language.length >= 3 ? language.slice(0, 3) : 'und').toLowerCase()
  let packed = 0
  for (let i = 0; i < 3; i++) {
    const value = (code.charCodeAt(i) - 0x60) & 0x1f
    packed = (packed << 5) | value
  }
  return packed
}

function hdlr(track: TrackConfig): Uint8Array {
  const handler = track.kind === 'video' ? 'vide' : 'soun'
  const name = track.kind === 'video' ? 'VideoHandler' : 'SoundHandler'
  return fullBox(
    'hdlr',
    0,
    0,
    u32(0), // pre_defined
    ascii(handler),
    u32(0), u32(0), u32(0), // reserved
    ascii(name),
    u8(0), // null terminator
  )
}

function minf(track: TrackConfig): Uint8Array {
  const header =
    track.kind === 'video'
      ? fullBox('vmhd', 0, 1, u16(0), u16(0), u16(0), u16(0))
      : fullBox('smhd', 0, 0, u16(0), u16(0))
  return box('minf', header, dinf(), stbl(track))
}

function dinf(): Uint8Array {
  // A single self-referencing data entry: samples live in this same file.
  return box('dinf', fullBox('dref', 0, 0, u32(1), fullBox('url ', 0, 1)))
}

function stbl(track: TrackConfig): Uint8Array {
  return box(
    'stbl',
    fullBox('stsd', 0, 0, u32(1), track.sampleEntry),
    // All four sample tables are empty by design — fragments carry the timing.
    fullBox('stts', 0, 0, u32(0)),
    fullBox('stsc', 0, 0, u32(0)),
    fullBox('stsz', 0, 0, u32(0), u32(0)),
    fullBox('stco', 0, 0, u32(0)),
  )
}

function trex(trackId: number): Uint8Array {
  return fullBox(
    'trex',
    0,
    0,
    u32(trackId),
    u32(1), // default sample description index
    u32(0), // default sample duration
    u32(0), // default sample size
    u32(0), // default sample flags
  )
}

/* ── Sample entries ─────────────────────────────────────────────────────── */

/** 32-byte fixed-length Pascal string used by VisualSampleEntry. */
function compressorName(name: string): Uint8Array {
  const out = new Uint8Array(32)
  const encoded = ascii(name).subarray(0, 31)
  out[0] = encoded.length
  out.set(encoded, 1)
  return out
}

export function visualSampleEntry(
  type: string,
  width: number,
  height: number,
  configBox: Uint8Array,
  extra: Uint8Array[] = [],
): Uint8Array {
  return box(
    type,
    u8(0), u8(0), u8(0), u8(0), u8(0), u8(0), // reserved
    u16(1), // data reference index
    u16(0), // pre_defined
    u16(0), // reserved
    u32(0), u32(0), u32(0), // pre_defined
    u16(width),
    u16(height),
    u32(0x00480000), // horizontal resolution 72dpi
    u32(0x00480000), // vertical resolution 72dpi
    u32(0), // reserved
    u16(1), // frame count
    compressorName(type),
    u16(0x0018), // depth: colour with no alpha
    i16(-1), // pre_defined
    configBox,
    ...extra,
  )
}

export function audioSampleEntry(
  type: string,
  channels: number,
  sampleRate: number,
  configBox: Uint8Array,
): Uint8Array {
  return box(
    type,
    u8(0), u8(0), u8(0), u8(0), u8(0), u8(0), // reserved
    u16(1), // data reference index
    u32(0), u32(0), // reserved
    u16(channels),
    u16(16), // sample size
    u16(0), // pre_defined
    u16(0), // reserved
    // 16.16 fixed point. Rates above 65535 cannot be expressed here; the real
    // rate is in the codec config, which is what decoders actually read.
    u32(((Math.min(sampleRate, 65535) << 16) >>> 0)),
    configBox,
  )
}

/**
 * MPEG-4 elementary stream descriptor wrapping an AudioSpecificConfig.
 *
 * The descriptor lengths are written in the one-byte short form, which is
 * valid as long as each descriptor stays under 128 bytes — an ASC is at most
 * a handful of bytes, so that always holds here.
 */
export function esds(audioSpecificConfig: Uint8Array): Uint8Array {
  const decoderSpecific = concat(u8(0x05), u8(audioSpecificConfig.length), audioSpecificConfig)
  const decoderConfig = concat(
    u8(0x04),
    u8(13 + decoderSpecific.length),
    u8(0x40), // objectTypeIndication: MPEG-4 Audio
    u8(0x15), // streamType 5 (audio) << 2 | upStream 0 | reserved 1
    u24(0), // buffer size
    u32(0), // max bitrate
    u32(0), // average bitrate
    decoderSpecific,
  )
  const slConfig = concat(u8(0x06), u8(1), u8(0x02))
  const esDescriptor = concat(
    u8(0x03),
    u8(3 + decoderConfig.length + slConfig.length),
    u16(0), // ES_ID
    u8(0), // flags, stream priority 0
    decoderConfig,
    slConfig,
  )
  return fullBox('esds', 0, 0, esDescriptor)
}

/* ── Fragments ──────────────────────────────────────────────────────────── */

export interface Mp4Sample {
  /** In track timescale units. */
  duration: number
  size: number
  /** Composition offset (PTS - DTS), signed, in timescale units. */
  compositionOffset: number
  keyframe: boolean
}

/**
 * Build one `moof` + `mdat` pair.
 *
 * `trun` uses version 1 so composition offsets can be negative, which is what
 * B-frames need. `tfdt` is version 1 (64-bit) so long files do not overflow
 * the media-time counter.
 */
export function fragment(
  trackId: number,
  sequenceNumber: number,
  baseMediaDecodeTime: number,
  samples: Mp4Sample[],
  data: Uint8Array,
): Uint8Array {
  // trun flags: data-offset, sample-duration, sample-size, sample-flags,
  // sample-composition-time-offset.
  const trunFlags = 0x000001 | 0x000100 | 0x000200 | 0x000400 | 0x000800
  const trunSize = 8 + 4 + 4 + 4 + samples.length * 16
  const trafSize = 8 + (8 + 4 + 4) + (8 + 4 + 8) + trunSize
  // moof = header + mfhd + traf. The data offset points at the first byte of
  // mdat's payload, measured from the start of the moof box.
  const moofSize = 8 + (8 + 4 + 4) + trafSize
  const dataOffset = moofSize + 8

  const sampleRows: Uint8Array[] = []
  for (const sample of samples) {
    sampleRows.push(
      u32(sample.duration),
      u32(sample.size),
      u32(sampleFlags(sample.keyframe)),
      u32(sample.compositionOffset < 0 ? sample.compositionOffset + 0x100000000 : sample.compositionOffset),
    )
  }

  const trun = fullBox('trun', 1, trunFlags, u32(samples.length), u32(dataOffset), ...sampleRows)

  const traf = box(
    'traf',
    // tfhd flags 0x020000 = default-base-is-moof: offsets are relative to the
    // enclosing moof, which is what makes each fragment self-contained.
    fullBox('tfhd', 0, 0x020000, u32(trackId)),
    fullBox('tfdt', 1, 0, u64(baseMediaDecodeTime)),
    trun,
  )

  const moof = box('moof', fullBox('mfhd', 0, 0, u32(sequenceNumber)), traf)
  const mdat = box('mdat', data)
  return concat(moof, mdat)
}

/**
 * Per-sample flags.
 *
 * A keyframe depends on nothing and must not be discarded; everything else is
 * marked as depending on other samples so the decoder knows it cannot start
 * there. `sample_is_non_sync_sample` is the bit seeking actually keys off.
 */
function sampleFlags(keyframe: boolean): number {
  if (keyframe) {
    // depends_on = 2 (does not depend on others), is_non_sync = 0
    return 0x02000000
  }
  // depends_on = 1 (depends on others), is_non_sync = 1
  return 0x01010000
}
