/**
 * The storage screen.
 *
 * Bucket configuration plus a connection test. The application key is
 * write-only from here: the server returns a mask, never the value, and an
 * empty field on save means "keep what is stored" — so the form can be
 * submitted repeatedly without the secret ever reaching the browser.
 */

import { describeError } from './test-panel.js'

/** Backblaze regions, with the endpoint each one implies. */
const REGIONS = [
  'us-west-000',
  'us-west-001',
  'us-west-002',
  'us-west-004',
  'us-east-005',
  'eu-central-003',
]

export class StoragePanel {
  #root
  #api
  #els = {}
  #settings = null

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
          <h2>Bucket</h2>
          <div class="animeh-row">
            <div class="animeh-field">
              <label for="animeh-region">Bölge</label>
              <input id="animeh-region" list="animeh-regions" placeholder="us-west-004" spellcheck="false" />
              <datalist id="animeh-regions">
                ${REGIONS.map((r) => `<option value="${r}"></option>`).join('')}
              </datalist>
            </div>
            <div class="animeh-field">
              <label for="animeh-bucket">Bucket adı</label>
              <input id="animeh-bucket" placeholder="animeh-media" spellcheck="false" />
            </div>
          </div>
          <div class="animeh-field">
            <label for="animeh-endpoint">S3 endpoint <span class="animeh-muted">(boş bırakırsan bölgeden türetilir)</span></label>
            <input id="animeh-endpoint" placeholder="s3.us-west-004.backblazeb2.com" spellcheck="false" />
          </div>
          <div class="animeh-row">
            <div class="animeh-field">
              <label for="animeh-key-id">Uygulama anahtarı kimliği</label>
              <input id="animeh-key-id" spellcheck="false" autocomplete="off" />
            </div>
            <div class="animeh-field">
              <label for="animeh-secret">Uygulama anahtarı</label>
              <input id="animeh-secret" type="password" spellcheck="false" autocomplete="new-password" />
              <span class="animeh-muted" id="animeh-secret-state"></span>
            </div>
          </div>
        </section>

        <section class="animeh-card">
          <h2>Yayın</h2>
          <div class="animeh-field">
            <label for="animeh-friendly">Friendly URL tabanı <span class="animeh-muted">(CDN veya özel alan adı)</span></label>
            <input id="animeh-friendly" placeholder="https://f004.backblazeb2.com/file/animeh-media" spellcheck="false" />
          </div>
          <div class="animeh-field">
            <label>
              <input type="checkbox" id="animeh-public" />
              Bucket herkese açık
            </label>
            <span class="animeh-muted" id="animeh-public-note"></span>
          </div>
          <div class="animeh-field">
            <label for="animeh-ttl">İmzalı bağlantı ömrü (saniye)</label>
            <input id="animeh-ttl" type="number" min="300" max="604800" step="300" value="3600" />
          </div>
        </section>

        <section class="animeh-card">
          <h2>Durum</h2>
          <div class="ap-checks" id="animeh-storage-checks"></div>
          <div class="animeh-row" style="margin-top: 12px">
            <button type="button" class="button button-primary" id="animeh-save">Kaydet</button>
            <button type="button" class="button" id="animeh-test">Bağlantıyı Sına</button>
          </div>
          <p class="animeh-error" id="animeh-storage-error" hidden></p>
          <p class="animeh-hint" id="animeh-storage-status" hidden></p>
        </section>
      </div>
    `

    const byId = (id) => this.#root.querySelector(`#${id}`)
    this.#els = {
      region: byId('animeh-region'),
      bucket: byId('animeh-bucket'),
      endpoint: byId('animeh-endpoint'),
      keyId: byId('animeh-key-id'),
      secret: byId('animeh-secret'),
      secretState: byId('animeh-secret-state'),
      friendly: byId('animeh-friendly'),
      publicBucket: byId('animeh-public'),
      publicNote: byId('animeh-public-note'),
      ttl: byId('animeh-ttl'),
      checks: byId('animeh-storage-checks'),
      save: byId('animeh-save'),
      test: byId('animeh-test'),
      error: byId('animeh-storage-error'),
      status: byId('animeh-storage-status'),
    }

    this.#els.save.addEventListener('click', () => void this.#save())
    this.#els.test.addEventListener('click', () => void this.#test())
    this.#els.publicBucket.addEventListener('change', () => this.#renderPublicNote())
  }

  async #load() {
    try {
      const { storage } = await this.#api.request('/storage/settings')
      this.#settings = storage
      this.#fill(storage)
    } catch (error) {
      this.#showError(describeError(error))
    }
  }

  #fill(storage) {
    this.#els.region.value = storage.region ?? ''
    this.#els.bucket.value = storage.bucket ?? ''
    this.#els.endpoint.value = storage.endpoint ?? ''
    this.#els.keyId.value = storage.key_id ?? ''
    this.#els.friendly.value = storage.friendly_base ?? ''
    this.#els.publicBucket.checked = Boolean(storage.public_bucket)
    this.#els.ttl.value = String(storage.link_ttl ?? 3600)

    this.#els.secret.value = ''
    this.#els.secret.placeholder = storage.has_secret ? storage.secret_masked : 'Uygulama anahtarını yapıştır'
    this.#els.secretState.textContent = storage.has_secret
      ? 'Kayıtlı. Değiştirmek istemiyorsan boş bırak.'
      : 'Henüz girilmedi.'

    this.#renderPublicNote()
    this.#renderChecks(storage)
  }

  #renderPublicNote() {
    // The two bucket modes hand the player very different URLs, and which one
    // is in use decides whether the friendly-to-S3 failover exists at all.
    this.#els.publicNote.textContent = this.#els.publicBucket.checked
      ? 'Oynatma adresi Friendly URL olur, S3 adresi yedek olarak verilir.'
      : 'Her oynatma için imzalı S3 adresi üretilir; Friendly URL özel bucket’ta çalışmaz.'
  }

  #renderChecks(storage) {
    const rows = [
      ['Yapılandırma', storage.configured ? 'ok' : 'bad', storage.configured ? 'tamam' : 'eksik alan var'],
      ['Uygulama anahtarı', storage.has_secret ? 'ok' : 'bad', storage.has_secret ? storage.secret_masked : 'girilmedi'],
      [
        'Şifreleme',
        storage.encryption ? 'ok' : 'warn',
        storage.encryption ? 'anahtar şifreli saklanıyor' : 'OpenSSL yok — anahtar düz saklanıyor',
      ],
      [
        'Bucket erişimi',
        storage.public_bucket ? 'ok' : 'ok',
        storage.public_bucket ? 'herkese açık' : 'özel (imzalı bağlantı)',
      ],
    ]

    this.#els.checks.replaceChildren(
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

  async #save() {
    this.#showError('')
    this.#els.save.disabled = true
    this.#setStatus('Kaydediliyor…')

    try {
      const { storage } = await this.#api.request('/storage/settings', {
        method: 'POST',
        json: {
          region: this.#els.region.value.trim(),
          bucket: this.#els.bucket.value.trim(),
          endpoint: this.#els.endpoint.value.trim(),
          key_id: this.#els.keyId.value.trim(),
          // Empty means "leave the stored one alone".
          secret: this.#els.secret.value,
          friendly_base: this.#els.friendly.value.trim(),
          public_bucket: this.#els.publicBucket.checked,
          link_ttl: Number(this.#els.ttl.value) || 3600,
        },
      })
      this.#settings = storage
      this.#fill(storage)
      this.#setStatus('Kaydedildi.')
    } catch (error) {
      this.#showError(describeError(error))
      this.#setStatus('')
    } finally {
      this.#els.save.disabled = false
    }
  }

  async #test() {
    this.#showError('')
    this.#els.test.disabled = true
    this.#setStatus('Bucket sınanıyor…')

    try {
      const { result } = await this.#api.request('/storage/test', { method: 'POST' })
      this.#setStatus(
        `Bağlantı çalışıyor — ${result.bucket} @ ${result.endpoint}, ${result.latency_ms} ms.`,
      )
      this.#markTested('ok', `${result.latency_ms} ms`)
    } catch (error) {
      this.#showError(describeError(error))
      this.#setStatus('')
      this.#markTested('bad', describeError(error))
    } finally {
      this.#els.test.disabled = false
    }
  }

  #markTested(state, detail) {
    const row = document.createElement('div')
    row.className = 'ap-check'
    row.dataset.state = state
    row.innerHTML =
      '<span class="ap-check__mark"></span><span class="ap-check__name"></span><span class="ap-check__detail"></span>'
    row.querySelector('.ap-check__mark').textContent = state === 'ok' ? '✓' : '✗'
    row.querySelector('.ap-check__name').textContent = 'Son sınama'
    row.querySelector('.ap-check__detail').textContent = detail
    // Replace any previous result rather than stacking them.
    this.#els.checks.querySelector('[data-test-result]')?.remove()
    row.dataset.testResult = 'true'
    this.#els.checks.append(row)
  }

  #showError(message) {
    this.#els.error.textContent = message
    this.#els.error.hidden = !message
  }

  #setStatus(message) {
    this.#els.status.textContent = message
    this.#els.status.hidden = !message
  }
}
