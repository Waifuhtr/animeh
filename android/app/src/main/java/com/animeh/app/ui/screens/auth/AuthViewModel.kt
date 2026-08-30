package com.animeh.app.ui.screens.auth

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppError
import com.animeh.app.core.AppResult
import com.animeh.app.data.repository.AuthRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
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
) : ViewModel() {

    private val _state = MutableStateFlow(AuthUiState())
    val state: StateFlow<AuthUiState> = _state.asStateFlow()

    fun login(login: String, password: String) {
        if (login.isBlank() || password.isBlank()) {
            _state.update { it.copy(error = AppError.Message("Kullanıcı adı ve şifre gerekli.")) }
            return
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
