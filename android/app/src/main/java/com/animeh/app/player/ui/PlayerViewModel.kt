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

    /** Answered once per work rather than once per episode. */
    private var acknowledgedWorkId: Long = 0

    /** The room this device is in, for the screen to draw. */
    val room = party.room

    private var roomJob: Job? = null

    /** True while a remote state is being applied, so it is not echoed back. */
    private var applyingRemote = false

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

        if (party.room.value == null) return

        roomJob = viewModelScope.launch {
            party.playback().collect { remote ->
                if (remote.by.isBlank() || remote.by == party.uid) return@collect
                if (remote.episodeId != 0L && remote.episodeId != currentEpisodeId) return@collect

                applyingRemote = true

                val drift = kotlin.math.abs(controller.state.value.positionMs - remote.positionMs)
                if (drift > SYNC_TOLERANCE_MS) {
                    controller.seekTo(remote.positionMs)
                }

                if (remote.playing != controller.state.value.phase.isPlaying) {
                    if (remote.playing) controller.play() else controller.pause()
                }

                applyingRemote = false
            }
        }
    }

    /** Tell the room what this device just did. */
    fun broadcast() {
        if (applyingRemote || party.room.value == null) return

        val state = controller.state.value
        party.publish(state.positionMs, state.phase.isPlaying, currentEpisodeId)
    }

    fun confirmAdult() {
        acknowledgedWorkId = _adultGate.value?.id ?: 0
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
                    if (playback.work.adult && acknowledgedWorkId != playback.work.id) {
                        pendingStart = start
                        _adultGate.value = playback.work
                    } else {
                        start()
                    }

                    followRoom()
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
    }
}
