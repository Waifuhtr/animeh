import '../ui/player.css'
import '../panel/panel.css'
import './harness.css'
import { createPlayer, type MountedPlayer } from '../index.ts'
import { CheckList, LogView, StatsTable, renderFontReport, statsRows } from '../panel/index.ts'
import type { FontReport } from '../fonts/registry.ts'
import type { MediaSourceDescriptor, PlayerSnapshot } from '../core/types.ts'

/**
 * Player test harness.
 *
 * Mirrors the WordPress admin panel — pick a source, start the player, watch
 * the checks and the buffering numbers — and shares its widgets with it
 * (`src/panel/`), so the two layouts cannot drift apart.
 */

const SOURCES: Record<string, MediaSourceDescriptor> = {
  hls: {
    url: '/media/hls/master.m3u8',
    type: 'hls',
    subtitles: [
      { id: 'tr', language: 'tr', label: 'Türkçe', url: '/media/subtitle.ass', format: 'ass', default: true },
    ],
    fonts: [
      { family: 'DejaVu Sans', url: '/media/fonts/DejaVuSans.ttf' },
      { family: 'DejaVu Serif', url: '/media/fonts/DejaVuSerif.ttf' },
      { family: 'DejaVu Sans Mono', url: '/media/fonts/DejaVuSansMono.ttf' },
    ],
    episode: {
      animeId: 'demo',
      episodeId: 'demo-hls-1',
      animeTitle: 'Animeh Test Serisi',
      episodeTitle: 'HLS kaynağı',
      season: 1,
      episodeNumber: 1,
      introStart: 8,
      introEnd: 20,
      outroStart: 52,
      hasNext: true,
      hasPrevious: false,
    },
  },
  mkv: {
    url: '/media/episode.mkv',
    type: 'mkv',
    episode: {
      animeId: 'demo',
      episodeId: 'demo-mkv-1',
      animeTitle: 'Animeh Test Serisi',
      episodeTitle: 'MKV — gömülü ASS ve fontlar',
      season: 1,
      episodeNumber: 2,
      introStart: 8,
      introEnd: 20,
      outroStart: 52,
      hasNext: true,
      hasPrevious: true,
    },
  },
  mkvVp9: {
    url: '/media/episode-vp9.mkv',
    type: 'mkv',
    episode: {
      animeId: 'demo',
      episodeId: 'demo-mkv-vp9',
      animeTitle: 'Animeh Test Serisi',
      episodeTitle: 'MKV — VP9 + Opus, gömülü ASS ve fontlar',
      season: 1,
      episodeNumber: 4,
      introStart: 5,
      introEnd: 12,
      outroStart: 26,
      hasNext: true,
      hasPrevious: true,
    },
  },
  hlsVp9: {
    url: '/media/hls-vp9/master.m3u8',
    type: 'hls',
    subtitles: [
      { id: 'tr', language: 'tr', label: 'Türkçe', url: '/media/subtitle.ass', format: 'ass', default: true },
    ],
    fonts: [
      { family: 'DejaVu Sans', url: '/media/fonts/DejaVuSans.ttf' },
      { family: 'DejaVu Serif', url: '/media/fonts/DejaVuSerif.ttf' },
      { family: 'DejaVu Sans Mono', url: '/media/fonts/DejaVuSansMono.ttf' },
    ],
    episode: {
      animeId: 'demo',
      episodeId: 'demo-hls-vp9',
      animeTitle: 'Animeh Test Serisi',
      episodeTitle: 'HLS — VP9 + Opus, fMP4 segmentler',
      season: 1,
      episodeNumber: 5,
      hasNext: false,
      hasPrevious: true,
    },
  },
  progressive: {
    url: '/media/source-vp9.webm',
    type: 'auto',
    subtitles: [
      { id: 'tr', language: 'tr', label: 'Türkçe', url: '/media/subtitle.ass', format: 'ass', default: true },
    ],
    fonts: [
      { family: 'DejaVu Sans', url: '/media/fonts/DejaVuSans.ttf' },
      { family: 'DejaVu Serif', url: '/media/fonts/DejaVuSerif.ttf' },
      { family: 'DejaVu Sans Mono', url: '/media/fonts/DejaVuSansMono.ttf' },
    ],
    episode: {
      animeId: 'demo',
      episodeId: 'demo-progressive',
      animeTitle: 'Animeh Test Serisi',
      episodeTitle: 'Tek dosya — tarayıcı doğrudan oynatır',
      season: 1,
      episodeNumber: 6,
      hasNext: false,
      hasPrevious: true,
    },
  },
  mkvOpus: {
    url: '/media/episode-opus.mkv',
    type: 'mkv',
    episode: {
      animeId: 'demo',
      episodeId: 'demo-mkv-opus',
      animeTitle: 'Animeh Test Serisi',
      episodeTitle: 'MKV — H.264 + Opus ses',
      season: 1,
      episodeNumber: 3,
      hasNext: false,
      hasPrevious: true,
    },
  },
}

const app = document.querySelector<HTMLDivElement>('#app')!
app.innerHTML = `
  <div class="wrap">
    <header class="head">
      <h1>Animeh Player — Test Paneli</h1>
      <p>HLS ve MKV kaynaklarını, ASS altyazıları ve font çözümlemesini doğrular.</p>
    </header>

    <section class="panel player-slot" id="player-slot"></section>

    <section class="panel">
      <h2>Kaynak</h2>
      <div class="field">
        <label for="source">Video</label>
        <select id="source">
          <option value="hls">HLS — 4 kaliteli ladder + harici ASS</option>
          <option value="mkv">MKV — gömülü ASS altyazı + 3 font</option>
          <option value="mkvVp9">MKV — VP9 + Opus (telifsiz kodekler)</option>
          <option value="hlsVp9">HLS — VP9 + Opus, fMP4 segmentler</option>
          <option value="progressive">Tek dosya (MP4/WebM) — tarayıcı doğrudan oynatır</option>
          <option value="mkvOpus">MKV — H.264 + Opus ses</option>
        </select>
      </div>
      <div class="row">
        <div class="field">
          <label for="kbps">Bant genişliği sınırı (kbps, 0 = sınırsız)</label>
          <input id="kbps" type="number" min="0" step="100" value="0" />
        </div>
        <div class="field">
          <label for="fail">İlk N isteği başarısız yap</label>
          <input id="fail" type="number" min="0" step="1" value="0" />
        </div>
      </div>
      <button class="primary" id="start">Oynatıcıyı başlat</button>
      <div class="row" style="margin-top: 8px">
        <button class="ghost" id="seek-mid">Ortaya atla</button>
        <button class="ghost" id="drop">Bağlantıyı kes</button>
      </div>
      <p class="hint">
        Kısayollar: <kbd>space</kbd> oynat · <kbd>←</kbd><kbd>→</kbd> 10sn ·
        <kbd>f</kbd> tam ekran · <kbd>c</kbd> altyazı · <kbd>d</kbd> hata ayıklama
      </p>
    </section>

    <section class="panel">
      <h2>Sonuçlar</h2>
      <div class="ap-checks" id="checks"></div>
    </section>

    <section class="panel">
      <h2>Ölçümler</h2>
      <dl class="ap-stats" id="stats"></dl>
    </section>

    <section class="panel">
      <h2>Günlük</h2>
      <div class="ap-log" id="log"></div>
    </section>
  </div>
`

const slot = document.querySelector<HTMLElement>('#player-slot')!
const checks = new CheckList(document.querySelector<HTMLElement>('#checks')!)
const statsTable = new StatsTable(document.querySelector<HTMLElement>('#stats')!)
const logView = new LogView(document.querySelector<HTMLElement>('#log')!)

const log = (message: string, tone: 'info' | 'ok' | 'warn' | 'error' = 'info') =>
  logView.append(message, tone)

let mounted: MountedPlayer | null = null
/** Last report, exposed for scripted checks so they assert on data, not copy. */
let lastFontReport: FontReport | null = null

async function start(): Promise<void> {
  await mounted?.destroy()
  mounted = null
  checks.clear()
  logView.clear()
  lastFontReport = null

  const key = document.querySelector<HTMLSelectElement>('#source')!.value
  const kbps = Number(document.querySelector<HTMLInputElement>('#kbps')!.value)
  const fail = Number(document.querySelector<HTMLInputElement>('#fail')!.value)

  const base = SOURCES[key]
  if (!base) return

  // Fault-injection parameters ride on the URL so the dev server can apply
  // them per request, including to the ranges the MKV engine issues.
  const query: string[] = []
  if (kbps > 0) query.push(`kbps=${kbps}`)
  if (fail > 0) query.push(`fail=${fail}`)
  const suffix = query.length > 0 ? `?${query.join('&')}` : ''
  const source: MediaSourceDescriptor = {
    ...base,
    url: base.url + suffix,
    subtitles: base.subtitles?.map((track) => ({ ...track, url: track.url + suffix })),
    fonts: base.fonts?.map((font) => ({ ...font, url: font.url + suffix })),
  }

  for (const [checkKey, label] of [
    ['source', 'Kaynak'],
    ['container', 'Konteyner'],
    ['video', 'Video'],
    ['audio', 'Ses'],
    ['subtitle', 'Altyazı'],
    ['renderer', 'ASS renderer'],
    ['fonts', 'Fontlar'],
  ] as const) {
    checks.set({ key: checkKey, label, state: 'pending', detail: checkKey === 'source' ? source.url : '' })
  }

  const player = createPlayer(slot, {
    subtitles: {
      workerUrl: '/jassub/jassub-worker.js',
      wasmUrl: '/jassub/jassub-worker.wasm',
      modernWasmUrl: '/jassub/jassub-worker-modern.wasm',
      fallbackFont: 'DejaVu Sans',
    },
    preferredHeight: null,
  })
  mounted = player

  player.player.events.on('error', (error) => {
    log(`${error.code}: ${error.message}`, error.fatal ? 'error' : 'warn')
    if (error.code === 'SUBTITLE_ERROR') {
      checks.update('subtitle', 'bad', error.code)
    }
    if (error.fatal) checks.update('video', 'bad', error.code)
  })

  player.player.events.on('fontReport', (report: FontReport) => showFontReport(report))
  player.player.events.on('navigate', (direction) => log(`bölüm isteği: ${direction}`))
  player.player.events.on('ended', () => log('oynatma tamamlandı', 'ok'))

  let sawFirstFrame = false
  player.player.subscribe((snapshot) => {
    statsTable.render(statsRows(snapshot, player.player.stats()))

    if (!sawFirstFrame && snapshot.phase === 'playing') {
      sawFirstFrame = true
      const startup = player.player.stats().startupTimeMs
      checks.update('video', 'ok', `${startup ?? '?'} ms içinde başladı`)
      log(`ilk kare ${startup} ms içinde`, 'ok')
    }
    if (snapshot.qualities.length > 0 && checks.get('container')?.state === 'pending') {
      const top = [...snapshot.qualities].sort((a, b) => b.height - a.height)[0]!
      const detail = `${source.type?.toUpperCase()} · ${snapshot.qualities.length} kalite · en yüksek ${top.label}`
      checks.update('container', 'ok', detail)
    }
    if (snapshot.audioTracks.length > 0 && checks.get('audio')?.state === 'pending') {
      const track = snapshot.audioTracks[0]!
      checks.update('audio', 'ok', `${track.codec ?? ''} ${track.label}`.trim())
    }
    if (snapshot.subtitleTracks.length > 0 && checks.get('subtitle')?.state === 'pending') {
      const track = snapshot.subtitleTracks[0]!
      const origin = track.origin === 'embedded' ? 'gömülü' : 'harici'
      checks.update('subtitle', 'ok', `${track.format.toUpperCase()} · ${origin}`)
    }
  })

  log(`yükleniyor: ${source.url}`)
  await player.player.load(source)
  checks.update('source', 'ok', source.url)
  await player.player.play()
}

function showFontReport(report: FontReport): void {
  lastFontReport = report
  checks.update('renderer', 'ok', 'libass (wasm)')
  renderFontReport(checks, report)
  if (report.missing.length > 0) {
    log(`eksik font: ${report.missing.join(', ')}`, 'warn')
  }
}

document.querySelector('#start')!.addEventListener('click', () => void start())
document.querySelector('#seek-mid')!.addEventListener('click', () => {
  const snapshot = mounted?.player.snapshot
  if (snapshot) mounted!.player.seek(snapshot.duration / 2)
})
document.querySelector('#drop')!.addEventListener('click', () => {
  // Simulates losing the network without touching devtools, so the offline
  // banner and the recovery path can be exercised from the panel itself.
  globalThis.dispatchEvent(new Event('offline'))
  log('çevrimdışı olayı tetiklendi', 'warn')
  setTimeout(() => {
    globalThis.dispatchEvent(new Event('online'))
    log('çevrimiçi olayı tetiklendi', 'ok')
  }, 4000)
})

// Expose the mounted player for scripted checks.
Object.defineProperty(globalThis, 'animehHarness', {
  get: () => ({ mounted, checks: checks.entries(), fontReport: lastFontReport, start }),
})

declare global {
  // eslint-disable-next-line no-var
  var animehHarness: {
    mounted: MountedPlayer | null
    checks: ReturnType<CheckList['entries']>
    fontReport: FontReport | null
    start: () => Promise<void>
  }
}

export type { PlayerSnapshot }
