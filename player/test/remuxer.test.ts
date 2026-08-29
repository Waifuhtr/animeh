import { strict as assert } from 'node:assert'
import { execFile } from 'node:child_process'
import { existsSync } from 'node:fs'
import { mkdtemp, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import { promisify } from 'node:util'
import {
  ClusterStream,
  isAudioTrack,
  isVideoTrack,
  readMkvHeader,
  type MkvFrame,
  type MkvTrack,
} from '../src/mkv/demuxer.ts'
import { MkvRemuxer } from '../src/mkv/remuxer.ts'
import { MEDIA_DIR, fileRangeReader } from './helpers.ts'

const run = promisify(execFile)
const MKV = join(MEDIA_DIR, 'episode.mkv')
const MKV_OPUS = join(MEDIA_DIR, 'episode-opus.mkv')

async function haveFfprobe(): Promise<boolean> {
  try {
    await run('ffprobe', ['-version'])
    return true
  } catch {
    return false
  }
}

/**
 * Demux the first `seconds` of a file and remux it, returning the concatenated
 * fMP4 byte streams exactly as they would be appended to a SourceBuffer.
 */
async function remuxPrefix(path: string, seconds: number) {
  const { read, size, close } = await fileRangeReader(path)
  try {
    const header = await readMkvHeader(read, size)
    const videoTrack = header.tracks.find(isVideoTrack) ?? null
    const audioTrack = header.tracks.find(isAudioTrack) ?? null
    const remuxer = new MkvRemuxer(videoTrack, audioTrack)

    const wanted = new Map<number, MkvTrack>()
    if (videoTrack) wanted.set(videoTrack.number, videoTrack)
    if (audioTrack) wanted.set(audioTrack.number, audioTrack)

    const stream = new ClusterStream(wanted, header.timestampScale)
    const videoParts: Uint8Array[] = [remuxer.initSegment('video')!]
    const audioParts: Uint8Array[] = remuxer.audio ? [remuxer.initSegment('audio')!] : []
    const allFrames: MkvFrame[] = []

    let offset = header.firstClusterOffset!
    let pending = new Uint8Array(0)
    let lastTimestampNs = 0

    while (offset < size && lastTimestampNs < seconds * 1e9) {
      const chunk = await read(offset, Math.min(offset + 512 * 1024, size))
      if (chunk.length === 0) break
      const merged = new Uint8Array(pending.length + chunk.length)
      merged.set(pending)
      merged.set(chunk, pending.length)

      const base = offset - pending.length
      const result = stream.push(merged, base)
      for (const frame of result.frames) {
        allFrames.push(frame)
        lastTimestampNs = Math.max(lastTimestampNs, frame.timestampNs)
      }
      const output = remuxer.push(result.frames)
      for (const segment of output.video) videoParts.push(segment.data)
      for (const segment of output.audio) audioParts.push(segment.data)

      pending = merged.slice(result.consumed)
      offset = base + result.consumed + (merged.length - result.consumed) - pending.length
      offset = base + merged.length
    }

    const tail = remuxer.flush()
    for (const segment of tail.video) videoParts.push(segment.data)
    for (const segment of tail.audio) audioParts.push(segment.data)

    return { remuxer, header, videoParts, audioParts, frames: allFrames }
  } finally {
    await close()
  }
}

function joinParts(parts: Uint8Array[]): Buffer {
  const total = parts.reduce((sum, part) => sum + part.length, 0)
  const out = Buffer.alloc(total)
  let offset = 0
  for (const part of parts) {
    out.set(part, offset)
    offset += part.length
  }
  return out
}

interface ProbeStream {
  codec_name: string
  codec_type: string
  width?: number
  height?: number
  sample_rate?: string
  channels?: number
  nb_read_frames?: string
}

async function probe(path: string): Promise<ProbeStream[]> {
  const { stdout } = await run('ffprobe', [
    '-v', 'error',
    '-count_frames',
    '-show_streams',
    '-print_format', 'json',
    path,
  ])
  return JSON.parse(stdout).streams as ProbeStream[]
}

const corpusReady = existsSync(MKV)

describe(
  'MKV → fMP4 remuxer',
  { skip: corpusReady ? false : 'run tools/make-test-media.sh first' },
  () => {
    it('advertises MSE-compatible codec strings', async () => {
      const { remuxer } = await remuxPrefix(MKV, 3)
      assert.ok(remuxer.video, 'no video mapping')
      assert.ok(remuxer.audio, 'no audio mapping')
      // High profile 4.0 as encoded by tools/make-test-media.sh.
      assert.match(remuxer.video.mimeType, /^video\/mp4; codecs="avc1\.[0-9a-f]{6}"$/)
      assert.equal(remuxer.audio.mimeType, 'audio/mp4; codecs="mp4a.40.2"')
      assert.deepEqual(remuxer.warnings, [])
    })

    it('produces video ffmpeg can decode, frame for frame', async (t) => {
      if (!(await haveFfprobe())) return t.skip('ffprobe not installed')
      const seconds = 5
      const { videoParts, frames, remuxer } = await remuxPrefix(MKV, seconds)
      const dir = await mkdtemp(join(tmpdir(), 'animeh-remux-'))
      try {
        const file = join(dir, 'video.mp4')
        await writeFile(file, joinParts(videoParts))

        const streams = await probe(file)
        assert.equal(streams.length, 1, 'expected exactly one video track')
        const video = streams[0]!
        assert.equal(video.codec_type, 'video')
        assert.equal(video.codec_name, 'h264')
        assert.equal(video.width, 1920)
        assert.equal(video.height, 1080)

        // Every sample we handed over must survive as a decodable frame.
        const expected = frames.filter((f) => f.track === remuxer.video!.trackNumber).length
        const decoded = Number(video.nb_read_frames)
        assert.ok(decoded > 0, 'ffmpeg decoded no frames')
        assert.equal(
          decoded,
          expected,
          `remuxed ${expected} samples but ffmpeg decoded ${decoded}`,
        )
      } finally {
        await rm(dir, { recursive: true, force: true })
      }
    })

    it('produces audio ffmpeg can decode', async (t) => {
      if (!(await haveFfprobe())) return t.skip('ffprobe not installed')
      const { audioParts, frames, remuxer } = await remuxPrefix(MKV, 5)
      const dir = await mkdtemp(join(tmpdir(), 'animeh-remux-'))
      try {
        const file = join(dir, 'audio.mp4')
        await writeFile(file, joinParts(audioParts))
        const streams = await probe(file)
        assert.equal(streams.length, 1)
        const audio = streams[0]!
        assert.equal(audio.codec_type, 'audio')
        assert.equal(audio.codec_name, 'aac')
        assert.equal(audio.sample_rate, '48000')
        assert.equal(audio.channels, 2)

        const expected = frames.filter((f) => f.track === remuxer.audio!.trackNumber).length
        assert.equal(Number(audio.nb_read_frames), expected)
      } finally {
        await rm(dir, { recursive: true, force: true })
      }
    })

    it('keeps presentation timestamps intact through the remux', async (t) => {
      if (!(await haveFfprobe())) return t.skip('ffprobe not installed')
      const { videoParts, frames, remuxer } = await remuxPrefix(MKV, 5)
      const dir = await mkdtemp(join(tmpdir(), 'animeh-remux-'))
      try {
        const file = join(dir, 'video.mp4')
        await writeFile(file, joinParts(videoParts))
        const { stdout } = await run('ffprobe', [
          '-v', 'error',
          '-select_streams', 'v:0',
          '-show_entries', 'packet=pts_time,dts_time,flags',
          '-print_format', 'json',
          file,
        ])
        const packets = JSON.parse(stdout).packets as {
          pts_time: string
          dts_time: string
          flags: string
        }[]
        assert.ok(packets.length > 50)

        const offset = remuxer.timelineOffsetSec

        // The first packet must be a keyframe, sitting one composition-bias
        // second into the remuxed timeline.
        assert.match(packets[0]!.flags, /K/)
        assert.ok(
          Math.abs(Number(packets[0]!.pts_time) - offset) < 0.05,
          `first pts ${packets[0]!.pts_time} should be ~${offset}`,
        )

        // Decode times must never run ahead of presentation times: that is the
        // condition that made demuxers rewrite our timeline.
        for (const packet of packets) {
          assert.ok(
            Number(packet.dts_time) <= Number(packet.pts_time) + 1e-6,
            `dts ${packet.dts_time} > pts ${packet.pts_time}`,
          )
        }

        // Decode order must advance strictly.
        for (let i = 1; i < packets.length; i++) {
          assert.ok(
            Number(packets[i]!.dts_time) > Number(packets[i - 1]!.dts_time),
            `dts went backwards at packet ${i}`,
          )
        }

        // The real invariant: every presentation timestamp from the Matroska
        // source survives the remux exactly, shifted only by the bias. Compared
        // against the source rather than an assumed frame-rate grid, because a
        // capture that stops mid-GOP legitimately has a ragged tail.
        const sourcePts = frames
          .filter((f) => f.track === remuxer.video!.trackNumber)
          .map((f) => Math.round(f.timestampNs / 1e6))
          .sort((a, b) => a - b)
        const remuxedPts = packets
          .map((p) => Math.round((Number(p.pts_time) - offset) * 1000))
          .sort((a, b) => a - b)
        assert.deepEqual(remuxedPts, sourcePts, 'presentation timestamps drifted')
      } finally {
        await rm(dir, { recursive: true, force: true })
      }
    })

    it('maps Opus audio into an MP4 sample entry', async (t) => {
      if (!existsSync(MKV_OPUS)) return t.skip('no Opus corpus')
      if (!(await haveFfprobe())) return t.skip('ffprobe not installed')
      const { remuxer, audioParts } = await remuxPrefix(MKV_OPUS, 4)
      assert.equal(remuxer.audio?.mimeType, 'audio/mp4; codecs="opus"')

      const dir = await mkdtemp(join(tmpdir(), 'animeh-remux-'))
      try {
        const file = join(dir, 'audio.mp4')
        await writeFile(file, joinParts(audioParts))
        const streams = await probe(file)
        assert.equal(streams[0]?.codec_name, 'opus')
        assert.equal(streams[0]?.channels, 2)
      } finally {
        await rm(dir, { recursive: true, force: true })
      }
    })
  },
)
