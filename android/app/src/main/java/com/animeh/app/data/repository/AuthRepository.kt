package com.animeh.app.data.repository

import android.os.Build
import com.animeh.app.core.AppError
import com.animeh.app.core.AppResult
import com.animeh.app.data.local.AnimehDatabase
import com.animeh.app.data.prefs.AuthState
import com.animeh.app.data.prefs.SessionStore
import com.animeh.app.data.prefs.SettingsStore
import com.animeh.app.data.remote.ApiErrorMapper
import com.animeh.app.data.remote.PublicApi
import com.animeh.app.data.remote.UserApi
import com.animeh.app.data.remote.dto.*
import kotlinx.coroutines.flow.StateFlow
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Signing in, signing out, and who is signed in.
 *
 * Sign-out clears the local database as well as the token. Leaving one user's
 * watch history in the cache for the next person to sign in on the same phone
 * is a privacy bug that only shows up when a device is shared.
 */
@Singleton
class AuthRepository @Inject constructor(
    private val publicApi: PublicApi,
    private val userApi: UserApi,
    private val sessionStore: SessionStore,
    private val settingsStore: SettingsStore,
    private val database: AnimehDatabase,
) {

    val authState: StateFlow<AuthState> = sessionStore.state

    val currentUser: UserDto? get() = sessionStore.state.value.user

    val isSignedIn: Boolean get() = sessionStore.state.value is AuthState.SignedIn

    /** Whether the app should draw admin UI. The server decides what it does. */
    val isAdmin: Boolean get() = sessionStore.state.value.isAdmin

    suspend fun login(login: String, password: String): AppResult<UserDto> =
        ApiErrorMapper.call({ it }) {
            publicApi.login(LoginRequest(login.trim(), password, deviceLabel()))
        }.also { result ->
            if (result is AppResult.Success) sessionStore.save(result.data)
        }.let { result ->
            when (result) {
                is AppResult.Success -> AppResult.Success(result.data.user)
                is AppResult.Failure -> result
            }
        }

    suspend fun register(username: String, email: String, password: String): AppResult<UserDto> {
        // Checked before the request so the user is told immediately rather
        // than after a round trip; the server checks it again regardless.
        if (password.length < MIN_PASSWORD_LENGTH) {
            return AppResult.Failure(AppError.Message("Şifre en az 8 karakter olmalı."))
        }

        val result = ApiErrorMapper.call({ it }) {
            publicApi.register(RegisterRequest(username.trim(), email.trim(), password, deviceLabel()))
        }

        if (result is AppResult.Success) sessionStore.save(result.data)

        return when (result) {
            is AppResult.Success -> AppResult.Success(result.data.user)
            is AppResult.Failure -> result
        }
    }

    suspend fun forgotPassword(login: String): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { publicApi.forgotPassword(ForgotPasswordRequest(login.trim())) }

    suspend fun changePassword(current: String, new: String): AppResult<Unit> {
        if (new.length < MIN_PASSWORD_LENGTH) {
            return AppResult.Failure(AppError.Message("Yeni şifre en az 8 karakter olmalı."))
        }

        val result = ApiErrorMapper.call({ it }) {
            userApi.changePassword(ChangePasswordRequest(current, new))
        }

        // The server rotates every session on a password change and hands back
        // a fresh pair for this device; saving it is what keeps the user from
        // being signed out by their own action.
        if (result is AppResult.Success) sessionStore.save(result.data)

        return when (result) {
            is AppResult.Success -> AppResult.Success(Unit)
            is AppResult.Failure -> result
        }
    }

    /**
     * Sign out.
     *
     * The local state is cleared whatever the server says. If the network call
     * fails the token stays valid server-side until it expires, but leaving the
     * user apparently signed in on a device they just signed out of is worse.
     */
    suspend fun logout(): AppResult<Unit> {
        val result = ApiErrorMapper.call({ Unit }) { userApi.logout() }

        sessionStore.clear()
        clearLocalData()

        return AppResult.Success(Unit).takeIf { result is AppResult.Success } ?: AppResult.Success(Unit)
    }

    suspend fun refreshProfile(): AppResult<ProfileDto> =
        ApiErrorMapper.call({ it }) { userApi.profile() }
            .also { result ->
                if (result is AppResult.Success) {
                    sessionStore.updateUser(result.data.user)
                    // The server's copy wins on a fresh sign-in, so preferences
                    // follow the account to a new device.
                    settingsStore.applyRemote(result.data.settings)
                }
            }

    suspend fun updateProfile(displayName: String?, email: String?): AppResult<UserDto> =
        ApiErrorMapper.call({ it.user }) {
            userApi.updateProfile(UpdateProfileRequest(displayName, email))
        }.also { result ->
            if (result is AppResult.Success) sessionStore.updateUser(result.data)
        }

    private suspend fun clearLocalData() {
        // Watch history and library belong to the account that was signed in;
        // the next person on this device must not inherit them.
        database.progressDao().clear()
        database.libraryDao().clear()
    }

    /** A human-readable device name for the sessions list. */
    private fun deviceLabel(): String =
        listOf(Build.MANUFACTURER, Build.MODEL)
            .filter { it.isNotBlank() }
            .joinToString(" ")
            .take(80)
            .ifBlank { "Android" }

    private companion object {
        const val MIN_PASSWORD_LENGTH = 8
    }
}
