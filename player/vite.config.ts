import { createReadStream, statSync } from 'node:fs'
import { join, normalize, resolve } from 'node:path'
import type { Connect, Plugin } from 'vite'
import { defineConfig } from 'vite'

const MEDIA_ROOT = resolve(import.meta.dirname, '..', 'media')

const CONTENT_TYPES: Record<string, string> = {
  '.m3u8': 'application/vnd.apple.mpegurl',
  '.ts': 'video/mp2t',
  '.mp4': 'video/mp4',
  '.mkv': 'video/x-matroska',
  '.ass': 'text/plain; charset=utf-8',
  '.srt': 'text/plain; charset=utf-8',
  '.vtt': 'text/vtt; charset=utf-8',
  '.ttf': 'font/ttf',
  '.otf': 'font/otf',
}

/**
 * Serves the generated test corpus with real HTTP Range support, and can be
 * asked to misbehave.
 *
 * Range support is not optional: Matroska playback is built on it. The fault
 * injection matters just as much — the buffering and recovery behaviour this
 * player exists for cannot be judged on a fast local connection, so
 * `?kbps=` throttles a response and `?fail=` makes one fail on demand.
 */
function testMediaServer(): Plugin {
  const failureCounts = new Map<string, number>()
  // Applies to every media request until changed. A throttle passed only on
  // the URL the player is given never reaches the variant playlists and
  // segments a manifest pulls in afterwards, which is most of the traffic.
  let stickyKbps = 0

  const middleware: Connect.NextHandleFunction = (req, res, next) => {
    const url = new URL(req.url ?? '/', 'http://localhost')

    // Control endpoint: /media/__throttle?kbps=700 (0 clears it).
    if (url.pathname === '/media/__throttle') {
      stickyKbps = Math.max(0, Number(url.searchParams.get('kbps') ?? '0'))
      failureCounts.clear()
      res.setHeader('Content-Type', 'application/json')
      res.setHeader('Access-Control-Allow-Origin', '*')
      return res.end(JSON.stringify({ kbps: stickyKbps }))
    }

    if (!url.pathname.startsWith('/media/')) return next()

    // Contain the path inside the media root: a dev server still should not
    // hand out arbitrary files.
    const relative = normalize(decodeURIComponent(url.pathname.slice('/media/'.length)))
    if (relative.startsWith('..')) {
      res.statusCode = 403
      return res.end('Forbidden')
    }
    const filePath = join(MEDIA_ROOT, relative)

    let stat
    try {
      stat = statSync(filePath)
    } catch {
      res.statusCode = 404
      return res.end('Not found')
    }
    if (!stat.isFile()) {
      res.statusCode = 404
      return res.end('Not found')
    }

    // ?fail=N makes the next N requests for this path fail, to exercise the
    // retry ladder.
    const failParam = Number(url.searchParams.get('fail') ?? '0')
    if (failParam > 0) {
      const seen = failureCounts.get(relative) ?? 0
      if (seen < failParam) {
        failureCounts.set(relative, seen + 1)
        res.statusCode = 503
        return res.end('Injected failure')
      }
    }

    const extension = relative.slice(relative.lastIndexOf('.'))
    res.setHeader('Content-Type', CONTENT_TYPES[extension] ?? 'application/octet-stream')
    res.setHeader('Accept-Ranges', 'bytes')
    res.setHeader('Access-Control-Allow-Origin', '*')
    res.setHeader('Cache-Control', 'no-store')

    const rangeHeader = req.headers.range
    let start = 0
    let end = stat.size - 1
    if (rangeHeader) {
      const match = /bytes=(\d*)-(\d*)/.exec(rangeHeader)
      if (match) {
        if (match[1]) start = Number(match[1])
        if (match[2]) end = Number(match[2])
        end = Math.min(end, stat.size - 1)
        if (start > end || start >= stat.size) {
          res.statusCode = 416
          res.setHeader('Content-Range', `bytes */${stat.size}`)
          return res.end()
        }
        res.statusCode = 206
        res.setHeader('Content-Range', `bytes ${start}-${end}/${stat.size}`)
      }
    }
    res.setHeader('Content-Length', String(end - start + 1))

    const kbps = Number(url.searchParams.get('kbps') ?? '0') || stickyKbps
    const stream = createReadStream(filePath, {
      start,
      end,
      // Throttling works by metering small reads: a 1 KB chunk per tick lands
      // close enough to the requested rate to make buffering behaviour visible.
      ...(kbps > 0 ? { highWaterMark: 1024 } : {}),
    })

    if (kbps > 0) {
      const bytesPerMs = (kbps * 1024) / 8 / 1000
      let scheduled = 0
      stream.on('data', (chunk) => {
        stream.pause()
        const delay = Math.max(1, chunk.length / bytesPerMs)
        scheduled += delay
        setTimeout(() => stream.resume(), delay)
      })
      stream.on('end', () => void scheduled)
    }

    stream.on('error', () => {
      res.statusCode = 500
      res.end()
    })
    stream.pipe(res)
    return undefined
  }

  return {
    name: 'animeh-test-media',
    configureServer(server) {
      server.middlewares.use(middleware)
    },
    configurePreviewServer(server) {
      server.middlewares.use(middleware)
    },
  }
}

export default defineConfig({
  plugins: [testMediaServer()],
  server: {
    host: '127.0.0.1',
    port: 5173,
    // Cross-origin isolation is not required by jassub, but it keeps the
    // door open for SharedArrayBuffer-backed rendering later.
    headers: { 'Cache-Control': 'no-store' },
  },
  // jassub resolves its own worker with `new URL(..., import.meta.url)`, which
  // Vite tries to bundle. We pass explicit worker/wasm URLs instead (see
  // scripts/bundle-jassub.mjs), but the bundler still has to be told to emit
  // any worker it does pick up as a module rather than an IIFE.
  worker: { format: 'es' },
  build: {
    target: 'es2022',
    sourcemap: true,
  },
})
