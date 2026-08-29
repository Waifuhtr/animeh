import { Emitter } from '../core/emitter.ts'
import type { ConnectionKind, NetworkSnapshot } from '../core/types.ts'
import type { ThroughputEstimator } from './throughput.ts'

interface NetworkInformationLike extends EventTarget {
  effectiveType?: string
  downlink?: number
  rtt?: number
  saveData?: boolean
  type?: string
}

/**
 * Watches connectivity and reports it in one shape.
 *
 * `navigator.connection` is Chromium-only and its numbers are coarse, so it is
 * treated as a hint that seeds our decisions before we have measured anything
 * ourselves — never as ground truth once `ThroughputEstimator` has samples.
 */
export class NetworkMonitor {
  readonly events = new Emitter<{ change: NetworkSnapshot; online: boolean }>()

  #connection: NetworkInformationLike | null
  #estimator: ThroughputEstimator | null = null
  #onChange = () => this.events.emit('change', this.snapshot())
  #onOnline = () => {
    this.events.emit('online', true)
    this.#onChange()
  }
  #onOffline = () => {
    this.events.emit('online', false)
    this.#onChange()
  }

  constructor() {
    const nav = navigator as Navigator & { connection?: NetworkInformationLike }
    this.#connection = nav.connection ?? null
    globalThis.addEventListener('online', this.#onOnline)
    globalThis.addEventListener('offline', this.#onOffline)
    this.#connection?.addEventListener('change', this.#onChange)
  }

  /** Lets the snapshot carry our own measurement alongside the browser's. */
  bindEstimator(estimator: ThroughputEstimator): void {
    this.#estimator = estimator
  }

  snapshot(): NetworkSnapshot {
    const online = navigator.onLine
    const c = this.#connection
    return {
      online,
      kind: online ? mapKind(c?.type, c?.effectiveType) : 'offline',
      effectiveType: c?.effectiveType ?? null,
      downlink: typeof c?.downlink === 'number' ? c.downlink : null,
      rtt: typeof c?.rtt === 'number' ? c.rtt : null,
      saveData: c?.saveData === true,
      measuredBps: this.#estimator?.bps ?? null,
    }
  }

  /**
   * Best available bandwidth figure in bits/s.
   * Prefers our own measurement; falls back to the browser hint; then to a
   * deliberately low guess so a cold start does not open at 1080p.
   */
  estimateBps(fallback = 900_000): number {
    const measured = this.#estimator?.bps
    if (measured !== null && measured !== undefined) return measured
    const downlink = this.#connection?.downlink
    // `downlink` is capped and rounded by the browser, and is a link-rate
    // hint rather than achievable throughput — halve it before trusting it.
    if (typeof downlink === 'number' && downlink > 0) return downlink * 1_000_000 * 0.5
    return fallback
  }

  destroy(): void {
    globalThis.removeEventListener('online', this.#onOnline)
    globalThis.removeEventListener('offline', this.#onOffline)
    this.#connection?.removeEventListener('change', this.#onChange)
    this.events.removeAll()
  }
}

function mapKind(type: string | undefined, effectiveType: string | undefined): ConnectionKind {
  switch (type) {
    case 'wifi':
      return 'wifi'
    case 'ethernet':
      return 'ethernet'
    case 'cellular':
      return 'cellular'
    case 'none':
      return 'offline'
  }
  // No `type` (most browsers): infer from effectiveType. A 2g/3g link is
  // cellular in every practical case.
  if (effectiveType === 'slow-2g' || effectiveType === '2g' || effectiveType === '3g') {
    return 'cellular'
  }
  return 'unknown'
}
