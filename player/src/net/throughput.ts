/**
 * Bandwidth estimator.
 *
 * Two exponentially-weighted averages over different half-lives, and we trust
 * the lower of the two. The fast average reacts when a train enters a tunnel;
 * the slow one stops us from yo-yoing the bitrate on a single slow segment.
 * Taking the minimum makes the estimate pessimistic, which is the direction
 * you want to be wrong in on a phone.
 */
export class ThroughputEstimator {
  #fast: number | null = null
  #slow: number | null = null
  /** Total bytes weighed in, used to discount the early, noisy samples. */
  #totalWeight = 0

  /** Half-life of the fast average, in seconds of transfer time. */
  readonly fastHalfLife: number
  /** Half-life of the slow average. */
  readonly slowHalfLife: number

  constructor(fastHalfLife = 2, slowHalfLife = 8) {
    this.fastHalfLife = fastHalfLife
    this.slowHalfLife = slowHalfLife
  }

  /**
   * @param bytes bytes transferred
   * @param durationMs wall time the transfer took
   */
  sample(bytes: number, durationMs: number): void {
    // Sub-50ms transfers are dominated by RTT and cache hits; they read as
    // absurdly fast and would poison the estimate.
    if (durationMs < 50 || bytes < 1024) return

    const seconds = durationMs / 1000
    const bps = (bytes * 8) / seconds

    this.#fast = ewma(this.#fast, bps, seconds, this.fastHalfLife)
    this.#slow = ewma(this.#slow, bps, seconds, this.slowHalfLife)
    this.#totalWeight += seconds
  }

  /** Bits per second, or null if we have not measured anything usable yet. */
  get bps(): number | null {
    if (this.#fast === null || this.#slow === null) return null
    // Until we have a couple of seconds of transfer, the averages are mostly
    // noise — better to say "unknown" and let the caller fall back.
    if (this.#totalWeight < 0.5) return null
    return Math.min(this.#fast, this.#slow)
  }

  reset(): void {
    this.#fast = null
    this.#slow = null
    this.#totalWeight = 0
  }
}

function ewma(prev: number | null, value: number, weight: number, halfLife: number): number {
  if (prev === null) return value
  const alpha = Math.pow(0.5, weight / halfLife)
  return prev * alpha + value * (1 - alpha)
}
