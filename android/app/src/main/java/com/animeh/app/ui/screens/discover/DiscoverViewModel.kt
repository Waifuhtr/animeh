package com.animeh.app.ui.screens.discover

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppResult
import com.animeh.app.core.UiState
import com.animeh.app.data.repository.CatalogRepository
import com.animeh.app.domain.Genre
import com.animeh.app.domain.Work
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.FlowPreview
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

data class DiscoverFilters(
    val query: String = "",
    val genre: String = "",
    val year: Int = 0,
    val season: String = "",
    val status: String = "",
    val sort: String = "recent",
) {
    val isActive: Boolean
        get() = query.isNotBlank() || genre.isNotBlank() || year > 0 ||
            season.isNotBlank() || status.isNotBlank()
}

@HiltViewModel
class DiscoverViewModel @Inject constructor(
    private val repository: CatalogRepository,
) : ViewModel() {

    private val _filters = MutableStateFlow(DiscoverFilters())
    val filters: StateFlow<DiscoverFilters> = _filters.asStateFlow()

    private val _results = MutableStateFlow<UiState<List<Work>>>(UiState.Empty)
    val results: StateFlow<UiState<List<Work>>> = _results.asStateFlow()

    private val _genres = MutableStateFlow<List<Genre>>(emptyList())
    val genres: StateFlow<List<Genre>> = _genres.asStateFlow()

    private var searchJob: Job? = null
    private var page = 1
    private var endReached = false

    init {
        viewModelScope.launch {
            (repository.genres() as? AppResult.Success)?.let { _genres.value = it.data }
        }
    }

    fun setQuery(query: String) {
        _filters.update { it.copy(query = query) }
        // Debounced rather than fired per keystroke: typing "attack on titan"
        // would otherwise be fifteen requests, and the first fourteen are
        // wasted before the last one lands.
        scheduleSearch(DEBOUNCE_MS)
    }

    fun setGenre(genre: String) {
        _filters.update { it.copy(genre = if (it.genre == genre) "" else genre) }
        scheduleSearch(0)
    }

    fun setYear(year: Int) {
        _filters.update { it.copy(year = if (it.year == year) 0 else year) }
        scheduleSearch(0)
    }

    fun setSeason(season: String) {
        _filters.update { it.copy(season = if (it.season == season) "" else season) }
        scheduleSearch(0)
    }

    fun setStatus(status: String) {
        _filters.update { it.copy(status = if (it.status == status) "" else status) }
        scheduleSearch(0)
    }

    fun setSort(sort: String) {
        _filters.update { it.copy(sort = sort) }
        scheduleSearch(0)
    }

    fun clearFilters() {
        _filters.value = DiscoverFilters()
        _results.value = UiState.Empty
    }

    /** Fetch the next page, if there is one. */
    fun loadMore() {
        if (endReached) return

        val existing = (_results.value as? UiState.Success)?.data ?: return

        viewModelScope.launch {
            page++
            when (val result = search(page)) {
                is AppResult.Success -> {
                    if (result.data.isEmpty()) {
                        endReached = true
                    } else {
                        _results.value = UiState.Success(existing + result.data)
                    }
                }
                // A failed page keeps what is already listed; the user can
                // scroll again to retry.
                is AppResult.Failure -> page--
            }
        }
    }

    private fun scheduleSearch(delayMs: Long) {
        searchJob?.cancel()

        val current = _filters.value
        if (!current.isActive) {
            _results.value = UiState.Empty
            return
        }

        searchJob = viewModelScope.launch {
            if (delayMs > 0) delay(delayMs)

            _results.value = UiState.Loading
            page = 1
            endReached = false

            when (val result = search(1)) {
                is AppResult.Success ->
                    _results.value = if (result.data.isEmpty()) UiState.Empty
                    else UiState.Success(result.data)

                is AppResult.Failure -> _results.value = UiState.Error(result.error)
            }
        }
    }

    private suspend fun search(page: Int): AppResult<List<Work>> {
        val current = _filters.value

        return repository.works(
            search = current.query,
            genre = current.genre,
            year = current.year,
            season = current.season,
            status = current.status,
            sort = current.sort,
            page = page,
        )
    }

    private companion object {
        const val DEBOUNCE_MS = 350L
    }
}
