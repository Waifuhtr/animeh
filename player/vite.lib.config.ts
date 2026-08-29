import { copyFileSync, mkdirSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join, resolve } from 'node:path'
import { defineConfig, type Plugin } from 'vite'

const require = createRequire(import.meta.url)
const OUT_DIR = resolve(import.meta.dirname, '..', 'wordpress-plugin', 'animeh', 'assets')

/**
 * Keeps jassub's asset fallbacks out of the bundle.
 *
 * jassub resolves its worker and wasm with `new URL('./…', import.meta.url)`
 * as a fallback for when no explicit URL is given. We always give explicit
 * URLs, so those branches never run — but the bundler cannot know that, and
 * statically inlines two 2 MB wasm binaries as base64, which is most of a
 * 6.8 MB bundle.
 *
 * Rewriting the paths through a concatenation defeats that static analysis
 * while leaving the fallbacks working: they now resolve at runtime against the
 * emitted module, which sits one directory from the copied assets.
 */
function externaliseJassubAssets(): Plugin {
  const REWRITES: [from: string, to: string][] = [
    ["'./worker/worker.js'", "'../jassub/' + 'jassub-worker.js'"],
    ["'./wasm/jassub-worker-modern.wasm'", "'../jassub/' + 'jassub-worker-modern.wasm'"],
    ["'./wasm/jassub-worker.wasm'", "'../jassub/' + 'jassub-worker.wasm'"],
    ["'./default.woff2'", "'../jassub/' + 'default.woff2'"],
  ]

  return {
    name: 'animeh-externalise-jassub-assets',
    enforce: 'pre',
    transform(code, id) {
      if (!id.includes('jassub/dist/jassub.js')) return null
      let out = code
      for (const [from, to] of REWRITES) {
        out = out.replaceAll(`new URL(${from}, import.meta.url)`, `new URL(${to}, import.meta.url)`)
      }
      return out === code ? null : { code: out, map: null }
    },
  }
}

/**
 * Copies the libass worker and its wasm alongside the bundle.
 *
 * They are passed to the player as plain URLs rather than imported, so the
 * bundler never sees them — which is the point: the same three files work
 * unchanged under Vite, inside WordPress, or from a CDN.
 */
function copyJassub(): Plugin {
  return {
    name: 'animeh-copy-jassub',
    closeBundle() {
      const jassubDist = dirname(require.resolve('jassub/dist/jassub.js'))
      const target = join(OUT_DIR, 'jassub')
      mkdirSync(target, { recursive: true })
      // The worker is pre-bundled by scripts/bundle-jassub.mjs; the wasm and
      // the fallback face come straight from the package.
      copyFileSync(join('public', 'jassub', 'jassub-worker.js'), join(target, 'jassub-worker.js'))
      for (const file of ['jassub-worker.wasm', 'jassub-worker-modern.wasm']) {
        copyFileSync(join(jassubDist, 'wasm', file), join(target, file))
      }
      copyFileSync(join(jassubDist, 'default.woff2'), join(target, 'default.woff2'))
    },
  }
}

/**
 * Library build consumed by the WordPress plugin.
 *
 * ES module output, printed into the admin page with `wp_print_script_tag`.
 * A single self-contained file with no code splitting: WordPress has no
 * bundler, and the plugin must work by copying a folder.
 */
export default defineConfig({
  plugins: [externaliseJassubAssets(), copyJassub()],
  // `public/` belongs to the dev harness. Copying it into the library output
  // would duplicate the 4 MB of wasm one directory too deep.
  publicDir: false,
  build: {
    outDir: join(OUT_DIR, 'player'),
    emptyOutDir: true,
    target: 'es2022',
    sourcemap: false,
    cssCodeSplit: false,
    lib: {
      entry: resolve(import.meta.dirname, 'src/plugin-entry.ts'),
      formats: ['es'],
      fileName: () => 'animeh-player.js',
    },
    rollupOptions: {
      output: {
        // One file. Chunking would need an import map on the WordPress side
        // for no benefit — the whole bundle is needed on the one page that
        // loads it.
        inlineDynamicImports: true,
        assetFileNames: 'animeh-player.[ext]',
      },
    },
  },
  worker: { format: 'es' },
})
