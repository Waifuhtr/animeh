/**
 * Drives the plugin's real admin JavaScript in a browser.
 *
 * WordPress cannot be installed in the development container, so the PHP that
 * calls WordPress APIs is not exercised here. Everything else is: the panel
 * code that ships in the plugin, the REST contract, the font upload loop from
 * the project brief, and the throttling proxy — against `tests/stub-server.php`,
 * which answers as the plugin's controllers do and delegates to the same
 * WordPress-free classes for validation and font parsing.
 */
import { mkdir } from 'node:fs/promises'
import { createRequire } from 'node:module'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const REPO = resolve(HERE, '..', '..', '..', '..')

// Playwright is a dependency of the player package, not of the plugin: the
// plugin ships no JavaScript toolchain of its own.
const { chromium } = createRequire(join(REPO, 'player', 'package.json'))('playwright')
const MEDIA = join(REPO, 'media')
const SHOTS = join(HERE, 'shots')
const BASE = process.env.STUB_URL ?? 'http://127.0.0.1:8765'
const CHROME = process.env.CHROME_PATH ?? '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'

let checks = 0
let failures = 0

function check(name, condition, detail = '') {
  checks++
  if (condition) {
    console.log(`  ok   ${name}${detail ? ` — ${detail}` : ''}`)
  } else {
    failures++
    console.log(`  FAIL ${name}${detail ? ` — ${detail}` : ''}`)
  }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function waitFor(page, fn, { timeout = 30_000, arg, label = 'condition' } = {}) {
  const deadline = Date.now() + timeout
  for (;;) {
    const value = await page.evaluate(fn, arg)
    if (value) return value
    if (Date.now() > deadline) throw new Error(`timed out waiting for ${label}`)
    await sleep(250)
  }
}

/** The check rows currently rendered, as data. */
const readChecks = () =>
  [...document.querySelectorAll('#animeh-checks .ap-check')].map((row) => ({
    state: row.dataset.state,
    label: row.querySelector('.ap-check__name')?.textContent ?? '',
    detail: row.querySelector('.ap-check__detail')?.textContent ?? '',
    action: row.querySelector('.ap-check__action')?.textContent ?? null,
  }))

async function fillSource(page, { url, type = 'auto', subtitle = '', kbps = 0, fail = 0 }) {
  await page.fill('#animeh-source-url', url)
  await page.selectOption('#animeh-source-type', type)
  await page.fill('#animeh-subtitle-url', subtitle)
  await page.fill('#animeh-kbps', String(kbps))
  await page.fill('#animeh-fail', String(fail))
}

async function main() {
  await mkdir(SHOTS, { recursive: true })

  const browser = await chromium.launch({
    executablePath: CHROME,
    args: ['--autoplay-policy=no-user-gesture-required', '--no-sandbox', '--mute-audio'],
  })
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } })

  const consoleErrors = []
  page.on('console', (m) => {
    if (m.type() === 'error') consoleErrors.push(m.text())
  })
  page.on('pageerror', (e) => consoleErrors.push(`pageerror: ${e.message}`))

  // The panel opens the picker itself; hand it the file when it does.
  let nextUpload = null
  page.on('filechooser', async (chooser) => {
    if (nextUpload) await chooser.setFiles(nextUpload)
  })

  try {
    // A run must not depend on being the first against a fresh server.
    const reset = await fetch(`${BASE}/__reset`).then((r) => r.ok).catch(() => false)
    if (!reset) {
      console.log('  FAIL could not reset the stub server state')
      process.exit(1)
    }

    /* ── 1. The panel mounts ──────────────────────────────────────────── */
    console.log('\n── Panel mounts and renders the source form ──')
    await page.goto(BASE, { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('#animeh-start', { timeout: 20_000 })

    check('source form rendered', await page.isVisible('#animeh-source-url'))
    check('checks list present', await page.isVisible('#animeh-checks'))
    check('proxy hint explains direct fetch', /doğrudan/.test(await page.textContent('#animeh-proxy-hint')))

    await page.fill('#animeh-kbps', '700')
    await sleep(150)
    check(
      'proxy hint warns when throttling',
      /proxy/i.test(await page.textContent('#animeh-proxy-hint')),
      'hint switches when a limit is set',
    )
    await page.fill('#animeh-kbps', '0')

    /* ── 2. An invalid URL is refused before anything is fetched ──────── */
    console.log('\n── A bad URL is refused up front ──')
    await fillSource(page, { url: 'ftp://example.com/video.mkv' })
    await page.click('#animeh-start')
    await sleep(800)
    const formError = (await page.textContent('#animeh-form-error')) ?? ''
    check('non-http source rejected', formError.trim().length > 0, formError.trim())

    /* ── 3. A real run: HLS + external ASS, empty font registry ───────── */
    console.log('\n── HLS run with an external ASS and no fonts registered yet ──')
    await fillSource(page, {
      url: `${BASE}/media/hls-vp9/master.m3u8`,
      type: 'hls',
      subtitle: `${BASE}/media/subtitle.ass`,
    })
    await page.click('#animeh-start')

    const started = await waitFor(
      page,
      () => {
        const v = document.querySelector('video')
        return v && v.readyState >= 3 && v.currentTime > 0.2
      },
      { timeout: 60_000, label: 'first frames' },
    ).catch(() => false)
    check('player starts from the panel', started === true)

    const videoInfo = await page.evaluate(() => {
      const v = document.querySelector('video')
      return { w: v?.videoWidth ?? 0, h: v?.videoHeight ?? 0, err: v?.error?.message ?? null }
    })
    check('video decodes', videoInfo.w > 0, `${videoInfo.w}x${videoInfo.h}`)

    // Every family the script names is missing: nothing has been uploaded yet.
    const missingRows = await waitFor(
      page,
      () => {
        const rows = [...document.querySelectorAll('#animeh-checks .ap-check')]
          .filter((r) => r.dataset.state === 'bad' && r.querySelector('.ap-check__action'))
        return rows.length >= 4 ? rows.length : false
      },
      { timeout: 40_000, label: 'missing font rows' },
    ).catch(() => 0)
    check('every required font reported missing', missingRows === 4, `${missingRows} rows`)

    const rows = await page.evaluate(readChecks)
    const uploadable = rows.filter((r) => r.action)
    check(
      'each missing font offers an upload action',
      uploadable.length === 4 && uploadable.every((r) => r.action === 'Font Yükle'),
      uploadable.map((r) => r.label).join(', '),
    )

    await page.screenshot({ path: join(SHOTS, 'panel-missing-fonts.png'), fullPage: true })

    /* ── 4. The upload loop from the brief ────────────────────────────── */
    console.log('\n── Uploading a missing font resolves it, live ──')

    const dejaVuRow = uploadable.find((r) => r.label === 'DejaVu Sans')
    check('DejaVu Sans listed as missing', Boolean(dejaVuRow))

    nextUpload = join(MEDIA, 'fonts', 'DejaVuSans.ttf')
    await page.click('#animeh-checks .ap-check[data-state="bad"] .ap-check__action >> nth=0')

    const resolved = await waitFor(
      page,
      () => {
        const row = [...document.querySelectorAll('#animeh-checks .ap-check')].find(
          (r) => r.querySelector('.ap-check__name')?.textContent === 'DejaVu Sans',
        )
        return row?.dataset.state === 'ok' ? row.querySelector('.ap-check__detail')?.textContent : false
      },
      { timeout: 30_000, label: 'font to resolve' },
    ).catch(() => null)

    check('uploaded font resolves without reloading', resolved !== null, `origin: ${resolved}`)
    check('resolved font is attributed to the backend', resolved === 'sunucu', String(resolved))

    const afterUpload = await page.evaluate(readChecks)
    const stillMissing = afterUpload.filter((r) => r.state === 'bad' && r.action)
    check('remaining fonts still reported', stillMissing.length === 3, `${stillMissing.length} left`)

    // The upload landed in the registry, keyed by the name read from the file.
    const registry = await page.evaluate(async () => {
      const response = await fetch('/wp-json/animeh/v1/fonts', {
        headers: { 'X-WP-Nonce': window.ANIMEH_ADMIN.nonce },
      })
      return response.json()
    })
    check(
      'registry stores the family from the file, not the filename',
      registry.fonts.length === 1 && registry.fonts[0].family === 'DejaVu Sans',
      `${registry.fonts[0]?.filename} → ${registry.fonts[0]?.family}`,
    )

    /* ── 5. Uploading the wrong face is called out ────────────────────── */
    console.log('\n── Uploading a font that is not the one asked for ──')
    const gothicRow = await page.evaluate(() => {
      const rows = [...document.querySelectorAll('#animeh-checks .ap-check')]
      const index = rows.findIndex(
        (r) => r.querySelector('.ap-check__name')?.textContent === 'Animeh Nonexistent Gothic',
      )
      return index
    })
    check('the genuinely absent font is still listed', gothicRow >= 0)

    nextUpload = join(MEDIA, 'fonts', 'DejaVuSerif.ttf')
    await page.evaluate(() => {
      const row = [...document.querySelectorAll('#animeh-checks .ap-check')].find(
        (r) => r.querySelector('.ap-check__name')?.textContent === 'Animeh Nonexistent Gothic',
      )
      row?.querySelector('.ap-check__action')?.click()
    })

    const mismatch = await waitFor(
      page,
      () => {
        const status = document.querySelector('#animeh-upload-status')
        const text = status?.textContent ?? ''
        return text.includes('aile adı') ? text : false
      },
      { timeout: 20_000, label: 'mismatch warning' },
    ).catch(() => null)
    check('mismatched family is called out, not silently accepted', mismatch !== null, String(mismatch))

    /* ── 6. The run is recorded server-side ───────────────────────────── */
    console.log('\n── The run is persisted ──')
    const sessions = await page.evaluate(async () => {
      const response = await fetch('/wp-json/animeh/v1/test/sessions', {
        headers: { 'X-WP-Nonce': window.ANIMEH_ADMIN.nonce },
      })
      return response.json()
    })
    const session = sessions.sessions?.[0]
    check('a session was created', Boolean(session), `id ${session?.id}`)
    check('metrics were recorded', typeof session?.metrics?.startupTimeMs === 'number',
      `startup ${session?.metrics?.startupTimeMs} ms`)
    check('the font report was stored', Array.isArray(session?.font_report?.missing),
      `${session?.font_report?.missing?.length} missing`)
    check('log lines were stored', (session?.events?.length ?? 0) > 0, `${session?.events?.length} events`)
    check('the server decided a verdict', typeof session?.verdict === 'string', session?.verdict)

    await page.screenshot({ path: join(SHOTS, 'panel-after-upload.png'), fullPage: true })

    /* ── 7. Throttled run through the proxy ───────────────────────────── */
    console.log('\n── Throttled run through the media proxy ──')
    await fillSource(page, {
      url: `${BASE}/media/hls-vp9/master.m3u8`,
      type: 'hls',
      subtitle: `${BASE}/media/subtitle.ass`,
      kbps: 700,
    })
    await page.click('#animeh-start')

    const throttledStarted = await waitFor(
      page,
      () => {
        const v = document.querySelector('video')
        return v && v.readyState >= 3 && v.currentTime > 0.2
      },
      { timeout: 90_000, label: 'throttled playback' },
    ).catch(() => false)
    check('playback starts over a throttled link', throttledStarted === true)

    const wentThroughProxy = await page.evaluate(() => {
      const v = document.querySelector('video')
      return v ? v.src.includes('/proxy') || performance.getEntriesByType('resource')
        .some((e) => e.name.includes('/proxy')) : false
    })
    check('media was fetched through the proxy', wentThroughProxy === true)

    await sleep(3000)
    const throttledStats = await page.evaluate(() => {
      const rows = [...document.querySelectorAll('#animeh-stats dt')]
      const out = {}
      for (const dt of rows) out[dt.textContent] = dt.nextElementSibling?.textContent ?? ''
      return out
    })
    check(
      'measured throughput reflects the cap',
      /kbps|Mbps/.test(throttledStats['Ortalama hız'] ?? ''),
      `${throttledStats['Ortalama hız']}`,
    )

    await page.screenshot({ path: join(SHOTS, 'panel-throttled.png'), fullPage: true })

    /* ── 8. The fonts screen ──────────────────────────────────────────── */
    console.log('\n── The font library screen ──')
    await page.goto(`${BASE}/?page=animeh-fonts`, { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('#animeh-font-rows tr', { timeout: 20_000 })

    const fontRows = await page.evaluate(() =>
      [...document.querySelectorAll('#animeh-font-rows tr')].map((row) =>
        [...row.querySelectorAll('td')].map((cell) => cell.textContent?.trim() ?? ''),
      ),
    )
    check('font library lists what was uploaded', fontRows.length >= 2, `${fontRows.length} rows`)
    check(
      'family column shows the name read from the file',
      fontRows.some((row) => row[0]?.startsWith('DejaVu Sans')),
      fontRows.map((r) => r[0]).join(' | '),
    )

    await page.screenshot({ path: join(SHOTS, 'panel-fonts.png'), fullPage: true })

    /* ── 9. Nothing broke along the way ───────────────────────────────── */
    const realErrors = consoleErrors.filter(
      (line) => !/favicon|Failed to load resource: the server responded with a status of 4/.test(line),
    )
    check('no unhandled console errors', realErrors.length === 0, realErrors.slice(0, 2).join(' | '))
  } finally {
    await browser.close()
  }

  console.log(`\n${checks - failures}/${checks} checks passed`)
  process.exit(failures === 0 ? 0 : 1)
}

await main()
