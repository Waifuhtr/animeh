/**
 * "Continue where you left off" persistence.
 *
 * Storage is behind an interface so the same controller works against
 * localStorage during development and against the backend's watch-history
 * endpoint in production, where progress has to follow the viewer between
 * devices.
 */
export interface ResumeRecord {
  episodeId: string
  animeId: string
  positionSec: number
  durationSec: number
  completed: boolean
  updatedAt: number
}

export interface ResumeStore {
  get(episodeId: string): Promise<ResumeRecord | null>
  save(record: ResumeRecord): Promise<void>
  clear(episodeId: string): Promise<void>
}

/**
 * A position this close to the end counts as finished, so the next episode is
 * offered instead of resuming into the closing credits.
 */
const COMPLETION_MARGIN_SEC = 30
/** Below this we do not bother: the viewer has effectively not started. */
const MINIMUM_SAVE_SEC = 10

export function isCompleted(positionSec: number, durationSec: number): boolean {
  if (!Number.isFinite(durationSec) || durationSec <= 0) return false
  return positionSec >= durationSec - COMPLETION_MARGIN_SEC
}

export function isWorthSaving(positionSec: number): boolean {
  return positionSec >= MINIMUM_SAVE_SEC
}

/** Development/offline store. */
export class LocalResumeStore implements ResumeStore {
  #prefix: string

  constructor(prefix = 'animeh:resume:') {
    this.#prefix = prefix
  }

  async get(episodeId: string): Promise<ResumeRecord | null> {
    try {
      const raw = localStorage.getItem(this.#prefix + episodeId)
      return raw ? (JSON.parse(raw) as ResumeRecord) : null
    } catch {
      // Private browsing and blocked site data both throw here; losing the
      // resume point is never a reason to fail playback.
      return null
    }
  }

  async save(record: ResumeRecord): Promise<void> {
    try {
      localStorage.setItem(this.#prefix + record.episodeId, JSON.stringify(record))
    } catch {
      return
    }
  }

  async clear(episodeId: string): Promise<void> {
    try {
      localStorage.removeItem(this.#prefix + episodeId)
    } catch {
      return
    }
  }
}
