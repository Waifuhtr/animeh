/**
 * Error taxonomy for the player.
 *
 * Two audiences, always kept separate:
 *   - `code` + `message` + `context` are for logs, telemetry and the admin debug panel.
 *   - `userMessage` is the only thing the UI is allowed to render.
 *
 * Never surface a raw exception dump to a viewer.
 */

export const ErrorCode = {
  /** Transport failed: DNS, TCP, TLS, timeout, offline. */
  NETWORK_ERROR: 'NETWORK_ERROR',
  /** Server said no: 401/403 on a signed media URL, expired token. */
  AUTH_ERROR: 'AUTH_ERROR',
  /** Manifest (m3u8) missing, malformed, or has no usable variant. */
  MANIFEST_ERROR: 'MANIFEST_ERROR',
  /** Container parsed but is structurally broken or truncated. */
  CONTAINER_ERROR: 'CONTAINER_ERROR',
  /** Container is fine, but this browser cannot decode the codecs inside. */
  MEDIA_UNSUPPORTED: 'MEDIA_UNSUPPORTED',
  /** Decoder rejected a sample we fed it. */
  MEDIA_DECODE_ERROR: 'MEDIA_DECODE_ERROR',
  /** Generic playback failure from the media element. */
  VIDEO_ERROR: 'VIDEO_ERROR',
  /** Subtitle track could not be fetched or parsed. */
  SUBTITLE_ERROR: 'SUBTITLE_ERROR',
  /** A font referenced by an ASS script could not be resolved anywhere. */
  FONT_MISSING: 'FONT_MISSING',
  /** MSE/SourceBuffer ran out of room, or persistence failed. */
  STORAGE_ERROR: 'STORAGE_ERROR',
  /** Anything we failed to classify. */
  UNKNOWN_ERROR: 'UNKNOWN_ERROR',
} as const

export type ErrorCode = (typeof ErrorCode)[keyof typeof ErrorCode]

/** User-facing copy, Turkish. Deliberately free of technical vocabulary. */
const USER_MESSAGES: Record<ErrorCode, string> = {
  NETWORK_ERROR: 'Bağlantı sorunu yaşanıyor. İnternetini kontrol edip tekrar dene.',
  AUTH_ERROR: 'Bu bölümü izleme yetkin doğrulanamadı. Tekrar giriş yapmayı dene.',
  MANIFEST_ERROR: 'Video kaynağı yüklenemedi. Birazdan tekrar dene.',
  CONTAINER_ERROR: 'Video dosyası okunamadı.',
  MEDIA_UNSUPPORTED: 'Bu video biçimi cihazında desteklenmiyor.',
  MEDIA_DECODE_ERROR: 'Video çözümlenemedi. Farklı bir kalite seçmeyi dene.',
  VIDEO_ERROR: 'Video oynatılamadı.',
  SUBTITLE_ERROR: 'Altyazı yüklenemedi. Video altyazısız devam ediyor.',
  FONT_MISSING: 'Altyazı için gereken bazı yazı tipleri bulunamadı.',
  STORAGE_ERROR: 'Cihazda yeterli alan yok gibi görünüyor.',
  UNKNOWN_ERROR: 'Beklenmeyen bir sorun oluştu.',
}

export interface PlayerErrorInit {
  code: ErrorCode
  /** Technical detail for logs. */
  message: string
  /** Overrides the default Turkish copy for this code. */
  userMessage?: string
  /** A fatal error stops playback; a non-fatal one degrades it. */
  fatal?: boolean
  /** Whether an automatic retry has any chance of helping. */
  retriable?: boolean
  cause?: unknown
  context?: Record<string, unknown>
}

export class PlayerError extends Error {
  readonly code: ErrorCode
  readonly userMessage: string
  readonly fatal: boolean
  readonly retriable: boolean
  readonly context: Record<string, unknown>
  readonly at: number

  constructor(init: PlayerErrorInit) {
    super(init.message, init.cause !== undefined ? { cause: init.cause } : undefined)
    this.name = 'PlayerError'
    this.code = init.code
    this.userMessage = init.userMessage ?? USER_MESSAGES[init.code]
    this.fatal = init.fatal ?? true
    this.retriable = init.retriable ?? isRetriableByDefault(init.code)
    this.context = init.context ?? {}
    this.at = Date.now()
  }

  /** Shape sent to telemetry / the WordPress log endpoint. */
  toJSON() {
    return {
      code: this.code,
      message: this.message,
      fatal: this.fatal,
      retriable: this.retriable,
      context: this.context,
      at: this.at,
    }
  }
}

function isRetriableByDefault(code: ErrorCode): boolean {
  switch (code) {
    case ErrorCode.NETWORK_ERROR:
    case ErrorCode.MANIFEST_ERROR:
    case ErrorCode.VIDEO_ERROR:
    case ErrorCode.MEDIA_DECODE_ERROR:
      return true
    default:
      return false
  }
}

/** Wrap anything thrown into a PlayerError without losing the original. */
export function toPlayerError(
  err: unknown,
  fallback: ErrorCode = ErrorCode.UNKNOWN_ERROR,
  context?: Record<string, unknown>,
): PlayerError {
  if (err instanceof PlayerError) return err
  if (err instanceof DOMException && err.name === 'QuotaExceededError') {
    return new PlayerError({
      code: ErrorCode.STORAGE_ERROR,
      message: `Quota exceeded: ${err.message}`,
      cause: err,
      context,
    })
  }
  // fetch() rejects with a bare TypeError for every transport-level failure.
  if (err instanceof TypeError && /fetch|network|load failed/i.test(err.message)) {
    return new PlayerError({
      code: ErrorCode.NETWORK_ERROR,
      message: err.message,
      cause: err,
      context,
    })
  }
  const message = err instanceof Error ? err.message : String(err)
  return new PlayerError({ code: fallback, message, cause: err, context })
}

/** Map an HTTP status onto the closest error code. */
export function errorCodeForStatus(status: number): ErrorCode {
  if (status === 401 || status === 403) return ErrorCode.AUTH_ERROR
  if (status === 404 || status === 410) return ErrorCode.MANIFEST_ERROR
  return ErrorCode.NETWORK_ERROR
}
