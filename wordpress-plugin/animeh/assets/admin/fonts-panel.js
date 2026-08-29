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
    }

    this.#els.upload.addEventListener('click', () => void this.#upload())
  }

  async #load() {
    try {
      const { fonts } = await this.#api.listFonts()
      this.#fonts = fonts
      this.#renderRows()
    } catch (error) {
      this.#els.rows.innerHTML = `<tr><td colspan="5">${escapeHtml(describeError(error))}</td></tr>`
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
