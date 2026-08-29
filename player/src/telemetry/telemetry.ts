import type { PlayerError } from '../core/errors.ts'
import type { PlaybackStats } from '../core/types.ts'

/**
 * Playback quality metrics.
 *
 * These are the numbers the WordPress test panel reports and the ones that say
 * whether a change to the buffering policy actually helped: startup time,
 * how often playback stalled, and how much of the session was spent stalled.
 */
export class Telemetry {
  #loadStartedAt: number | null = null
  #firstFrameAt: number | null = null
  #stallStartedAt: number | null = null
  #rebufferCount = 0
  #rebufferMs = 0
  #bytesLoaded = 0
  #qualitySwitches = 0
  #errors: PlaybackStats['errors'] = []
  #throughputBps: number | null = null

  reset(): void {
    this.#loadStartedAt = null
    this.#firstFrameAt = null
    this.#stallStartedAt = null
    this.#rebufferCount = 0
    this.#rebufferMs = 0
    this.#bytesLoaded = 0
    this.#qualitySwitches = 0
    this.#errors = []
    this.#throughputBps = null
  }

  markLoadStart(): void {
    this.#loadStartedAt = performance.now()
    this.#firstFrameAt = null
  }

  markFirstFrame(): void {
    if (this.#firstFrameAt !== null || this.#loadStartedAt === null) return
    this.#firstFrameAt = performance.now()
  }

  /**
   * A stall only counts as a rebuffer once playback has actually started —
   * before that it is startup time, which is measured separately.
   */
  markStallStart(): void {
    if (this.#firstFrameAt === null || this.#stallStartedAt !== null) return
    this.#stallStartedAt = performance.now()
    this.#rebufferCount++
  }

  markStallEnd(): void {
    if (this.#stallStartedAt === null) return
    this.#rebufferMs += performance.now() - this.#stallStartedAt
    this.#stallStartedAt = null
  }

  addBytes(bytes: number): void {
    this.#bytesLoaded += bytes
  }

  setThroughput(bps: number | null): void {
    this.#throughputBps = bps
  }

  markQualitySwitch(): void {
    this.#qualitySwitches++
  }

  recordError(error: PlayerError): void {
    this.#errors.push({ code: error.code, message: error.message, at: error.at })
    // Keep the tail bounded; a long session behind a flaky connection can
    // otherwise accumulate thousands of non-fatal entries.
    if (this.#errors.length > 50) this.#errors.shift()
  }

  snapshot(droppedFrames = 0): PlaybackStats {
    const startupTimeMs =
      this.#loadStartedAt !== null && this.#firstFrameAt !== null
        ? Math.round(this.#firstFrameAt - this.#loadStartedAt)
        : null
    // Include the stall in progress, otherwise a long freeze reads as zero.
    const ongoing = this.#stallStartedAt !== null ? performance.now() - this.#stallStartedAt : 0
    return {
      startupTimeMs,
      rebufferCount: this.#rebufferCount,
      rebufferMs: Math.round(this.#rebufferMs + ongoing),
      throughputBps: this.#throughputBps,
      bytesLoaded: this.#bytesLoaded,
      qualitySwitches: this.#qualitySwitches,
      droppedFrames,
      errors: [...this.#errors],
    }
  }
}
