#!/usr/bin/env bash
# Generates the local test corpus the player is developed against.
#
#   media/
#     source.mp4                 1080p H.264 + AAC master
#     hls/{360,480,720,1080}p/   per-rendition segments + media playlist
#     hls/master.m3u8            multivariant playlist
#     episode.mkv                MKV with embedded ASS track + attached fonts
#     episode-opus.mkv           MKV with Opus audio (codec-mapping coverage)
#     subtitle.ass               external ASS copy
#     fonts/                     the fonts the ASS script names
#
# Output is gitignored: it is reproducible, and binaries do not belong in git.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/media"
DURATION="${DURATION:-60}"
FONT_DIR="/usr/share/fonts/truetype/dejavu"

mkdir -p "$OUT/hls" "$OUT/fonts"

echo "==> source master (${DURATION}s, 1080p)"
# A moving pattern plus a burned-in clock: seeking is verifiable by eye, and the
# audio pitch steps every 10s so A/V sync problems are audible.
ffmpeg -y -loglevel error \
  -f lavfi -i "testsrc2=size=1920x1080:rate=24:duration=$DURATION" \
  -f lavfi -i "sine=frequency=440:sample_rate=48000:duration=$DURATION" \
  -vf "drawtext=fontfile=$FONT_DIR/DejaVuSans-Bold.ttf:text='%{pts\:hms}':fontsize=96:fontcolor=white:borderw=4:bordercolor=black:x=(w-tw)/2:y=80" \
  -c:v libx264 -preset veryfast -profile:v high -level 4.0 -pix_fmt yuv420p \
  -g 48 -keyint_min 48 -sc_threshold 0 -b:v 5000k \
  -c:a aac -b:a 128k -ar 48000 -ac 2 \
  "$OUT/source.mp4"

echo "==> HLS ladder"
# Renditions are encoded one at a time: slower than a filter_complex split, but
# each variant is independently reproducible and easy to inspect when one breaks.
make_variant() {
  local height=$1 width=$2 bitrate=$3 maxrate=$4 bufsize=$5
  local dir="$OUT/hls/${height}p"
  mkdir -p "$dir"
  ffmpeg -y -loglevel error -i "$OUT/source.mp4" \
    -vf "scale=${width}:${height}" \
    -c:v libx264 -preset veryfast -profile:v main -pix_fmt yuv420p \
    -g 48 -keyint_min 48 -sc_threshold 0 \
    -b:v "$bitrate" -maxrate "$maxrate" -bufsize "$bufsize" \
    -c:a aac -b:a 96k -ar 48000 -ac 2 \
    -f hls -hls_time 2 -hls_playlist_type vod -hls_list_size 0 \
    -hls_segment_filename "$dir/seg%03d.ts" \
    "$dir/index.m3u8"
  echo "    ${height}p done"
}

make_variant 360  640  "600k"  "700k"  "1200k"
make_variant 480  854  "1100k" "1300k" "2200k"
make_variant 720  1280 "2500k" "2900k" "5000k"
make_variant 1080 1920 "5000k" "5800k" "10000k"

echo "==> master playlist"
{
  echo "#EXTM3U"
  echo "#EXT-X-VERSION:3"
  echo '#EXT-X-STREAM-INF:BANDWIDTH=760000,AVERAGE-BANDWIDTH=700000,RESOLUTION=640x360,CODECS="avc1.4d401e,mp4a.40.2",FRAME-RATE=24.000'
  echo "360p/index.m3u8"
  echo '#EXT-X-STREAM-INF:BANDWIDTH=1300000,AVERAGE-BANDWIDTH=1200000,RESOLUTION=854x480,CODECS="avc1.4d401f,mp4a.40.2",FRAME-RATE=24.000'
  echo "480p/index.m3u8"
  echo '#EXT-X-STREAM-INF:BANDWIDTH=2800000,AVERAGE-BANDWIDTH=2600000,RESOLUTION=1280x720,CODECS="avc1.4d401f,mp4a.40.2",FRAME-RATE=24.000'
  echo "720p/index.m3u8"
  echo '#EXT-X-STREAM-INF:BANDWIDTH=5500000,AVERAGE-BANDWIDTH=5100000,RESOLUTION=1920x1080,CODECS="avc1.4d4028,mp4a.40.2",FRAME-RATE=24.000'
  echo "1080p/index.m3u8"
} > "$OUT/hls/master.m3u8"

echo "==> fonts"
cp "$FONT_DIR/DejaVuSans.ttf" "$FONT_DIR/DejaVuSerif.ttf" "$FONT_DIR/DejaVuSansMono.ttf" "$OUT/fonts/"

echo "==> subtitle"
cp "$ROOT/tools/subtitle.ass" "$OUT/subtitle.ass"

echo "==> MKV with embedded ASS + attached fonts"
# The attachment mimetype has to be set per attachment stream; ffmpeg maps
# -attach inputs onto 't' streams in the order they are given.
ffmpeg -y -loglevel error \
  -i "$OUT/source.mp4" -i "$OUT/subtitle.ass" \
  -attach "$OUT/fonts/DejaVuSans.ttf" \
  -attach "$OUT/fonts/DejaVuSerif.ttf" \
  -attach "$OUT/fonts/DejaVuSansMono.ttf" \
  -map 0:v:0 -map 0:a:0 -map 1:0 \
  -c:v copy -c:a copy -c:s ass \
  -metadata:s:t:0 mimetype=application/x-truetype-font \
  -metadata:s:t:1 mimetype=application/x-truetype-font \
  -metadata:s:t:2 mimetype=application/x-truetype-font \
  -metadata:s:s:0 language=tur -metadata:s:s:0 title="Türkçe" \
  -metadata:s:a:0 language=jpn -metadata:s:a:0 title="Japonca" \
  "$OUT/episode.mkv"

echo "==> MKV with Opus audio"
ffmpeg -y -loglevel error \
  -i "$OUT/source.mp4" -i "$OUT/subtitle.ass" \
  -attach "$OUT/fonts/DejaVuSans.ttf" \
  -map 0:v:0 -map 0:a:0 -map 1:0 \
  -c:v copy -c:a libopus -b:a 128k -c:s ass \
  -metadata:s:t:0 mimetype=application/x-truetype-font \
  "$OUT/episode-opus.mkv"

# ── Royalty-free corpus ─────────────────────────────────────────────────────
# Chromium builds without proprietary codecs (Playwright's included) cannot
# decode H.264 or AAC, so the same content is also produced as VP9 + Opus.
# That keeps the browser end of the pipeline testable in CI, and it exercises
# the VP9 configuration-record synthesis and the OpusHead-to-dOps conversion,
# neither of which the H.264 path touches.
if [ "${SKIP_VP9:-0}" != "1" ]; then
  echo "==> VP9 + Opus source (${VP9_DURATION:-30}s)"
  VP9_DURATION="${VP9_DURATION:-30}"
  ffmpeg -y -loglevel error -t "$VP9_DURATION" -i "$OUT/source.mp4" \
    -vf "scale=1280:720" \
    -c:v libvpx-vp9 -deadline realtime -cpu-used 8 -row-mt 1 -b:v 1800k \
    -g 48 -keyint_min 48 \
    -c:a libopus -b:a 96k -ar 48000 -ac 2 \
    -t "$VP9_DURATION" \
    "$OUT/source-vp9.webm"

  echo "==> VP9 MKV with embedded ASS + attached fonts"
  ffmpeg -y -loglevel error \
    -i "$OUT/source-vp9.webm" -i "$OUT/subtitle.ass" \
    -attach "$OUT/fonts/DejaVuSans.ttf" \
    -attach "$OUT/fonts/DejaVuSerif.ttf" \
    -attach "$OUT/fonts/DejaVuSansMono.ttf" \
    -map 0:v:0 -map 0:a:0 -map 1:0 \
    -c:v copy -c:a copy -c:s ass \
    -metadata:s:t:0 mimetype=application/x-truetype-font \
    -metadata:s:t:1 mimetype=application/x-truetype-font \
    -metadata:s:t:2 mimetype=application/x-truetype-font \
    -metadata:s:s:0 language=tur -metadata:s:s:0 title="Türkçe" \
    -metadata:s:a:0 language=jpn -metadata:s:a:0 title="Japonca" \
    -t "$VP9_DURATION" \
    -f matroska "$OUT/episode-vp9.mkv"

  echo "==> VP9 HLS ladder (fMP4 segments)"
  # MPEG-TS cannot carry VP9, so these variants use fMP4 segments — which is
  # also the modern HLS packaging, and worth having covered either way.
  make_vp9_variant() {
    local height=$1 width=$2 bitrate=$3
    local dir="$OUT/hls-vp9/${height}p"
    mkdir -p "$dir"
    ffmpeg -y -loglevel error -t "$VP9_DURATION" -i "$OUT/source.mp4" \
      -vf "scale=${width}:${height}" \
      -c:v libvpx-vp9 -deadline realtime -cpu-used 8 -row-mt 1 -b:v "$bitrate" \
      -g 48 -keyint_min 48 \
      -c:a libopus -b:a 96k -ar 48000 -ac 2 \
      -f hls -hls_time 2 -hls_playlist_type vod -hls_list_size 0 \
      -hls_segment_type fmp4 -hls_fmp4_init_filename "init.mp4" \
      -hls_segment_filename "$dir/seg%03d.m4s" \
      "$dir/index.m3u8"
    echo "    ${height}p done"
  }
  make_vp9_variant 360 640 "400k"
  make_vp9_variant 720 1280 "1600k"

  {
    echo "#EXTM3U"
    echo "#EXT-X-VERSION:7"
    echo '#EXT-X-STREAM-INF:BANDWIDTH=520000,RESOLUTION=640x360,CODECS="vp09.00.20.08,opus",FRAME-RATE=24.000'
    echo "360p/index.m3u8"
    echo '#EXT-X-STREAM-INF:BANDWIDTH=1800000,RESOLUTION=1280x720,CODECS="vp09.00.31.08,opus",FRAME-RATE=24.000'
    echo "720p/index.m3u8"
  } > "$OUT/hls-vp9/master.m3u8"
fi

echo
echo "Done. Corpus in $OUT:"
du -h -d 2 "$OUT" | sort -k2
