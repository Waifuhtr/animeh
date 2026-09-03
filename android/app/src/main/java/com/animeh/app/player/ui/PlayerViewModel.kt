package com.animeh.app.player.ui

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppResult
import com.animeh.app.core.UiState
import com.animeh.app.data.prefs.SettingsStore
import com.animeh.app.data.repository.CatalogRepository
import com.animeh.app.data.repository.LibraryRepository
import com.animeh.app.domain.Playback
import com.animeh.app.domain.Work
import com.animeh.app.player.PlaybackController
import com.animeh.app.player.QualitySelection
import com.animeh.app.social.WatchPartySession
import dagger.hilt.android.lifecycle.HiltViewModel
import android.os.SystemClock
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import javax.inject.Inject

/**
 * Drives one episode.
 *
 * Owns the loading of the playback payload and the wiring between the
 * controller and the repositories; the controller itself owns the media state
 * so it survives a configuration change without re-preparing.
 */
@HiltViewModel
class PlayerViewModel @Inject constructor(
    private val catalogRepository: CatalogRepository,
    private val libraryRepository: LibraryRepository,
    private val settingsStore: SettingsStore,
    val controller: PlaybackController,
    private val party: WatchPartySession,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    private val _loadState = MutableStateFlow<UiState<Playback>>(UiState.Loading)
    val loadState: StateFlow<UiState<Playback>> = _loadState.asStateFlow()

    val playerState = controller.state
    val cues = controller.cues
    val typefaces = controller.typefaces
    val primaryFont = controller.primaryFont

    private var currentEpisodeId: Long = savedStateHandle["episodeId"] ?: 0L
    private var currentWorkId: Long = 0L

    init {
        controller.attach(viewModelScope)

        controller.onProgress = { position, duration, watched ->
            viewModelScope.launch {
                libraryRepository.recordProgress(
                    currentEpisodeId,
                    currentWorkId,
                    position,
                    duration,
                    watched,
                )
            }
        }

        controller.onCompleted = {
            viewModelScope.launch {
                val state = controller.state.value
                if (state.autoplayNext) {
                    state.next?.let { open(it.id) }
                }
            }
        }

        if (currentEpisodeId > 0) open(currentEpisodeId)
    }

    /** Load and start an episode. */
    /**
     * The work whose adult-content warning is waiting to be answered.
     *
     * The anime page asks first and is where the warning is meant to appear,
     * but playback also starts from a home rail, the library, a resumed
     * episode and autoplay into the next one — so the last gate before the
     * media loads is here, where every one of those routes passes.
     */
    private val _adultGate = MutableStateFlow<Work?>(null)
    val adultGate: StateFlow<Work?> = _adultGate.asStateFlow()

    /** Series accepted in this player, on top of what was accepted before. */
    private var acknowledgedWorkId: Long = 0

    /** The room this device is in, for the screen to draw. */
    val room = party.room

    private var roomJob: Job? = null

    private var publishJob: Job? = null

    /**
     * The last state a remote device asked for, and when.
     *
     * This is the echo guard, and it has to be a record rather than a flag.
     * Applying a remote pause reaches ExoPlayer, whose own listener then
     * changes the local state — asynchronously, after any `applying = false`
     * would already have run. Comparing what is about to be published against
     * what was just received catches the echo whenever it arrives, and lets a
     * genuinely different action through immediately.
     */
    private var appliedPlaying: Boolean? = null
    private var appliedAt = 0L

    /** What to run once the warning is answered. */
    private var pendingStart: (() -> Unit)? = null

    /**
     * Follow the room, and tell it where this device is.
     *
     * Two guards keep this from turning into a feedback loop. A state written
     * by this device is ignored, or applying a remote pause would fire the
     * local pause listener and write the state again for ever. And a position
     * within a couple of seconds of the local one is not seeked to, because
     * two devices are never frame-identical and correcting a 300ms difference
     * would make both of them stutter continuously.
     */
    private fun followRoom() {
        roomJob?.cancel()

        // Subscribed unconditionally rather than only when a room is already
        // open: the flow follows the room, so a party started after playback
        // began is picked up without anything having to re-subscribe.
        roomJob = viewModelScope.launch {
            party.playback().collect { remote ->
                if (remote.by.isBlank() || remote.by == party.uid) return@collect
                if (remote.episodeId != 0L && remote.episodeId != currentEpisodeId) return@collect

                appliedPlaying = remote.playing
                appliedAt = SystemClock.elapsedRealtime()

                // Play state first, position second. Somebody who pressed
                // pause is waiting for the picture to stop, and seeking a
                // network stream can take seconds to re-buffer — doing that
                // first is what made a pause look several seconds late.
                if (remote.playing != controller.state.value.phase.isPlaying) {
                    if (remote.playing) controller.play() else controller.pause()
                }

                val drift = kotlin.math.abs(controller.state.value.positionMs - remote.positionMs)
                if (drift > SYNC_TOLERANCE_MS) {
                    controller.seekTo(remote.positionMs)
                }
            }
        }
    }

    /**
     * Tell the room whenever this device starts or stops playing.
     *
     * Driven by the controller's state rather than by the buttons, so a pause
     * from the notification, a headset, an incoming call or the end of an
     * episode reaches the room like any other — pressing pause on the screen
     * was previously the only thing anyone else heard about.
     */
    private fun publishLocalPlayback() {
        publishJob?.cancel()

        publishJob = viewModelScope.launch {
            var last: Boolean? = null

            controller.state.collect { state ->
                val playing = state.phase.isPlaying

                if (playing == last) return@collect
                last = playing

                if (party.room.value == null) return@collect

                // The echo of a remote change, arriving through ExoPlayer's
                // own listener a moment after it was applied.
                val echo = appliedPlaying == playing &&
                    SystemClock.elapsedRealtime() - appliedAt < ECHO_WINDOW_MS

                if (!echo) {
                    party.publish(state.positionMs, playing, currentEpisodeId)
                }
            }
        }
    }

    /** Tell the room where the playhead was just moved to. */
    fun broadcast() {
        if (party.room.value == null) return

        val state = controller.state.value
        party.publish(state.positionMs, state.phase.isPlaying, currentEpisodeId)
    }

    fun confirmAdult() {
        val workId = _adultGate.value?.id ?: 0
        acknowledgedWorkId = workId

        // Remembered where the anime page reads it too, so accepting here does
        // not leave the page asking again next time.
        if (workId > 0) {
            viewModelScope.launch { settingsStore.acknowledgeAdult(workId) }
        }

        _adultGate.value = null
        pendingStart?.invoke()
        pendingStart = null
    }

    /** Declining leaves nothing loaded; the activity closes itself. */
    fun declineAdult() {
        _adultGate.value = null
        pendingStart = null
    }

    fun open(episodeId: Long) {
        currentEpisodeId = episodeId
        _loadState.value = UiState.Loading

        viewModelScope.launch {
            val settings = settingsStore.settings.first()

            when (val result = catalogRepository.playback(episodeId)) {
                is AppResult.Success -> {
                    val playback = result.data
                    currentWorkId = playback.work.id
                    _loadState.value = UiState.Success(playback)

                    // The local position wins over the server's: it is written
                    // before the server hears about it, so on a device that has
                    // been offline it is the more recent of the two.
                    val local = libraryRepository.localProgress(episodeId)
                    val resume = when {
                        local != null && local.isResumable -> local.positionSeconds
                        playback.resume?.isResumable == true -> playback.resume.positionSeconds
                        else -> 0
                    }

                    // Whichever side has seen more of the episode: the phone
                    // may hold time the server has not been told about yet.
                    val watched = maxOf(
                        local?.watchedSeconds ?: 0,
                        playback.resume?.watchedSeconds ?: 0,
                    )

                    val start = {
                        controller.load(
                            source = playback,
                            preferredQuality = qualityFrom(settings.defaultQuality),
                            subtitlesEnabled = settings.subtitlesEnabled,
                            subtitleLanguage = settings.subtitleLanguage,
                            dataSaver = settings.dataSaver,
                            autoplayNext = settings.autoplayNext,
                            speed = settings.playbackSpeed,
                            startPositionSeconds = resume,
                            alreadyWatchedSeconds = watched,
                        )
                    }

                    // Nothing is loaded until the question is answered: asking
                    // over a video that is already playing is not a warning.
                    // The anime page asks first and remembers the answer;
                    // this is the backstop for every other route into
                    // playback — a home rail, the library, a resumed episode,
                    // autoplay into the next one, a room invitation.
                    val accepted = acknowledgedWorkId == playback.work.id ||
                        playback.work.id in settingsStore.acknowledgedAdult.first()

                    if (playback.work.adult && !accepted) {
                        pendingStart = start
                        _adultGate.value = playback.work
                    } else {
                        start()
                    }

                    followRoom()
                    publishLocalPlayback()
                }

                is AppResult.Failure -> {
                    _loadState.value = UiState.Error(result.error)
                }
            }
        }
    }

    fun playNext() {
        controller.state.value.next?.let { open(it.id) }
    }

    fun playPrevious() {
        controller.state.value.previous?.let { open(it.id) }
    }

    fun setQuality(selection: QualitySelection) {
        controller.setQuality(selection)
        viewModelScope.launch {
            settingsStore.setQuality(
                when (selection) {
                    is QualitySelection.Auto -> "auto"
                    is QualitySelection.Fixed -> selection.height.toString()
                }
            )
        }
    }

    fun setSpeed(speed: Float) {
        controller.setSpeed(speed)
        viewModelScope.launch { settingsStore.setPlaybackSpeed(speed) }
    }

    fun retry() {
        if (_loadState.value is UiState.Error) open(currentEpisodeId) else controller.retry()
    }

    override fun onCleared() {
        controller.release()
        super.onCleared()
    }

    private fun qualityFrom(stored: String): QualitySelection =
        stored.toIntOrNull()?.let { QualitySelection.Fixed(it) } ?: QualitySelection.Auto

    private companion object {
        /**
         * How far apart two devices may drift before one is corrected.
         *
         * Two phones on different connections are never frame-identical, and
         * chasing a 300ms difference makes both of them stutter forever. Two
         * seconds is under a sentence of dialogue.
         */
        const val SYNC_TOLERANCE_MS = 2_000L

        /**
         * How long a local change is treated as the echo of a remote one.
         *
         * Long enough to cover ExoPlayer telling us what we just told it,
         * short enough that somebody pressing pause a moment after being
         * paused is still heard.
         */
        const val ECHO_WINDOW_MS = 1_500L
    }
}
