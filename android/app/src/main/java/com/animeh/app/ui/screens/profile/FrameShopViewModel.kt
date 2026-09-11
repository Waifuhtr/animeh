package com.animeh.app.ui.screens.profile

import androidx.compose.runtime.Immutable
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppResult
import com.animeh.app.core.explain
import com.animeh.app.data.remote.dto.FrameDto
import com.animeh.app.data.repository.RewardsRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

@Immutable
data class FrameShopState(
    val loading: Boolean = true,
    val balance: Int = 0,
    val equipped: Long = 0,
    val frames: List<FrameDto> = emptyList(),
    /** Which card the preview is showing; zero until the list arrives. */
    val selectedId: Long = 0,
    /** The frame a request is in flight for, so only its button spins. */
    val busyId: Long = 0,
) {
    val equippedFrame: FrameDto? get() = frames.firstOrNull { it.id == equipped }
}

@HiltViewModel
class FrameShopViewModel @Inject constructor(
    private val rewards: RewardsRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(FrameShopState())
    val state: StateFlow<FrameShopState> = _state.asStateFlow()

    /** One-shot text for the snackbar; cleared once it has been shown. */
    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            when (val result = rewards.frames()) {
                is AppResult.Success -> _state.value = _state.value.copy(
                    loading = false,
                    balance = result.data.balance,
                    equipped = result.data.equipped,
                    frames = result.data.frames,
                    // Open on what they are wearing, or the first thing on
                    // offer — never on nothing, which would draw an empty
                    // preview above a full grid.
                    selectedId = _state.value.selectedId
                        .takeIf { id -> result.data.frames.any { it.id == id } }
                        ?: result.data.equipped.takeIf { it > 0 }
                        ?: result.data.frames.firstOrNull()?.id
                        ?: 0,
                )

                is AppResult.Failure -> {
                    _state.value = _state.value.copy(loading = false)
                    _message.value = result.error.explain()
                }
            }
        }
    }

    fun select(frameId: Long) {
        _state.value = _state.value.copy(selectedId = frameId)
    }

    fun buy(frame: FrameDto) {
        if (_state.value.busyId != 0L) return

        viewModelScope.launch {
            _state.value = _state.value.copy(busyId = frame.id)

            when (val result = rewards.buy(frame.id)) {
                is AppResult.Success -> {
                    // Owned locally the moment the server says so, rather than
                    // after a reload: the card is on screen and the person is
                    // looking at it.
                    _state.value = _state.value.copy(
                        balance = result.data,
                        busyId = 0,
                        frames = _state.value.frames.map {
                            if (it.id == frame.id) it.copy(owned = true) else it
                        },
                    )
                    _message.value = "${frame.name} alındı"
                    // And straight onto their face — buying a frame nobody
                    // then wears is a purchase with no visible result.
                    equip(frame)
                }

                is AppResult.Failure -> {
                    _state.value = _state.value.copy(busyId = 0)
                    _message.value = result.error.explain()
                }
            }
        }
    }

    fun equip(frame: FrameDto) {
        viewModelScope.launch {
            when (val result = rewards.equip(frame.id)) {
                is AppResult.Success -> _state.value = _state.value.copy(
                    equipped = frame.id,
                    frames = _state.value.frames.map { it.copy(equipped = it.id == frame.id) },
                )

                is AppResult.Failure -> _message.value = result.error.explain()
            }
        }
    }

    fun removeFrame() {
        viewModelScope.launch {
            when (rewards.equip(0)) {
                is AppResult.Success -> _state.value = _state.value.copy(
                    equipped = 0,
                    frames = _state.value.frames.map { it.copy(equipped = false) },
                )

                is AppResult.Failure -> Unit
            }
        }
    }

    fun messageShown() {
        _message.value = null
    }
}
