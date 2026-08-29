import { strict as assert } from 'node:assert'
import { describe, it } from 'node:test'
import { sniffContainer } from '../src/core/engine.ts'

describe('container sniffing', () => {
  it('routes playlists to the HLS engine', () => {
    assert.equal(sniffContainer('https://cdn.example/a/master.m3u8'), 'hls')
    assert.equal(sniffContainer('https://cdn.example/a/index.m3u'), 'hls')
    // Query strings and fragments must not defeat the extension check.
    assert.equal(sniffContainer('https://cdn.example/a/master.m3u8?token=abc'), 'hls')
    assert.equal(sniffContainer('https://cdn.example/a/master.m3u8#t=10'), 'hls')
  })

  it('routes Matroska to our own demuxer', () => {
    // MKV is the one container no browser demuxes, and the only one carrying
    // ASS subtitles and font attachments worth extracting.
    assert.equal(sniffContainer('https://cdn.example/e1.mkv'), 'mkv')
    assert.equal(sniffContainer('https://cdn.example/e1.MKV'), 'mkv')
    assert.equal(sniffContainer('https://cdn.example/audio.mka'), 'mkv')
  })

  it('hands progressive files to the browser', () => {
    // Regression: an .mp4 used to be routed to the Matroska demuxer, which
    // read its `ftyp` box as an EBML id and failed on the first byte with
    // "Invalid EBML id at 0".
    assert.equal(sniffContainer('https://cdn.example/episode.mp4'), 'mp4')
    assert.equal(sniffContainer('https://example.com/uploads/2026/08/ep1_Fast.mp4'), 'mp4')
    assert.equal(sniffContainer('https://cdn.example/clip.m4v'), 'mp4')
    assert.equal(sniffContainer('https://cdn.example/clip.mov'), 'mp4')

    // WebM is Matroska, but browsers play it natively and it routinely uses
    // VP8 or Vorbis, neither of which can be remuxed into MP4 for MSE.
    assert.equal(sniffContainer('https://cdn.example/clip.webm'), 'mp4')
  })

  it('falls back to the browser for an unknown or absent extension', () => {
    // A signed URL with no extension is common; the browser sniffing the bytes
    // is a better guess than assuming Matroska.
    assert.equal(sniffContainer('https://cdn.example/stream'), 'mp4')
    assert.equal(sniffContainer('https://cdn.example/a/b/c'), 'mp4')
  })
})
