import type { FontReport } from '../fonts/registry.ts'
import type { PlaybackStats, PlayerSnapshot } from '../core/types.ts'
import { formatBitrate, formatBytes, formatTime } from '../ui/player-ui.ts'
import type { CheckList } from './checks.ts'

/** Prefix for the per-family rows, so they can be replaced as a group. */
export const FONT_CHECK_PREFIX = 'font:'

export interface FontReportOptions {
  /** Offered next to each missing family. Omitted when uploading is not allowed. */
  onUpload?: (family: string) => void
  uploadLabel?: string
}

/**
 * Render a font report into the check list.
 *
 * The summary row plus one row per family, because "3/4 found" is not
 * actionable on its own — the operator needs the name of the face to go and
 * upload, which is the whole point of the report.
 */
export function renderFontReport(
  checks: CheckList,
  report: FontReport,
  options: FontReportOptions = {},
): void {
  checks.set({
    key: 'fonts',
    label: 'Fontlar',
    state: report.missing.length === 0 ? 'ok' : 'warn',
    detail: `${report.resolved.length}/${report.required.length} bulundu`,
  })

  // Replace the whole group: a family can move from missing to resolved.
  checks.removeByPrefix(FONT_CHECK_PREFIX)

  for (const entry of report.resolved) {
    checks.set({
      key: `${FONT_CHECK_PREFIX}${entry.family}`,
      label: entry.family,
      state: 'ok',
      detail: originLabel(entry.origin),
      nested: true,
    })
  }

  for (const family of report.missing) {
    checks.set({
      key: `${FONT_CHECK_PREFIX}${family}`,
      label: family,
      state: 'bad',
      detail: 'bulunamadı',
      nested: true,
      ...(options.onUpload
        ? {
            action: {
              label: options.uploadLabel ?? 'Font Yükle',
              onSelect: () => options.onUpload!(family),
            },
          }
        : {}),
    })
  }
}

const ORIGIN_LABELS: Record<string, string> = {
  embedded: 'gömülü',
  cache: 'önbellek',
  server: 'sunucu',
  public: 'genel kaynak',
}

export function originLabel(origin: string): string {
  return ORIGIN_LABELS[origin] ?? origin
}

/** The measurement rows both panels show. */
export function statsRows(snapshot: PlayerSnapshot, stats: PlaybackStats): [string, string][] {
  const quality = snapshot.qualities.find((level) => level.id === snapshot.activeQualityId)
  return [
    ['Durum', snapshot.phase],
    ['Konum', `${formatTime(snapshot.position)} / ${formatTime(snapshot.duration)}`],
    ['Tampon', `${snapshot.bufferAhead.toFixed(1)} sn`],
    ['Aktif kalite', quality ? `${quality.label}${snapshot.autoQuality ? ' (oto)' : ''}` : '—'],
    ['Başlangıç süresi', stats.startupTimeMs === null ? '—' : `${stats.startupTimeMs} ms`],
    ['Yeniden tamponlama', `${stats.rebufferCount}× · ${(stats.rebufferMs / 1000).toFixed(1)} sn`],
    ['Ortalama hız', formatBitrate(stats.throughputBps)],
    ['İndirilen', formatBytes(stats.bytesLoaded)],
    ['Kalite değişimi', String(stats.qualitySwitches)],
    ['Düşen kare', String(stats.droppedFrames)],
    ['Ağ', `${snapshot.network.kind}${snapshot.network.saveData ? ' · veri tasarrufu' : ''}`],
  ]
}

/**
 * Overall verdict for a run.
 *
 * A failed check is a failure; a warning (a missing font, say) is a pass with
 * a caveat, because the episode still plays.
 */
export function verdictFor(checks: CheckList): 'ok' | 'warn' | 'bad' | 'pending' {
  const states = checks.entries().map((entry) => entry.state)
  if (states.includes('bad')) return 'bad'
  if (states.includes('pending')) return 'pending'
  if (states.includes('warn')) return 'warn'
  return 'ok'
}
