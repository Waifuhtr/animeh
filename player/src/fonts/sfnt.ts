/**
 * Minimal SFNT (TrueType/OpenType) name-table reader.
 *
 * An ASS script asks for a font by family name, but an attachment is just a
 * filename — and `DejaVuSans.ttf` is the family "DejaVu Sans". Matching one to
 * the other means reading the family name out of the font itself; guessing from
 * the filename gets it wrong for most real releases.
 */

/** Name IDs worth reading; see the OpenType `name` table spec. */
const NAME_ID_FAMILY = 1
const NAME_ID_FULL_NAME = 4
const NAME_ID_POSTSCRIPT = 6
const NAME_ID_TYPOGRAPHIC_FAMILY = 16

export interface FontNames {
  /** Family names this file answers to, most specific first. */
  families: string[]
  fullName: string | null
  postScriptName: string | null
}

/**
 * Read the family names from a font file.
 * Returns null when the bytes are not a font we can read.
 */
export function readFontNames(data: Uint8Array): FontNames | null {
  try {
    const view = new DataView(data.buffer, data.byteOffset, data.byteLength)
    if (data.byteLength < 12) return null

    const tag = view.getUint32(0)
    // A TrueType Collection holds several fonts; the first one names the file.
    if (tag === 0x74746366 /* 'ttcf' */) {
      if (data.byteLength < 16) return null
      const firstFontOffset = view.getUint32(12)
      return readNamesAt(view, firstFontOffset)
    }
    return readNamesAt(view, 0)
  } catch {
    // A truncated or malformed attachment must not take down the load.
    return null
  }
}

function readNamesAt(view: DataView, base: number): FontNames | null {
  const version = view.getUint32(base)
  // 0x00010000 = TrueType outlines, 'OTTO' = CFF outlines, 'true'/'typ1' legacy.
  const known =
    version === 0x00010000 ||
    version === 0x4f54544f ||
    version === 0x74727565 ||
    version === 0x74797031
  if (!known) return null

  const numTables = view.getUint16(base + 4)
  let nameOffset = -1
  let nameLength = 0
  for (let i = 0; i < numTables; i++) {
    const record = base + 12 + i * 16
    if (record + 16 > view.byteLength) return null
    const tableTag = view.getUint32(record)
    if (tableTag === 0x6e616d65 /* 'name' */) {
      nameOffset = view.getUint32(record + 8)
      nameLength = view.getUint32(record + 12)
      break
    }
  }
  if (nameOffset < 0 || nameOffset + 6 > view.byteLength) return null

  const count = view.getUint16(nameOffset + 2)
  const stringBase = nameOffset + view.getUint16(nameOffset + 4)

  const families: string[] = []
  let fullName: string | null = null
  let postScriptName: string | null = null
  // Typographic family (16) is the modern, most specific answer; keep it apart
  // so it can be preferred over the legacy family (1).
  const typographic: string[] = []

  for (let i = 0; i < count; i++) {
    const record = nameOffset + 6 + i * 12
    if (record + 12 > view.byteLength) break
    const platformId = view.getUint16(record)
    const encodingId = view.getUint16(record + 2)
    const nameId = view.getUint16(record + 6)
    const length = view.getUint16(record + 8)
    const offset = view.getUint16(record + 10)

    if (
      nameId !== NAME_ID_FAMILY &&
      nameId !== NAME_ID_FULL_NAME &&
      nameId !== NAME_ID_POSTSCRIPT &&
      nameId !== NAME_ID_TYPOGRAPHIC_FAMILY
    ) {
      continue
    }

    const start = stringBase + offset
    if (start + length > view.byteLength || start + length > nameOffset + nameLength + 65536) continue
    const value = decodeName(view, start, length, platformId, encodingId)
    if (!value) continue

    switch (nameId) {
      case NAME_ID_TYPOGRAPHIC_FAMILY:
        pushUnique(typographic, value)
        break
      case NAME_ID_FAMILY:
        pushUnique(families, value)
        break
      case NAME_ID_FULL_NAME:
        fullName ??= value
        break
      case NAME_ID_POSTSCRIPT:
        postScriptName ??= value
        break
    }
  }

  const all = [...typographic]
  for (const family of families) pushUnique(all, family)
  if (all.length === 0 && fullName) all.push(fullName)
  if (all.length === 0) return null

  return { families: all, fullName, postScriptName }
}

function pushUnique(list: string[], value: string): void {
  if (!list.some((existing) => existing.toLowerCase() === value.toLowerCase())) list.push(value)
}

function decodeName(
  view: DataView,
  start: number,
  length: number,
  platformId: number,
  encodingId: number,
): string | null {
  const bytes = new Uint8Array(view.buffer, view.byteOffset + start, length)
  // Platform 3 (Windows) and platform 0 (Unicode) store UTF-16BE. Platform 1
  // (Macintosh) with encoding 0 is MacRoman, which is ASCII for font names.
  const isUtf16 = platformId === 3 || platformId === 0
  try {
    const text = isUtf16
      ? new TextDecoder('utf-16be').decode(bytes)
      : new TextDecoder(encodingId === 0 ? 'windows-1252' : 'utf-8').decode(bytes)
    const trimmed = text.replace(/\0/g, '').trim()
    return trimmed.length > 0 ? trimmed : null
  } catch {
    return null
  }
}

/** Case- and space-insensitive key, matching how libass compares family names. */
export function fontKey(family: string): string {
  return family.toLowerCase().replace(/\s+/g, ' ').trim()
}
