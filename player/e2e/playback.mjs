/**
 * Browser verification.
 *
 * Drives the real player in a real Chromium against the generated corpus and
 * asserts what a viewer would actually see: frames advancing, subtitles drawn,
 * fonts resolved, seeking landing where it was asked to.
 *
 * Playwright's Chromium is the open-source build, which ships without H.264 or
 * AAC. The VP9 + Opus corpus exists so the browser path can still be verified
 * end to end here; the H.264/AAC remux is verified separately against ffprobe
 * in the unit tests.
 */
import { chromium } from 'playwright'
import { mkdir } from 'node:fs/promises'

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:5173'
const CHROME = process.env.CHROME_PATH ?? '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'
const SHOTS = new URL('../shots/', import.meta.url).pathname

let failures = 0
let checks = 0

function check(name, condition, detail = '') {
  checks++
  if (condition) {
    console.log(`  ok   ${name}${detail ? ` — ${detail}` : ''}`)
  } else {
    failures++
    console.log(`  FAIL ${name}${detail ? ` — ${detail}` : ''}`)
  }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/** Poll until `fn` returns truthy, or give up. */
async function waitFor(page, fn, { timeout = 20_000, interval = 200, label = 'condition', arg = undefined } = {}) {
  const deadline = Date.now() + timeout
  for (;;) {
    const value = await page.evaluate(fn, arg)
    if (value) return value
    if (Date.now() > deadline) throw new Error(`timed out waiting for ${label}`)
    await sleep(interval)
  }
}

const videoState = () => {
  const v = document.querySelector('video')
  if (!v) return null
  return {
    currentTime: v.currentTime,
    duration: v.duration,
    readyState: v.readyState,
    paused: v.paused,
    videoWidth: v.videoWidth,
    videoHeight: v.videoHeight,
    buffered: v.buffered.length > 0 ? v.buffered.end(v.buffered.length - 1) : 0,
    error: v.error ? { code: v.error.code, message: v.error.message } : null,
  }
}

/** Count non-transparent pixels on the subtitle canvas. */
const subtitlePixels = () => {
  const canvas = document.querySelector('canvas.animeh__subtitles')
  if (!canvas || canvas.width === 0) return { width: 0, height: 0, painted: -1 }
  // The renderer may own the context; read through a copy so we never fight it.
  const probe = document.createElement('canvas')
  probe.width = canvas.width
  probe.height = canvas.height
  const ctx = probe.getContext('2d')
  ctx.drawImage(canvas, 0, 0)
  const { data } = ctx.getImageData(0, 0, probe.width, probe.height)
  let painted = 0
  for (let i = 3; i < data.length; i += 4) if (data[i] > 16) painted++
  return { width: canvas.width, height: canvas.height, painted }
}

async function runSource(page, key, label, opts = {}) {
  console.log(`\n── ${label} ──`)
  const logs = []
  page.on('console', (message) => {
    if (message.type() === 'error') logs.push(message.text())
  })

  // The throttle is set server-side so it reaches the variant playlists and
  // segments a manifest pulls in, not just the URL the player was handed.
  await page.evaluate(
    (kbps) => fetch(`/media/__throttle?kbps=${kbps}`).then((r) => r.json()),
    opts.kbps ?? 0,
  )

  await page.selectOption('#source', key)
  await page.fill('#kbps', '0')
  await page.fill('#fail', String(opts.fail ?? 0))
  await page.click('#start')

  // 1. Playback actually starts.
  const started = await waitFor(
    page,
    () => {
      const v = document.querySelector('video')
      return v && v.readyState >= 3 && v.currentTime > 0.2 ? true : false
    },
    { timeout: 45_000, label: 'first frames' },
  ).catch((err) => {
    console.log(`  FAIL playback did not start — ${err.message}`)
    failures++
    return false
  })
  if (!started) {
    const state = await page.evaluate(videoState)
    console.log(`       video state: ${JSON.stringify(state)}`)
    if (logs.length) console.log(`       console: ${logs.slice(0, 3).join(' | ')}`)
    return
  }

  const first = await page.evaluate(videoState)
  check('video decodes', first.videoWidth > 0, `${first.videoWidth}x${first.videoHeight}`)
  check('no media error', first.error === null, first.error ? JSON.stringify(first.error) : '')

  // 2. The playhead advances on its own.
  const before = first.currentTime
  await sleep(2500)
  const after = await page.evaluate(videoState)
  check('playhead advances', after.currentTime > before + 0.5, `${before.toFixed(2)}s → ${after.currentTime.toFixed(2)}s`)
  check('duration known', after.duration > 5, `${after.duration.toFixed(1)}s`)
  if (opts.expectBufferAhead !== false) {
    check('buffer builds ahead', after.buffered > after.currentTime, `${after.buffered.toFixed(1)}s buffered`)
  }

  // 3. Subtitles are rendered as pixels, not just loaded.
  //    The corpus has a line on screen from 0.5s onward.
  const painted = await waitFor(
    page,
    subtitlePixels,
    { timeout: 25_000, label: 'subtitle canvas' },
  ).then(() =>
    waitFor(page, () => {
      const canvas = document.querySelector('canvas.animeh__subtitles')
      if (!canvas || canvas.width === 0) return false
      const probe = document.createElement('canvas')
      probe.width = canvas.width
      probe.height = canvas.height
      const ctx = probe.getContext('2d')
      ctx.drawImage(canvas, 0, 0)
      const { data } = ctx.getImageData(0, 0, probe.width, probe.height)
      let painted = 0
      for (let i = 3; i < data.length; i += 4) if (data[i] > 16) painted++
      return painted > 500 ? painted : false
    }, { timeout: 25_000, label: 'subtitle pixels' }),
  ).catch(() => 0)
  if (opts.expectSubtitles !== false) {
    check('ASS subtitles render', painted > 500, `${painted} opaque pixels`)
  }

  // 4. Font resolution reports exactly the one font the corpus omits.
  if (opts.expectFonts === false) {
    // Scenarios that only exercise transport skip the font assertions.
  }
  // Assert against the report itself rather than the rendered copy, which is
  // localised and free to change.
  const report = await page.evaluate(() => globalThis.animehHarness.fontReport)
  const resolved = report?.resolved ?? []
  const missing = report?.missing ?? []
  check(
    'fonts resolved',
    resolved.length === 3,
    resolved.map((r) => `${r.family} (${r.origin})`).join(', '),
  )
  check(
    'missing font reported',
    missing.length === 1 && missing[0] === 'Animeh Nonexistent Gothic',
    missing.join(', ') || 'none',
  )
  if (opts.expectEmbeddedFonts) {
    check(
      'fonts came from the container',
      resolved.every((row) => row.origin === 'embedded'),
      resolved.map((r) => r.origin).join(', '),
    )
  }

  // 5. Seeking lands where it was asked to and keeps playing.
  const target = Math.min(after.duration * 0.6, after.duration - 4)
  await page.evaluate((t) => globalThis.animehHarness.mounted.player.seek(t), target)
  const seeked = await waitFor(
    page,
    (t) => {
      const v = document.querySelector('video')
      return v && Math.abs(v.currentTime - t) < 3 && v.readyState >= 3 ? v.currentTime : false
    },
    { timeout: 30_000, label: 'seek', arg: target },
  ).catch(() => null)
  check('seek lands on target', seeked !== null, seeked === null ? 'timed out' : `${seeked.toFixed(2)}s (asked ${target.toFixed(2)}s)`)

  if (seeked !== null) {
    const beforeResume = await page.evaluate(videoState)
    await sleep(2000)
    const afterResume = await page.evaluate(videoState)
    check(
      'playback resumes after seek',
      afterResume.currentTime > beforeResume.currentTime + 0.3,
      `${beforeResume.currentTime.toFixed(2)}s → ${afterResume.currentTime.toFixed(2)}s`,
    )
  }

  // 6. Controls reflect state and respond.
  await page.mouse.move(400, 300)
  await page.waitForTimeout(300)
  await page.screenshot({ path: `${SHOTS}${key}-playing.png` })

  const pauseWorked = await page.evaluate(async () => {
    const player = globalThis.animehHarness.mounted.player
    player.pause()
    await new Promise((r) => setTimeout(r, 300))
    return document.querySelector('video').paused
  })
  check('pause control works', pauseWorked === true)

  await page.evaluate(() => globalThis.animehHarness.mounted.player.play())
  await sleep(600)

  if (opts.expectAdaptsDown) {
    const chosen = await page.evaluate(() => {
      const s = globalThis.animehHarness.mounted.player.snapshot
      const level = s.qualities.find((q) => q.id === s.activeQualityId)
      return { height: level?.height ?? null, auto: s.autoQuality, count: s.qualities.length }
    })
    check(
      'ABR picks a rendition the link can carry',
      chosen.height !== null && chosen.height < 720,
      `chose ${chosen.height}p of ${chosen.count} (auto=${chosen.auto})`,
    )
  }

  const stats = await page.evaluate(() => globalThis.animehHarness.mounted.player.stats())
  if (opts.kbps) {
    check(
      'throughput estimate tracks the cap',
      stats.throughputBps !== null && stats.throughputBps < opts.kbps * 1024 * 1.6,
      `measured ${stats.throughputBps ? Math.round(stats.throughputBps / 1000) : '—'} kbps against a ${opts.kbps} kbps cap`,
    )
  }
  console.log(
    `       startup ${stats.startupTimeMs} ms · rebuffers ${stats.rebufferCount} (${(stats.rebufferMs / 1000).toFixed(1)}s) · ` +
      `throughput ${stats.throughputBps ? Math.round(stats.throughputBps / 1000) + ' kbps' : 'n/a'} · ${(stats.bytesLoaded / 1e6).toFixed(1)} MB`,
  )

  const fatal = logs.filter((line) => !/favicon|Failed to load resource/.test(line))
  check('no unhandled console errors', fatal.length === 0, fatal.slice(0, 2).join(' | '))
}

async function main() {
  await mkdir(SHOTS, { recursive: true })
  const browser = await chromium.launch({
    executablePath: CHROME,
    args: ['--autoplay-policy=no-user-gesture-required', '--no-sandbox', '--mute-audio'],
  })
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } })
  page.on('pageerror', (err) => console.log(`  page error: ${err.message}`))

  try {
    await page.goto(BASE, { waitUntil: 'domcontentloaded' })

    const support = await page.evaluate(() => ({
      vp9: MediaSource.isTypeSupported('video/mp4; codecs="vp09.00.31.08"'),
      opus: MediaSource.isTypeSupported('audio/mp4; codecs="opus"'),
      avc: MediaSource.isTypeSupported('video/mp4; codecs="avc1.640028"'),
    }))
    console.log(`browser codecs: vp9=${support.vp9} opus=${support.opus} avc=${support.avc}`)

    await runSource(page, 'mkvVp9', 'MKV (VP9 + Opus) — embedded ASS and fonts', {
      expectEmbeddedFonts: true,
    })
    await runSource(page, 'hlsVp9', 'HLS (VP9 + Opus, fMP4) — external ASS')

    // A link that is constrained but still wider than the file's bitrate: the
    // MKV path has no ladder to adapt down, so this is what "works" looks like.
    await runSource(page, 'mkvVp9', 'MKV over a 3 Mbps link', { kbps: 3000 })

    // The adaptation test belongs to HLS, which is the only path with a ladder.
    // 700 kbps sits below the 720p rendition and above the 360p one.
    await runSource(page, 'hlsVp9', 'HLS over a throttled 700 kbps link', {
      kbps: 700,
      expectAdaptsDown: true,
      // A stall is the expected outcome while the ladder settles; what matters
      // is that it keeps playing and picks the rendition the link can carry.
      expectBufferAhead: false,
    })

    // Recovery: the first two requests fail outright.
    await runSource(page, 'hlsVp9', 'HLS with the first 2 requests failing', { fail: 2 })

    // A single file the browser demuxes itself. This path used to be routed to
    // the Matroska demuxer, which failed on the first byte.
    await runSource(page, 'progressive', 'Progressive single file, played natively', {
      expectEmbeddedFonts: false,
    })
  } finally {
    await page.screenshot({ path: `${SHOTS}final.png`, fullPage: true }).catch(() => {})
    await browser.close()
  }

  console.log(`\n${checks - failures}/${checks} checks passed`)
  process.exit(failures === 0 ? 0 : 1)
}

await main()
