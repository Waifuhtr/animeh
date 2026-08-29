/**
 * The player test screen.
 *
 * Layout follows the project brief: a source form on one side, checks and
 * measurements on the other. The widgets come from the player bundle, which is
 * the same code its development harness uses — one implementation, so the two
 * cannot drift.
 */

import { ApiError } from './api.js'

// `mp4` means "let the browser demux it", which covers MP4, WebM and anything
// else it plays natively. Only Matroska goes through our own demuxer, because
// no browser demuxes it and because that is what gets a release's embedded ASS
// subtitles and fonts out.
const SOURCE_TYPES = [
  ['auto', 'Otomatik algıla'],
  ['hls', 'HLS (m3u8)'],
  ['mkv', 'MKV (gömülü altyazı ve font)'],
  ['mp4', 'MP4 / WebM (tarayıcı doğrudan oynatır)'],
]

const CHECK_LABELS = [
  ['source', 'Kaynak'],
  ['container', 'Konteyner'],
  ['video', 'Video'],
  ['audio', 'Ses'],
  ['subtitle', 'Altyazı'],
  ['renderer', 'ASS renderer'],
  ['fonts', 'Fontlar'],
]

export class TestPanel {
  #root
  #api
  #config
  #player
  /** @type {import('../player/animeh-player.js')} */
  #lib
  #checks
  #stats
  #log
  #els = {}
  #presets = []
  #sessionId = null
  #lastReport = null
  #saveTimer = null
  #pendingUploadFamily = null

  /**
   * @param {HTMLElement} root Mount point.
   * @param {import('./api.js').Api} api REST client.
   * @param {object} config Server-provided configuration.
   * @param {object} lib The player bundle's exports.
   */
  constructor(root, api, config, lib) {
    this.#root = root
    this.#api = api
    this.#config = config
    this.#lib = lib
    this.#render()
  }

  #render() {
    this.#root.innerHTML = `
      <div class="animeh-grid">
        <section class="animeh-card animeh-card--player">
          <div id="animeh-player-slot" class="animeh-player-slot"></div>
        </section>

        <section class="animeh-card">
          <h2>Kaynak</h2>
          <div class="animeh-field">
            <label for="animeh-preset">Kayıtlı kaynak</label>
            <div class="animeh-row">
              <select id="animeh-preset"><option value="">— seç —</option></select>
              <button type="button" class="button" id="animeh-save-preset">Kaydet</button>
            </div>
          </div>
          <div class="animeh-field">
            <label for="animeh-source-url">Video URL</label>
            <input type="url" id="animeh-source-url" placeholder="https://…/master.m3u8" spellcheck="false" />
          </div>
          <div class="animeh-field">
            <label for="animeh-source-type">Konteyner</label>
            <select id="animeh-source-type">
              ${SOURCE_TYPES.map(([value, label]) => `<option value="${value}">${label}</option>`).join('')}
            </select>
          </div>
          <div class="animeh-field">
            <label for="animeh-subtitle-url">Altyazı URL (ASS/SSA/SRT, isteğe bağlı)</label>
            <input type="url" id="animeh-subtitle-url" placeholder="https://…/tr.ass" spellcheck="false" />
          </div>
          <div class="animeh-row">
            <div class="animeh-field">
              <label for="animeh-kbps">Bant genişliği sınırı (kbps)</label>
              <input type="number" id="animeh-kbps" min="0" step="100" value="0" />
            </div>
            <div class="animeh-field">
              <label for="animeh-fail">İlk N isteği düşür</label>
              <input type="number" id="animeh-fail" min="0" step="1" value="0" />
            </div>
          </div>
          <p class="animeh-hint" id="animeh-proxy-hint"></p>
          <button type="button" class="button button-primary button-hero" id="animeh-start">Testi Başlat</button>
          <p class="animeh-error" id="animeh-form-error" hidden></p>
        </section>

        <section class="animeh-card">
          <h2>Sonuçlar</h2>
          <div class="ap-checks" id="animeh-checks"></div>
          <input type="file" id="animeh-font-input" accept=".ttf,.otf,.ttc,.woff,.woff2" hidden />
          <p class="animeh-hint" id="animeh-upload-status" hidden></p>
        </section>

        <section class="animeh-card">
          <h2>Ölçümler</h2>
          <dl class="ap-stats" id="animeh-stats"></dl>
        </section>

        <section class="animeh-card">
          <h2>Günlük</h2>
          <div class="ap-log" id="animeh-log"></div>
        </section>
      </div>
    `

    const byId = (id) => this.#root.querySelector(`#${id}`)
    this.#els = {
      slot: byId('animeh-player-slot'),
      preset: byId('animeh-preset'),
      savePreset: byId('animeh-save-preset'),
      url: byId('animeh-source-url'),
      type: byId('animeh-source-type'),
      subtitle: byId('animeh-subtitle-url'),
      kbps: byId('animeh-kbps'),
      fail: byId('animeh-fail'),
      start: byId('animeh-start'),
      formError: byId('animeh-form-error'),
      fontInput: byId('animeh-font-input'),
      uploadStatus: byId('animeh-upload-status'),
      proxyHint: byId('animeh-proxy-hint'),
    }

    this.#checks = new this.#lib.CheckList(byId('animeh-checks'))
    this.#stats = new this.#lib.StatsTable(byId('animeh-stats'))
    this.#log = new this.#lib.LogView(byId('animeh-log'))

    this.#fillPresets(this.#config.presets ?? [])
    this.#updateProxyHint()

    this.#els.start.addEventListener('click', () => void this.#start())
    this.#els.kbps.addEventListener('input', () => this.#updateProxyHint())
    this.#els.fail.addEventListener('input', () => this.#updateProxyHint())
    this.#els.preset.addEventListener('change', () => this.#applyPreset())
    this.#els.savePreset.addEventListener('click', () => void this.#savePreset())
    this.#els.fontInput.addEventListener('change', () => void this.#handleFontUpload())
  }

  /* ── Source form ──────────────────────────────────────────────────────── */

  #fillPresets(presets) {
    this.#presets = presets
    const options = ['<option value="">— seç —</option>']
    for (const preset of presets) {
      const label = preset.label || preset.source_url
      options.push(`<option value="${escapeAttr(preset.id)}">${escapeHtml(label)}</option>`)
    }
    this.#els.preset.innerHTML = options.join('')
  }

  #applyPreset() {
    const preset = (this.#presets ?? []).find((entry) => entry.id === this.#els.preset.value)
    if (!preset) return
    this.#els.url.value = preset.source_url ?? ''
    this.#els.type.value = preset.source_type ?? 'auto'
    this.#els.subtitle.value = preset.subtitle_url ?? ''
    this.#els.kbps.value = String(preset.throttle_kbps ?? 0)
    this.#updateProxyHint()
  }

  async #savePreset() {
    const url = this.#els.url.value.trim()
    if (!url) {
      this.#showFormError('Önce bir video URL gir.')
      return
    }
    try {
      const label = window.prompt('Bu kaynak için bir ad:', url) ?? ''
      const { preset } = await this.#api.createPreset({
        label: label.trim(),
        source_url: url,
        source_type: this.#els.type.value,
        subtitle_url: this.#els.subtitle.value.trim(),
        throttle_kbps: Number(this.#els.kbps.value) || 0,
      })
      this.#fillPresets([...(this.#presets ?? []), preset])
      this.#els.preset.value = preset.id
      this.#log.append(`kaynak kaydedildi: ${preset.label || preset.source_url}`, 'ok')
    } catch (error) {
      this.#showFormError(describeError(error))
    }
  }

  /** Whether the run will go through the throttling proxy, and why. */
  #updateProxyHint() {
    const kbps = Number(this.#els.kbps.value) || 0
    const fail = Number(this.#els.fail.value) || 0
    if (kbps > 0 || fail > 0) {
      this.#els.proxyHint.textContent =
        'Bu ayarlarla medya, WordPress üzerindeki kısıtlama proxy’sinden geçirilir. ' +
        'Trafik sunucundan akar; yalnızca test için kullan.'
    } else {
      this.#els.proxyHint.textContent = 'Medya doğrudan kaynağından çekilir.'
    }
  }

  #showFormError(message) {
    this.#els.formError.textContent = message
    this.#els.formError.hidden = !message
  }

  /* ── Running a test ───────────────────────────────────────────────────── */

  async #start() {
    this.#showFormError('')
    const url = this.#els.url.value.trim()
    if (!url) {
      this.#showFormError('Bir video URL gir.')
      return
    }

    await this.#teardown()
    this.#checks.clear()
    this.#log.clear()
    this.#lastReport = null

    const throttleKbps = Number(this.#els.kbps.value) || 0
    const failCount = Number(this.#els.fail.value) || 0
    const sourceType = this.#els.type.value
    const subtitleUrl = this.#els.subtitle.value.trim()

    // The server records the run and re-checks the URL; a rejection here means
    // the source never gets fetched at all.
    let session
    try {
      const created = await this.#api.createSession({
        source_url: url,
        source_type: sourceType,
        subtitle_url: subtitleUrl,
        throttle_kbps: throttleKbps,
      })
      session = created.session
      this.#sessionId = session.id
    } catch (error) {
      this.#showFormError(describeError(error))
      return
    }

    for (const [key, label] of CHECK_LABELS) {
      this.#checks.set({ key, label, state: 'pending', detail: key === 'source' ? url : '' })
    }

    const mediaUrl = this.#proxied(url, throttleKbps, failCount)
    const subtitles = subtitleUrl
      ? [
          {
            id: 'primary',
            language: 'tr',
            label: 'Altyazı',
            url: this.#proxied(subtitleUrl, 0, 0),
            format: guessSubtitleFormat(subtitleUrl),
            default: true,
          },
        ]
      : []

    this.#player = this.#lib.createPlayer(this.#els.slot, {
      subtitles: {
        workerUrl: this.#config.assets.worker,
        wasmUrl: this.#config.assets.wasm,
        modernWasmUrl: this.#config.assets.modernWasm,
      },
      preferredHeight: null,
    })

    // Fonts the backend holds are offered on demand: which faces a subtitle
    // needs is not known until its script has been parsed.
    this.#player.player.subtitles.fonts.setResolver({
      name: 'WordPress',
      origin: 'server',
      resolve: async (family) => {
        const found = await this.#api.resolveFont(family)
        return found ? { url: found.url } : null
      },
    })

    this.#bindPlayer(sourceType, url)

    this.#log.append(`yükleniyor: ${url}`)
    try {
      await this.#player.player.load({
        url: mediaUrl,
        type: sourceType,
        subtitles,
        episode: {
          animeId: 'test',
          episodeId: `session-${this.#sessionId}`,
          animeTitle: 'Player Test',
          episodeTitle: shortenUrl(url),
        },
      })
      this.#checks.update('source', 'ok', shortenUrl(url))
      await this.#player.player.play()
    } catch (error) {
      this.#checks.update('video', 'bad', describeError(error))
      this.#log.append(describeError(error), 'error')
    }

    this.#startAutosave()
  }

  #bindPlayer(sourceType, originalUrl) {
    const player = this.#player.player

    player.events.on('error', (error) => {
      this.#log.append(`${error.code}: ${error.message}`, error.fatal ? 'error' : 'warn')
      if (error.code === 'SUBTITLE_ERROR') this.#checks.update('subtitle', 'bad', error.code)
      if (error.fatal) this.#checks.update('video', 'bad', error.code)
    })

    player.events.on('fontReport', (report) => this.#showFontReport(report))
    player.events.on('ended', () => {
      this.#log.append('oynatma tamamlandı', 'ok')
      void this.#save()
    })

    let sawFirstFrame = false
    player.subscribe((snapshot) => {
      this.#stats.render(this.#lib.statsRows(snapshot, player.stats()))

      if (!sawFirstFrame && snapshot.phase === 'playing') {
        sawFirstFrame = true
        const startup = player.stats().startupTimeMs
        this.#checks.update('video', 'ok', `${startup ?? '?'} ms içinde başladı`)
        this.#log.append(`ilk kare ${startup} ms içinde`, 'ok')
      }
      if (snapshot.qualities.length > 0 && this.#checks.get('container')?.state === 'pending') {
        const top = [...snapshot.qualities].sort((a, b) => b.height - a.height)[0]
        const kind = sourceType === 'auto' ? guessContainer(originalUrl) : sourceType
        this.#checks.update(
          'container',
          'ok',
          `${kind.toUpperCase()} · ${snapshot.qualities.length} kalite · en yüksek ${top.label}`,
        )
      }
      if (snapshot.audioTracks.length > 0 && this.#checks.get('audio')?.state === 'pending') {
        const track = snapshot.audioTracks[0]
        this.#checks.update('audio', 'ok', `${track.codec ?? ''} ${track.label}`.trim())
      }
      if (snapshot.subtitleTracks.length > 0 && this.#checks.get('subtitle')?.state === 'pending') {
        const track = snapshot.subtitleTracks[0]
        const origin = track.origin === 'embedded' ? 'gömülü' : 'harici'
        this.#checks.update('subtitle', 'ok', `${track.format.toUpperCase()} · ${origin}`)
      }
    })
  }

  /* ── Fonts ────────────────────────────────────────────────────────────── */

  #showFontReport(report) {
    this.#lastReport = report
    this.#checks.update('renderer', 'ok', 'libass (wasm)')
    this.#lib.renderFontReport(this.#checks, report, {
      uploadLabel: 'Font Yükle',
      onUpload: (family) => this.#promptFontUpload(family),
    })
    if (report.missing.length > 0) {
      this.#log.append(`eksik font: ${report.missing.join(', ')}`, 'warn')
    }
  }

  /** @param {string} family */
  #promptFontUpload(family) {
    this.#pendingUploadFamily = family
    this.#els.uploadStatus.hidden = false
    this.#els.uploadStatus.textContent = `"${family}" için bir font dosyası seç.`
    this.#els.fontInput.value = ''
    this.#els.fontInput.click()
  }

  async #handleFontUpload() {
    const file = this.#els.fontInput.files?.[0]
    const family = this.#pendingUploadFamily
    if (!file) return

    this.#els.uploadStatus.hidden = false
    this.#els.uploadStatus.textContent = `${file.name} yükleniyor…`

    try {
      const { font } = await this.#api.uploadFont(file)
      this.#log.append(`font yüklendi: ${font.family} (${font.filename})`, 'ok')

      // The font was accepted, but its family is read from the file — which is
      // the point of reading it server-side, and also means it may not be the
      // family that was missing.
      const matches = family && this.#lib.fontKey(font.family) === this.#lib.fontKey(family)
      this.#els.uploadStatus.textContent = matches
        ? `"${font.family}" eklendi.`
        : `Yüklendi, ama fontun aile adı "${font.family}" — aranan "${family}" değil.`

      const refreshed = await this.#player?.player.subtitles.refreshFonts()
      if (refreshed) this.#showFontReport(refreshed)
      void this.#save()
    } catch (error) {
      this.#els.uploadStatus.textContent = describeError(error)
      this.#log.append(`font yüklenemedi: ${describeError(error)}`, 'error')
    } finally {
      this.#pendingUploadFamily = null
    }
  }

  /* ── Persistence ──────────────────────────────────────────────────────── */

  #startAutosave() {
    this.#stopAutosave()
    // Often enough that a browser crash loses little, rarely enough that a
    // throttled run is not competing with its own bookkeeping for bandwidth.
    this.#saveTimer = window.setInterval(() => void this.#save(), 10_000)
  }

  #stopAutosave() {
    if (this.#saveTimer !== null) {
      window.clearInterval(this.#saveTimer)
      this.#saveTimer = null
    }
  }

  async #save() {
    if (this.#sessionId === null || !this.#player) return
    try {
      await this.#api.updateSession(this.#sessionId, {
        metrics: this.#player.player.stats(),
        font_report: this.#lastReport ?? {},
        // Only the top-level checks decide the verdict. The per-family font
        // rows are marked bad so they stand out in the list, but a missing
        // font is a degradation, not a failed run — the summary row above
        // them already carries that as a warning.
        states: this.#checks
          .entries()
          .filter((entry) => !entry.nested)
          .map((entry) => entry.state),
        events: this.#log.lines(),
      })
    } catch {
      // Losing a progress write is not worth interrupting a test run over.
    }
  }

  async #teardown() {
    this.#stopAutosave()
    if (this.#player) {
      await this.#player.destroy()
      this.#player = null
    }
  }

  /** Route a URL through the proxy when throttling or fault injection is on. */
  #proxied(url, kbps, fail) {
    if (kbps <= 0 && fail <= 0) return url
    const proxy = new URL(this.#config.proxy.url, window.location.origin)
    proxy.searchParams.set('src', url)
    proxy.searchParams.set('_wpnonce', this.#config.proxy.nonce)
    if (kbps > 0) proxy.searchParams.set('kbps', String(kbps))
    if (fail > 0) proxy.searchParams.set('fail', String(fail))
    return proxy.toString()
  }

  async destroy() {
    await this.#teardown()
  }
}

/* ── Helpers ────────────────────────────────────────────────────────────── */

function guessSubtitleFormat(url) {
  const path = url.split(/[?#]/, 1)[0] ?? url
  const ext = path.slice(path.lastIndexOf('.') + 1).toLowerCase()
  if (ext === 'ssa') return 'ssa'
  if (ext === 'srt') return 'srt'
  if (ext === 'vtt') return 'vtt'
  return 'ass'
}

// Mirrors the player's own sniffing, for the container label in the checks.
function guessContainer(url) {
  const path = url.split(/[?#]/, 1)[0] ?? url
  const ext = path.slice(path.lastIndexOf('.') + 1).toLowerCase()
  if (ext === 'm3u8' || ext === 'm3u') return 'hls'
  if (ext === 'mkv' || ext === 'mka') return 'mkv'
  return 'mp4'
}

function shortenUrl(url) {
  try {
    const parsed = new URL(url, window.location.origin)
    const file = parsed.pathname.split('/').filter(Boolean).pop() ?? parsed.pathname
    return `${parsed.host}/…/${file}`
  } catch {
    return url
  }
}

export function describeError(error) {
  if (error instanceof ApiError) return error.message
  if (error instanceof Error) return error.message
  return String(error)
}

function escapeHtml(value) {
  return String(value).replace(
    /[&<>"']/g,
    (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char],
  )
}

function escapeAttr(value) {
  return escapeHtml(value)
}
