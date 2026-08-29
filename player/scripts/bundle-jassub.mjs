/**
 * Bundle the libass worker into `public/jassub/`.
 *
 * jassub ships its worker as an ES module with bare imports, which a browser
 * cannot load directly from node_modules. Pre-bundling it into a self-contained
 * file means the player can point at a plain static URL — the same one whether
 * it runs under Vite, inside WordPress, or from a CDN — instead of depending on
 * whatever bundler happens to host it.
 */
import { build } from 'esbuild'
import { copyFile, mkdir } from 'node:fs/promises'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'

const require = createRequire(import.meta.url)
const jassubDist = dirname(require.resolve('jassub/dist/jassub.js'))
const outDir = new URL('../public/jassub/', import.meta.url).pathname

await mkdir(outDir, { recursive: true })

await build({
  entryPoints: [join(jassubDist, 'worker', 'worker.js')],
  outfile: join(outDir, 'jassub-worker.js'),
  bundle: true,
  format: 'esm',
  platform: 'browser',
  target: 'es2022',
  minify: true,
  // The wasm glue resolves its binary at runtime from the URL we pass in.
  external: ['*.wasm'],
  loader: { '.wasm': 'file' },
  logLevel: 'warning',
})

for (const file of ['jassub-worker.wasm', 'jassub-worker-modern.wasm']) {
  await copyFile(join(jassubDist, 'wasm', file), join(outDir, file))
}
await copyFile(join(jassubDist, 'default.woff2'), join(outDir, 'default.woff2'))

console.log(`jassub bundled into ${outDir}`)
