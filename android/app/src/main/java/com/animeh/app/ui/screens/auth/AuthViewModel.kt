package com.animeh.app.ui.screens.auth

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppError
import com.animeh.app.core.AppResult
import com.animeh.app.data.prefs.SettingsStore
import com.animeh.app.data.repository.AuthRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

data class AuthUiState(
    val submitting: Boolean = false,
    val error: AppError? = null,
    val message: String? = null,
    val done: Boolean = false,
)

@HiltViewModel
class AuthViewModel @Inject constructor(
    private val repository: AuthRepository,
    private val settingsStore: SettingsStore,
) : ViewModel() {

    private val _state = MutableStateFlow(AuthUiState())
    val state: StateFlow<AuthUiState> = _state.asStateFlow()

    /**
     * The name the sign-in field starts with, if there is one.
     *
     * Blank when "Beni hatırla" was last left unticked, which is also what
     * unticking it writes — so forgetting is as immediate as remembering.
     */
    val rememberedLogin: StateFlow<String> = settingsStore.rememberedLogin
        .stateIn(viewModelScope, SharingStarted.Eagerly, "")

    fun login(login: String, password: String, remember: Boolean = false) {
        if (login.isBlank() || password.isBlank()) {
            _state.update { it.copy(error = AppError.Message("Kullanıcı adı ve şifre gerekli.")) }
            return
        }

        // Written before the attempt rather than after it. Somebody who
        // mistyped their password still typed their name correctly, and
        // having to type it again is exactly what the box was ticked to
        // avoid. Only the name is stored — never the password.
        viewModelScope.launch {
            settingsStore.rememberLogin(if (remember) login else "")
        }

        submit { repository.login(login, password) }
    }

    fun register(username: String, email: String, password: String) {
        when {
            username.isBlank() || email.isBlank() || password.isBlank() ->
                _state.update { it.copy(error = AppError.Message("Tüm alanları doldur.")) }

            !EMAIL.matches(email) ->
                _state.update { it.copy(error = AppError.Message("Geçerli bir e-posta gir.")) }

            else -> submit { repository.register(username, email, password) }
        }
    }

    fun forgotPassword(login: String, sentMessage: String) {
        if (login.isBlank()) {
            _state.update { it.copy(error = AppError.Message("Kullanıcı adı veya e-posta gir.")) }
            return
        }

        viewModelScope.launch {
            _state.update { it.copy(submitting = true, error = null, message = null) }

            repository.forgotPassword(login)

            // The same answer whether or not the account exists, matching the
            // server: a different message here would leak which addresses are
            // registered.
            _state.update { it.copy(submitting = false, message = sentMessage) }
        }
    }

    fun changePassword(current: String, new: String) {
        submit { repository.changePassword(current, new) }
    }

    fun clearError() = _state.update { it.copy(error = null) }

    private fun submit(block: suspend () -> AppResult<*>) {
        viewModelScope.launch {
            _state.update { it.copy(submitting = true, error = null, message = null) }

            when (val result = block()) {
                is AppResult.Success -> _state.update { it.copy(submitting = false, done = true) }
                is AppResult.Failure -> _state.update { it.copy(submitting = false, error = result.error) }
            }
        }
    }

    private companion object {
        val EMAIL = Regex("""^[^\s@]+@[^\s@]+\.[^\s@]+$""")
    }
}
