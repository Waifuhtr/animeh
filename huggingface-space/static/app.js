/**
 * The build console.
 *
 * Plain JavaScript, no framework — the page has one button and a log pane, and
 * a build tool that needs a build step of its own is a bad joke.
 *
 * The one thing worth care: the log arrives over Server-Sent Events and can
 * exceed a hundred thousand characters. Appending to `innerHTML` per line would
 * re-parse the whole pane on every line and lock the tab a minute into a build;
 * lines are batched into a DocumentFragment on an animation frame instead.
 */

const el = (id) => document.getElementById(id)

const ui = {
  badge: el('badge'),
  variant: el('variant'),
  apiBase: el('apiBase'),
  clean: el('clean'),
  build: el('build'),
  cancel: el('cancel'),
  download: el('download'),
  logfile: el('logfile'),
  result: el('result'),
  env: el('env').querySelector('tbody'),
  log: el('log'),
  follow: el('follow'),
  clearLog: el('clearLog'),
  elapsed: el('elapsed'),
}

let stream = null
let cursor = 0
let pending = []
let flushQueued = false

const LABELS = {
  idle: 'hazır',
  running: 'derleniyor',
  success: 'başarılı',
  failed: 'başarısız',
  cancelled: 'iptal edildi',
}

/** Colour a line by what it is, so a failure is findable by eye. */
function classify(line) {
  if (/^\$ /.test(line)) return 'cmd'
  if (/^═|^── BAŞARILI|BUILD SUCCESSFUL/.test(line)) return 'ok'
  if (/HATA|FAILURE|BUILD FAILED|error:|^e: |Exception|BAŞARISIZ/i.test(line)) return 'err'
  if (/warning|uyarı|^w: |deprecat/i.test(line)) return 'warn'
  return ''
}

function queueLines(lines) {
  pending.push(...lines)

  if (flushQueued) return
  flushQueued = true

  requestAnimationFrame(() => {
    flushQueued = false

    const batch = pending
    pending = []
    if (batch.length === 0) return

    const fragment = document.createDocumentFragment()

    for (const line of batch) {
      const kind = classify(line)
      if (kind) {
        const span = document.createElement('span')
        span.className = kind
        span.textContent = line + '\n'
        fragment.appendChild(span)
      } else {
        fragment.appendChild(document.createTextNode(line + '\n'))
      }
    }

    ui.log.appendChild(fragment)

    // Trim from the top rather than letting the DOM grow without bound; a
    // long Gradle build produces thousands of lines.
    while (ui.log.childNodes.length > 6000) {
      ui.log.removeChild(ui.log.firstChild)
    }

    if (ui.follow.checked) ui.log.scrollTop = ui.log.scrollHeight
  })
}

function setStatus(status, detail) {
  ui.badge.className = 'badge ' + status
  ui.badge.textContent = LABELS[status] || status

  const running = status === 'running'
  ui.build.disabled = running
  ui.cancel.disabled = !running
  ui.variant.disabled = running
  ui.apiBase.disabled = running
  ui.clean.disabled = running

  if (detail) {
    ui.result.hidden = false
    ui.result.className = 'result ' + status
    ui.result.textContent = detail
  }
}

async function loadEnv() {
  try {
    const response = await fetch('/api/env')
    const env = await response.json()

    const rows = [
      ['Java', env.java],
      ['Gradle', env.gradle],
      ['SDK', env.sdk_root],
      ['Platformlar', env.platforms.join(', ') || '—'],
      ['Build tools', env.build_tools.join(', ') || '—'],
      ['Proje', env.project_present ? env.project : 'BULUNAMADI'],
      ['Wrapper', env.wrapper_present ? 'var' : 'yok (gradle kullanılacak)'],
      ['Boş disk', env.disk_free_mb + ' MB'],
    ]

    ui.env.innerHTML = ''

    for (const [label, value] of rows) {
      const tr = document.createElement('tr')
      const key = document.createElement('td')
      const val = document.createElement('td')

      key.textContent = label
      val.textContent = value
      // The one row that matters before a build is attempted: without the
      // project there is nothing to compile.
      if (value === 'BULUNAMADI') val.className = 'bad'

      tr.append(key, val)
      ui.env.appendChild(tr)
    }
  } catch (error) {
    ui.env.innerHTML = '<tr><td colspan="2" class="bad">Ortam bilgisi okunamadı.</td></tr>'
  }
}

function openStream() {
  if (stream) stream.close()

  stream = new EventSource(`/api/logs/stream?after=${cursor}`)

  stream.onmessage = (event) => {
    const data = JSON.parse(event.data)
    queueLines([data.line])
  }

  stream.addEventListener('status', (event) => {
    const data = JSON.parse(event.data)
    cursor = data.cursor
    ui.elapsed.textContent = data.duration ? `${data.duration} sn` : ''

    if (data.status !== 'running') refreshStatus()
  })

  stream.addEventListener('done', () => {
    stream.close()
    stream = null
    refreshStatus()
  })

  stream.onerror = () => {
    // A Space can drop a long-lived connection; reopening from the cursor
    // picks up exactly where it left off rather than replaying the log.
    if (stream) {
      stream.close()
      stream = null
    }
    setTimeout(() => {
      if (ui.badge.classList.contains('running')) openStream()
    }, 2000)
  }
}

async function refreshStatus() {
  try {
    const response = await fetch('/api/status')
    const status = await response.json()

    setStatus(status.status, status.message)
    ui.elapsed.textContent = status.duration ? `${status.duration} sn` : ''

    ui.download.hidden = !status.apk_available
    ui.logfile.hidden = status.lines === 0

    if (status.status === 'running' && !stream) openStream()
  } catch (error) {
    // Nothing to do: the next tick tries again.
  }
}

ui.build.addEventListener('click', async () => {
  ui.log.textContent = ''
  ui.result.hidden = true
  ui.download.hidden = true
  cursor = 0

  setStatus('running')

  try {
    const response = await fetch('/api/build', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        variant: ui.variant.value,
        api_base: ui.apiBase.value.trim(),
        clean: ui.clean.checked,
      }),
    })

    if (!response.ok) {
      const error = await response.json().catch(() => ({}))
      setStatus('failed', error.detail || `HTTP ${response.status}`)
      return
    }

    openStream()
  } catch (error) {
    setStatus('failed', 'Sunucuya ulaşılamadı.')
  }
})

ui.cancel.addEventListener('click', async () => {
  ui.cancel.disabled = true
  await fetch('/api/cancel', { method: 'POST' }).catch(() => {})
})

ui.clearLog.addEventListener('click', () => {
  ui.log.textContent = ''
})

// Reconnect to a build already in progress: reloading the page mid-build
// should show the build, not an idle console.
loadEnv()
refreshStatus()
setInterval(() => {
  if (!stream) refreshStatus()
}, 5000)
