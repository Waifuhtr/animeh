/**
 * Drives the backup and migration panel in a browser, across two sites.
 *
 * Two stub servers run side by side — an old site and a new one, sharing one
 * bucket — because that is the shape of the thing being tested. A pull that
 * never crosses a process boundary would not prove that a pairing code travels,
 * that the receiving site validates what arrives, or that a code is spent once.
 *
 * The panel's JavaScript, the snapshot envelope, the checksum and the pairing
 * code are all the shipped code. Only WordPress's own layer — $wpdb, cron,
 * capabilities — is standing in.
 */
import { createRequire } from 'node:module'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { mkdir } from 'node:fs/promises'

const HERE = dirname(fileURLToPath(import.meta.url))
const REPO = resolve(HERE, '..', '..', '..', '..')

const { chromium } = createRequire(join(REPO, 'player', 'package.json'))('playwright')
const SHOTS = join(HERE, 'shots')
const OLD_SITE = process.env.STUB_URL ?? 'http://127.0.0.1:8765'
const NEW_SITE = process.env.STUB_URL_B ?? 'http://127.0.0.1:8766'
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

async function waitFor(page, fn, { timeout = 20_000, arg, label = 'condition' } = {}) {
  const deadline = Date.now() + timeout
  for (;;) {
    const value = await page.evaluate(fn, arg)
    if (value) return value
    if (Date.now() > deadline) throw new Error(`timed out waiting for ${label}`)
    await sleep(200)
  }
}

/** The migration screen's check rows, as data. */
const readChecks = (selector) =>
  [...document.querySelectorAll(`${selector} .ap-check`)].map((row) => ({
    state: row.dataset.state,
    label: row.querySelector('.ap-check__name')?.textContent ?? '',
    detail: row.querySelector('.ap-check__detail')?.textContent ?? '',
  }))

/** Seed a site with data through its REST API, so there is something to move. */
async function seed(base, labels) {
  for (const label of labels) {
    await fetch(`${base}/wp-json/animeh/v1/test/presets`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': 'stub-nonce', 'Content-Type': 'application/json' },
      body: JSON.stringify({ label, source_url: `https://example.test/${label}.m3u8` }),
    })
  }
}

async function readPresets(base) {
  const response = await fetch(`${base}/wp-json/animeh/v1/test/presets`, {
    headers: { 'X-WP-Nonce': 'stub-nonce' },
  })
  const body = await response.json()
  return body.presets ?? []
}

async function main() {
  await mkdir(SHOTS, { recursive: true })

  for (const [label, base] of [['old', OLD_SITE], ['new', NEW_SITE]]) {
    const ok = await fetch(`${base}/__reset`).then((r) => r.ok).catch(() => false)
    if (!ok) {
      console.error(`stub (${label} site) is not answering at ${base}.`)
      console.error('  STUB_PORT=8765 tests/stub-server.sh start')
      console.error('  STUB_PORT=8766 STUB_LOG=/tmp/animeh-stub-b.log tests/stub-server.sh start')
      process.exit(1)
    }
  }

  // Give the old site something worth moving.
  await seed(OLD_SITE, ['alpha', 'beta', 'eski-site-marker'])

  const browser = await chromium.launch({ executablePath: CHROME })
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
  const page = await context.newPage()

  const consoleErrors = []
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text())
  })
  page.on('pageerror', (error) => consoleErrors.push(String(error)))

  try {
    /* ── 1. The screen loads and reports where it stands ───────────────── */
    console.log('\n── The migration screen ──')
    await page.goto(`${OLD_SITE}/?page=animeh-migration`, { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('#animeh-mig-checks .ap-check', { timeout: 20_000 })

    let rows = await page.evaluate(readChecks, '#animeh-mig-checks')
    check('panel mounted on the migration screen', rows.length >= 4, `${rows.length} rows`)
    check(
      'reports the bucket as configured',
      rows.find((r) => r.label === 'Bucket')?.state === 'ok',
      rows.find((r) => r.label === 'Bucket')?.detail,
    )
    check(
      'reports no snapshot yet',
      /henüz alınmadı/.test(rows.find((r) => r.label === 'Son yedek')?.detail ?? ''),
      rows.find((r) => r.label === 'Son yedek')?.detail,
    )

    /* ── 2. Taking a snapshot ──────────────────────────────────────────── */
    console.log('\n── Taking a snapshot ──')
    await page.click('#animeh-mig-snapshot')
    await waitFor(
      page,
      () => document.querySelector('#animeh-mig-status')?.textContent?.includes('Yedek alındı') ?? false,
      { label: 'snapshot to finish' },
    )

    const snapshotStatus = await page.evaluate(
      () => document.querySelector('#animeh-mig-status')?.textContent ?? '',
    )
    check('snapshot reported back with a size', /\d+(\.\d+)?\s?(B|KB|MB)/.test(snapshotStatus), snapshotStatus)
    check('snapshot names what it holds', /fonts:|test_sessions:/.test(snapshotStatus), snapshotStatus)

    await page.waitForSelector('#animeh-mig-table tbody tr', { timeout: 10_000 })
    const listed = await page.evaluate(
      () => document.querySelectorAll('#animeh-mig-table tbody tr').length,
    )
    check('the snapshot appears in the list', listed === 1, `${listed} rows`)

    const dateCell = await page.evaluate(
      () => document.querySelector('#animeh-mig-table tbody tr td')?.textContent ?? '',
    )
    check('the key is shown as a date, not a raw key', !dateCell.includes('_animeh/'), dateCell)

    rows = await page.evaluate(readChecks, '#animeh-mig-checks')
    check(
      'last-snapshot row turned green',
      rows.find((r) => r.label === 'Son yedek')?.state === 'ok',
      rows.find((r) => r.label === 'Son yedek')?.detail,
    )

    /* ── 3. The daily schedule ─────────────────────────────────────────── */
    await page.click('#animeh-mig-schedule')
    await waitFor(
      page,
      () => document.querySelector('#animeh-mig-status')?.textContent?.includes('açıldı') ?? false,
      { label: 'schedule to save' },
    )
    await page.reload({ waitUntil: 'domcontentloaded' })
    await page.waitForSelector('#animeh-mig-checks .ap-check', { timeout: 20_000 })
    const scheduled = await page.evaluate(() => document.querySelector('#animeh-mig-schedule').checked)
    check('the daily schedule survives a reload', scheduled === true)

    /* ── 4. The pointer ────────────────────────────────────────────────── */
    console.log('\n── The backend pointer ──')
    await page.waitForSelector('#animeh-mig-pointer .ap-check', { timeout: 10_000 })
    let pointer = await page.evaluate(readChecks, '#animeh-mig-pointer')
    check(
      'the old site is the backend after its snapshot',
      pointer.find((r) => r.label === 'Bu site')?.state === 'ok',
      pointer.find((r) => r.label === 'Bu site')?.detail,
    )

    await page.screenshot({ path: join(SHOTS, 'migration-old-site.png'), fullPage: true })

    /* ── 5. Issuing a pairing code ─────────────────────────────────────── */
    console.log('\n── Handing over to the new site ──')
    await page.click('#animeh-mig-code')
    await page.waitForSelector('#animeh-mig-code-value:not([hidden])', { timeout: 10_000 })
    const code = await page.evaluate(
      () => document.querySelector('#animeh-mig-code-value')?.textContent ?? '',
    )
    check('a code is shown in readable groups', /^[0-9A-Z]{5}(-[0-9A-Z]{5}){3}$/.test(code), code)
    check('the code avoids confusable letters', !/[ILOU]/.test(code), code)

    const note = await page.evaluate(
      () => document.querySelector('#animeh-mig-code-note')?.textContent ?? '',
    )
    check('the panel counts the code down', /kaldı/.test(note), note)

    /* ── 6. Pulling from the new site ──────────────────────────────────── */
    const before = await readPresets(NEW_SITE)
    check('the new site starts empty', before.length === 0, `${before.length} presets`)

    await page.goto(`${NEW_SITE}/?page=animeh-migration`, { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('#animeh-mig-checks .ap-check', { timeout: 20_000 })

    // A destructive action asks before it runs.
    page.on('dialog', (dialog) => void dialog.accept())

    await page.fill('#animeh-mig-source', OLD_SITE)
    await page.fill('#animeh-mig-code-input', code.toLowerCase().replace(/-/g, ' '))
    await page.click('#animeh-mig-pull')
    await waitFor(
      page,
      () => document.querySelector('#animeh-mig-status')?.textContent?.includes('Taşındı') ?? false,
      { label: 'the pull to finish' },
    )

    const moved = await page.evaluate(
      () => document.querySelector('#animeh-mig-status')?.textContent ?? '',
    )
    check('a code retyped in lower case with spaces still works', /Taşındı/.test(moved), moved)
    check('the pull names where the data came from', moved.includes('8765'), moved)

    const after = await readPresets(NEW_SITE)
    check('the library arrived on the new site', after.length === 3, `${after.length} presets`)
    check(
      'the data is the old site’s, not a fresh one',
      after.some((p) => p.label === 'eski-site-marker'),
      after.map((p) => p.label).join(', '),
    )

    /* ── 7. The code is spent ──────────────────────────────────────────── */
    await page.fill('#animeh-mig-code-input', code)
    await page.click('#animeh-mig-pull')
    await waitFor(
      page,
      () => !document.querySelector('#animeh-mig-error')?.hidden,
      { label: 'the second pull to be refused' },
    )
    const refusal = await page.evaluate(
      () => document.querySelector('#animeh-mig-error')?.textContent ?? '',
    )
    check('a used code is refused the second time', /geçersiz|süresi/.test(refusal), refusal)

    /* ── 8. A wrong code never gets that far ───────────────────────────── */
    await page.fill('#animeh-mig-code-input', 'ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ')
    await page.click('#animeh-mig-pull')
    await sleep(1000)
    const wrong = await page.evaluate(
      () => document.querySelector('#animeh-mig-error')?.textContent ?? '',
    )
    check('a wrong code is refused', /geçersiz|süresi/.test(wrong), wrong)

    /* ── 9. The new site takes over as backend ─────────────────────────── */
    console.log('\n── The new site takes over ──')
    await page.click('#animeh-mig-claim')
    await waitFor(
      page,
      () => document.querySelector('#animeh-mig-status')?.textContent?.includes('bu siteyi') ?? false,
      { label: 'the pointer to be claimed' },
    )

    pointer = await page.evaluate(readChecks, '#animeh-mig-pointer')
    check(
      'the pointer now names the new site',
      pointer.find((r) => r.label === 'Kayıtlı backend')?.detail.includes('8766'),
      pointer.find((r) => r.label === 'Kayıtlı backend')?.detail,
    )
    check(
      'the new site reports itself as active',
      pointer.find((r) => r.label === 'Bu site')?.state === 'ok',
      pointer.find((r) => r.label === 'Bu site')?.detail,
    )

    await page.screenshot({ path: join(SHOTS, 'migration-new-site.png'), fullPage: true })

    /* ── 10. Disaster recovery: restore from the bucket alone ──────────── */
    console.log('\n── Recovering from the bucket ──')
    // The new site can see the old site's snapshot because the bucket is
    // shared — which is the whole point: it survives losing a host.
    await page.reload({ waitUntil: 'domcontentloaded' })
    await page.waitForSelector('#animeh-mig-table tbody tr', { timeout: 20_000 })

    // Wipe the new site's library, then restore it from that snapshot.
    for (const preset of await readPresets(NEW_SITE)) {
      await fetch(`${NEW_SITE}/wp-json/animeh/v1/test/presets/${encodeURIComponent(preset.id)}`, {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': 'stub-nonce' },
      })
    }
    check('the new site was emptied', (await readPresets(NEW_SITE)).length === 0)

    await page.reload({ waitUntil: 'domcontentloaded' })
    await page.waitForSelector('#animeh-mig-table tbody tr button', { timeout: 20_000 })
    await page.click('#animeh-mig-table tbody tr button')
    await waitFor(
      page,
      () => document.querySelector('#animeh-mig-status')?.textContent?.includes('Geri yüklendi') ?? false,
      { label: 'the restore to finish' },
    )

    const restored = await readPresets(NEW_SITE)
    check(
      'the library came back from the bucket snapshot',
      restored.some((p) => p.label === 'eski-site-marker'),
      restored.map((p) => p.label).join(', '),
    )

    /* ── 11. A corrupted snapshot is refused ───────────────────────────── */
    const corrupted = await fetch(`${NEW_SITE}/wp-json/animeh/v1/migration/restore`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': 'stub-nonce', 'Content-Type': 'application/json' },
      body: JSON.stringify({ key: '_animeh/snapshots/nope.json.gz', confirm: true }),
    })
    check('a snapshot that is not there is a 404, not a wipe', corrupted.status === 404, `${corrupted.status}`)

    const escaping = await fetch(`${NEW_SITE}/wp-json/animeh/v1/migration/restore`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': 'stub-nonce', 'Content-Type': 'application/json' },
      body: JSON.stringify({ key: 'anime/some-show/season-01/episode-001.mp4', confirm: true }),
    })
    check(
      'a key outside the snapshot prefix is refused',
      escaping.status === 400,
      `${escaping.status}`,
    )

    const unconfirmed = await fetch(`${NEW_SITE}/wp-json/animeh/v1/migration/restore`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': 'stub-nonce', 'Content-Type': 'application/json' },
      body: JSON.stringify({ key: '_animeh/snapshots/whatever.json.gz' }),
    })
    check('a restore without confirmation is refused', unconfirmed.status === 400, `${unconfirmed.status}`)

    /* ── 12. The export route is not open ──────────────────────────────── */
    const open = await fetch(`${OLD_SITE}/wp-json/animeh/v1/migration/export`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': 'stub-nonce', 'Content-Type': 'application/json' },
      body: JSON.stringify({}),
    })
    check('export without a code is refused', open.status === 403, `${open.status}`)

    /* ── 13. Nothing broke along the way ───────────────────────────────── */
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
