package com.animeh.app.ui.screens.profile

import android.content.ContentResolver
import android.net.Uri
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppResult
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
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
    private val contentResolver: ContentResolver,
) : ViewModel() {

    private val _stats = MutableStateFlow<UserStatsDto?>(null)
    val stats: StateFlow<UserStatsDto?> = _stats.asStateFlow()

    private val _uploadingAvatar = MutableStateFlow(false)
    val uploadingAvatar: StateFlow<Boolean> = _uploadingAvatar.asStateFlow()

    init {
        refresh()
    }

    /**
     * Send a picked image as the profile picture.
     *
     * Read whole rather than streamed: the endpoint caps this at 3 MB, which
     * fits in memory, and a streamed body would need a length the content
     * resolver does not always know.
     */
    fun uploadAvatar(uri: Uri) {
        viewModelScope.launch {
            _uploadingAvatar.value = true

            val bytes = withContext(Dispatchers.IO) {
                runCatching { contentResolver.openInputStream(uri)?.use { it.readBytes() } }
                    .getOrNull()
            }

            if (bytes != null && bytes.isNotEmpty()) {
                repository.uploadAvatar(bytes)
            }

            _uploadingAvatar.value = false
        }
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
