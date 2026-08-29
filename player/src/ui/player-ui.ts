import type { AnimehPlayer } from '../core/controller.ts'
import type { PlayerSnapshot, QualityLevel } from '../core/types.ts'
import { icons } from './icons.ts'

const PLAYBACK_RATES = [0.5, 0.75, 1, 1.25, 1.5, 2]
const CONTROLS_HIDE_DELAY_MS = 3200
const SEEK_STEP_SEC = 10

type MenuName = 'quality' | 'speed' | 'subtitles' | 'audio' | null

/**
 * The player's controls.
 *
 * Plain DOM on purpose. The same markup has to work inside a WordPress admin
 * page, a standalone test harness, and eventually a WebView, none of which
 * should have to agree on a framework. It renders from `PlayerSnapshot` and
 * sends intents back to the controller — it holds no playback state of its own,
 * so what is on screen can never disagree with what is playing.
 */
export class PlayerUI {
  #player: AnimehPlayer
  #root: HTMLElement
  #controls!: HTMLElement
  #els!: {
    playPause: HTMLButtonElement
    rewind: HTMLButtonElement
    forward: HTMLButtonElement
    previous: HTMLButtonElement
    next: HTMLButtonElement
    lock: HTMLButtonElement
    unlock: HTMLButtonElement
    fullscreen: HTMLButtonElement
    mute: HTMLButtonElement
    volume: HTMLInputElement
    quality: HTMLButtonElement
    speed: HTMLButtonElement
    subtitles: HTMLButtonElement
    audio: HTMLButtonElement
    title: HTMLElement
    subtitleLine: HTMLElement
    current: HTMLElement
    total: HTMLElement
    track: HTMLElement
    rail: HTMLElement
    buffer: HTMLElement
    played: HTMLElement
    thumb: HTMLElement
    markers: HTMLElement
    spinner: HTMLElement
    banner: HTMLElement
    bannerText: HTMLElement
    error: HTMLElement
    errorMessage: HTMLElement
    errorCode: HTMLElement
    retry: HTMLButtonElement
    menu: HTMLElement
    skip: HTMLButtonElement
    debug: HTMLElement
  }

  /**
   * Where the controls are mounted.
   *
   * The stage, not the outer root: it is the element that carries the video's
   * dimensions and the size container the controls query to decide how compact
   * to be. Mounting a level higher leaves them outside that container, and the
   * short-player layout silently never applies.
   */
  #layer!: HTMLElement
  #menu: MenuName = null
  #hideTimer: ReturnType<typeof setTimeout> | null = null
  #controlsHidden = false
  #dragging = false
  #snapshot: PlayerSnapshot | null = null
  #debugVisible = false
  #skipTarget: number | null = null
  #unsubscribe: (() => void) | null = null
  #detachers: (() => void)[] = []

  constructor(player: AnimehPlayer, root: HTMLElement) {
    this.#player = player
    this.#root = root
    this.#layer = root.querySelector<HTMLElement>('.animeh__stage') ?? root
    this.#build()
    this.#bind()
    this.#unsubscribe = player.subscribe((snapshot) => this.render(snapshot))
  }

  /* ── Markup ───────────────────────────────────────────────────────────── */

  #build(): void {
    const controls = el('div', 'animeh__controls')
    controls.setAttribute('role', 'group')
    controls.setAttribute('aria-label', 'Oynatıcı kontrolleri')

    // Top bar: identity and the menus that are not part of the transport.
    const top = el('div', 'animeh__top')
    const titleBlock = el('div', 'animeh__title-block')
    const title = el('div', 'animeh__title')
    const subtitleLine = el('div', 'animeh__subtitle-line')
    titleBlock.append(title, subtitleLine)
    const topActions = el('div', 'animeh__button-row')
    const audio = iconButton('audio', 'Ses parçası')
    const subtitlesBtn = iconButton('subtitles', 'Altyazı')
    const speed = iconButton('speed', 'Oynatma hızı')
    const quality = iconButton('quality', 'Kalite')
    topActions.append(audio, subtitlesBtn, speed, quality)
    top.append(titleBlock, topActions)

    // Centre: the transport controls, sized for a thumb.
    const center = el('div', 'animeh__center')
    const previous = iconButton('previous', 'Önceki bölüm')
    const rewind = iconButton('rewind10', '10 saniye geri')
    const playPause = iconButton('play', 'Oynat', 'animeh__btn--primary')
    const forward = iconButton('forward10', '10 saniye ileri')
    const next = iconButton('next', 'Sonraki bölüm')
    center.append(previous, rewind, playPause, forward, next)

    // Bottom: scrubber, then the remaining controls.
    const bottom = el('div', 'animeh__bottom')
    const scrubber = el('div', 'animeh__scrubber')
    const current = el('span', 'animeh__time')
    current.textContent = '0:00'
    const total = el('span', 'animeh__time')
    total.textContent = '0:00'

    const track = el('div', 'animeh__track')
    track.setAttribute('role', 'slider')
    track.setAttribute('tabindex', '0')
    track.setAttribute('aria-label', 'İlerleme çubuğu')
    track.setAttribute('aria-valuemin', '0')
    const rail = el('div', 'animeh__rail')
    const buffer = el('div', 'animeh__buffer')
    const played = el('div', 'animeh__played')
    const markers = el('div', 'animeh__markers')
    const thumb = el('div', 'animeh__thumb')
    rail.append(buffer, played, markers)
    track.append(rail, thumb)
    scrubber.append(current, track, total)

    const row = el('div', 'animeh__button-row')
    const volumeWrap = el('div', 'animeh__volume')
    const mute = iconButton('volumeHigh', 'Sesi kapat')
    const volume = document.createElement('input')
    volume.type = 'range'
    volume.min = '0'
    volume.max = '1'
    volume.step = '0.05'
    volume.value = '1'
    volume.className = 'animeh__volume-slider'
    volume.setAttribute('aria-label', 'Ses seviyesi')
    volumeWrap.append(mute, volume)
    const lock = iconButton('lock', 'Ekranı kilitle')
    const fullscreen = iconButton('fullscreen', 'Tam ekran')
    row.append(volumeWrap, el('div', 'animeh__spacer'), lock, fullscreen)
    bottom.append(scrubber, row)

    controls.append(top, center, bottom)

    // Lock mode keeps exactly one control alive.
    const lockLayer = el('div', 'animeh__lock-layer')
    const unlock = iconButton('unlock', 'Kilidi aç')
    lockLayer.append(unlock)
    controls.append(lockLayer)

    // Overlays sit outside the controls so they survive auto-hide.
    const spinner = el('div', 'animeh__spinner')
    spinner.hidden = true
    spinner.setAttribute('role', 'status')
    spinner.setAttribute('aria-label', 'Yükleniyor')

    const banner = el('div', 'animeh__banner')
    banner.hidden = true
    const bannerIcon = el('span')
    bannerIcon.innerHTML = icons.offline
    const bannerText = el('span')
    banner.append(bannerIcon, bannerText)

    const skip = el('button', 'animeh__skip') as HTMLButtonElement
    skip.type = 'button'
    skip.hidden = true
    skip.innerHTML = `${icons.skip}<span>Jenerigi atla</span>`

    const error = el('div', 'animeh__error')
    error.hidden = true
    error.setAttribute('role', 'alert')
    const errorIcon = el('div')
    errorIcon.innerHTML = icons.warning
    const errorMessage = el('p', 'animeh__error-message')
    const errorCode = el('code', 'animeh__error-code')
    const retry = el('button', 'animeh__action') as HTMLButtonElement
    retry.type = 'button'
    retry.textContent = 'Tekrar dene'
    error.append(errorIcon, errorMessage, errorCode, retry)

    const menu = el('div', 'animeh__menu')
    menu.hidden = true
    menu.setAttribute('role', 'menu')

    const debug = el('div', 'animeh__debug')
    debug.hidden = true

    this.#layer.append(controls, spinner, banner, skip, menu, error, debug)
    this.#controls = controls
    this.#els = {
      playPause, rewind, forward, previous, next, lock, unlock, fullscreen,
      mute, volume, quality, speed, subtitles: subtitlesBtn, audio,
      title, subtitleLine, current, total, track, rail, buffer, played, thumb,
      markers, spinner, banner, bannerText, error, errorMessage, errorCode,
      retry, menu, skip, debug,
    }
  }

  /* ── Interaction ──────────────────────────────────────────────────────── */

  #bind(): void {
    const on = <K extends keyof HTMLElementEventMap>(
      target: EventTarget,
      type: K,
      handler: (event: HTMLElementEventMap[K]) => void,
      options?: AddEventListenerOptions,
    ) => {
      target.addEventListener(type, handler as EventListener, options)
      this.#detachers.push(() => target.removeEventListener(type, handler as EventListener, options))
    }

    const e = this.#els
    on(e.playPause, 'click', () => this.#player.togglePlay())
    on(e.rewind, 'click', () => this.#player.seekBy(-SEEK_STEP_SEC))
    on(e.forward, 'click', () => this.#player.seekBy(SEEK_STEP_SEC))
    on(e.previous, 'click', () => this.#player.requestPrevious())
    on(e.next, 'click', () => this.#player.requestNext())
    on(e.lock, 'click', () => this.#player.setLocked(true))
    on(e.unlock, 'click', () => this.#player.setLocked(false))
    on(e.fullscreen, 'click', () => void this.#player.toggleFullscreen(this.#root))
    on(e.mute, 'click', () => this.#player.toggleMute())
    on(e.volume, 'input', () => this.#player.setVolume(Number(e.volume.value)))
    on(e.retry, 'click', () => void this.#player.retry())
    on(e.skip, 'click', () => {
      if (this.#skipTarget !== null) this.#player.seek(this.#skipTarget)
    })

    on(e.quality, 'click', () => this.#toggleMenu('quality'))
    on(e.speed, 'click', () => this.#toggleMenu('speed'))
    on(e.subtitles, 'click', () => this.#toggleMenu('subtitles'))
    on(e.audio, 'click', () => this.#toggleMenu('audio'))

    // Tapping the picture toggles the controls; tapping again plays or pauses.
    on(this.#root, 'pointermove', () => this.#showControls())
    on(this.#root, 'pointerdown', (event) => {
      const target = event.target as HTMLElement
      if (target.closest('.animeh__btn, .animeh__menu, .animeh__action, .animeh__track, .animeh__skip')) {
        return
      }
      if (this.#controlsHidden) this.#showControls()
      else if (this.#menu) this.#closeMenu()
      else this.#player.togglePlay()
    })
    on(this.#root, 'pointerleave', () => this.#scheduleHide(600))

    this.#bindScrubber(on)
    this.#bindKeyboard(on)

    on(document, 'fullscreenchange', () => this.render(this.#player.snapshot))
  }

  #bindScrubber(on: (t: EventTarget, k: never, h: never, o?: AddEventListenerOptions) => void): void {
    const track = this.#els.track
    const seekFromEvent = (event: PointerEvent, commit: boolean) => {
      const snapshot = this.#snapshot
      if (!snapshot || snapshot.duration <= 0) return
      const rect = track.getBoundingClientRect()
      const ratio = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width))
      const target = ratio * snapshot.duration
      // Paint the new position immediately; committing it waits for release so
      // dragging does not fire a seek per pixel.
      this.#paintProgress(target, snapshot)
      if (commit) this.#player.seek(target)
    }

    track.addEventListener('pointerdown', (event) => {
      if (this.#snapshot?.locked) return
      this.#dragging = true
      track.dataset.dragging = 'true'
      track.setPointerCapture(event.pointerId)
      seekFromEvent(event, false)
    })
    track.addEventListener('pointermove', (event) => {
      if (!this.#dragging) return
      seekFromEvent(event, false)
    })
    const endDrag = (event: PointerEvent) => {
      if (!this.#dragging) return
      this.#dragging = false
      delete track.dataset.dragging
      seekFromEvent(event, true)
    }
    track.addEventListener('pointerup', endDrag)
    track.addEventListener('pointercancel', endDrag)

    track.addEventListener('keydown', (event) => {
      const snapshot = this.#snapshot
      if (!snapshot) return
      switch (event.key) {
        case 'ArrowLeft':
          event.preventDefault()
          this.#player.seekBy(-5)
          break
        case 'ArrowRight':
          event.preventDefault()
          this.#player.seekBy(5)
          break
        case 'Home':
          event.preventDefault()
          this.#player.seek(0)
          break
        case 'End':
          event.preventDefault()
          this.#player.seek(snapshot.duration)
          break
      }
    })
    void on
  }

  #bindKeyboard(
    on: <K extends keyof HTMLElementEventMap>(
      t: EventTarget,
      k: K,
      h: (event: HTMLElementEventMap[K]) => void,
    ) => void,
  ): void {
    this.#root.tabIndex = 0
    on(this.#root, 'keydown', (event) => {
      // Never swallow typing in a form field embedded near the player.
      const target = event.target as HTMLElement
      if (target instanceof HTMLInputElement && target.type !== 'range') return
      if (this.#snapshot?.locked && event.key !== 'Escape') return

      switch (event.key) {
        case ' ':
        case 'k':
          event.preventDefault()
          this.#player.togglePlay()
          break
        case 'ArrowLeft':
          event.preventDefault()
          this.#player.seekBy(-SEEK_STEP_SEC)
          break
        case 'ArrowRight':
          event.preventDefault()
          this.#player.seekBy(SEEK_STEP_SEC)
          break
        case 'ArrowUp':
          event.preventDefault()
          this.#player.setVolume((this.#snapshot?.volume ?? 1) + 0.1)
          break
        case 'ArrowDown':
          event.preventDefault()
          this.#player.setVolume((this.#snapshot?.volume ?? 1) - 0.1)
          break
        case 'm':
          this.#player.toggleMute()
          break
        case 'f':
          void this.#player.toggleFullscreen(this.#root)
          break
        case 'c':
          this.#cycleSubtitles()
          break
        case 'Escape':
          if (this.#menu) this.#closeMenu()
          break
        case 'd':
          // Hidden diagnostics, for the WordPress test panel and field debugging.
          this.#debugVisible = !this.#debugVisible
          this.#renderDebug()
          break
      }
      this.#showControls()
    })
  }

  /* ── Rendering ────────────────────────────────────────────────────────── */

  render(snapshot: PlayerSnapshot): void {
    this.#snapshot = snapshot
    const e = this.#els

    this.#root.dataset.locked = String(snapshot.locked)
    this.#root.dataset.phase = snapshot.phase

    const playing = snapshot.phase === 'playing'
    setIcon(e.playPause, snapshot.phase === 'ended' ? 'replay' : playing ? 'pause' : 'play')
    e.playPause.setAttribute('aria-label', playing ? 'Duraklat' : 'Oynat')

    const episode = snapshot.episode
    e.title.textContent = episode?.animeTitle ?? ''
    e.subtitleLine.textContent = episode
      ? [
          episode.season !== undefined ? `Sezon ${episode.season}` : null,
          episode.episodeNumber !== undefined ? `Bölüm ${episode.episodeNumber}` : null,
          episode.episodeTitle ?? null,
        ]
          .filter(Boolean)
          .join(' • ')
      : ''

    e.previous.disabled = episode?.hasPrevious !== true
    e.next.disabled = episode?.hasNext !== true

    if (!this.#dragging) this.#paintProgress(snapshot.position, snapshot)
    e.total.textContent = formatTime(snapshot.duration)
    e.track.setAttribute('aria-valuemax', String(Math.round(snapshot.duration)))
    e.track.setAttribute('aria-valuenow', String(Math.round(snapshot.position)))
    e.track.setAttribute('aria-valuetext', formatTime(snapshot.position))

    setIcon(e.mute, snapshot.muted || snapshot.volume === 0 ? 'volumeMuted' : snapshot.volume < 0.5 ? 'volumeLow' : 'volumeHigh')
    e.mute.setAttribute('aria-label', snapshot.muted ? 'Sesi aç' : 'Sesi kapat')
    if (document.activeElement !== e.volume) {
      e.volume.value = String(snapshot.muted ? 0 : snapshot.volume)
    }

    setIcon(e.fullscreen, snapshot.fullscreen ? 'exitFullscreen' : 'fullscreen')
    e.quality.disabled = snapshot.qualities.length < 2
    e.audio.disabled = snapshot.audioTracks.length < 2
    e.subtitles.dataset.active = String(snapshot.activeSubtitleTrackId !== null)

    // Buffering and engine loading are different things; either one justifies
    // a spinner, but only a mid-playback stall counts as a rebuffer.
    e.spinner.hidden = !(
      snapshot.phase === 'loading' ||
      snapshot.phase === 'buffering' ||
      snapshot.phase === 'seeking' ||
      (snapshot.loading && snapshot.bufferAhead < 1 && snapshot.phase !== 'paused')
    )

    this.#renderBanner(snapshot)
    this.#renderError(snapshot)
    this.#renderSkip(snapshot)
    this.#renderMarkers(snapshot)
    if (this.#menu) this.#renderMenu()
    this.#renderDebug()

    // Controls stay put while paused: hiding them mid-pause looks like a bug.
    if (snapshot.phase === 'playing' && !this.#menu) this.#scheduleHide()
    else this.#showControls(false)
  }

  #paintProgress(position: number, snapshot: PlayerSnapshot): void {
    const duration = snapshot.duration
    const ratio = duration > 0 ? Math.min(1, Math.max(0, position / duration)) : 0
    this.#els.played.style.width = `${ratio * 100}%`
    this.#els.thumb.style.left = `${ratio * 100}%`
    this.#els.current.textContent = formatTime(position)

    // Show the buffered run that contains the playhead, not the union of all
    // ranges — after a seek those are far apart and a union would lie.
    const active = snapshot.buffered.find((range) => range.start <= position + 0.25 && range.end > position)
    if (active && duration > 0) {
      this.#els.buffer.style.left = `${(active.start / duration) * 100}%`
      this.#els.buffer.style.width = `${((active.end - active.start) / duration) * 100}%`
    } else {
      this.#els.buffer.style.width = '0%'
    }
  }

  #renderMarkers(snapshot: PlayerSnapshot): void {
    const episode = snapshot.episode
    const duration = snapshot.duration
    if (!episode || duration <= 0) {
      this.#els.markers.replaceChildren()
      return
    }
    const spans: [number, number][] = []
    if (episode.introStart !== undefined && episode.introEnd !== undefined) {
      spans.push([episode.introStart, episode.introEnd])
    }
    if (episode.outroStart !== undefined) spans.push([episode.outroStart, duration])

    this.#els.markers.replaceChildren(
      ...spans.map(([start, end]) => {
        const marker = el('div', 'animeh__marker')
        marker.style.left = `${(start / duration) * 100}%`
        marker.style.width = `${((end - start) / duration) * 100}%`
        return marker
      }),
    )
  }

  #renderSkip(snapshot: PlayerSnapshot): void {
    const episode = snapshot.episode
    const skip = this.#els.skip
    if (!episode || snapshot.locked) {
      skip.hidden = true
      this.#skipTarget = null
      return
    }
    const { introStart, introEnd, outroStart } = episode
    if (introStart !== undefined && introEnd !== undefined && snapshot.position >= introStart && snapshot.position < introEnd) {
      skip.hidden = false
      skip.querySelector('span')!.textContent = 'Jeneriği atla'
      this.#skipTarget = introEnd
      return
    }
    if (outroStart !== undefined && snapshot.position >= outroStart && episode.hasNext) {
      skip.hidden = false
      skip.querySelector('span')!.textContent = 'Sonraki bölüm'
      this.#skipTarget = null
      skip.onclick = () => this.#player.requestNext()
      return
    }
    skip.hidden = true
    this.#skipTarget = null
  }

  #renderBanner(snapshot: PlayerSnapshot): void {
    const banner = this.#els.banner
    if (!snapshot.network.online) {
      banner.hidden = false
      banner.dataset.tone = 'danger'
      this.#els.bannerText.textContent = 'Çevrimdışısın. Bağlantı bekleniyor…'
      return
    }
    if (snapshot.phase === 'reconnecting') {
      banner.hidden = false
      banner.dataset.tone = 'warning'
      this.#els.bannerText.textContent = 'Bağlantı yeniden kuruluyor…'
      return
    }
    if (snapshot.network.saveData) {
      banner.hidden = false
      banner.dataset.tone = 'warning'
      this.#els.bannerText.textContent = 'Veri tasarrufu açık — kalite sınırlandı'
      return
    }
    banner.hidden = true
  }

  #renderError(snapshot: PlayerSnapshot): void {
    const error = snapshot.error
    const shouldShow = snapshot.phase === 'error' && error !== null
    this.#els.error.hidden = !shouldShow
    if (!shouldShow || !error) return
    // Viewers see the plain-language message; the code is there so a support
    // request can name what actually failed.
    this.#els.errorMessage.textContent = error.userMessage
    this.#els.errorCode.textContent = error.code
    // Retry is offered for anything a second attempt could plausibly fix. It is
    // withheld only where the failure is a property of the file rather than of
    // the moment, because a button that provably cannot work is worse than no
    // button at all.
    this.#els.retry.hidden =
      error.code === 'MEDIA_UNSUPPORTED' || error.code === 'CONTAINER_ERROR'
  }

  #renderDebug(): void {
    const debug = this.#els.debug
    debug.hidden = !this.#debugVisible
    if (!this.#debugVisible || !this.#snapshot) return
    const s = this.#snapshot
    const stats = this.#player.stats()
    const quality = s.qualities.find((level) => level.id === s.activeQualityId)
    debug.innerHTML = [
      `<b>durum</b> ${s.phase}${s.loading ? ' · yükleniyor' : ''}`,
      `<b>tampon</b> ${s.bufferAhead.toFixed(1)}s`,
      `<b>kalite</b> ${quality ? quality.label : '—'}${s.autoQuality ? ' (oto)' : ''}`,
      `<b>başlangıç</b> ${stats.startupTimeMs ?? '—'} ms`,
      `<b>takılma</b> ${stats.rebufferCount}× · ${(stats.rebufferMs / 1000).toFixed(1)}s`,
      `<b>bant genişliği</b> ${formatBitrate(stats.throughputBps)}`,
      `<b>indirilen</b> ${formatBytes(stats.bytesLoaded)}`,
      `<b>düşen kare</b> ${stats.droppedFrames}`,
      `<b>ağ</b> ${s.network.kind}${s.network.effectiveType ? ` · ${s.network.effectiveType}` : ''}`,
      stats.errors.length > 0
        ? `<span class="warn">son hata: ${escapeHtml(stats.errors.at(-1)!.code)}</span>`
        : '',
    ]
      .filter(Boolean)
      .join('<br>')
  }

  /* ── Menus ────────────────────────────────────────────────────────────── */

  #toggleMenu(name: MenuName): void {
    this.#menu = this.#menu === name ? null : name
    this.#renderMenu()
    this.#showControls(false)
  }

  #closeMenu(): void {
    this.#menu = null
    this.#els.menu.hidden = true
  }

  #renderMenu(): void {
    const menu = this.#els.menu
    const snapshot = this.#snapshot
    if (!this.#menu || !snapshot) {
      menu.hidden = true
      return
    }
    menu.hidden = false
    menu.replaceChildren()

    switch (this.#menu) {
      case 'quality':
        menu.append(menuTitle('Kalite'))
        menu.append(
          menuItem('Otomatik', snapshot.autoQuality, activeQualityNote(snapshot), () => {
            this.#player.setQuality(null)
            this.#closeMenu()
          }),
        )
        for (const level of [...snapshot.qualities].sort((a, b) => b.height - a.height)) {
          menu.append(
            menuItem(
              level.label,
              !snapshot.autoQuality && snapshot.selectedQualityId === level.id,
              level.bitrate > 0 ? formatBitrate(level.bitrate) : undefined,
              () => {
                this.#player.setQuality(level.id)
                this.#closeMenu()
              },
            ),
          )
        }
        break

      case 'speed':
        menu.append(menuTitle('Oynatma hızı'))
        for (const rate of PLAYBACK_RATES) {
          menu.append(
            menuItem(rate === 1 ? 'Normal' : `${rate}×`, Math.abs(snapshot.playbackRate - rate) < 0.01, undefined, () => {
              this.#player.setPlaybackRate(rate)
              this.#closeMenu()
            }),
          )
        }
        break

      case 'subtitles':
        menu.append(menuTitle('Altyazı'))
        menu.append(
          menuItem('Kapalı', snapshot.activeSubtitleTrackId === null, undefined, () => {
            void this.#player.setSubtitleTrack(null)
            this.#closeMenu()
          }),
        )
        for (const track of snapshot.subtitleTracks) {
          menu.append(
            menuItem(
              track.label,
              snapshot.activeSubtitleTrackId === track.id,
              // Say where a track came from: an embedded ASS is the release's
              // own typesetting, a sidecar may not match it.
              `${track.format.toUpperCase()} · ${track.origin === 'embedded' ? 'gömülü' : 'harici'}`,
              () => {
                void this.#player.setSubtitleTrack(track.id)
                this.#closeMenu()
              },
            ),
          )
        }
        break

      case 'audio':
        menu.append(menuTitle('Ses parçası'))
        for (const track of snapshot.audioTracks) {
          menu.append(
            menuItem(track.label, snapshot.activeAudioTrackId === track.id, track.codec, () => {
              this.#player.setAudioTrack(track.id)
              this.#closeMenu()
            }),
          )
        }
        break
    }
  }

  #cycleSubtitles(): void {
    const snapshot = this.#snapshot
    if (!snapshot) return
    const ids: (string | null)[] = [null, ...snapshot.subtitleTracks.map((track) => track.id)]
    const index = ids.indexOf(snapshot.activeSubtitleTrackId)
    void this.#player.setSubtitleTrack(ids[(index + 1) % ids.length] ?? null)
  }

  /* ── Auto-hide ────────────────────────────────────────────────────────── */

  #showControls(autoHide = true): void {
    this.#controlsHidden = false
    this.#controls.dataset.hidden = 'false'
    this.#root.style.cursor = ''
    if (autoHide && this.#snapshot?.phase === 'playing' && !this.#menu) this.#scheduleHide()
    else if (this.#hideTimer !== null) {
      clearTimeout(this.#hideTimer)
      this.#hideTimer = null
    }
  }

  #scheduleHide(delay = CONTROLS_HIDE_DELAY_MS): void {
    if (this.#hideTimer !== null) clearTimeout(this.#hideTimer)
    this.#hideTimer = setTimeout(() => {
      this.#hideTimer = null
      if (this.#menu || this.#dragging) return
      if (this.#snapshot?.phase !== 'playing') return
      this.#controlsHidden = true
      this.#controls.dataset.hidden = 'true'
      // Hiding the cursor too, so a full-screen frame is genuinely uncluttered.
      this.#root.style.cursor = 'none'
    }, delay)
  }

  destroy(): void {
    this.#unsubscribe?.()
    for (const detach of this.#detachers) detach()
    this.#detachers = []
    if (this.#hideTimer !== null) clearTimeout(this.#hideTimer)
  }
}

/* ── DOM helpers ────────────────────────────────────────────────────────── */

function el<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  className?: string,
): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag)
  if (className) node.className = className
  return node
}

function iconButton(name: keyof typeof icons, label: string, extra = ''): HTMLButtonElement {
  const button = el('button', `animeh__btn ${extra}`.trim()) as HTMLButtonElement
  button.type = 'button'
  button.innerHTML = icons[name]
  button.setAttribute('aria-label', label)
  button.title = label
  return button
}

function setIcon(button: HTMLButtonElement, name: keyof typeof icons): void {
  if (button.dataset.icon === name) return
  button.dataset.icon = name
  button.innerHTML = icons[name]
}

function menuTitle(text: string): HTMLElement {
  const node = el('div', 'animeh__menu-title')
  node.textContent = text
  return node
}

function menuItem(
  label: string,
  checked: boolean,
  note: string | undefined,
  onSelect: () => void,
): HTMLElement {
  const button = el('button', 'animeh__menu-item') as HTMLButtonElement
  button.type = 'button'
  button.setAttribute('role', 'menuitemradio')
  button.setAttribute('aria-checked', String(checked))
  button.innerHTML = icons.check
  const text = el('span', 'animeh__menu-item-label')
  text.textContent = label
  button.append(text)
  if (note) {
    const noteEl = el('span', 'animeh__menu-item-note')
    noteEl.textContent = note
    button.append(noteEl)
  }
  button.addEventListener('click', onSelect)
  return button
}

function activeQualityNote(snapshot: PlayerSnapshot): string | undefined {
  if (!snapshot.autoQuality) return undefined
  const active = snapshot.qualities.find((level: QualityLevel) => level.id === snapshot.activeQualityId)
  return active ? active.label : undefined
}

export function formatTime(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds < 0) return '0:00'
  const total = Math.floor(seconds)
  const hours = Math.floor(total / 3600)
  const minutes = Math.floor((total % 3600) / 60)
  const secs = total % 60
  if (hours > 0) return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`
  return `${minutes}:${String(secs).padStart(2, '0')}`
}

export function formatBitrate(bps: number | null): string {
  if (bps === null || !Number.isFinite(bps)) return '—'
  if (bps >= 1_000_000) return `${(bps / 1_000_000).toFixed(1)} Mbps`
  return `${Math.round(bps / 1000)} kbps`
}

export function formatBytes(bytes: number): string {
  if (bytes >= 1_000_000) return `${(bytes / 1_000_000).toFixed(1)} MB`
  if (bytes >= 1000) return `${Math.round(bytes / 1000)} KB`
  return `${bytes} B`
}

function escapeHtml(value: string): string {
  return value.replace(/[&<>"']/g, (char) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char] ?? char,
  )
}
