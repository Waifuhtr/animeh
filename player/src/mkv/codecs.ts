import { ErrorCode, PlayerError } from '../core/errors.ts'
import type { MkvTrack } from './demuxer.ts'
import {
  audioSampleEntry,
  box,
  concat,
  esds,
  fullBox,
  i16,
  u16,
  u32,
  u8,
  visualSampleEntry,
} from './mp4.ts'

/**
 * MP4 timescale used for every remuxed track.
 *
 * Microseconds. Matroska stores timestamps in units of TimestampScale, which
 * is one millisecond in practice, so microseconds represent every timestamp
 * exactly while leaving headroom for finer-grained files. A 32-bit counter
 * would wrap after ~71 minutes at this rate, which is why `tfdt` is written as
 * version 1.
 */
export const MP4_TIMESCALE = 1_000_000

export interface Mp4TrackMapping {
  /** The `stsd` sample entry box for this track. */
  sampleEntry: Uint8Array
  /** RFC 6381 codec string, e.g. `avc1.640028`. */
  codecString: string
  /** Full MSE type, e.g. `video/mp4; codecs="avc1.640028"`. */
  mimeType: string
}

function unsupported(codecId: string, detail: string): PlayerError {
  return new PlayerError({
    code: ErrorCode.MEDIA_UNSUPPORTED,
    message: `Cannot remux ${codecId} into MP4: ${detail}`,
    context: { codecId },
  })
}

/* ── Video ──────────────────────────────────────────────────────────────── */

export function mapVideoTrack(track: MkvTrack): Mp4TrackMapping {
  const width = track.video?.pixelWidth ?? 0
  const height = track.video?.pixelHeight ?? 0
  const priv = track.codecPrivate

  switch (track.codecId) {
    case 'V_MPEG4/ISO/AVC': {
      // CodecPrivate is already an AVCDecoderConfigurationRecord, and MKV
      // stores AVC samples length-prefixed, so the bitstream needs no rewriting.
      if (!priv || priv.length < 4) throw unsupported(track.codecId, 'missing avcC')
      const codecString = `avc1.${hex(priv[1]!)}${hex(priv[2]!)}${hex(priv[3]!)}`
      return {
        sampleEntry: visualSampleEntry('avc1', width, height, box('avcC', priv)),
        codecString,
        mimeType: `video/mp4; codecs="${codecString}"`,
      }
    }

    case 'V_MPEGH/ISO/HEVC': {
      if (!priv || priv.length < 23) throw unsupported(track.codecId, 'missing hvcC')
      const codecString = hevcCodecString(priv)
      return {
        sampleEntry: visualSampleEntry('hvc1', width, height, box('hvcC', priv)),
        codecString,
        mimeType: `video/mp4; codecs="${codecString}"`,
      }
    }

    case 'V_AV1': {
      if (!priv || priv.length < 4) throw unsupported(track.codecId, 'missing av1C')
      const codecString = av1CodecString(priv)
      return {
        sampleEntry: visualSampleEntry('av01', width, height, box('av1C', priv)),
        codecString,
        mimeType: `video/mp4; codecs="${codecString}"`,
      }
    }

    case 'V_VP9': {
      // Matroska rarely carries CodecPrivate for VP9, so the configuration
      // record has to be synthesised from the track's dimensions.
      const config = priv && priv.length >= 8 ? priv : synthesiseVpcC(width, height)
      const profile = config[0] ?? 0
      const level = config[1] ?? vp9Level(width, height)
      const bitDepth = (config[2] ?? 0x08) >> 4 || 8
      const codecString = `vp09.${pad2(profile)}.${pad2(level)}.${pad2(bitDepth)}`
      return {
        sampleEntry: visualSampleEntry('vp09', width, height, fullBox('vpcC', 1, 0, config)),
        codecString,
        mimeType: `video/mp4; codecs="${codecString}"`,
      }
    }

    case 'V_VP8':
      // VP8 has no standard MP4 encapsulation; it only ships in WebM.
      throw unsupported(track.codecId, 'VP8 has no MP4 sample entry')

    default:
      throw unsupported(track.codecId, 'unrecognised video codec')
  }
}

/* ── Audio ──────────────────────────────────────────────────────────────── */

export function mapAudioTrack(track: MkvTrack): Mp4TrackMapping {
  const channels = track.audio?.channels ?? 2
  const sampleRate = Math.round(track.audio?.samplingFrequency ?? 48000)
  const priv = track.codecPrivate

  switch (track.codecId) {
    case 'A_AAC': {
      // CodecPrivate holds the AudioSpecificConfig. When a muxer omits it we
      // can rebuild a plain AAC-LC config from the track's own rate/channels.
      const config = priv && priv.length >= 2 ? priv : buildAudioSpecificConfig(sampleRate, channels)
      const objectType = config[0]! >> 3 || 2
      const codecString = `mp4a.40.${objectType === 31 ? 2 : objectType}`
      return {
        sampleEntry: audioSampleEntry('mp4a', channels, sampleRate, esds(config)),
        codecString,
        mimeType: `audio/mp4; codecs="${codecString}"`,
      }
    }

    case 'A_OPUS': {
      if (!priv || priv.length < 19) throw unsupported(track.codecId, 'missing OpusHead')
      return {
        sampleEntry: audioSampleEntry('Opus', channels, 48000, box('dOps', opusHeadToDOps(priv))),
        codecString: 'opus',
        mimeType: 'audio/mp4; codecs="opus"',
      }
    }

    case 'A_FLAC': {
      if (!priv || priv.length < 4) throw unsupported(track.codecId, 'missing FLAC headers')
      // Matroska keeps the whole native FLAC header; `dfLa` wants only the
      // metadata blocks, so drop the four-byte "fLaC" magic when present.
      const magic = String.fromCharCode(priv[0]!, priv[1]!, priv[2]!, priv[3]!)
      const blocks = magic === 'fLaC' ? priv.subarray(4) : priv
      return {
        sampleEntry: audioSampleEntry('fLaC', channels, sampleRate, fullBox('dfLa', 0, 0, blocks)),
        codecString: 'flac',
        mimeType: 'audio/mp4; codecs="flac"',
      }
    }

    // Codecs with no usable MSE path in mainstream browsers. Named explicitly
    // so the error tells the operator which track to re-encode.
    case 'A_AC3':
    case 'A_EAC3':
    case 'A_DTS':
    case 'A_TRUEHD':
      throw unsupported(track.codecId, 'browsers do not decode this audio codec via MSE')
    case 'A_VORBIS':
      throw unsupported(track.codecId, 'Vorbis has no standard MP4 encapsulation')

    default:
      throw unsupported(track.codecId, 'unrecognised audio codec')
  }
}

/* ── Codec string helpers ───────────────────────────────────────────────── */

function hex(value: number): string {
  return value.toString(16).padStart(2, '0')
}

function pad2(value: number): string {
  return String(value).padStart(2, '0')
}

/**
 * Build an `hvc1.…` codec string from an HEVCDecoderConfigurationRecord.
 * Layout: profile_space/tier/profile_idc, 32 compatibility bits, 48
 * constraint bits, then the level.
 */
function hevcCodecString(hvcC: Uint8Array): string {
  const byte1 = hvcC[1]!
  const profileSpace = byte1 >> 6
  const tierFlag = (byte1 >> 5) & 0x1
  const profileIdc = byte1 & 0x1f

  let compatibility = 0
  for (let i = 2; i <= 5; i++) compatibility = ((compatibility << 8) | hvcC[i]!) >>> 0
  // The string carries the compatibility flags bit-reversed.
  let reversed = 0
  for (let i = 0; i < 32; i++) {
    reversed = ((reversed << 1) | ((compatibility >>> i) & 1)) >>> 0
  }

  const levelIdc = hvcC[12]!
  const parts = [
    'hvc1',
    `${['', 'A', 'B', 'C'][profileSpace] ?? ''}${profileIdc}`,
    reversed.toString(16),
    `${tierFlag === 0 ? 'L' : 'H'}${levelIdc}`,
  ]

  // Constraint bytes, most significant first, with trailing zero bytes trimmed.
  const constraints: string[] = []
  for (let i = 6; i <= 11; i++) constraints.push(hex(hvcC[i]!))
  while (constraints.length > 0 && constraints.at(-1) === '00') constraints.pop()
  return [...parts, ...constraints].join('.')
}

/** Build an `av01.…` codec string from an AV1CodecConfigurationRecord. */
function av1CodecString(av1C: Uint8Array): string {
  const byte1 = av1C[1]!
  const profile = byte1 >> 5
  const levelIdx = byte1 & 0x1f
  const byte2 = av1C[2]!
  const tier = (byte2 >> 7) & 0x1
  const highBitdepth = (byte2 >> 6) & 0x1
  const twelveBit = (byte2 >> 5) & 0x1
  const bitDepth = twelveBit ? 12 : highBitdepth ? 10 : 8
  return `av01.${profile}.${pad2(levelIdx)}${tier === 0 ? 'M' : 'H'}.${pad2(bitDepth)}`
}

/**
 * Synthesise a VP9 configuration record.
 * Profile 0, 8-bit, 4:2:0 — the combination essentially every VP9 file in the
 * wild uses — with colour fields left "unspecified" so the decoder falls back
 * to its own defaults.
 */
function synthesiseVpcC(width: number, height: number): Uint8Array {
  return new Uint8Array([
    0, // profile
    vp9Level(width, height),
    // bit depth 8 (high nibble) | chroma 4:2:0 colocated (bits 3-1) | full range 0
    (8 << 4) | (1 << 1),
    2, // colour primaries: unspecified
    2, // transfer characteristics: unspecified
    2, // matrix coefficients: unspecified
    0, 0, // codec initialisation data size
  ])
}

/** Coarse VP9 level from luma sample count, per the VP9 level table. */
function vp9Level(width: number, height: number): number {
  const samples = width * height
  if (samples <= 36864) return 10 // 1.0
  if (samples <= 73728) return 11 // 1.1
  if (samples <= 122880) return 20 // 2.0
  if (samples <= 245760) return 21 // 2.1
  if (samples <= 552960) return 30 // 3.0
  if (samples <= 983040) return 31 // 3.1
  if (samples <= 2228224) return 40 // 4.0 — covers 1080p
  if (samples <= 8912896) return 50 // 5.0 — covers 4K
  return 51
}

const AAC_SAMPLE_RATES = [
  96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350,
]

/** Two-byte AAC-LC AudioSpecificConfig for muxers that omitted CodecPrivate. */
function buildAudioSpecificConfig(sampleRate: number, channels: number): Uint8Array {
  const rateIndex = AAC_SAMPLE_RATES.indexOf(sampleRate)
  const index = rateIndex >= 0 ? rateIndex : 4 // default to 44.1 kHz
  const objectType = 2 // AAC-LC
  const channelConfig = Math.min(channels, 7)
  // 5 bits object type | 4 bits rate index | 4 bits channel config, packed.
  return new Uint8Array([
    (objectType << 3) | (index >> 1),
    ((index & 1) << 7) | (channelConfig << 3),
  ])
}

/**
 * Convert an Ogg-style OpusHead into the MP4 `dOps` payload.
 * Same fields, but OpusHead is little-endian and prefixed with a magic and a
 * version byte that `dOps` does not carry.
 */
function opusHeadToDOps(opusHead: Uint8Array): Uint8Array {
  const view = new DataView(opusHead.buffer, opusHead.byteOffset, opusHead.byteLength)
  const channelCount = opusHead[9]!
  const preSkip = view.getUint16(10, true)
  const inputSampleRate = view.getUint32(12, true)
  const outputGain = view.getInt16(16, true)
  const mappingFamily = opusHead[18]!

  const head = concat(
    u8(0), // dOps version
    u8(channelCount),
    u16(preSkip),
    u32(inputSampleRate),
    i16(outputGain),
    u8(mappingFamily),
  )
  // Family 0 is mono/stereo and carries no channel mapping table.
  if (mappingFamily === 0) return head
  return concat(head, opusHead.subarray(19))
}
