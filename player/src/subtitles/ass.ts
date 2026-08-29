/**
 * ASS/SSA script handling.
 *
 * Rendering is libass's job — this module only reads enough structure to know
 * which fonts a script needs, and to rebuild a playable script from the split
 * form Matroska stores.
 */

export interface AssStyle {
  name: string
  fontName: string
  fontSize: number
  bold: boolean
  italic: boolean
}

export interface AssScriptInfo {
  title: string | null
  scriptType: string | null
  playResX: number | null
  playResY: number | null
  wrapStyle: number | null
}

export interface ParsedAss {
  info: AssScriptInfo
  styles: AssStyle[]
  /** Field names from the `[Events]` Format line. */
  eventFormat: string[]
  dialogueCount: number
}

const SECTION_RE = /^\s*\[([^\]]+)\]\s*$/

/** Split a script into its sections, preserving line order. */
function sections(content: string): Map<string, string[]> {
  const result = new Map<string, string[]>()
  let current: string[] | null = null
  // Strip a BOM: libass copes, but our own field parsing would not.
  for (const rawLine of content.replace(/^﻿/, '').split(/\r?\n/)) {
    const match = SECTION_RE.exec(rawLine)
    if (match) {
      current = []
      result.set(match[1]!.trim().toLowerCase(), current)
      continue
    }
    current?.push(rawLine)
  }
  return result
}

/** `Key: value` — the shape of every non-section ASS line. */
function splitEntry(line: string): { key: string; value: string } | null {
  const index = line.indexOf(':')
  if (index < 0) return null
  return { key: line.slice(0, index).trim(), value: line.slice(index + 1).trim() }
}

export function parseAss(content: string): ParsedAss {
  const map = sections(content)
  const info: AssScriptInfo = {
    title: null,
    scriptType: null,
    playResX: null,
    playResY: null,
    wrapStyle: null,
  }

  for (const line of map.get('script info') ?? []) {
    // `;` opens a comment line in Script Info.
    if (line.trimStart().startsWith(';')) continue
    const entry = splitEntry(line)
    if (!entry) continue
    switch (entry.key.toLowerCase()) {
      case 'title':
        info.title = entry.value
        break
      case 'scripttype':
        info.scriptType = entry.value
        break
      case 'playresx':
        info.playResX = toNumber(entry.value)
        break
      case 'playresy':
        info.playResY = toNumber(entry.value)
        break
      case 'wrapstyle':
        info.wrapStyle = toNumber(entry.value)
        break
    }
  }

  const styles: AssStyle[] = []
  // `[V4+ Styles]` is ASS; `[V4 Styles]` is the older SSA spelling.
  const styleLines = map.get('v4+ styles') ?? map.get('v4 styles') ?? []
  let styleFormat: string[] = []
  for (const line of styleLines) {
    const entry = splitEntry(line)
    if (!entry) continue
    if (entry.key.toLowerCase() === 'format') {
      styleFormat = entry.value.split(',').map((field) => field.trim().toLowerCase())
    } else if (entry.key.toLowerCase() === 'style' && styleFormat.length > 0) {
      const style = parseStyle(styleFormat, entry.value)
      if (style) styles.push(style)
    }
  }

  let eventFormat: string[] = []
  let dialogueCount = 0
  for (const line of map.get('events') ?? []) {
    const entry = splitEntry(line)
    if (!entry) continue
    const key = entry.key.toLowerCase()
    if (key === 'format') {
      eventFormat = entry.value.split(',').map((field) => field.trim())
    } else if (key === 'dialogue') {
      dialogueCount++
    }
  }

  return { info, styles, eventFormat, dialogueCount }
}

function parseStyle(format: string[], value: string): AssStyle | null {
  // Style values are comma-separated and positional; only the last field
  // (Encoding) can never contain a comma, so a plain split is safe here.
  const fields = value.split(',')
  const get = (name: string): string => {
    const index = format.indexOf(name)
    return index >= 0 ? (fields[index] ?? '').trim() : ''
  }
  const name = get('name')
  if (!name) return null
  return {
    name,
    fontName: normaliseFontName(get('fontname')),
    fontSize: toNumber(get('fontsize')) ?? 0,
    // ASS booleans are -1 for true, 0 for false.
    bold: get('bold') === '-1' || get('bold') === '1',
    italic: get('italic') === '-1' || get('italic') === '1',
  }
}

function toNumber(value: string): number | null {
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) ? parsed : null
}

/**
 * Strip the decorations ASS allows around a font name.
 *
 * A leading `@` requests the vertical-writing variant of a CJK face; the family
 * being asked for is the same one either way.
 */
export function normaliseFontName(name: string): string {
  return name.trim().replace(/^@/, '').trim()
}

/**
 * Every font family a script can ask for.
 *
 * Two places name fonts: the `Fontname` column of each style, and `\fn`
 * override tags inside dialogue text. Missing either one means a line renders
 * in the wrong face at playback time, which is exactly the failure the font
 * report exists to catch before a viewer sees it.
 */
export function collectFontFamilies(content: string): string[] {
  const families = new Set<string>()

  for (const style of parseAss(content).styles) {
    if (style.fontName) families.add(style.fontName)
  }

  // `\fn` runs until the next backslash or the end of the override block.
  for (const match of content.matchAll(/\\fn([^\\}]*)/g)) {
    const name = normaliseFontName(match[1] ?? '')
    if (name) families.add(name)
  }

  return [...families].sort((a, b) => a.localeCompare(b))
}

/* ── Matroska embedded ASS ──────────────────────────────────────────────── */

/** One `Dialogue` event recovered from a Matroska block. */
export interface AssBlockEvent {
  /** Seconds. */
  start: number
  end: number
  /** The block payload: everything after `ReadOrder`. */
  fields: string
  /** ASS sorts equal-timed events by the muxer's original read order. */
  readOrder: number
}

/**
 * Rebuild a `Dialogue` line from a Matroska subtitle block.
 *
 * Matroska splits an ASS event: timing moves into the block header, and the
 * payload keeps only `ReadOrder,Layer,Style,Name,MarginL,MarginR,MarginV,
 * Effect,Text`. Reassembling means dropping ReadOrder and splicing the block's
 * own timestamps back into the positions the format expects.
 */
export function blockToDialogue(payload: string, startSec: number, endSec: number): AssBlockEvent | null {
  // Only the first eight commas are structural; the ninth field is the text,
  // which routinely contains commas of its own.
  const parts = splitLimit(payload, ',', 9)
  if (parts.length < 9) return null

  const readOrder = Number.parseInt(parts[0] ?? '0', 10)
  const rest = parts.slice(1).join(',')
  return {
    start: startSec,
    end: endSec,
    fields: rest,
    readOrder: Number.isFinite(readOrder) ? readOrder : 0,
  }
}

function splitLimit(value: string, separator: string, limit: number): string[] {
  const parts: string[] = []
  let start = 0
  while (parts.length < limit - 1) {
    const index = value.indexOf(separator, start)
    if (index < 0) break
    parts.push(value.slice(start, index))
    start = index + 1
  }
  parts.push(value.slice(start))
  return parts
}

/** `H:MM:SS.cc` — ASS timestamps are centisecond-precision. */
export function formatAssTime(seconds: number): string {
  const clamped = Math.max(0, seconds)
  const hours = Math.floor(clamped / 3600)
  const minutes = Math.floor((clamped % 3600) / 60)
  const secs = Math.floor(clamped % 60)
  const centis = Math.round((clamped - Math.floor(clamped)) * 100)
  // Rounding can carry into the next second; normalise rather than emit ".100".
  if (centis === 100) return formatAssTime(Math.floor(clamped) + 1)
  return `${hours}:${pad(minutes)}:${pad(secs)}.${pad(centis)}`
}

function pad(value: number): string {
  return String(value).padStart(2, '0')
}

/**
 * Assemble a complete script from a Matroska track's CodecPrivate header and
 * the events recovered from its blocks.
 */
export function buildAssScript(header: string, events: AssBlockEvent[]): string {
  const ordered = [...events].sort((a, b) => a.start - b.start || a.readOrder - b.readOrder)
  const lines = ordered.map(
    (event) =>
      `Dialogue: ${spliceTiming(event.fields, formatAssTime(event.start), formatAssTime(event.end))}`,
  )

  // CodecPrivate ends with the [Events] section's Format line, so the dialogue
  // lines append directly onto it.
  const base = header.replace(/\s*$/, '')
  return `${base}\n${lines.join('\n')}\n`
}

/**
 * Insert Start and End into a block's field list.
 *
 * The block payload is `Layer,Style,Name,MarginL,…`; a Dialogue line is
 * `Layer,Start,End,Style,Name,MarginL,…`, so timing goes in after Layer.
 */
function spliceTiming(fields: string, start: string, end: string): string {
  const index = fields.indexOf(',')
  if (index < 0) return `0,${start},${end},${fields}`
  const layer = fields.slice(0, index)
  return `${layer},${start},${end},${fields.slice(index + 1)}`
}

/* ── SubRip ─────────────────────────────────────────────────────────────── */

const SRT_TIME_RE = /(\d+):(\d{2}):(\d{2})[,.](\d{3})\s*-->\s*(\d+):(\d{2}):(\d{2})[,.](\d{3})/

/**
 * Convert SubRip to ASS.
 *
 * Everything renders through libass, so a plain-text format is lifted into a
 * minimal script rather than handled by a second rendering path. One renderer
 * means one set of positioning, scaling and font behaviours to reason about.
 */
export function srtToAss(srt: string, fontName = 'Sans', fontSize = 48): string {
  const header = [
    '[Script Info]',
    'ScriptType: v4.00+',
    'WrapStyle: 0',
    'ScaledBorderAndShadow: yes',
    'PlayResX: 1920',
    'PlayResY: 1080',
    '',
    '[V4+ Styles]',
    'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
    `Style: Default,${fontName},${fontSize},&H00FFFFFF,&H000000FF,&H00000000,&H80000000,0,0,0,0,100,100,0,0,1,3,1,2,80,80,60,1`,
    '',
    '[Events]',
    'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
  ].join('\n')

  const lines: string[] = []
  for (const block of srt.replace(/^﻿/, '').split(/\r?\n\r?\n/)) {
    const rows = block.split(/\r?\n/).filter((row) => row.trim() !== '')
    if (rows.length < 2) continue
    const timeRow = rows.find((row) => SRT_TIME_RE.test(row))
    if (!timeRow) continue
    const match = SRT_TIME_RE.exec(timeRow)!
    const start = srtTime(match[1]!, match[2]!, match[3]!, match[4]!)
    const end = srtTime(match[5]!, match[6]!, match[7]!, match[8]!)
    const textRows = rows.slice(rows.indexOf(timeRow) + 1)
    const text = textRows
      .join('\\N')
      // SubRip's inline HTML has direct ASS override equivalents.
      .replace(/<i>/gi, '{\\i1}')
      .replace(/<\/i>/gi, '{\\i0}')
      .replace(/<b>/gi, '{\\b1}')
      .replace(/<\/b>/gi, '{\\b0}')
      .replace(/<u>/gi, '{\\u1}')
      .replace(/<\/u>/gi, '{\\u0}')
      .replace(/<[^>]+>/g, '')
    lines.push(`Dialogue: 0,${formatAssTime(start)},${formatAssTime(end)},Default,,0,0,0,,${text}`)
  }

  return `${header}\n${lines.join('\n')}\n`
}

function srtTime(h: string, m: string, s: string, ms: string): number {
  return Number(h) * 3600 + Number(m) * 60 + Number(s) + Number(ms) / 1000
}
