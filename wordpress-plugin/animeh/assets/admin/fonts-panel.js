/**
 * The font library screen.
 *
 * Lists what the registry holds and accepts uploads. The family column is the
 * name read out of the font file itself, not the filename — that is the name a
 * subtitle will ask for, and the two are rarely the same.
 */

import { describeError } from './test-panel.js'

export class FontsPanel {
  #root
  #api
  #els = {}
  #fonts = []
  #wanted = []

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
      <div class="animeh-grid animeh-grid--single">
        <section class="animeh-card">
          <h2>Font Yükle</h2>
          <p class="animeh-hint">
            .ttf, .otf, .ttc, .woff ve .woff2 kabul edilir. Dosya içeriğine bakılarak doğrulanır;
            uzantıya güvenilmez. Aynı font iki kez yüklenirse tek kayıt tutulur.
          </p>
          <div class="animeh-row">
            <input type="file" id="animeh-font-file" accept=".ttf,.otf,.ttc,.woff,.woff2" multiple />
            <button type="button" class="button button-primary" id="animeh-font-upload">Yükle</button>
          </div>
          <p class="animeh-error" id="animeh-font-error" hidden></p>
          <p class="animeh-hint" id="animeh-font-status" hidden></p>
        </section>

        <section class="animeh-card" id="animeh-wanted-card" hidden>
          <h2>İstenen Fontlar <span class="animeh-count" id="animeh-wanted-count"></span></h2>
          <p class="animeh-hint">
            Altyazıların istediği ama burada bulunmayan aileler. Uygulama bir bölümü oynatırken
            bunları bildiriyor. Dosya adının birebir aynı olması gerekmiyor — "Sans" istenmişse
            <code>sans-test.ttf</code> de cevap verir, kalın hali istenmişse ailenin kendisi yeter.
          </p>
          <table class="widefat striped animeh-table">
            <thead>
              <tr>
                <th scope="col">Aile adı</th>
                <th scope="col">Kaç kez</th>
                <th scope="col">Son</th>
                <th scope="col"></th>
              </tr>
            </thead>
            <tbody id="animeh-wanted-rows"></tbody>
          </table>
        </section>

        <section class="animeh-card">
          <h2>Kayıtlı Fontlar <span class="animeh-count" id="animeh-font-count"></span></h2>
          <table class="widefat striped animeh-table">
            <thead>
              <tr>
                <th scope="col">Aile adı</th>
                <th scope="col">Dosya</th>
                <th scope="col">Biçim</th>
                <th scope="col">Boyut</th>
                <th scope="col"></th>
              </tr>
            </thead>
            <tbody id="animeh-font-rows">
              <tr><td colspan="5">Yükleniyor…</td></tr>
            </tbody>
          </table>
        </section>
      </div>
    `

    const byId = (id) => this.#root.querySelector(`#${id}`)
    this.#els = {
      file: byId('animeh-font-file'),
      upload: byId('animeh-font-upload'),
      error: byId('animeh-font-error'),
      status: byId('animeh-font-status'),
      rows: byId('animeh-font-rows'),
      count: byId('animeh-font-count'),
      wantedCard: byId('animeh-wanted-card'),
      wantedRows: byId('animeh-wanted-rows'),
      wantedCount: byId('animeh-wanted-count'),
    }

    this.#els.upload.addEventListener('click', () => void this.#upload())
  }

  async #load() {
    try {
      const { fonts, wanted } = await this.#api.listFonts()
      this.#fonts = fonts
      this.#wanted = wanted ?? []
      this.#renderRows()
      this.#renderWanted()
    } catch (error) {
      this.#els.rows.innerHTML = `<tr><td colspan="5">${escapeHtml(describeError(error))}</td></tr>`
    }
  }

  /**
   * What the subtitles asked for and did not get.
   *
   * The card hides itself when the list is empty rather than sitting there
   * saying "nothing" — an empty list here is the normal, good state, and a
   * permanent empty table trains people to stop looking at it.
   */
  #renderWanted() {
    this.#els.wantedCard.hidden = this.#wanted.length === 0
    this.#els.wantedCount.textContent = this.#wanted.length > 0 ? `(${this.#wanted.length})` : ''

    if (this.#wanted.length === 0) return

    this.#els.wantedRows.replaceChildren(
      ...this.#wanted.map((entry) => {
        const row = document.createElement('tr')
        row.innerHTML = `
          <td><strong>${escapeHtml(entry.family)}</strong></td>
          <td>${entry.count}</td>
          <td class="animeh-muted">${escapeHtml(entry.last_seen ?? '')}</td>
          <td class="animeh-table__actions"></td>
        `
        const forget = document.createElement('button')
        forget.type = 'button'
        forget.className = 'button button-link-delete'
        forget.textContent = 'Listeden çıkar'
        forget.addEventListener('click', () => void this.#forget(entry))
        row.querySelector('.animeh-table__actions').append(forget)
        return row
      }),
    )
  }

  async #forget(entry) {
    try {
      const { wanted } = await this.#api.forgetWantedFont(entry.family)
      this.#wanted = wanted ?? []
      this.#renderWanted()
    } catch (error) {
      this.#showError(describeError(error))
    }
  }

  #renderRows() {
    this.#els.count.textContent = this.#fonts.length > 0 ? `(${this.#fonts.length})` : ''

    if (this.#fonts.length === 0) {
      this.#els.rows.innerHTML =
        '<tr><td colspan="5">Henüz font yüklenmedi. Altyazılar eksik font bildirdiğinde buraya ekleyebilirsin.</td></tr>'
      return
    }

    this.#els.rows.replaceChildren(
      ...this.#fonts.map((font) => {
        const row = document.createElement('tr')
        row.innerHTML = `
          <td><strong>${escapeHtml(font.family)}</strong>${
            font.postscript_name
              ? `<br><span class="animeh-muted">${escapeHtml(font.postscript_name)}</span>`
              : ''
          }</td>
          <td>${escapeHtml(font.filename)}</td>
          <td>${escapeHtml(font.format.toUpperCase())}</td>
          <td>${formatBytes(font.size_bytes)}</td>
          <td class="animeh-table__actions"></td>
        `
        const remove = document.createElement('button')
        remove.type = 'button'
        remove.className = 'button button-link-delete'
        remove.textContent = 'Sil'
        remove.addEventListener('click', () => void this.#delete(font))
        row.querySelector('.animeh-table__actions').append(remove)
        return row
      }),
    )
  }

  async #upload() {
    const files = [...(this.#els.file.files ?? [])]
    if (files.length === 0) {
      this.#showError('Önce bir dosya seç.')
      return
    }

    this.#showError('')
    this.#els.upload.disabled = true

    const added = []
    const failed = []

    // Uploaded one at a time so a single rejected file does not take the batch
    // with it, and so the message can name which one failed.
    for (const file of files) {
      this.#setStatus(`${file.name} yükleniyor…`)
      try {
        const { font } = await this.#api.uploadFont(file)
        added.push(font)
      } catch (error) {
        failed.push(`${file.name}: ${describeError(error)}`)
      }
    }

    this.#els.upload.disabled = false
    this.#els.file.value = ''

    if (added.length > 0) {
      this.#setStatus(`${added.length} font eklendi: ${added.map((font) => font.family).join(', ')}`)
    } else {
      this.#setStatus('')
    }
    if (failed.length > 0) {
      this.#showError(failed.join(' · '))
    }

    await this.#load()
  }

  async #delete(font) {
    const confirmed = window.confirm(`"${font.family}" silinsin mi?`)
    if (!confirmed) return
    try {
      await this.#api.deleteFont(font.id)
      this.#fonts = this.#fonts.filter((entry) => entry.id !== font.id)
      this.#renderRows()
    } catch (error) {
      this.#showError(describeError(error))
    }
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

function formatBytes(bytes) {
  if (bytes >= 1_000_000) return `${(bytes / 1_000_000).toFixed(1)} MB`
  if (bytes >= 1000) return `${Math.round(bytes / 1000)} KB`
  return `${bytes} B`
}

function escapeHtml(value) {
  return String(value).replace(
    /[&<>"']/g,
    (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char],
  )
}
