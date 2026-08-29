import { strict as assert } from 'node:assert'
import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import {
  blockToDialogue,
  buildAssScript,
  collectFontFamilies,
  formatAssTime,
  parseAss,
  srtToAss,
} from '../src/subtitles/ass.ts'
import { FontRegistry } from '../src/fonts/registry.ts'
import { readFontNames, fontKey } from '../src/fonts/sfnt.ts'
import {
  ClusterStream,
  isSubtitleTrack,
  readAttachments,
  readMkvHeader,
  type MkvTrack,
} from '../src/mkv/demuxer.ts'
import { MEDIA_DIR, fileRangeReader } from './helpers.ts'

const ASS_PATH = join(MEDIA_DIR, 'subtitle.ass')
const MKV = join(MEDIA_DIR, 'episode.mkv')
const corpusReady = existsSync(ASS_PATH) && existsSync(MKV)

describe('ASS parsing', { skip: corpusReady ? false : 'run tools/make-test-media.sh first' }, () => {
  const script = readFileSync(ASS_PATH, 'utf8')

  it('reads script info and styles', () => {
    const parsed = parseAss(script)
    assert.equal(parsed.info.playResX, 1920)
    assert.equal(parsed.info.playResY, 1080)
    assert.equal(parsed.info.scriptType, 'v4.00+')
    assert.equal(parsed.styles.length, 5)

    const karaoke = parsed.styles.find((s) => s.name === 'Karaoke')
    assert.ok(karaoke)
    assert.equal(karaoke.fontName, 'DejaVu Sans Mono')
    assert.equal(karaoke.fontSize, 60)
    assert.equal(karaoke.bold, true)

    const italics = parsed.styles.find((s) => s.name === 'Italics')
    assert.equal(italics?.italic, true)

    assert.ok(parsed.dialogueCount >= 12)
    assert.equal(parsed.eventFormat[0], 'Layer')
  })

  it('collects every font family the script can ask for', () => {
    const families = collectFontFamilies(script)
    assert.deepEqual(families, [
      'Animeh Nonexistent Gothic',
      'DejaVu Sans',
      'DejaVu Sans Mono',
      'DejaVu Serif',
    ])
  })

  it('picks up fonts named only by an inline override tag', () => {
    const withOverride = `${script}\nDialogue: 0,0:01:00.00,0:01:05.00,Default,,0,0,0,,{\\fnRoboto Slab\\b1}override font\n`
    const families = collectFontFamilies(withOverride)
    assert.ok(families.includes('Roboto Slab'), 'missed a \\fn override')
  })

  it('strips the vertical-writing prefix from font names', () => {
    const vertical = 'Dialogue: 0,0:00:00.00,0:00:01.00,Default,,0,0,0,,{\\fn@MS Gothic}text'
    assert.deepEqual(collectFontFamilies(vertical), ['MS Gothic'])
  })

  it('formats timestamps the way ASS expects', () => {
    assert.equal(formatAssTime(0), '0:00:00.00')
    assert.equal(formatAssTime(65.43), '0:01:05.43')
    assert.equal(formatAssTime(3661.5), '1:01:01.50')
    // Rounding to centiseconds must carry into the next second, not emit ".100".
    assert.equal(formatAssTime(1.999), '0:00:02.00')
    assert.equal(formatAssTime(-5), '0:00:00.00')
  })
})

describe('SubRip conversion', () => {
  it('lifts SRT into a renderable ASS script', () => {
    const srt = [
      '1',
      '00:00:01,000 --> 00:00:03,500',
      'First line',
      'second row',
      '',
      '2',
      '00:00:04,000 --> 00:00:06,000',
      '<i>italic</i> and <b>bold</b>',
      '',
    ].join('\n')

    const ass = srtToAss(srt)
    const parsed = parseAss(ass)
    assert.equal(parsed.dialogueCount, 2)
    assert.match(ass, /Dialogue: 0,0:00:01\.00,0:00:03\.50,Default,,0,0,0,,First line\\Nsecond row/)
    assert.match(ass, /\{\\i1\}italic\{\\i0\} and \{\\b1\}bold\{\\b0\}/)
  })
})

describe(
  'embedded subtitles and fonts',
  { skip: corpusReady ? false : 'run tools/make-test-media.sh first' },
  () => {
    it('rebuilds a playable script from Matroska blocks', async () => {
      const { read, size, close } = await fileRangeReader(MKV)
      try {
        const header = await readMkvHeader(read, size)
        const subtitle = header.tracks.find(isSubtitleTrack)!
        const codecPrivate = new TextDecoder().decode(subtitle.codecPrivate!)

        const stream = new ClusterStream(
          new Map<number, MkvTrack>([[subtitle.number, subtitle]]),
          header.timestampScale,
        )
        // Subtitle blocks are sparse and spread across the whole file, so the
        // entire cluster range is read to recover every event.
        const start = header.firstClusterOffset!
        const bytes = await read(start, size)
        const result = stream.push(bytes, start)

        const events = result.frames.flatMap((frame) => {
          const payload = new TextDecoder().decode(frame.data)
          const startSec = frame.timestampNs / 1e9
          const endSec = startSec + (frame.durationNs ?? 0) / 1e9
          const event = blockToDialogue(payload, startSec, endSec)
          return event ? [event] : []
        })
        // The source script has twelve dialogue lines; all of them must survive
        // the trip through Matroska and back.
        assert.equal(events.length, 12, `expected 12 events, got ${events.length}`)

        const rebuilt = buildAssScript(codecPrivate, events)
        const parsed = parseAss(rebuilt)

        // The reassembled script must carry the original styling and events.
        assert.equal(parsed.styles.length, 5)
        assert.equal(parsed.dialogueCount, events.length)
        assert.equal(parsed.info.playResX, 1920)
        // ffmpeg shifts every stream a few milliseconds when muxing to keep
        // decode times non-negative, so timing is checked with tolerance while
        // the fields around it are checked exactly.
        const firstEvent = events[0]!
        assert.ok(
          Math.abs(firstEvent.start - 0.5) < 0.05,
          `first event starts at ${firstEvent.start}, expected ~0.5`,
        )
        assert.ok(Math.abs(firstEvent.end - 5.0) < 0.05)
        assert.match(
          rebuilt,
          /Dialogue: 0,0:00:00\.\d\d,0:00:05\.\d\d,Default,,0,0,0,,Animeh player test/,
        )
        assert.match(rebuilt, /Dialogue: 0,[^,]+,[^,]+,Sign,,0,0,0,,\{\\an8\}Top-aligned sign/)
        // Karaoke and positioning overrides must survive untouched.
        assert.match(rebuilt, /\{\\k50\}Ka\{\\k50\}ra/)
        assert.match(rebuilt, /\{\\pos\(300,300\)\}/)
        assert.match(rebuilt, /çğıöşü/)

        // And the font requirements are unchanged from the standalone script.
        assert.deepEqual(collectFontFamilies(rebuilt), [
          'Animeh Nonexistent Gothic',
          'DejaVu Sans',
          'DejaVu Sans Mono',
          'DejaVu Serif',
        ])
      } finally {
        await close()
      }
    })

    it('names attached fonts by their family, not their filename', async () => {
      const { read, size, close } = await fileRangeReader(MKV)
      try {
        const header = await readMkvHeader(read, size)
        const attachments = await readAttachments(header, read, size)
        const names = attachments.map((font) => readFontNames(font.data))

        for (const parsed of names) assert.ok(parsed, 'could not read a font name table')
        const families = names.flatMap((parsed) => parsed!.families)
        // The filenames are DejaVuSans.ttf etc; the families have spaces.
        assert.ok(families.includes('DejaVu Sans'))
        assert.ok(families.includes('DejaVu Serif'))
        assert.ok(families.includes('DejaVu Sans Mono'))
      } finally {
        await close()
      }
    })

    it('resolves what the container carries and reports only the true gap', async () => {
      const { read, size, close } = await fileRangeReader(MKV)
      try {
        const header = await readMkvHeader(read, size)
        const attachments = await readAttachments(header, read, size)

        const registry = new FontRegistry()
        registry.registerEmbedded(attachments)

        const required = collectFontFamilies(readFileSync(ASS_PATH, 'utf8'))
        const report = await registry.resolve(required)

        assert.deepEqual(report.missing, ['Animeh Nonexistent Gothic'])
        assert.equal(report.resolved.length, 3)
        for (const entry of report.resolved) assert.equal(entry.origin, 'embedded')

        // libass is handed the fonts keyed the way it compares family names.
        const { available } = registry.libassFonts()
        assert.ok(available[fontKey('DejaVu Sans')] instanceof Uint8Array)
      } finally {
        await close()
      }
    })

    it('prefers the release’s own font over a backend substitute', async () => {
      const { read, size, close } = await fileRangeReader(MKV)
      try {
        const header = await readMkvHeader(read, size)
        const attachments = await readAttachments(header, read, size)

        const registry = new FontRegistry()
        // Backend registration arrives first; the attachment must still win,
        // because it is the exact face the release was typeset against.
        registry.registerServerFonts([
          { family: 'DejaVu Sans', url: 'https://cdn.example/dejavu-sans.ttf' },
        ])
        registry.registerEmbedded(attachments)

        const entry = registry.get('DejaVu Sans')
        assert.equal(entry?.origin, 'embedded')
        assert.ok(entry?.data, 'embedded entry should carry bytes')
      } finally {
        await close()
      }
    })

    it('falls back to a backend font for a family the container lacks', async () => {
      const registry = new FontRegistry()
      registry.registerServerFonts([
        { family: 'Animeh Nonexistent Gothic', url: 'https://cdn.example/gothic.ttf' },
      ])
      const report = await registry.resolve(['Animeh Nonexistent Gothic', 'Totally Absent'])
      assert.deepEqual(report.missing, ['Totally Absent'])
      assert.equal(report.resolved[0]?.origin, 'server')
    })
  },
)
