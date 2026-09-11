package com.animeh.app.ui.screens.discover

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppResult
import com.animeh.app.core.UiState
import com.animeh.app.data.repository.CatalogRepository
import com.animeh.app.data.repository.WorkPage
import com.animeh.app.domain.Genre
import com.animeh.app.domain.KIND_ANIME
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
    /**
     * Which half of the catalogue is being browsed.
     *
     * Not one of [isActive]'s tests: switching to manga is not a filter over
     * the anime list, it is a different list — and the "clear filters" button
     * should not quietly send somebody back to the other one.
     */
    val kind: String = KIND_ANIME,
    val genre: String = "",
    /** TV, Film, OVA, ONA, Special — or a manga's own kind. */
    val format: String = "",
    val year: Int = 0,
    val season: String = "",
    val status: String = "",
    val sort: String = "recent",
) {
    /** Whether anything has been narrowed down, which decides what an empty result means. */
    val isActive: Boolean
        get() = query.isNotBlank() || genre.isNotBlank() || format.isNotBlank() ||
            year > 0 || season.isNotBlank() || status.isNotBlank()

    /** How many of the five filter rows are set, for the badge on the button. */
    val activeCount: Int
        get() = listOf(
            genre.isNotBlank(),
            format.isNotBlank(),
            year > 0,
            season.isNotBlank(),
            status.isNotBlank(),
        ).count { it }
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

    /** How many works match, which is not how many are on screen. */
    private val _total = MutableStateFlow(0)
    val total: StateFlow<Int> = _total.asStateFlow()

    private var searchJob: Job? = null
    private var page = 1
    private var endReached = false

    init {
        viewModelScope.launch {
            (repository.genres() as? AppResult.Success)?.let { _genres.value = it.data }
        }

        // Opens on the catalogue rather than on an empty screen asking to be
        // typed into. Browsing is the point of this tab; searching is one of
        // the things you can do once you are here.
        scheduleSearch(0)
    }

    /** Switch between anime and manga, and start the list again. */
    fun setKind(kind: String) {
        if (_filters.value.kind == kind) return

        _filters.update { it.copy(kind = kind) }
        scheduleSearch(0)
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

    /**
     * Browse one genre, arriving from somewhere else.
     *
     * Sets rather than toggles: a tap on "Doujinshi" on a manga's page means
     * show me that, and toggling would turn the second such tap into a no-op
     * that looks like the link is broken.
     */
    fun browseGenre(genre: String, kind: String) {
        _filters.value = DiscoverFilters(kind = kind, genre = genre)
        scheduleSearch(0)
    }

    fun setFormat(format: String) {
        _filters.update { it.copy(format = if (it.format == format) "" else format) }
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
        // The kind survives: it is which list you are looking at, not a filter
        // over it, and clearing filters should not move you to a different
        // catalogue.
        _filters.value = DiscoverFilters(kind = _filters.value.kind)
        scheduleSearch(0)
    }

    /** Fetch the next page, if there is one. */
    fun loadMore() {
        if (endReached) return

        val existing = (_results.value as? UiState.Success)?.data ?: return

        viewModelScope.launch {
            page++
            when (val result = search(page)) {
                is AppResult.Success -> {
                    _total.value = result.data.total
                    if (result.data.items.isEmpty()) {
                        endReached = true
                    } else {
                        _results.value = UiState.Success(existing + result.data.items)
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

        searchJob = viewModelScope.launch {
            if (delayMs > 0) delay(delayMs)

            _results.value = UiState.Loading
            page = 1
            endReached = false

            when (val result = search(1)) {
                is AppResult.Success -> {
                    _total.value = result.data.total
                    _results.value = if (result.data.items.isEmpty()) UiState.Empty
                    else UiState.Success(result.data.items)
                }

                is AppResult.Failure -> _results.value = UiState.Error(result.error)
            }
        }
    }

    private suspend fun search(page: Int): AppResult<WorkPage> {
        val current = _filters.value

        return repository.worksPage(
            search = current.query,
            kind = current.kind,
            genre = current.genre,
            format = current.format,
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
