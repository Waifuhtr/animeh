# @animeh/player

Animeh'in özel video oynatıcısı. HLS ve MKV, ASS altyazı, font çözümleme ve
zayıf bağlantılara göre ayarlanmış tamponlama.

Mimari: [`../docs/01-player-architecture.md`](../docs/01-player-architecture.md)

## Kurulum

```bash
npm install
../tools/make-test-media.sh   # test korpusunu üretir (ffmpeg gerekir)
npm run dev                   # http://127.0.0.1:5173
```

`make-test-media.sh` yaklaşık 200 MB üretir ve git'e girmez. VP9 kodlaması
yavaştır; atlamak için `SKIP_VP9=1 ../tools/make-test-media.sh`.

## Komutlar

| | |
| --- | --- |
| `npm run dev` | Test paneliyle geliştirme sunucusu |
| `npm run build` | Tip kontrolü + production build |
| `npm test` | Birim testleri (demux, remux, ASS, font) |
| `npm run e2e` | Chromium'da oynatma doğrulaması |
| `npm run shots` | Arayüz ekran görüntüleri (`shots/`) |

`e2e` ve `shots` çalışan bir `npm run dev` sunucusu ister.

## Kullanım

```ts
import { createPlayer } from '@animeh/player'
import '@animeh/player/src/ui/player.css'

const { player } = createPlayer(document.querySelector('#player'), {
  subtitles: {
    workerUrl: '/jassub/jassub-worker.js',
    wasmUrl: '/jassub/jassub-worker.wasm',
    modernWasmUrl: '/jassub/jassub-worker-modern.wasm',
    fallbackFont: 'Noto Sans',
  },
})

await player.load({
  url: 'https://cdn.example/anime/1/s1/e1/master.m3u8',
  type: 'auto',                    // hls | mkv | mp4 | auto
  subtitles: [
    { id: 'tr', language: 'tr', label: 'Türkçe',
      url: '…/tr.ass', format: 'ass', default: true },
  ],
  fonts: [{ family: 'Noto Sans', url: '…/NotoSans.ttf' }],
  episode: {
    animeId: '21', episodeId: '21-1-1',
    animeTitle: 'One Piece', episodeTitle: 'Romance Dawn',
    season: 1, episodeNumber: 1,
    introStart: 62, introEnd: 152,
    hasNext: true, hasPrevious: false,
  },
})

await player.play()
```

`jassub` worker ve wasm dosyaları `npm run prepare:jassub` ile
`public/jassub/` altına paketlenir (`dev` ve `build` bunu kendisi çağırır).
WordPress'e taşırken bu üç dosyanın statik olarak servis edilmesi yeterli.

### Durum okuma

```ts
const unsubscribe = player.subscribe((snapshot) => {
  // snapshot.phase, position, bufferAhead, qualities, subtitleTracks, error…
})

player.events.on('fontReport', ({ required, resolved, missing }) => {
  // missing → admin panelindeki "Font Yükle" akışı
})
```

## Test sunucusu

Geliştirme sunucusu `/media` altını gerçek HTTP Range ile servis eder ve
istenirse bozar:

```bash
curl "http://127.0.0.1:5173/media/__throttle?kbps=700"   # bant genişliği sınırı
curl "http://127.0.0.1:5173/media/episode.mkv?fail=2"    # ilk 2 isteği düşür
```

Tamponlama ve kurtarma davranışı yalnızca böyle değerlendirilebilir.

## Klavye

`space`/`k` oynat · `←`/`→` 10 sn · `↑`/`↓` ses · `m` sessiz · `f` tam ekran ·
`c` altyazı · `d` hata ayıklama · `Escape` menü kapat
