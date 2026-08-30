package com.animeh.app.ui.screens.profile

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppResult
import com.animeh.app.data.remote.dto.UserStatsDto
import com.animeh.app.data.repository.AuthRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

@HiltViewModel
class ProfileViewModel @Inject constructor(
    private val repository: AuthRepository,
) : ViewModel() {

    private val _stats = MutableStateFlow<UserStatsDto?>(null)
    val stats: StateFlow<UserStatsDto?> = _stats.asStateFlow()

    init {
        refresh()
    }

    fun refresh() {
        if (!repository.isSignedIn) return

        viewModelScope.launch {
            // A failed refresh leaves the cached user on screen rather than
            // blanking a profile that was perfectly readable a moment ago.
            (repository.refreshProfile() as? AppResult.Success)?.let {
                _stats.value = it.data.stats
            }
        }
    }

    fun logout() {
        viewModelScope.launch { repository.logout() }
    }
}
