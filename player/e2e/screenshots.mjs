/**
 * Captures the player's states for visual review.
 * Not an assertion pass — `playback.mjs` does the checking.
 */
import { chromium } from 'playwright'
import { mkdir } from 'node:fs/promises'

const SHOTS = new URL('../shots/', import.meta.url).pathname
await mkdir(SHOTS, { recursive: true })

const browser = await chromium.launch({
  executablePath: process.env.CHROME_PATH ?? '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  args: ['--autoplay-policy=no-user-gesture-required', '--no-sandbox', '--mute-audio'],
})

async function shoot(page, name) {
  const el = await page.$('.animeh')
  await el.screenshot({ path: `${SHOTS}${name}.png` })
  console.log(`  ${name}.png`)
}

const wake = (page) => page.mouse.move(500, 250).then(() => page.waitForTimeout(400))

// Desktop
{
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } })
  await page.goto('http://127.0.0.1:5173/', { waitUntil: 'domcontentloaded' })
  await page.evaluate(() => fetch('/media/__throttle?kbps=0'))
  await page.selectOption('#source', 'mkvVp9')
  await page.click('#start')
  await page.waitForFunction(() => {
    const v = document.querySelector('video')
    return v && v.readyState >= 3 && v.currentTime > 1
  }, { timeout: 45_000 })

  // A moment with a subtitle on screen and the controls up.
  await page.evaluate(() => globalThis.animehHarness.mounted.player.seek(6.2))
  await page.waitForTimeout(1500)
  await wake(page)
  await shoot(page, 'ui-controls')

  // Quality menu.
  await page.click('button[aria-label="Altyazı"]')
  await page.waitForTimeout(300)
  await shoot(page, 'ui-menu-subtitles')
  await page.keyboard.press('Escape')

  // Debug overlay.
  await page.click('.animeh')
  await page.keyboard.press('d')
  await wake(page)
  await shoot(page, 'ui-debug')
  await page.keyboard.press('d')

  // Locked.
  await page.click('button[aria-label="Ekranı kilitle"]')
  await page.waitForTimeout(300)
  await shoot(page, 'ui-locked')
  await page.click('button[aria-label="Kilidi aç"]')

  // Error state, via a source that cannot resolve.
  await page.evaluate(async () => {
    await globalThis.animehHarness.mounted.player.load({
      url: '/media/does-not-exist.mkv',
      type: 'mkv',
      episode: { animeId: 'x', episodeId: 'x', animeTitle: 'Animeh Test Serisi', episodeTitle: 'Hata durumu' },
    })
  })
  await page.waitForTimeout(2500)
  await wake(page)
  await shoot(page, 'ui-error')
  await page.close()
}

// Phone-sized
{
  const page = await browser.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true })
  await page.goto('http://127.0.0.1:5173/', { waitUntil: 'domcontentloaded' })
  await page.selectOption('#source', 'hlsVp9')
  await page.click('#start')
  await page.waitForFunction(() => {
    const v = document.querySelector('video')
    return v && v.readyState >= 3 && v.currentTime > 1
  }, { timeout: 45_000 })
  await page.evaluate(() => globalThis.animehHarness.mounted.player.seek(6.2))
  await page.waitForTimeout(1500)
  await page.touchscreen.tap(195, 110)
  await page.waitForTimeout(400)
  await shoot(page, 'ui-mobile')
  await page.close()
}

await browser.close()
