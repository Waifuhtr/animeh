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
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
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

    private fun loadList(tab: LibraryTab) {
        _state.update { it.copy(works = UiState.Loading) }

        viewModelScope.launch {
            when (val result = repository.list(tab.list)) {
                is AppResult.Success -> _state.update {
                    it.copy(
                        works = if (result.data.isEmpty()) UiState.Empty
                        else UiState.Success(result.data)
                    )
                }
                is AppResult.Failure -> _state.update { it.copy(works = UiState.Error(result.error)) }
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
