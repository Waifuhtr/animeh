/**
 * Drives the Space's real UI in Chromium against a stub gradle.
 *
 * The Android SDK is unavailable here, so what is being checked is everything
 * around the compiler: that a build starts, that its log streams live rather
 * than arriving at the end, that success surfaces a downloadable APK, that a
 * failure is reported as one, and that cancelling actually stops it.
 */
import { createRequire } from 'node:module'

const require = createRequire('/home/user/animeh/player/package.json')
const { chromium } = require('playwright')

const BASE = 'http://127.0.0.1:7860'
const CHROME = process.env.CHROME_PATH ?? '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'

let checks = 0
let failures = 0

function check(name, ok, detail = '') {
  checks++
  if (ok) console.log(`  ok   ${name}${detail ? ` — ${detail}` : ''}`)
  else { failures++; console.log(`  FAIL ${name}${detail ? ` — ${detail}` : ''}`) }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function waitFor(page, fn, { timeout = 30000, label = 'condition' } = {}) {
  const deadline = Date.now() + timeout
  for (;;) {
    if (await page.evaluate(fn)) return true
    if (Date.now() > deadline) throw new Error(`timed out waiting for ${label}`)
    await sleep(200)
  }
}

const badge = () => document.getElementById('badge').textContent
const logText = () => document.getElementById('log').textContent

async function main() {
  const browser = await chromium.launch({ executablePath: CHROME })
  const page = await browser.newPage()

  const errors = []
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()) })
  page.on('pageerror', (e) => errors.push(String(e)))

  try {
    console.log('\n── The console loads ──')
    await page.goto(BASE, { waitUntil: 'domcontentloaded' })
    // The placeholder row matches '#env tr' straight away, so wait for the
    // real table rather than for any row at all.
    await waitFor(page, () => document.querySelectorAll('#env tr').length > 2,
      { timeout: 15000, label: 'environment table' })

    const envRows = await page.evaluate(() =>
      [...document.querySelectorAll('#env tr')].map((r) =>
        [...r.querySelectorAll('td')].map((c) => c.textContent),
      ),
    )
    check('environment panel filled', envRows.length >= 6, `${envRows.length} rows`)
    check(
      'project is found at the expected path',
      envRows.some(([k, v]) => k === 'Proje' && v.endsWith('/android')),
      envRows.find(([k]) => k === 'Proje')?.[1],
    )
    check(
      'wrapper is detected',
      envRows.some(([k, v]) => k === 'Wrapper' && v === 'var'),
    )
    check('starts idle', (await page.evaluate(badge)) === 'hazır')

    console.log('\n── A successful debug build ──')
    await page.click('#build')
    await waitFor(page, badge, { label: 'status to change' })
    check('status switches to running', (await page.evaluate(badge)) === 'derleniyor')

    const buildDisabled = await page.evaluate(() => document.getElementById('build').disabled)
    check('build button locks while running', buildDisabled === true)

    // The point of the stream: output appears *during* the build.
    await waitFor(page, () => document.getElementById('log').textContent.includes('compileDebugKotlin'),
      { label: 'live log output' })
    const midBuild = await page.evaluate(badge)
    check('log streams while the build is still running', midBuild === 'derleniyor')

    await waitFor(page, () => document.getElementById('badge').textContent !== 'derleniyor',
      { timeout: 60000, label: 'build to finish' })

    check('build succeeds', (await page.evaluate(badge)) === 'başarılı')

    const log = await page.evaluate(logText)
    check('header names the variant', /Varyant\s*:\s*debug/.test(log))
    check('the gradle command is shown', log.includes('$ ') && log.includes('assembleDebug'))
    check('gradle output is present', log.includes('BUILD SUCCESSFUL'))
    check('the APK is named in the log', /APK: app-debug\.apk/.test(log))

    const result = await page.evaluate(() => document.getElementById('result').textContent)
    check('result line reports size', /app-debug\.apk.*MB/.test(result), result)

    const downloadShown = await page.evaluate(() => !document.getElementById('download').hidden)
    check('download button appears', downloadShown === true)

    console.log('\n── The APK actually downloads ──')
    const apk = await fetch(`${BASE}/api/download`)
    const bytes = (await apk.arrayBuffer()).byteLength
    check('APK downloads', apk.ok && bytes > 1_000_000, `${bytes} bytes`)
    check(
      'served with the APK content type',
      apk.headers.get('content-type')?.includes('android.package-archive'),
      apk.headers.get('content-type'),
    )

    const logFile = await fetch(`${BASE}/api/log-file`)
    check('the log file downloads too', logFile.ok)

    console.log('\n── Colouring makes a failure findable ──')
    const warnCount = await page.evaluate(() => document.querySelectorAll('#log .warn').length)
    check('warnings are highlighted', warnCount > 0, `${warnCount} lines`)

    console.log('\n── A failing build is reported as failing ──')
    // The stub reads STUB_FAIL from its environment; the server inherits it.
    await fetch(`${BASE}/api/build`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ variant: 'debug', api_base: '', clean: false }),
    })
    await page.reload({ waitUntil: 'domcontentloaded' })
    await waitFor(page, () => document.getElementById('badge').textContent === 'derleniyor',
      { timeout: 15000, label: 'second build to start' })
    await waitFor(page, () => document.getElementById('badge').textContent !== 'derleniyor',
      { timeout: 90000, label: 'second build to finish' })

    const failStatus = await (await fetch(`${BASE}/api/status`)).json()
    if (process.env.STUB_FAIL === '1') {
      check('a failing build reports failed', failStatus.status === 'failed', failStatus.status)
      check('the exit code is surfaced', failStatus.exit_code === 1, `${failStatus.exit_code}`)
      const failLog = await page.evaluate(logText)
      check('the compiler error is in the log', /unresolved reference/.test(failLog))
      check('errors are highlighted', (await page.evaluate(
        () => document.querySelectorAll('#log .err').length)) > 0)
    } else {
      check('a repeat build still succeeds', failStatus.status === 'success', failStatus.status)
    }

    console.log('\n── Concurrency is refused, not queued ──')
    await fetch(`${BASE}/api/build`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ variant: 'debug' }),
    })
    const second = await fetch(`${BASE}/api/build`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ variant: 'debug' }),
    })
    check('a second concurrent build is refused', second.status === 409, `${second.status}`)

    console.log('\n── Cancelling stops it ──')
    const cancelled = await fetch(`${BASE}/api/cancel`, { method: 'POST' })
    check('cancel is accepted', cancelled.ok)
    await sleep(1500)
    const afterCancel = await (await fetch(`${BASE}/api/status`)).json()
    check('status becomes cancelled', afterCancel.status === 'cancelled', afterCancel.status)

    console.log('\n── Input validation ──')
    const badUrl = await fetch(`${BASE}/api/build`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ variant: 'debug', api_base: 'https://x.test/\nsdk.dir=/evil' }),
    })
    await sleep(1200)
    const afterBad = await (await fetch(`${BASE}/api/status`)).json()
    check(
      'a newline in the server address is refused, not written into local.properties',
      afterBad.status === 'failed' && /Geçersiz/.test(afterBad.message),
      afterBad.message,
    )

    const badVariant = await fetch(`${BASE}/api/build`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ variant: 'nonsense' }),
    })
    check('an unknown variant is refused', badVariant.status === 400, `${badVariant.status}`)

    console.log('\n── Nothing broke ──')
    const real = errors.filter((e) => !/favicon/.test(e))
    check('no unhandled console errors', real.length === 0, real.slice(0, 2).join(' | '))
  } finally {
    await browser.close()
  }

  console.log(`\n${checks - failures}/${checks} checks passed`)
  process.exit(failures === 0 ? 0 : 1)
}

await main()
