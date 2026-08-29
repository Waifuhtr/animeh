import JASSUB from 'jassub'
import { Emitter } from '../core/emitter.ts'
import { ErrorCode, PlayerError } from '../core/errors.ts'
import { FontRegistry, type FontReport } from '../fonts/registry.ts'
import { fontKey } from '../fonts/sfnt.ts'
import type { EmbeddedFont, FontDescriptor } from '../core/types.ts'
import { collectFontFamilies, srtToAss } from './ass.ts'

export interface SubtitleEngineOptions {
  /** Bundled libass worker. */
  workerUrl: string
  /** Non-SIMD wasm build. */
  wasmUrl: string
  /** SIMD wasm build, used where the browser supports it. */
  modernWasmUrl?: string
  /** Family libass falls back to when a script names a font we do not have. */
  fallbackFont?: string
}

export interface SubtitleEngineEvents {
  /** Emitted whenever the set of required or missing fonts changes. */
  fontReport: FontReport
  error: PlayerError
}

export type SubtitleSource =
  | { kind: 'script'; format: 'ass' | 'ssa' | 'srt' | 'vtt'; content: string }
  /** An ASS header whose events arrive later, block by block. */
  | { kind: 'header'; format: 'ass' | 'ssa'; header: string }

/**
 * ASS/SSA rendering.
 *
 * Rendering runs through libass (compiled to WebAssembly) rather than any
 * text-layout code of our own: karaoke timing, `\pos`, rotation, per-glyph
 * outlines and drawing commands are the whole point of the format, and a
 * DOM-based approximation gets them wrong in ways viewers notice immediately.
 *
 * What is ours is everything around it — which fonts a script needs, where
 * they come from, and what to report when one cannot be found.
 */
export class SubtitleEngine {
  readonly events = new Emitter<SubtitleEngineEvents>()
  readonly fonts = new FontRegistry()

  #options: SubtitleEngineOptions
  #renderer: JASSUB | null = null
  #video: HTMLVideoElement | null = null
  #canvas: HTMLCanvasElement | null = null
  #currentSource: SubtitleSource | null = null
  /**
   * Families seen so far, keyed for matching but holding the name as the
   * script spelled it — a report that says "dejavu sans" is no use to an
   * operator looking for the file to upload.
   */
  #knownFamilies = new Map<string, string>()
  #lastReport: FontReport | null = null
  #destroyed = false
  #ready: Promise<void> | null = null
  /**
   * Events that arrived before libass was ready.
   *
   * A container interleaves subtitles with media, so blocks start flowing the
   * moment playback does — well before the wasm renderer has finished starting.
   * Dropping them would silently lose the opening lines of an episode.
   */
  #blockQueue: { payload: string; startMs: number; durationMs: number }[] = []

  constructor(options: SubtitleEngineOptions) {
    this.#options = options
  }

  /** Whether the browser can run the wasm renderer at all. */
  static isSupported(): boolean {
    return typeof WebAssembly === 'object' && typeof Worker === 'function'
  }

  attach(video: HTMLVideoElement, canvas: HTMLCanvasElement): void {
    this.#video = video
    this.#canvas = canvas
  }

  /** Fonts published by the backend for this episode. */
  registerServerFonts(fonts: FontDescriptor[]): void {
    this.fonts.registerServerFonts(fonts)
  }

  /**
   * Add fonts the container carried.
   *
   * These are the exact faces the release was typeset against, so they are
   * pushed into libass immediately rather than waiting to be asked for.
   */
  async addEmbeddedFonts(fonts: EmbeddedFont[]): Promise<void> {
    if (fonts.length === 0) return
    this.fonts.registerEmbedded(fonts)
    // Attachments often arrive before a track is selected. Registering them is
    // enough in that case: the renderer reads the registry when it starts.
    // Once it exists, its worker proxy is only usable after `ready` resolves.
    const renderer = this.#renderer
    if (renderer) {
      try {
        await this.#ready
        await renderer.renderer.addFonts(fonts.map((font) => font.data))
      } catch (err) {
        this.events.emit(
          'error',
          new PlayerError({
            code: ErrorCode.FONT_MISSING,
            message: `libass rejected an embedded font: ${(err as Error).message}`,
            fatal: false,
          }),
        )
      }
    }
    await this.#refreshFontReport()
  }

  /** Load a subtitle track. Passing null clears the current one. */
  async setSource(source: SubtitleSource | null): Promise<void> {
    this.#currentSource = source
    this.#knownFamilies.clear()

    if (!source) {
      this.#blockQueue = []
      await this.#ready?.catch(() => {})
      try {
        await this.#renderer?.renderer.freeTrack()
      } catch {
        // Nothing loaded yet.
      }
      return
    }

    const script = this.#toAss(source)
    await this.#ensureRenderer(script)
    if (this.#destroyed) return

    try {
      await this.#renderer!.renderer.setTrack(script)
      // The track exists now, so anything that queued during startup can go in.
      await this.#flushBlockQueue()
    } catch (err) {
      throw new PlayerError({
        code: ErrorCode.SUBTITLE_ERROR,
        message: `libass could not load the script: ${(err as Error).message}`,
        fatal: false,
        cause: err,
      })
    }

    await this.#noteFamilies(script)
  }

  /**
   * Feed one event from a container that interleaves subtitles with media.
   *
   * Matroska stores each event separately with its timing in the block header,
   * so events arrive as playback streams rather than all at once. libass
   * consumes exactly this form, which avoids rebuilding and re-parsing the
   * whole script every time a line appears.
   */
  pushBlock(payload: string, startMs: number, durationMs: number): void {
    if (this.#destroyed) return
    // Events can name fonts the styles never mention, via `\fn` overrides.
    void this.#noteFamilies(payload)

    const renderer = this.#renderer
    if (!renderer) {
      // Bounded: a runaway queue would mean the renderer never started, and
      // holding a whole episode of dialogue helps nobody.
      if (this.#blockQueue.length < 500) {
        this.#blockQueue.push({ payload, startMs, durationMs })
      }
      return
    }
    void this.#deliver(renderer, payload, startMs, durationMs)
  }

  async #deliver(
    renderer: JASSUB,
    payload: string,
    startMs: number,
    durationMs: number,
  ): Promise<void> {
    try {
      await this.#ready
      if (this.#destroyed) return
      await renderer.renderer.processChunk(payload, startMs, durationMs)
    } catch {
      // A malformed event must not interrupt playback.
    }
  }

  /** Hand libass everything that queued up while it was starting. */
  async #flushBlockQueue(): Promise<void> {
    const renderer = this.#renderer
    if (!renderer || this.#blockQueue.length === 0) return
    const queued = this.#blockQueue
    this.#blockQueue = []
    for (const block of queued) {
      await this.#deliver(renderer, block.payload, block.startMs, block.durationMs)
    }
  }

  /** Nudge subtitle timing, in seconds. Positive shows lines later. */
  setTimeOffset(seconds: number): void {
    if (this.#renderer) this.#renderer.timeOffset = seconds
  }

  get report(): FontReport | null {
    return this.#lastReport
  }

  async destroy(): Promise<void> {
    this.#destroyed = true
    this.events.removeAll()
    const renderer = this.#renderer
    this.#renderer = null
    if (renderer) {
      try {
        await renderer.destroy()
      } catch {
        // Already gone.
      }
    }
  }

  /* ── Internals ────────────────────────────────────────────────────────── */

  #toAss(source: SubtitleSource): string {
    if (source.kind === 'header') return source.header
    switch (source.format) {
      case 'ass':
      case 'ssa':
        return source.content
      case 'srt':
        return srtToAss(source.content, this.#options.fallbackFont ?? 'Sans')
      case 'vtt':
        // WebVTT's cue syntax is close enough to SubRip's for the timing and
        // text to convert directly once the header and cue ids are dropped.
        return srtToAss(vttToSrt(source.content), this.#options.fallbackFont ?? 'Sans')
    }
  }

  async #ensureRenderer(initialScript: string): Promise<void> {
    if (this.#renderer) {
      await this.#ready
      return
    }
    const video = this.#video
    const canvas = this.#canvas
    if (!video || !canvas) throw new Error('attach() must be called before setSource()')

    const { available } = this.fonts.libassFonts()
    const renderer = new JASSUB({
      video,
      canvas,
      subContent: initialScript,
      workerUrl: this.#options.workerUrl,
      wasmUrl: this.#options.wasmUrl,
      ...(this.#options.modernWasmUrl ? { modernWasmUrl: this.#options.modernWasmUrl } : {}),
      availableFonts: available,
      defaultFont: this.#options.fallbackFont ?? 'Sans',
      // Local and remote font querying is off by default: it reaches for faces
      // outside the sources we vetted, and a silently substituted font is
      // harder to notice than a missing one.
      queryFonts: false,
      // Rendering above the video's own height wastes work on a phone.
      prescaleFactor: 1,
      dropAllAnimations: false,
    } as ConstructorParameters<typeof JASSUB>[0])

    this.#renderer = renderer
    this.#ready = renderer.ready
    await this.#ready
  }

  /** Track which families a script mentions and re-resolve when new ones appear. */
  async #noteFamilies(script: string): Promise<void> {
    const families = collectFontFamilies(script)
    let changed = false
    for (const family of families) {
      const key = fontKey(family)
      if (!this.#knownFamilies.has(key)) {
        this.#knownFamilies.set(key, family)
        changed = true
      }
    }
    if (changed) await this.#refreshFontReport()
  }

  async #refreshFontReport(): Promise<void> {
    if (this.#knownFamilies.size === 0) return
    const required = [...this.#knownFamilies.values()].sort((a, b) => a.localeCompare(b))
    const report = await this.fonts.resolve(required)

    // Publish first. Which fonts are needed and which are missing is known as
    // soon as resolution finishes; making the report wait on a multi-megabyte
    // download would leave the admin panel blank for exactly as long as the
    // connection is slow, which is when it is most wanted.
    this.#lastReport = report
    this.events.emit('fontReport', report)

    // Then fetch the ones libass does not already hold, in the background.
    void this.#materialisePending(report.resolved)
  }

  async #materialisePending(
    resolved: FontReport['resolved'],
  ): Promise<void> {
    const remote = resolved.filter((entry) => entry.origin === 'server' || entry.origin === 'public')
    if (remote.length === 0) return
    await Promise.all(
      remote.map(async (entry) => {
        try {
          const data = await this.fonts.materialise(entry.family)
          if (this.#destroyed) return
          await this.#ready
          await this.#renderer?.renderer.addFonts([data])
        } catch {
          // The face stays unavailable; libass falls back and the next report
          // still names it as resolved-but-unfetched rather than missing.
        }
      }),
    )
  }

  /** The script currently loaded, for the debug panel. */
  get currentSource(): SubtitleSource | null {
    return this.#currentSource
  }
}

/** Strip WebVTT framing so the SubRip converter can handle the cues. */
function vttToSrt(vtt: string): string {
  return vtt
    .replace(/^WEBVTT[^\n]*\n/, '')
    // NOTE, STYLE and REGION blocks carry no cue text.
    .replace(/^(NOTE|STYLE|REGION)[\s\S]*?(\n\n|$)/gm, '')
    // WebVTT allows cue settings after the timing; they have no SubRip meaning.
    .replace(/([\d:.]+\s*-->\s*[\d:.]+)[^\n]*/g, '$1')
}
