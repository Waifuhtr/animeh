import type { NetworkSnapshot, QualityLevel } from '../core/types.ts'

/**
 * How good the connection looks right now. Everything downstream — start
 * bitrate, buffer depth, retry patience — keys off this one value.
 */
export type NetworkTier = 'poor' | 'moderate' | 'good'

export interface BufferProfile {
  /** Seconds of media to gather before we let playback begin. */
  startupSec: number
  /** Seconds of forward buffer to maintain during playback. */
  forwardSec: number
  /** Seconds of already-played media to keep, for cheap short seeks back. */
  backSec: number
  /** Hard memory ceiling for the forward buffer. */
  maxSizeMB: number
  /** Do not fetch renditions taller than this. `null` means no cap. */
  maxHeight: number | null
  /** How long to tolerate a stall before forcing a quality drop. */
  starvationDelaySec: number
}

/**
 * Buffer strategy per tier.
 *
 * The counter-intuitive part is that the *worse* the connection, the *deeper*
 * we buffer forward. A weak link cannot be made faster, but it can be given a
 * head start, so we hoard whatever it delivers. Fast startup on such links
 * comes from picking a low bitrate rather than from a short startup buffer —
 * few bytes arrive quickly even at 400 kbps.
 */
const PROFILES: Record<NetworkTier, BufferProfile> = {
  poor: {
    startupSec: 2,
    forwardSec: 60,
    backSec: 15,
    maxSizeMB: 40,
    maxHeight: 480,
    starvationDelaySec: 3,
  },
  moderate: {
    startupSec: 3,
    forwardSec: 45,
    backSec: 30,
    maxSizeMB: 60,
    maxHeight: 720,
    starvationDelaySec: 4,
  },
  good: {
    startupSec: 4,
    forwardSec: 30,
    backSec: 90,
    maxSizeMB: 90,
    maxHeight: null,
    starvationDelaySec: 4,
  },
}

/** Mbps thresholds between tiers. */
const POOR_CEILING_BPS = 1_200_000
const MODERATE_CEILING_BPS = 4_000_000

export function classifyNetwork(network: NetworkSnapshot): NetworkTier {
  // An explicit data-saver request outranks any measurement: the user told us
  // what they want and it is not "whatever the link can take".
  if (network.saveData) return 'poor'
  if (network.effectiveType === 'slow-2g' || network.effectiveType === '2g') return 'poor'

  const bps = network.measuredBps
  if (bps !== null) {
    if (bps < POOR_CEILING_BPS) return 'poor'
    if (bps < MODERATE_CEILING_BPS) return 'moderate'
    return 'good'
  }

  // No measurement yet — lean on the browser hint, pessimistically.
  if (network.effectiveType === '3g') return 'moderate'
  if (network.effectiveType === '4g') return 'good'
  return 'moderate'
}

export function bufferProfileFor(network: NetworkSnapshot): BufferProfile {
  const profile = PROFILES[classifyNetwork(network)]
  // Data saver additionally pins the ceiling to 360p regardless of tier.
  if (network.saveData) return { ...profile, maxHeight: Math.min(profile.maxHeight ?? 360, 360) }
  return profile
}

export interface StartLevelOptions {
  /** Viewport height in CSS px, so we never fetch more pixels than we show. */
  viewportHeight?: number
  /** Device pixel ratio, folded into the viewport cap. */
  pixelRatio?: number
  /** User's saved preference, e.g. 720. `null` means auto. */
  preferredHeight?: number | null
}

/**
 * Pick the rendition to open with.
 *
 * Startup is when the estimate is least trustworthy, so the safety factor is
 * harsher than the one used for mid-playback upswitches. Opening too high
 * costs a visible stall in the first seconds — the moment a viewer is most
 * likely to give up — while opening too low costs a few seconds of soft
 * picture that ABR quietly corrects.
 */
export function pickStartLevel(
  levels: QualityLevel[],
  estimatedBps: number,
  profile: BufferProfile,
  options: StartLevelOptions = {},
): number {
  if (levels.length === 0) return -1

  const sorted = [...levels].sort((a, b) => a.bitrate - b.bitrate)
  const maxHeight = effectiveHeightCap(profile, options)
  const budget = estimatedBps * 0.6

  let chosen = sorted[0]!
  for (const level of sorted) {
    if (maxHeight !== null && level.height > maxHeight) continue
    if (level.bitrate <= budget) chosen = level
  }
  // If the cap excluded everything (a ladder that starts above the cap),
  // fall back to the smallest rendition rather than refusing to play.
  return chosen.id
}

/** Renditions the ABR is allowed to use, given the profile and the viewport. */
export function allowedLevels(
  levels: QualityLevel[],
  profile: BufferProfile,
  options: StartLevelOptions = {},
): QualityLevel[] {
  const cap = effectiveHeightCap(profile, options)
  if (cap === null) return levels
  const within = levels.filter((l) => l.height <= cap)
  // Never return an empty ladder; the smallest rendition is always allowed.
  if (within.length > 0) return within
  const smallest = [...levels].sort((a, b) => a.height - b.height)[0]
  return smallest ? [smallest] : []
}

function effectiveHeightCap(profile: BufferProfile, options: StartLevelOptions): number | null {
  const caps: number[] = []
  if (profile.maxHeight !== null) caps.push(profile.maxHeight)
  if (options.preferredHeight != null) caps.push(options.preferredHeight)
  if (options.viewportHeight) {
    // Allow a little headroom over the viewport: a 720p rendition in an 800px
    // box still looks better than 480p, and phones report odd sizes.
    const ratio = Math.min(options.pixelRatio ?? 1, 2)
    caps.push(Math.ceil(options.viewportHeight * ratio * 1.15))
  }
  return caps.length > 0 ? Math.min(...caps) : null
}

export function formatQualityLabel(height: number, frameRate?: number): string {
  const base = height >= 2160 ? '4K' : `${height}p`
  return frameRate && frameRate >= 50 ? `${base}${Math.round(frameRate)}` : base
}
