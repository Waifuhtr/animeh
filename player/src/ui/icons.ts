/**
 * Inline SVG icons.
 *
 * Kept as strings rather than pulled from an icon font or a sprite sheet: the
 * player has to drop into a WordPress page and, later, be read alongside a
 * Compose implementation, and a self-contained string has no loading order to
 * get wrong.
 */
const svg = (paths: string, viewBox = '0 0 24 24'): string =>
  `<svg viewBox="${viewBox}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`

export const icons = {
  play: svg('<path d="M7 4.5v15l12-7.5z" fill="currentColor" stroke="none"/>'),
  pause: svg('<rect x="6" y="4.5" width="4" height="15" rx="1.2" fill="currentColor" stroke="none"/><rect x="14" y="4.5" width="4" height="15" rx="1.2" fill="currentColor" stroke="none"/>'),
  replay: svg('<path d="M12 5V2L8 6l4 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7z" fill="currentColor" stroke="none"/>'),
  back: svg('<path d="M15 19l-7-7 7-7"/>'),
  // A three-quarter circle with the arrowhead in the gap, and the step size
  // written inside. Drawn as an explicit arc rather than a rotated glyph so the
  // direction reads correctly at the small sizes phone controls use.
  rewind10: svg(
    '<path d="M12 5A8 8 0 1 1 4 13"/>' +
      '<path d="M12 1.6 8.2 5 12 8.4z" fill="currentColor" stroke="none"/>' +
      '<text x="12" y="17" font-size="9" font-family="system-ui, sans-serif" font-weight="700" text-anchor="middle" fill="currentColor" stroke="none">10</text>',
  ),
  forward10: svg(
    '<path d="M12 5A8 8 0 1 0 20 13"/>' +
      '<path d="M12 1.6 15.8 5 12 8.4z" fill="currentColor" stroke="none"/>' +
      '<text x="12" y="17" font-size="9" font-family="system-ui, sans-serif" font-weight="700" text-anchor="middle" fill="currentColor" stroke="none">10</text>',
  ),
  previous: svg('<path d="M18 5v14L8 12z" fill="currentColor" stroke="none"/><rect x="5" y="5" width="2.2" height="14" rx="1" fill="currentColor" stroke="none"/>'),
  next: svg('<path d="M6 5v14l10-7z" fill="currentColor" stroke="none"/><rect x="16.8" y="5" width="2.2" height="14" rx="1" fill="currentColor" stroke="none"/>'),
  volumeHigh: svg('<path d="M4 9v6h4l5 4V5L8 9H4z" fill="currentColor" stroke="none"/><path d="M16.5 8.5a5 5 0 0 1 0 7"/><path d="M19 6a8.5 8.5 0 0 1 0 12"/>'),
  volumeLow: svg('<path d="M4 9v6h4l5 4V5L8 9H4z" fill="currentColor" stroke="none"/><path d="M16.5 8.5a5 5 0 0 1 0 7"/>'),
  volumeMuted: svg('<path d="M4 9v6h4l5 4V5L8 9H4z" fill="currentColor" stroke="none"/><path d="M17 9.5l5 5M22 9.5l-5 5"/>'),
  fullscreen: svg('<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>'),
  exitFullscreen: svg('<path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/>'),
  lock: svg('<rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>'),
  unlock: svg('<rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 7.5-2"/>'),
  settings: svg('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>'),
  subtitles: svg('<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M7 14h4M13 14h4"/>'),
  speed: svg('<path d="M12 20a8 8 0 1 1 8-8"/><path d="M12 12l4.5-3.5"/>'),
  quality: svg('<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M8 15v-6M8 12h3.5M11.5 9v6M16 9v6"/>'),
  audio: svg('<path d="M12 3v18"/><path d="M8 7v10M16 7v10M4 10v4M20 10v4"/>'),
  check: svg('<path d="M5 12.5l4.5 4.5L19 7.5"/>'),
  warning: svg('<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17.5h.01"/>'),
  offline: svg('<path d="M3 3l18 18"/><path d="M8.5 15.5a5 5 0 0 1 7 0"/><path d="M5 12a10 10 0 0 1 3.2-2.2M19 12a10 10 0 0 0-6.5-2.9"/><path d="M12 19h.01"/>'),
  info: svg('<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>'),
  skip: svg('<path d="M5 5v14l9-7z" fill="currentColor" stroke="none"/><path d="M17 5v14"/>'),
} as const

export type IconName = keyof typeof icons
