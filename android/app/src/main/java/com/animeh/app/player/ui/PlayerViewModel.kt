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
import com.animeh.app.player.PlaybackController
import com.animeh.app.player.QualitySelection
import dagger.hilt.android.lifecycle.HiltViewModel
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
}
