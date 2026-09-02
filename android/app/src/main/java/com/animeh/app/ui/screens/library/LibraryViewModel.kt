package com.animeh.app.ui.screens.library

import androidx.annotation.StringRes
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.R
import com.animeh.app.core.AppResult
import com.animeh.app.core.UiState
import com.animeh.app.data.repository.LibraryRepository
import com.animeh.app.domain.ContinueItem
import com.animeh.app.domain.Work
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

enum class LibraryTab(@StringRes val labelRes: Int, val list: String) {
    FAVORITES(R.string.library_favorites, LibraryRepository.LIST_FAVORITE),
    WATCHLIST(R.string.library_watchlist, LibraryRepository.LIST_WATCHLIST),
    HISTORY(R.string.library_history, ""),
    DOWNLOADS(R.string.library_downloads, ""),
}

data class LibraryUiState(
    val tab: LibraryTab = LibraryTab.FAVORITES,
    val works: UiState<List<Work>> = UiState.Loading,
    val history: UiState<List<ContinueItem>> = UiState.Loading,
)

@HiltViewModel
class LibraryViewModel @Inject constructor(
    private val repository: LibraryRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(LibraryUiState())
    val state: StateFlow<LibraryUiState> = _state.asStateFlow()

    /** The collection feeding the current tab, cancelled when the tab changes. */
    private var listJob: Job? = null

    init {
        loadList(LibraryTab.FAVORITES)
    }

    fun selectTab(tab: LibraryTab) {
        _state.update { it.copy(tab = tab) }

        when (tab) {
            LibraryTab.HISTORY -> loadHistory()
            LibraryTab.DOWNLOADS -> Unit
            else -> loadList(tab)
        }
    }

    fun reload() = selectTab(_state.value.tab)

    /**
     * Render the local table and refresh it from the server at the same time.
     *
     * Collecting rather than fetching once is what keeps the list live: this
     * model survives a tab switch, so a one-shot load left a title favourited
     * on the detail screen invisible here until the app was restarted.
     */
    private fun loadList(tab: LibraryTab) {
        listJob?.cancel()
        _state.update { it.copy(works = UiState.Loading) }

        listJob = viewModelScope.launch {
            // Whether the server has confirmed the list. Until it has, an
            // empty table only means "not fetched yet", so the skeleton stays
            // up rather than flashing the empty state at someone who has
            // items. It is a flow rather than a flag so that flipping it
            // re-renders — with a plain variable the emission carrying the
            // fetched rows could race ahead of the write and strand the
            // screen on its skeleton.
            val fetched = MutableStateFlow(false)

            launch {
                when (val result = repository.list(tab.list)) {
                    is AppResult.Success -> fetched.value = true
                    is AppResult.Failure -> _state.update { current ->
                        // A stale list beats an error screen, so this only
                        // surfaces when there is nothing cached to show. Left
                        // unfetched deliberately: nothing may overwrite the
                        // error with an empty state afterwards.
                        if (current.tab == tab && current.works !is UiState.Success) {
                            current.copy(works = UiState.Error(result.error))
                        } else {
                            current
                        }
                    }
                }
            }

            combine(repository.observe(tab.list), fetched) { works, confirmed ->
                works to confirmed
            }
                .collect { (works, confirmed) ->
                    _state.update { current ->
                        if (current.tab != tab) return@update current

                        current.copy(
                            works = when {
                                works.isNotEmpty() -> UiState.Success(works)
                                confirmed -> UiState.Empty
                                current.works is UiState.Error -> current.works
                                else -> UiState.Loading
                            }
                        )
                    }
                }
        }
    }

    private fun loadHistory() {
        _state.update { it.copy(history = UiState.Loading) }

        viewModelScope.launch {
            when (val result = repository.history()) {
                is AppResult.Success -> _state.update {
                    it.copy(
                        history = if (result.data.isEmpty()) UiState.Empty
                        else UiState.Success(result.data)
                    )
                }
                is AppResult.Failure -> _state.update { it.copy(history = UiState.Error(result.error)) }
            }
        }
    }
}
