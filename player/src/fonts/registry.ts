import { ErrorCode, PlayerError } from '../core/errors.ts'
import { fetchBytes } from '../net/fetcher.ts'
import type { EmbeddedFont, FontDescriptor } from '../core/types.ts'
import { fontKey, readFontNames } from './sfnt.ts'

export type FontOrigin =
  /** Already resolved earlier in this session. */
  | 'cache'
  /** An attachment inside the media container. */
  | 'embedded'
  /** Served by our own backend's font registry. */
  | 'server'
  /** A licensed public font source, only when one is configured. */
  | 'public'

export interface FontEntry {
  family: string
  origin: FontOrigin
  /** Present for fonts we hold bytes for. */
  data?: Uint8Array
  /** Present for fonts libass should fetch itself. */
  url?: string
  /** Where it came from, for the admin panel. */
  provider?: string
}

/** What the player reports back to the test panel and the admin UI. */
export interface FontReport {
  /** Families the subtitle script asks for. */
  required: string[]
  resolved: { family: string; origin: FontOrigin; provider?: string }[]
  /** Families nothing could supply. Drives the "⚠ Missing Fonts" panel. */
  missing: string[]
}

/**
 * Resolves a font, or reports that it cannot be found.
 *
 * Implementations must only consult sources whose licences permit
 * redistribution. There is deliberately no built-in web resolver: guessing a
 * face from an arbitrary site risks shipping a font nobody has the right to
 * serve, and a wrong-but-present font is harder to notice than a missing one.
 */
export interface PublicFontResolver {
  readonly name: string
  resolve(family: string, signal?: AbortSignal): Promise<{ url: string } | { data: Uint8Array } | null>
}

/**
 * Font resolution for ASS rendering.
 *
 * Sources are tried in order of confidence: fonts we already hold, fonts the
 * container carried, fonts our backend registered for this episode, and only
 * then an optional public resolver. Anything still unmatched is named in the
 * report rather than silently substituted.
 */
export class FontRegistry {
  #entries = new Map<string, FontEntry>()
  #publicResolver: PublicFontResolver | null = null

  /** Enable a licensed public source. None is configured by default. */
  setPublicResolver(resolver: PublicFontResolver | null): void {
    this.#publicResolver = resolver
  }

  /**
   * Add fonts carried inside the media container.
   *
   * The family name comes from the font's own name table, not its filename:
   * a script asking for "DejaVu Sans" has to match `DejaVuSans.ttf`, and only
   * the file itself knows that.
   */
  registerEmbedded(fonts: EmbeddedFont[]): string[] {
    const added: string[] = []
    for (const font of fonts) {
      const names = readFontNames(font.data)
      if (!names) {
        // Keep it anyway under its filename stem: libass matches on the font's
        // internal names regardless, so an unreadable header still renders.
        const stem = font.filename.replace(/\.[^.]+$/, '')
        this.#put({ family: stem, origin: 'embedded', data: font.data, provider: font.filename })
        added.push(stem)
        continue
      }
      for (const family of names.families) {
        this.#put({ family, origin: 'embedded', data: font.data, provider: font.filename })
        added.push(family)
      }
    }
    return added
  }

  /** Add fonts our backend published for this episode. */
  registerServerFonts(fonts: FontDescriptor[]): void {
    for (const font of fonts) {
      this.#put({ family: font.family, origin: 'server', url: font.url, provider: 'backend' })
    }
  }

  has(family: string): boolean {
    return this.#entries.has(fontKey(family))
  }

  get(family: string): FontEntry | undefined {
    return this.#entries.get(fontKey(family))
  }

  /**
   * Work out which of `required` we can supply.
   *
   * Never throws: a missing font degrades rendering, it does not stop playback.
   */
  async resolve(required: string[], signal?: AbortSignal): Promise<FontReport> {
    const resolved: FontReport['resolved'] = []
    const missing: string[] = []

    for (const family of required) {
      const existing = this.get(family)
      if (existing) {
        resolved.push({ family, origin: existing.origin, provider: existing.provider })
        continue
      }

      if (this.#publicResolver) {
        try {
          const found = await this.#publicResolver.resolve(family, signal)
          if (found) {
            const entry: FontEntry = {
              family,
              origin: 'public',
              provider: this.#publicResolver.name,
              ...('url' in found ? { url: found.url } : { data: found.data }),
            }
            this.#put(entry)
            resolved.push({ family, origin: 'public', provider: entry.provider })
            continue
          }
        } catch {
          // A resolver failure is indistinguishable from "not found" as far as
          // the viewer is concerned; both end up in the missing list.
        }
      }

      missing.push(family)
    }

    return { required: [...required], resolved, missing }
  }

  /** Download any URL-only entries so they can be handed over as bytes. */
  async materialise(family: string, signal?: AbortSignal): Promise<Uint8Array> {
    const entry = this.get(family)
    if (!entry) {
      throw new PlayerError({
        code: ErrorCode.FONT_MISSING,
        message: `No source registered for font "${family}"`,
        fatal: false,
        context: { family },
      })
    }
    if (entry.data) return entry.data
    if (!entry.url) {
      throw new PlayerError({
        code: ErrorCode.FONT_MISSING,
        message: `Font "${family}" has neither data nor a URL`,
        fatal: false,
        context: { family },
      })
    }
    const result = await fetchBytes(entry.url, { signal, retries: 2 })
    // Cache the bytes so a re-render or a later episode reuses them.
    this.#put({ ...entry, origin: 'cache', data: result.data })
    return result.data
  }

  /**
   * Fonts in the shape libass wants.
   *
   * `available` is consulted lazily by family name, so a large font pack costs
   * nothing until a script actually asks for one of its faces. `preload` is
   * reserved for fonts that must be present before the first frame renders.
   */
  libassFonts(): { available: Record<string, Uint8Array | string>; preload: (Uint8Array | string)[] } {
    const available: Record<string, Uint8Array | string> = {}
    for (const [key, entry] of this.#entries) {
      const source = entry.data ?? entry.url
      if (source) available[key] = source
    }
    return { available, preload: [] }
  }

  /** Everything we hold, for the debug overlay and the admin panel. */
  entries(): FontEntry[] {
    return [...this.#entries.values()]
  }

  clear(): void {
    this.#entries.clear()
  }

  #put(entry: FontEntry): void {
    const key = fontKey(entry.family)
    const existing = this.#entries.get(key)
    // Higher-confidence sources win; a later server font must not displace the
    // exact face the release shipped with.
    if (existing && originRank(existing.origin) <= originRank(entry.origin)) return
    this.#entries.set(key, entry)
  }
}

function originRank(origin: FontOrigin): number {
  switch (origin) {
    case 'embedded':
      return 0
    case 'cache':
      return 1
    case 'server':
      return 2
    case 'public':
      return 3
  }
}
