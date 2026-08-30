/**
 * Admin entry point.
 *
 * The player bundle is loaded dynamically rather than imported statically: its
 * URL comes from the server, so the plugin works from any install path without
 * a per-site build step.
 */

import { Api } from './api.js'
import { TestPanel } from './test-panel.js'
import { FontsPanel } from './fonts-panel.js'
import { StoragePanel } from './storage-panel.js'
import { MigrationPanel } from './migration-panel.js'

async function boot() {
  const config = window.ANIMEH_ADMIN
  if (!config) return

  const api = new Api(config)

  if ('fonts' === config.screen) {
    const root = document.querySelector('#animeh-fonts-root')
    if (root) new FontsPanel(root, api)
    return
  }

  if ('storage' === config.screen) {
    const root = document.querySelector('#animeh-storage-root')
    if (root) new StoragePanel(root, api)
    return
  }

  if ('migration' === config.screen) {
    const root = document.querySelector('#animeh-migration-root')
    if (root) {
      const panel = new MigrationPanel(root, api)
      // The code countdown is an interval; leaving it running on a page that
      // is going away keeps a timer alive in bfcache.
      window.addEventListener('pagehide', () => panel.destroy(), { once: true })
    }
    return
  }

  const root = document.querySelector('#animeh-test-root')
  if (!root) return

  let lib
  try {
    lib = await import(/* webpackIgnore: true */ config.assets.player)
  } catch (error) {
    root.innerHTML =
      '<div class="notice notice-error"><p>Oynatıcı paketi yüklenemedi. ' +
      'Eklentinin <code>assets/player/</code> klasörünün eksiksiz kopyalandığından emin ol.</p></div>'
    console.error('[animeh] player bundle failed to load', error)
    return
  }

  const panel = new TestPanel(root, api, config, lib)
  // Stop a throttled stream when the page goes away, rather than leaving the
  // proxy pushing bytes at a tab that no longer exists.
  window.addEventListener('pagehide', () => void panel.destroy(), { once: true })
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => void boot(), { once: true })
} else {
  void boot()
}
