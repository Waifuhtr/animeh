package com.animeh.app.ui.screens.home

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppResult
import com.animeh.app.core.LaunchGate
import com.animeh.app.core.UiState
import com.animeh.app.data.repository.CatalogRepository
import com.animeh.app.data.repository.CommunityRepository
import com.animeh.app.domain.Announcement
import com.animeh.app.domain.HomeFeed
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

@HiltViewModel
class HomeViewModel @Inject constructor(
    private val repository: CatalogRepository,
    private val community: CommunityRepository,
    private val launchGate: LaunchGate,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<HomeFeed>>(UiState.Loading)
    val state: StateFlow<UiState<HomeFeed>> = _state.asStateFlow()

    /** Display names for genres and the rest, applied wherever one is drawn. */
    val labels = community.terms

    private val _announcements = MutableStateFlow<List<Announcement>>(emptyList())
    val announcements: StateFlow<List<Announcement>> = _announcements.asStateFlow()

    private val _refreshing = MutableStateFlow(false)
    val refreshing: StateFlow<Boolean> = _refreshing.asStateFlow()

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            when (val result = repository.home()) {
                is AppResult.Success -> {
                    val feed = result.data.value
                    _state.value = if (feed.isEmpty) {
                        UiState.Empty
                    } else {
                        UiState.Success(feed, fromCache = result.data.fromCache)
                    }
                }
                is AppResult.Failure -> _state.value = UiState.Error(result.error)
            }

            // Whatever came back, the launch screen has waited long enough:
            // the page underneath now has either content or something to say.
            launchGate.markReady()
        }

        viewModelScope.launch {
            // Best-effort: a failed announcements call must not stop the home
            // screen from drawing.
            (repository.announcements() as? AppResult.Success)?.let {
                _announcements.value = it.data
            }
        }

        viewModelScope.launch { community.refreshTerms() }
    }

    fun refresh() {
        viewModelScope.launch {
            _refreshing.value = true
            when (val result = repository.home()) {
                is AppResult.Success -> {
                    val feed = result.data.value
                    if (!feed.isEmpty) {
                        _state.value = UiState.Success(feed, fromCache = result.data.fromCache)
                    }
                }
                // A failed refresh keeps what is on screen: replacing content
                // the user is looking at with an error is worse than a silent
                // no-op.
                is AppResult.Failure -> Unit
            }
            _refreshing.value = false
        }
    }
}
