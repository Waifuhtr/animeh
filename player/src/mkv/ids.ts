/**
 * Matroska element IDs, stored with their EBML marker bit intact — the same
 * form `readElementId` returns, so lookups are a plain integer compare.
 *
 * Only the elements this player actually consumes are listed. Everything else
 * is skipped by size, which is why an unknown element is harmless.
 */
export const ID = {
  // Global
  EBML: 0x1a45dfa3,
  DocType: 0x4282,
  DocTypeReadVersion: 0x4285,
  Void: 0xec,
  CRC32: 0xbf,

  // Segment
  Segment: 0x18538067,

  // SeekHead — the table of contents that lets us range-fetch Cues directly
  SeekHead: 0x114d9b74,
  Seek: 0x4dbb,
  SeekID: 0x53ab,
  SeekPosition: 0x53ac,

  // Info
  Info: 0x1549a966,
  TimestampScale: 0x2ad7b1,
  Duration: 0x4489,
  MuxingApp: 0x4d80,
  WritingApp: 0x5741,
  Title: 0x7ba9,

  // Tracks
  Tracks: 0x1654ae6b,
  TrackEntry: 0xae,
  TrackNumber: 0xd7,
  TrackUID: 0x73c5,
  TrackType: 0x83,
  FlagEnabled: 0xb9,
  FlagDefault: 0x88,
  FlagForced: 0x55aa,
  FlagLacing: 0x9c,
  DefaultDuration: 0x23e383,
  Name: 0x536e,
  Language: 0x22b59c,
  LanguageBCP47: 0x22b59d,
  CodecID: 0x86,
  CodecPrivate: 0x63a2,
  CodecName: 0x258688,
  CodecDelay: 0x56aa,
  SeekPreRoll: 0x56bb,

  // Track > Video
  Video: 0xe0,
  PixelWidth: 0xb0,
  PixelHeight: 0xba,
  DisplayWidth: 0x54b0,
  DisplayHeight: 0x54ba,
  PixelCropBottom: 0x54aa,
  PixelCropTop: 0x54bb,
  PixelCropLeft: 0x54cc,
  PixelCropRight: 0x54dd,

  // Track > Audio
  Audio: 0xe1,
  SamplingFrequency: 0xb5,
  OutputSamplingFrequency: 0x78b5,
  Channels: 0x9f,
  BitDepth: 0x6264,

  // Cluster
  Cluster: 0x1f43b675,
  Timestamp: 0xe7,
  SimpleBlock: 0xa3,
  BlockGroup: 0xa0,
  Block: 0xa1,
  BlockDuration: 0x9b,
  ReferenceBlock: 0xfb,

  // Cues — the seek index
  Cues: 0x1c53bb6b,
  CuePoint: 0xbb,
  CueTime: 0xb3,
  CueTrackPositions: 0xb7,
  CueTrack: 0xf7,
  CueClusterPosition: 0xf1,
  CueRelativePosition: 0xf0,

  // Attachments — where embedded subtitle fonts live
  Attachments: 0x1941a469,
  AttachedFile: 0x61a7,
  FileDescription: 0x467e,
  FileName: 0x466e,
  FileMimeType: 0x4660,
  FileData: 0x465c,
  FileUID: 0x46ae,

  // Chapters / Tags (skipped, listed so SeekHead entries are recognisable)
  Chapters: 0x1043a770,
  Tags: 0x1254c367,
} as const

/** TrackType values. */
export const TrackType = {
  VIDEO: 1,
  AUDIO: 2,
  COMPLEX: 3,
  LOGO: 0x10,
  SUBTITLE: 0x11,
  BUTTONS: 0x12,
  CONTROL: 0x20,
  METADATA: 0x21,
} as const

/** Elements we descend into. Anything else is skipped whole. */
export const MASTER_ELEMENTS = new Set<number>([
  ID.EBML,
  ID.Segment,
  ID.SeekHead,
  ID.Seek,
  ID.Info,
  ID.Tracks,
  ID.TrackEntry,
  ID.Video,
  ID.Audio,
  ID.Cluster,
  ID.BlockGroup,
  ID.Cues,
  ID.CuePoint,
  ID.CueTrackPositions,
  ID.Attachments,
  ID.AttachedFile,
])
