package com.animeh.app.ui.screens.social

import androidx.compose.runtime.Immutable
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppResult
import com.animeh.app.core.explain
import com.animeh.app.data.remote.dto.UserDto
import com.animeh.app.data.repository.SocialRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

@Immutable
data class RecommendState(
    val friends: List<UserDto> = emptyList(),
    val picked: Set<Long> = emptySet(),
    val note: String = "",
    val loading: Boolean = true,
    val sending: Boolean = false,
    val sent: Boolean = false,
    val error: String? = null,
)

@HiltViewModel
class RecommendViewModel @Inject constructor(
    private val repository: SocialRepository,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    private val workId: Long = savedStateHandle["workId"] ?: 0L

    private val _state = MutableStateFlow(RecommendState())
    val state: StateFlow<RecommendState> = _state.asStateFlow()

    init {
        load()
    }

    private fun load() {
        viewModelScope.launch {
            when (val result = repository.friends()) {
                is AppResult.Success -> _state.update {
                    it.copy(friends = result.data.friends, loading = false)
                }

                is AppResult.Failure -> _state.update {
                    it.copy(loading = false, error = result.error.explain())
                }
            }
        }
    }

    fun toggle(userId: Long) {
        _state.update {
            it.copy(picked = if (userId in it.picked) it.picked - userId else it.picked + userId)
        }
    }

    fun setNote(value: String) = _state.update { it.copy(note = value) }

    fun send() {
        val current = _state.value
        if (current.picked.isEmpty() || current.sending) return

        viewModelScope.launch {
            _state.update { it.copy(sending = true, error = null) }

            when (val result = repository.recommend(workId, current.picked.toList(), current.note)) {
                is AppResult.Success -> _state.update { it.copy(sending = false, sent = true) }

                is AppResult.Failure -> _state.update {
                    it.copy(sending = false, error = result.error.explain())
                }
            }
        }
    }
}
