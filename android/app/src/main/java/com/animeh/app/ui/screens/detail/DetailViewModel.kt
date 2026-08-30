package com.animeh.app.ui.screens.detail

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppResult
import com.animeh.app.core.UiState
import com.animeh.app.data.repository.CatalogRepository
import com.animeh.app.data.repository.LibraryRepository
import com.animeh.app.domain.Episode
import com.animeh.app.domain.Work
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

data class DetailUiState(
    val work: UiState<Work> = UiState.Loading,
    val episodes: UiState<List<Episode>> = UiState.Loading,
    val selectedSeason: Int = 1,
    val isFavorite: Boolean = false,
    val inWatchlist: Boolean = false,
    val fromCache: Boolean = false,
)

@HiltViewModel
class DetailViewModel @Inject constructor(
    private val catalogRepository: CatalogRepository,
    private val libraryRepository: LibraryRepository,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    private val workId: Long = savedStateHandle["workId"] ?: 0L

    private val _state = MutableStateFlow(DetailUiState())
    val state: StateFlow<DetailUiState> = _state.asStateFlow()

    init {
        load()
        observeLibrary()
    }

    fun load() {
        viewModelScope.launch {
            when (val result = catalogRepository.work(workId.toString())) {
                is AppResult.Success -> {
                    val work = result.data.value
                    _state.update {
                        it.copy(
                            work = UiState.Success(work, result.data.fromCache),
                            fromCache = result.data.fromCache,
                            isFavorite = work.isFavorite,
                            inWatchlist = work.inWatchlist,
                            selectedSeason = work.seasons.firstOrNull()?.number ?: 1,
                        )
                    }
                    loadEpisodes(work.seasons.firstOrNull()?.number ?: 0)
                }
                is AppResult.Failure -> _state.update { it.copy(work = UiState.Error(result.error)) }
            }
        }
    }

    fun selectSeason(season: Int) {
        _state.update { it.copy(selectedSeason = season, episodes = UiState.Loading) }
        loadEpisodes(season)
    }

    fun toggleFavorite() {
        val wanted = !_state.value.isFavorite
        // Flipped immediately; the repository puts it back if the server refuses.
        _state.update { it.copy(isFavorite = wanted) }

        viewModelScope.launch {
            val result = libraryRepository.toggleFavorite(workId, wanted)
            if (result is AppResult.Failure) {
                _state.update { it.copy(isFavorite = !wanted) }
            }
        }
    }

    fun toggleWatchlist() {
        val wanted = !_state.value.inWatchlist
        _state.update { it.copy(inWatchlist = wanted) }

        viewModelScope.launch {
            val result = libraryRepository.toggleWatchlist(workId, wanted)
            if (result is AppResult.Failure) {
                _state.update { it.copy(inWatchlist = !wanted) }
            }
        }
    }

    /** The episode "İzle" should open: the first unfinished one. */
    fun nextEpisodeToPlay(): Episode? {
        val episodes = (_state.value.episodes as? UiState.Success)?.data ?: return null

        return episodes.firstOrNull { it.progress?.completed != true }
            ?: episodes.firstOrNull()
    }

    private fun loadEpisodes(season: Int) {
        viewModelScope.launch {
            when (val result = catalogRepository.episodes(workId, season)) {
                is AppResult.Success -> {
                    val episodes = result.data.value
                    _state.update {
                        it.copy(
                            episodes = if (episodes.isEmpty()) UiState.Empty
                            else UiState.Success(episodes, result.data.fromCache)
                        )
                    }
                }
                is AppResult.Failure -> _state.update { it.copy(episodes = UiState.Error(result.error)) }
            }
        }
    }

    private fun observeLibrary() {
        viewModelScope.launch {
            libraryRepository.isFavorite(workId).collect { value ->
                _state.update { it.copy(isFavorite = value) }
            }
        }
        viewModelScope.launch {
            libraryRepository.isInWatchlist(workId).collect { value ->
                _state.update { it.copy(inWatchlist = value) }
            }
        }
    }
}
