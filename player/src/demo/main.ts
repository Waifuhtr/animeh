import '../ui/player.css'
import './harness.css'
import { createPlayer, type MountedPlayer } from '../index.ts'
import { formatBitrate, formatBytes, formatTime } from '../ui/player-ui.ts'
import type { FontReport } from '../fonts/registry.ts'
import type { MediaSourceDescriptor, PlayerSnapshot } from '../core/types.ts'

/**
 * Player test harness.
 *
 * Mirrors the WordPress admin panel described in the project brief — pick a
 * source, start the player, watch the checks and the buffering numbers — so
 * the plugin can adopt this layout instead of inventing a second one.
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
  mkvOpus: {
    url: '/media/episode-opus.mkv',
    type: 'mkv',
    episode: {
      animeId: 'demo',
      episodeId: 'demo-mkv-opus',
      animeTitle: 'Animeh Test Serisi',
      episodeTitle: 'MKV — Opus ses',
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
      <div class="checks" id="checks"></div>
    </section>

    <section class="panel">
      <h2>Ölçümler</h2>
      <dl class="stats" id="stats"></dl>
    </section>

    <section class="panel">
      <h2>Günlük</h2>
      <div class="log" id="log"></div>
    </section>
  </div>
`

const slot = document.querySelector<HTMLElement>('#player-slot')!
const checksEl = document.querySelector<HTMLElement>('#checks')!
const statsEl = document.querySelector<HTMLElement>('#stats')!
const logEl = document.querySelector<HTMLElement>('#log')!

type CheckState = 'pending' | 'ok' | 'bad' | 'warn'
const checks = new Map<string, { label: string; state: CheckState; detail: string }>()

function setCheck(key: string, label: string, state: CheckState, detail = ''): void {
  checks.set(key, { label, state, detail })
  renderChecks()
}

function renderChecks(): void {
  const marks: Record<CheckState, string> = { pending: '·', ok: '✓', bad: '✗', warn: '!' }
  checksEl.replaceChildren(
    ...[...checks.values()].map((check) => {
      const row = document.createElement('div')
      row.className = 'check'
      row.dataset.state = check.state
      row.innerHTML = `<span class="mark">${marks[check.state]}</span><span class="name"></span><span class="detail"></span>`
      row.querySelector('.name')!.textContent = check.label
      row.querySelector('.detail')!.textContent = check.detail
      return row
    }),
  )
}

function log(message: string, tone: '' | 'ok' | 'warn' | 'err' = ''): void {
  const line = document.createElement('div')
  if (tone) line.className = tone
  const time = new Date().toLocaleTimeString('tr-TR', { hour12: false })
  line.textContent = `${time}  ${message}`
  logEl.prepend(line)
  while (logEl.childElementCount > 300) logEl.lastElementChild?.remove()
}

let mounted: MountedPlayer | null = null

async function start(): Promise<void> {
  await mounted?.destroy()
  mounted = null
  checks.clear()
  logEl.replaceChildren()

  const key = (document.querySelector<HTMLSelectElement>('#source')!).value
  const kbps = Number((document.querySelector<HTMLInputElement>('#kbps')!).value)
  const fail = Number((document.querySelector<HTMLInputElement>('#fail')!).value)

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

  setCheck('source', 'Kaynak', 'pending', source.url)
  setCheck('container', 'Konteyner', 'pending')
  setCheck('video', 'Video', 'pending')
  setCheck('audio', 'Ses', 'pending')
  setCheck('subtitle', 'Altyazı', 'pending')
  setCheck('renderer', 'ASS renderer', 'pending')
  setCheck('fonts', 'Fontlar', 'pending')

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
    log(`${error.code}: ${error.message}`, error.fatal ? 'err' : 'warn')
    if (error.code === 'SUBTITLE_ERROR') setCheck('subtitle', 'Altyazı', 'bad', error.code)
    if (error.fatal) setCheck('video', 'Video', 'bad', error.code)
  })

  player.player.events.on('fontReport', (report: FontReport) => {
    renderFontReport(report)
  })

  player.player.events.on('navigate', (direction) => log(`bölüm isteği: ${direction}`))
  player.player.events.on('ended', () => log('oynatma tamamlandı', 'ok'))

  let sawFirstFrame = false
  player.player.subscribe((snapshot) => {
    renderStats(snapshot, player)
    if (!sawFirstFrame && snapshot.phase === 'playing') {
      sawFirstFrame = true
      const stats = player.player.stats()
      setCheck('video', 'Video', 'ok', `${stats.startupTimeMs ?? '?'} ms içinde başladı`)
      log(`ilk kare ${stats.startupTimeMs} ms içinde`, 'ok')
    }
    if (snapshot.qualities.length > 0 && checks.get('container')?.state === 'pending') {
      const top = [...snapshot.qualities].sort((a, b) => b.height - a.height)[0]!
      setCheck(
        'container',
        'Konteyner',
        'ok',
        `${source.type?.toUpperCase()} · ${snapshot.qualities.length} kalite · en yüksek ${top.label}`,
      )
    }
    if (snapshot.audioTracks.length > 0 && checks.get('audio')?.state === 'pending') {
      const track = snapshot.audioTracks[0]!
      setCheck('audio', 'Ses', 'ok', `${track.codec ?? ''} ${track.label}`.trim())
    }
    if (snapshot.subtitleTracks.length > 0 && checks.get('subtitle')?.state === 'pending') {
      const track = snapshot.subtitleTracks[0]!
      setCheck(
        'subtitle',
        'Altyazı',
        'ok',
        `${track.format.toUpperCase()} · ${track.origin === 'embedded' ? 'gömülü' : 'harici'}`,
      )
    }
  })

  log(`yükleniyor: ${source.url}`)
  await player.player.load(source)
  setCheck('source', 'Kaynak', 'ok', source.url)
  await player.player.play()
}

function renderFontReport(report: FontReport): void {
  setCheck('renderer', 'ASS renderer', 'ok', 'libass (wasm)')
  const detail = `${report.resolved.length}/${report.required.length} bulundu`
  setCheck('fonts', 'Fontlar', report.missing.length === 0 ? 'ok' : 'warn', detail)

  for (const entry of report.resolved) {
    setCheck(`font:${entry.family}`, `  ${entry.family}`, 'ok', entry.origin)
  }
  for (const family of report.missing) {
    setCheck(`font:${family}`, `  ${family}`, 'bad', 'bulunamadı')
  }
  if (report.missing.length > 0) {
    log(`eksik font: ${report.missing.join(', ')}`, 'warn')
  }
}

function renderStats(snapshot: PlayerSnapshot, player: MountedPlayer): void {
  const stats = player.player.stats()
  const quality = snapshot.qualities.find((level) => level.id === snapshot.activeQualityId)
  const rows: [string, string][] = [
    ['Durum', snapshot.phase],
    ['Konum', `${formatTime(snapshot.position)} / ${formatTime(snapshot.duration)}`],
    ['Tampon', `${snapshot.bufferAhead.toFixed(1)} sn`],
    ['Aktif kalite', quality ? `${quality.label}${snapshot.autoQuality ? ' (oto)' : ''}` : '—'],
    ['Başlangıç süresi', stats.startupTimeMs === null ? '—' : `${stats.startupTimeMs} ms`],
    ['Yeniden tamponlama', `${stats.rebufferCount}× · ${(stats.rebufferMs / 1000).toFixed(1)} sn`],
    ['Ortalama hız', formatBitrate(stats.throughputBps)],
    ['İndirilen', formatBytes(stats.bytesLoaded)],
    ['Düşen kare', String(stats.droppedFrames)],
    ['Ağ', `${snapshot.network.kind}${snapshot.network.saveData ? ' · veri tasarrufu' : ''}`],
  ]
  statsEl.replaceChildren(
    ...rows.flatMap(([label, value]) => {
      const dt = document.createElement('dt')
      dt.textContent = label
      const dd = document.createElement('dd')
      dd.textContent = value
      return [dt, dd]
    }),
  )
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
  get: () => ({ mounted, checks: [...checks.values()], start }),
})
