import { AnimehPlayer, type AnimehPlayerOptions, type PlayerEvents } from './core/controller.ts'
import { PlayerUI } from './ui/player-ui.ts'
import type { SubtitleEngineOptions } from './subtitles/engine.ts'

export { AnimehPlayer } from './core/controller.ts'
export { PlayerUI } from './ui/player-ui.ts'
export { PlayerError, ErrorCode } from './core/errors.ts'
export { SubtitleEngine } from './subtitles/engine.ts'
export { FontRegistry } from './fonts/registry.ts'
export { HlsEngine } from './engines/hls-engine.ts'
export { MkvEngine } from './engines/mkv-engine.ts'
export { LocalResumeStore } from './core/resume.ts'
export { collectFontFamilies, parseAss, srtToAss } from './subtitles/ass.ts'
export { readMkvHeader, readAttachments, ClusterStream } from './mkv/demuxer.ts'
export { MkvRemuxer } from './mkv/remuxer.ts'
export { classifyNetwork, bufferProfileFor } from './net/policy.ts'
export type { AnimehPlayerOptions, PlayerEvents }
export type * from './core/types.ts'
export type { FontReport, FontEntry, PublicFontResolver } from './fonts/registry.ts'
export type { ResumeStore, ResumeRecord } from './core/resume.ts'
export type { SubtitleEngineOptions } from './subtitles/engine.ts'

export interface CreatePlayerOptions
  extends Omit<AnimehPlayerOptions, 'video' | 'subtitleCanvas'> {
  subtitles: SubtitleEngineOptions
  /** Rendered controls. Pass false to drive the player entirely from code. */
  ui?: boolean
  /** Forwarded to the <video> element. */
  crossOrigin?: '' | 'anonymous' | 'use-credentials'
  poster?: string
}

export interface MountedPlayer {
  player: AnimehPlayer
  ui: PlayerUI | null
  video: HTMLVideoElement
  root: HTMLElement
  destroy(): Promise<void>
}

/**
 * Build the player's DOM inside `container` and wire it up.
 *
 * The elements are created here rather than expected in the page so that the
 * subtitle canvas is guaranteed to sit directly over the video at the same
 * size — libass positions by pixel, and a layout the host page controls would
 * put every sign in the wrong place.
 */
export function createPlayer(container: HTMLElement, options: CreatePlayerOptions): MountedPlayer {
  const root = document.createElement('div')
  root.className = 'animeh'

  const stage = document.createElement('div')
  stage.className = 'animeh__stage'

  const video = document.createElement('video')
  video.className = 'animeh__video'
  video.playsInline = true
  video.preload = 'metadata'
  if (options.crossOrigin !== undefined) video.crossOrigin = options.crossOrigin
  if (options.poster) video.poster = options.poster

  const canvas = document.createElement('canvas')
  canvas.className = 'animeh__subtitles'

  stage.append(video, canvas)
  root.append(stage)
  container.append(root)

  const player = new AnimehPlayer({ ...options, video, subtitleCanvas: canvas })
  const ui = options.ui === false ? null : new PlayerUI(player, root)

  return {
    player,
    ui,
    video,
    root,
    async destroy() {
      ui?.destroy()
      await player.destroy()
      root.remove()
    },
  }
}
