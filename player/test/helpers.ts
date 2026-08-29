import { open } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import type { RangeReader } from '../src/mkv/demuxer.ts'

const here = dirname(fileURLToPath(import.meta.url))
export const MEDIA_DIR = join(here, '..', '..', 'media')

/** A RangeReader backed by a local file, mirroring HTTP Range semantics. */
export async function fileRangeReader(
  path: string,
): Promise<{ read: RangeReader; size: number; close: () => Promise<void> }> {
  const handle = await open(path, 'r')
  const stat = await handle.stat()
  const read: RangeReader = async (start, end) => {
    const clampedEnd = Math.min(end, stat.size)
    const length = Math.max(0, clampedEnd - start)
    const buffer = Buffer.alloc(length)
    if (length > 0) await handle.read(buffer, 0, length, start)
    return new Uint8Array(buffer)
  }
  return { read, size: stat.size, close: () => handle.close() }
}
