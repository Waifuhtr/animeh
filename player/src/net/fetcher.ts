import { ErrorCode, PlayerError, errorCodeForStatus, toPlayerError } from '../core/errors.ts'

export interface FetchOptions {
  headers?: Record<string, string>
  /** Inclusive byte range, HTTP `Range` semantics. */
  range?: { start: number; end?: number }
  signal?: AbortSignal
  /** Per-attempt timeout. */
  timeoutMs?: number
  retries?: number
  /** Called after each completed attempt with a throughput sample. */
  onProgress?: (bytes: number, durationMs: number) => void
}

export interface FetchResult {
  data: Uint8Array
  status: number
  headers: Headers
  /** Total resource size parsed from `Content-Range`, when the server sent one. */
  totalSize: number | null
  durationMs: number
}

const DEFAULT_TIMEOUT_MS = 20_000
const DEFAULT_RETRIES = 3

/**
 * Range-capable fetch with exponential backoff and jitter.
 *
 * Retries only what is worth retrying: transport failures, timeouts, 5xx and
 * 429. A 403 on a signed URL will still be a 403 in 8 seconds, so it fails
 * fast and lets the caller refresh the token instead.
 */
export async function fetchBytes(url: string, options: FetchOptions = {}): Promise<FetchResult> {
  const retries = options.retries ?? DEFAULT_RETRIES
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS
  let lastError: PlayerError | null = null

  for (let attempt = 0; attempt <= retries; attempt++) {
    if (options.signal?.aborted) throw abortError()
    if (attempt > 0) {
      await sleep(backoffDelay(attempt), options.signal)
    }

    const started = performance.now()
    const timeout = new AbortController()
    const timer = setTimeout(() => timeout.abort(new Error('timeout')), timeoutMs)
    const signal = options.signal
      ? AbortSignal.any([options.signal, timeout.signal])
      : timeout.signal

    try {
      const headers = new Headers(options.headers)
      if (options.range) {
        const { start, end } = options.range
        headers.set('Range', `bytes=${start}-${end ?? ''}`)
      }

      const response = await fetch(url, { headers, signal, credentials: 'omit' })

      if (!response.ok && response.status !== 206) {
        const err = new PlayerError({
          code: errorCodeForStatus(response.status),
          message: `HTTP ${response.status} for ${url}`,
          retriable: isRetriableStatus(response.status),
          context: { url, status: response.status, attempt },
        })
        if (!err.retriable || attempt === retries) throw err
        lastError = err
        continue
      }

      const buffer = await response.arrayBuffer()
      const durationMs = performance.now() - started
      const data = new Uint8Array(buffer)
      options.onProgress?.(data.byteLength, durationMs)

      return {
        data,
        status: response.status,
        headers: response.headers,
        totalSize: parseTotalSize(response.headers),
        durationMs,
      }
    } catch (err) {
      // The caller cancelled — not a failure, do not retry or reclassify.
      if (options.signal?.aborted) throw abortError()

      const isTimeout = timeout.signal.aborted
      const playerError = isTimeout
        ? new PlayerError({
            code: ErrorCode.NETWORK_ERROR,
            message: `Timed out after ${timeoutMs}ms: ${url}`,
            context: { url, attempt },
          })
        : toPlayerError(err, ErrorCode.NETWORK_ERROR, { url, attempt })

      if (!playerError.retriable || attempt === retries) throw playerError
      lastError = playerError
    } finally {
      clearTimeout(timer)
    }
  }

  throw lastError ?? new PlayerError({ code: ErrorCode.NETWORK_ERROR, message: `Failed: ${url}` })
}

/** 500ms, 1s, 2s, 4s… capped at 8s, with jitter so retries do not synchronise. */
export function backoffDelay(attempt: number, baseMs = 500, capMs = 8_000): number {
  const exponential = Math.min(capMs, baseMs * 2 ** (attempt - 1))
  return exponential * (0.7 + Math.random() * 0.6)
}

function isRetriableStatus(status: number): boolean {
  return status === 408 || status === 429 || status >= 500
}

function parseTotalSize(headers: Headers): number | null {
  const contentRange = headers.get('Content-Range')
  if (contentRange) {
    const match = /\/(\d+)\s*$/.exec(contentRange)
    if (match?.[1]) return Number(match[1])
  }
  const length = headers.get('Content-Length')
  return length ? Number(length) : null
}

export function sleep(ms: number, signal?: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) return reject(abortError())
    const timer = setTimeout(() => {
      signal?.removeEventListener('abort', onAbort)
      resolve()
    }, ms)
    const onAbort = () => {
      clearTimeout(timer)
      reject(abortError())
    }
    signal?.addEventListener('abort', onAbort, { once: true })
  })
}

export function abortError(): DOMException {
  return new DOMException('Aborted', 'AbortError')
}

export function isAbort(err: unknown): boolean {
  return err instanceof DOMException && err.name === 'AbortError'
}
