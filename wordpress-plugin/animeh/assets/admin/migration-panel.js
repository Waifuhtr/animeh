/**
 * The backup and migration screen.
 *
 * Three things live here because they are three answers to the same question —
 * "what happens when this site is not the site any more":
 *
 *   1. Snapshots into the bucket, which is the only copy that survives losing
 *      the host entirely.
 *   2. A one-time code that moves the library directly, for a planned move
 *      while the old site still answers.
 *   3. The backend pointer, which is what spares the app from being re-pointed
 *      by hand afterwards.
 *
 * Restore is destructive, so it asks twice: a checkbox on the row and a
 * confirm() before the request leaves.
 */

import { describeError } from './test-panel.js'

export class MigrationPanel {
  #root
  #api
  #els = {}
  #status = null
  #countdown = 0
  #timer = 0

  /**
   * @param {HTMLElement} root Mount point.
   * @param {import('./api.js').Api} api REST client.
   */
  constructor(root, api) {
    this.#root = root
    this.#api = api
    this.#render()
    void this.#load()
  }

  #render() {
    this.#root.innerHTML = `
      <div class="animeh-grid">
        <section class="animeh-card">
          <h2>Yedekler</h2>
          <div class="ap-checks" id="animeh-mig-checks"></div>
          <div class="animeh-field" style="margin-top: 12px">
            <label>
              <input type="checkbox" id="animeh-mig-schedule" />
              Her gün otomatik yedek al
            </label>
            <span class="animeh-muted">Yedek yalnızca eklentinin kendi tablolarını içerir; bucket anahtarları asla yedeğe girmez.</span>
          </div>
          <div class="animeh-row" style="margin-top: 12px">
            <button type="button" class="button button-primary" id="animeh-mig-snapshot">Şimdi Yedek Al</button>
            <button type="button" class="button" id="animeh-mig-refresh">Listeyi Yenile</button>
          </div>
          <p class="animeh-error" id="animeh-mig-error" hidden></p>
          <p class="animeh-hint" id="animeh-mig-status" hidden></p>
          <table class="widefat striped animeh-table" id="animeh-mig-table" hidden>
            <thead>
              <tr><th>Tarih</th><th>Boyut</th><th></th></tr>
            </thead>
            <tbody></tbody>
          </table>
        </section>

        <section class="animeh-card">
          <h2>Siteyi taşı</h2>
          <p class="animeh-muted">
            Eski site hâlâ ayaktaysa: eski sitede kod üret, yeni sitede o kodu ve eski sitenin adresini gir.
            Yeni site veriyi kendisi çeker.
          </p>

          <div class="animeh-field">
            <strong>Bu site eski site ise</strong>
            <div class="animeh-row" style="margin-top: 8px">
              <button type="button" class="button" id="animeh-mig-code">Taşıma Kodu Üret</button>
              <button type="button" class="button" id="animeh-mig-code-cancel" hidden>İptal Et</button>
            </div>
            <p class="animeh-code" id="animeh-mig-code-value" hidden></p>
            <span class="animeh-muted" id="animeh-mig-code-note"></span>
          </div>

          <hr />

          <div class="animeh-field">
            <strong>Bu site yeni site ise</strong>
            <label for="animeh-mig-source" style="margin-top: 8px">Eski sitenin adresi</label>
            <input id="animeh-mig-source" placeholder="https://eski-site.com" spellcheck="false" />
            <label for="animeh-mig-code-input">Taşıma kodu</label>
            <input id="animeh-mig-code-input" placeholder="ABCDE-FGHJK-MNPQR-STVWX" spellcheck="false" autocomplete="off" />
            <div class="animeh-row" style="margin-top: 12px">
              <button type="button" class="button button-primary" id="animeh-mig-pull">Veriyi Çek</button>
            </div>
            <span class="animeh-muted">Çekme işlemi bu sitedeki mevcut kütüphane verisinin üzerine yazar.</span>
          </div>
        </section>

        <section class="animeh-card">
          <h2>Uygulama yönlendirmesi</h2>
          <p class="animeh-muted">
            Uygulama, kayıtlı adrese ulaşamazsa bucket içindeki
            <code>_animeh/backend.json</code> dosyasına bakar ve orada yazan adrese geçer.
          </p>
          <div class="ap-checks" id="animeh-mig-pointer"></div>
          <div class="animeh-row" style="margin-top: 12px">
            <button type="button" class="button" id="animeh-mig-claim">Bu Siteyi Aktif Backend Yap</button>
          </div>
        </section>
      </div>
    `

    const byId = (id) => this.#root.querySelector(`#${id}`)
    this.#els = {
      checks: byId('animeh-mig-checks'),
      schedule: byId('animeh-mig-schedule'),
      snapshot: byId('animeh-mig-snapshot'),
      refresh: byId('animeh-mig-refresh'),
      error: byId('animeh-mig-error'),
      status: byId('animeh-mig-status'),
      table: byId('animeh-mig-table'),
      tbody: byId('animeh-mig-table').querySelector('tbody'),
      code: byId('animeh-mig-code'),
      codeCancel: byId('animeh-mig-code-cancel'),
      codeValue: byId('animeh-mig-code-value'),
      codeNote: byId('animeh-mig-code-note'),
      source: byId('animeh-mig-source'),
      codeInput: byId('animeh-mig-code-input'),
      pull: byId('animeh-mig-pull'),
      pointer: byId('animeh-mig-pointer'),
      claim: byId('animeh-mig-claim'),
    }

    this.#els.snapshot.addEventListener('click', () => void this.#snapshot())
    this.#els.refresh.addEventListener('click', () => void this.#loadList())
    this.#els.schedule.addEventListener('change', () => void this.#setSchedule())
    this.#els.code.addEventListener('click', () => void this.#openHandoff())
    this.#els.codeCancel.addEventListener('click', () => void this.#closeHandoff())
    this.#els.pull.addEventListener('click', () => void this.#pull())
    this.#els.claim.addEventListener('click', () => void this.#claim())
  }

  async #load() {
    try {
      this.#status = await this.#api.request('/migration/status')
      this.#fill()
      if (this.#status.storage_configured) {
        await this.#loadList()
        await this.#loadPointer()
      }
    } catch (error) {
      this.#showError(describeError(error))
    }
  }

  #fill() {
    const s = this.#status
    this.#els.schedule.checked = Boolean(s.scheduled)

    const last = s.last_snapshot ?? {}
    const lastState = last.ok === true ? 'ok' : last.ok === false ? 'bad' : 'warn'
    // PHP hands back an empty *array* when nothing is recorded, which arrives
    // as `[]` — and `[].at` is Array.prototype.at, not undefined. Check the
    // type, or an untouched install reports "Invalid Date".
    const lastDetail =
      typeof last.at !== 'number'
        ? 'henüz alınmadı'
        : `${new Date(last.at * 1000).toLocaleString('tr-TR')}${last.ok ? ` · ${formatBytes(last.bytes ?? 0)}` : ` · ${last.message ?? 'başarısız'}`}`

    this.#renderChecks(this.#els.checks, [
      [
        'Bucket',
        s.storage_configured ? 'ok' : 'bad',
        s.storage_configured ? 'yapılandırıldı' : 'önce Depolama ekranından bucket bilgilerini gir',
      ],
      ['Otomatik yedek', s.scheduled ? 'ok' : 'warn', s.scheduled ? 'günlük' : 'kapalı'],
      ['Son yedek', lastState, lastDetail],
      ['Saklanan yedek sayısı', 'ok', `en fazla ${s.keep}`],
    ])

    this.#renderHandoff(s.handoff ?? { open: false })

    // A snapshot without a bucket to put it in cannot work; saying so on the
    // button beats a failed request.
    this.#els.snapshot.disabled = !s.storage_configured
  }

  #renderHandoff(handoff) {
    if (!handoff.open) {
      this.#els.codeValue.hidden = true
      this.#els.codeCancel.hidden = true
      this.#els.codeNote.textContent = handoff.used
        ? 'Son kod kullanıldı. Yeni bir taşıma için kod üret.'
        : 'Kod tek kullanımlıktır ve 30 dakika geçerlidir.'
      this.#stopCountdown()
      return
    }

    this.#els.codeCancel.hidden = false
    this.#startCountdown(handoff.expires_in ?? 0)
  }

  #startCountdown(seconds) {
    this.#stopCountdown()
    this.#countdown = seconds
    const tick = () => {
      if (this.#countdown <= 0) {
        this.#els.codeNote.textContent = 'Kodun süresi doldu.'
        this.#els.codeValue.hidden = true
        this.#els.codeCancel.hidden = true
        this.#stopCountdown()
        return
      }
      const m = Math.floor(this.#countdown / 60)
      const sec = this.#countdown % 60
      this.#els.codeNote.textContent = `Kod açık — ${m}:${String(sec).padStart(2, '0')} kaldı.`
      this.#countdown -= 1
    }
    tick()
    this.#timer = window.setInterval(tick, 1000)
  }

  #stopCountdown() {
    if (this.#timer) {
      window.clearInterval(this.#timer)
      this.#timer = 0
    }
  }

  async #loadList() {
    if (!this.#status?.storage_configured) return
    try {
      const { snapshots } = await this.#api.request('/migration/snapshots')
      this.#renderList(snapshots ?? [])
    } catch (error) {
      this.#showError(describeError(error))
    }
  }

  #renderList(snapshots) {
    this.#els.table.hidden = snapshots.length === 0
    this.#els.tbody.replaceChildren(
      ...snapshots.map((snapshot) => {
        const row = document.createElement('tr')

        const when = document.createElement('td')
        when.textContent = readableKey(snapshot.key)

        const size = document.createElement('td')
        size.textContent = formatBytes(snapshot.size)

        const actions = document.createElement('td')
        const button = document.createElement('button')
        button.type = 'button'
        button.className = 'button button-link-delete'
        button.textContent = 'Bu yedeğe dön'
        button.addEventListener('click', () => void this.#restore(snapshot.key))
        actions.append(button)

        row.append(when, size, actions)
        return row
      }),
    )
  }

  async #snapshot() {
    this.#showError('')
    this.#els.snapshot.disabled = true
    this.#setStatus('Yedek alınıyor…')
    try {
      const result = await this.#api.request('/migration/snapshots', { method: 'POST' })
      const counts = Object.entries(result.counts ?? {})
        .map(([table, n]) => `${table.replace('animeh_', '')}: ${n}`)
        .join(' · ')
      this.#setStatus(`Yedek alındı — ${formatBytes(result.bytes)}${counts ? ` · ${counts}` : ''}`)
      await this.#load()
    } catch (error) {
      this.#showError(describeError(error))
      this.#setStatus('')
    } finally {
      this.#els.snapshot.disabled = false
    }
  }

  async #setSchedule() {
    const enabled = this.#els.schedule.checked
    try {
      await this.#api.request('/migration/schedule', { method: 'POST', json: { enabled } })
      this.#setStatus(enabled ? 'Günlük yedek açıldı.' : 'Günlük yedek kapatıldı.')
    } catch (error) {
      this.#showError(describeError(error))
      // Put the box back where the server actually is, rather than leaving it
      // showing a state that was never saved.
      this.#els.schedule.checked = !enabled
    }
  }

  async #restore(key) {
    if (!window.confirm(`${readableKey(key)} yedeğine dönülecek. Bu sitedeki mevcut kütüphane verisi silinip yedektekiyle değiştirilecek. Devam edilsin mi?`)) {
      return
    }

    this.#showError('')
    this.#setStatus('Geri yükleniyor…')
    try {
      const result = await this.#api.request('/migration/restore', {
        method: 'POST',
        json: { key, confirm: true },
      })
      this.#setStatus(`Geri yüklendi — ${describeRestore(result)}`)
      await this.#load()
    } catch (error) {
      this.#showError(describeError(error))
      this.#setStatus('')
    }
  }

  async #openHandoff() {
    this.#showError('')
    try {
      const result = await this.#api.request('/migration/handoff', { method: 'POST' })
      this.#els.codeValue.textContent = result.code
      this.#els.codeValue.hidden = false
      this.#renderHandoff({ open: true, expires_in: result.expires_in })
    } catch (error) {
      this.#showError(describeError(error))
    }
  }

  async #closeHandoff() {
    try {
      await this.#api.request('/migration/handoff', { method: 'DELETE' })
      this.#els.codeValue.hidden = true
      this.#renderHandoff({ open: false })
    } catch (error) {
      this.#showError(describeError(error))
    }
  }

  async #pull() {
    const source = this.#els.source.value.trim()
    const code = this.#els.codeInput.value.trim()
    if (!source || !code) {
      this.#showError('Eski sitenin adresi ve taşıma kodu gerekli.')
      return
    }
    if (!window.confirm('Eski siteden gelen veri bu sitedeki kütüphanenin üzerine yazılacak. Devam edilsin mi?')) {
      return
    }

    this.#showError('')
    this.#els.pull.disabled = true
    this.#setStatus('Eski siteden veri çekiliyor…')
    try {
      const result = await this.#api.request('/migration/pull', {
        method: 'POST',
        json: { source_url: source, code },
      })
      this.#setStatus(`Taşındı — ${describeRestore(result)}`)
      this.#els.codeInput.value = ''
      await this.#load()
    } catch (error) {
      this.#showError(describeError(error))
      this.#setStatus('')
    } finally {
      this.#els.pull.disabled = false
    }
  }

  async #loadPointer() {
    try {
      const { pointer, is_self: isSelf } = await this.#api.request('/migration/pointer')
      this.#renderChecks(this.#els.pointer, [
        [
          'Kayıtlı backend',
          isSelf ? 'ok' : 'warn',
          pointer?.site_url ? pointer.site_url : 'henüz yazılmadı',
        ],
        [
          'Bu site',
          isSelf ? 'ok' : 'warn',
          isSelf ? 'aktif backend' : 'aktif değil — uygulama başka adrese gidiyor',
        ],
        ['Güncellenme', 'ok', pointer?.updated_at ? new Date(pointer.updated_at).toLocaleString('tr-TR') : '—'],
      ])
    } catch (error) {
      this.#renderChecks(this.#els.pointer, [['Kayıtlı backend', 'warn', describeError(error)]])
    }
  }

  async #claim() {
    this.#showError('')
    this.#els.claim.disabled = true
    try {
      await this.#api.request('/migration/pointer', { method: 'POST' })
      this.#setStatus('Uygulama bundan sonra bu siteyi kullanacak.')
      await this.#loadPointer()
    } catch (error) {
      this.#showError(describeError(error))
    } finally {
      this.#els.claim.disabled = false
    }
  }

  /**
   * @param {HTMLElement} host Container.
   * @param {Array<[string, string, string]>} rows Label, state, detail.
   */
  #renderChecks(host, rows) {
    host.replaceChildren(
      ...rows.map(([label, state, detail]) => {
        const row = document.createElement('div')
        row.className = 'ap-check'
        row.dataset.state = state
        row.innerHTML =
          '<span class="ap-check__mark"></span><span class="ap-check__name"></span><span class="ap-check__detail"></span>'
        row.querySelector('.ap-check__mark').textContent = { ok: '✓', warn: '!', bad: '✗' }[state]
        row.querySelector('.ap-check__name').textContent = label
        row.querySelector('.ap-check__detail').textContent = detail
        return row
      }),
    )
  }

  #showError(message) {
    this.#els.error.textContent = message
    this.#els.error.hidden = !message
  }

  #setStatus(message) {
    this.#els.status.textContent = message
    this.#els.status.hidden = !message
  }

  destroy() {
    this.#stopCountdown()
  }
}

/** @param {number} bytes */
function formatBytes(bytes) {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const exponent = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)))
  return `${(bytes / 1024 ** exponent).toFixed(exponent === 0 ? 0 : 1)} ${units[exponent]}`
}

/**
 * Snapshot keys are timestamps; show them the way a person reads a date.
 * @param {string} key
 */
function readableKey(key) {
  const match = /(\d{4})-(\d{2})-(\d{2})T(\d{2})(\d{2})(\d{2})Z/.exec(key)
  if (!match) return key.split('/').pop() ?? key
  const [, y, mo, d, h, mi, s] = match
  return new Date(Date.UTC(+y, +mo - 1, +d, +h, +mi, +s)).toLocaleString('tr-TR')
}

/** @param {{restored?: Record<string, number>, origin?: {site_url?: string}}} result */
function describeRestore(result) {
  const counts = Object.entries(result.restored ?? {})
    .map(([table, n]) => `${table.replace('animeh_', '')}: ${n}`)
    .join(' · ')
  const from = result.origin?.site_url ? ` (${result.origin.site_url})` : ''
  return `${counts}${from}`
}
