/**
 * Thin client for the plugin's REST API.
 *
 * WordPress authenticates REST calls from an admin page with a cookie plus the
 * `wp_rest` nonce; without the nonce the request is treated as unauthenticated
 * no matter who is logged in.
 */

/** @typedef {{restUrl: string, nonce: string}} AdminConfig */

export class ApiError extends Error {
  /**
   * @param {string} message Human-readable message from the server.
   * @param {number} status HTTP status.
   * @param {string} code Machine-readable error code.
   */
  constructor(message, status, code) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
  }
}

export class Api {
  #base
  #nonce

  /** @param {AdminConfig} config */
  constructor(config) {
    // Trailing slash normalised once, so every call site can just append.
    this.#base = config.restUrl.replace(/\/+$/, '')
    this.#nonce = config.nonce
  }

  /**
   * @param {string} path Path under the plugin namespace.
   * @param {RequestInit & {json?: unknown}} [options]
   */
  async request(path, options = {}) {
    const { json, headers, ...rest } = options
    const init = {
      credentials: 'same-origin',
      ...rest,
      headers: {
        'X-WP-Nonce': this.#nonce,
        ...(json !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...headers,
      },
    }
    if (json !== undefined) init.body = JSON.stringify(json)

    const response = await fetch(`${this.#base}${path}`, init)
    const text = await response.text()
    const body = text ? safeParse(text) : null

    if (!response.ok) {
      throw new ApiError(
        body?.message ?? `HTTP ${response.status}`,
        response.status,
        body?.code ?? 'http_error',
      )
    }
    return body
  }

  listFonts() {
    return this.request('/fonts')
  }

  /** @param {File} file */
  uploadFont(file) {
    const form = new FormData()
    form.append('font', file, file.name)
    // No Content-Type header: the browser must set the multipart boundary.
    return this.request('/fonts', { method: 'POST', body: form })
  }

  /** @param {number} id */
  deleteFont(id) {
    return this.request(`/fonts/${id}`, { method: 'DELETE' })
  }

  /**
   * Drop a family from the wanted list, or all of them when none is named.
   *
   * @param {string} [family]
   */
  forgetWantedFont(family = '') {
    const query = family ? `?family=${encodeURIComponent(family)}` : ''
    return this.request(`/fonts/wanted${query}`, { method: 'DELETE' })
  }

  /**
   * Look up one family. Resolves to null when the backend does not have it,
   * because "not registered" is an ordinary answer here, not a failure.
   * @param {string} family
   */
  async resolveFont(family) {
    try {
      return await this.request(`/fonts/resolve?family=${encodeURIComponent(family)}`)
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) return null
      throw error
    }
  }

  /** @param {object} source */
  createSession(source) {
    return this.request('/test/sessions', { method: 'POST', json: source })
  }

  /**
   * @param {number} id
   * @param {object} updates
   */
  updateSession(id, updates) {
    return this.request(`/test/sessions/${id}`, { method: 'PATCH', json: updates })
  }

  /** @param {number} [perPage] */
  listSessions(perPage = 10) {
    return this.request(`/test/sessions?per_page=${perPage}`)
  }

  /** @param {number} id */
  deleteSession(id) {
    return this.request(`/test/sessions/${id}`, { method: 'DELETE' })
  }

  listPresets() {
    return this.request('/test/presets')
  }

  /** @param {object} preset */
  createPreset(preset) {
    return this.request('/test/presets', { method: 'POST', json: preset })
  }

  /** @param {string} id */
  deletePreset(id) {
    return this.request(`/test/presets/${encodeURIComponent(id)}`, { method: 'DELETE' })
  }
}

/** @param {string} text */
function safeParse(text) {
  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}
