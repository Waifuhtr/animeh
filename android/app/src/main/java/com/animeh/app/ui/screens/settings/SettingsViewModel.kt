package com.animeh.app.ui.screens.settings

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppResult
import com.animeh.app.data.prefs.LocalSettings
import com.animeh.app.data.prefs.SettingsStore
import com.animeh.app.data.remote.UserApi
import com.animeh.app.data.remote.dto.AppSettingsDto
import com.animeh.app.data.repository.AuthRepository
import com.animeh.app.data.repository.LibraryRepository
import com.animeh.app.player.ass.FontResolver
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.*
import kotlinx.coroutines.launch
import javax.inject.Inject

@HiltViewModel
class SettingsViewModel @Inject constructor(
    private val settingsStore: SettingsStore,
    private val userApi: UserApi,
    private val authRepository: AuthRepository,
    private val libraryRepository: LibraryRepository,
    private val fontResolver: FontResolver,
) : ViewModel() {

    val settings: StateFlow<LocalSettings> = settingsStore.settings
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), LocalSettings())

    val apiBase: StateFlow<String> = settingsStore.apiBase
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), "")

    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    fun setQuality(value: String) = update { settingsStore.setQuality(value) }
    fun setSubtitleLanguage(value: String) = update { settingsStore.setSubtitleLanguage(value) }
    fun setSubtitlesEnabled(value: Boolean) = update { settingsStore.setSubtitlesEnabled(value) }
    fun setAutoplayNext(value: Boolean) = update { settingsStore.setAutoplayNext(value) }
    fun setSkipIntro(value: Boolean) = update { settingsStore.setSkipIntro(value) }
    fun setDataSaver(value: Boolean) = update { settingsStore.setDataSaver(value) }
    fun setWifiOnlyDownload(value: Boolean) = update { settingsStore.setWifiOnlyDownload(value) }
    fun setNotifications(value: Boolean) = update { settingsStore.setNotifications(value) }

    /**
     * Point the app at a different WordPress.
     *
     * This is what makes §5.4 of the migration design work from the app's side:
     * when the backend moves, the user types the new address here and nothing
     * else has to change.
     */
    fun setApiBase(value: String, savedMessage: String) {
        viewModelScope.launch {
            settingsStore.setApiBase(value)
            _message.value = savedMessage
        }
    }

    fun clearCache(clearedMessage: String) {
        viewModelScope.launch {
            fontResolver.clearCache()
            libraryRepository.clearHistory()
            _message.value = clearedMessage
        }
    }

    fun dismissMessage() {
        _message.value = null
    }

    /** Push the local preferences to the server so they follow the account. */
    private fun update(block: suspend () -> Unit) {
        viewModelScope.launch {
            block()

            if (!authRepository.isSignedIn) return@launch

            val current = settings.value

            // Best-effort: preferences that failed to sync are still correct
            // locally, and will go up with the next change.
            runCatching {
                userApi.saveSettings(
                    AppSettingsDto(
                        defaultQuality = current.defaultQuality,
                        subtitleLanguage = current.subtitleLanguage,
                        subtitlesEnabled = current.subtitlesEnabled,
                        autoplayNext = current.autoplayNext,
                        skipIntro = current.skipIntro,
                        wifiOnlyDownload = current.wifiOnlyDownload,
                        notifications = current.notifications,
                        dataSaver = current.dataSaver,
                    )
                )
            }
        }
    }
}
