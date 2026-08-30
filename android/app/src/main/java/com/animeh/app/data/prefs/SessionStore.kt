package com.animeh.app.data.prefs

import android.content.Context
import android.content.SharedPreferences
import android.util.Log
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.animeh.app.data.remote.dto.SessionDto
import com.animeh.app.data.remote.dto.UserDto
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Where the session lives on the device.
 *
 * The refresh token is a long-lived credential: anyone holding it can mint
 * access tokens until it expires. §9 asks for Android's secure storage, so this
 * uses `EncryptedSharedPreferences` with a key held in the Android Keystore —
 * which means the file is useless if copied off the device, and on hardware
 * with a StrongBox the key never leaves it.
 *
 * The fallback path matters as much as the happy one. `EncryptedSharedPreferences`
 * does fail: a device whose keystore was reset by a factory-reset-protection
 * bug, or an OEM with a broken provider. Falling back to plain preferences
 * would silently downgrade every user's token storage, so instead the fallback
 * is *no persistence at all* — the session lives in memory for this launch and
 * the user signs in again next time. Worse UX, but it never quietly stores a
 * credential in the clear.
 */
@Singleton
class SessionStore @Inject constructor(
    private val context: Context,
) {

    private val memoryOnly = mutableMapOf<String, String>()

    private val prefs: SharedPreferences? by lazy {
        try {
            val masterKey = MasterKey.Builder(context)
                .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                .build()

            EncryptedSharedPreferences.create(
                context,
                FILE_NAME,
                masterKey,
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
            )
        } catch (error: Exception) {
            // Never fall through to unencrypted storage; see the class comment.
            Log.e(TAG, "Encrypted storage unavailable; session will not persist", error)
            null
        }
    }

    private val _state = MutableStateFlow(load())

    /** The current session, as the whole app sees it. */
    val state: StateFlow<AuthState> = _state.asStateFlow()

    /** Whether the token store survives a restart on this device. */
    val isPersistent: Boolean get() = prefs != null

    fun save(session: SessionDto) {
        write(KEY_ACCESS, session.accessToken)
        write(KEY_REFRESH, session.refreshToken)
        write(KEY_USER_ID, session.user.id.toString())
        write(KEY_USERNAME, session.user.username)
        write(KEY_DISPLAY_NAME, session.user.displayName)
        write(KEY_EMAIL, session.user.email)
        write(KEY_AVATAR, session.user.avatar)
        write(KEY_IS_ADMIN, session.user.isAdmin.toString())
        // Stored as an absolute instant, so a refresh decision does not depend
        // on knowing when the app last launched.
        write(KEY_EXPIRES_AT, (System.currentTimeMillis() + session.expiresIn * 1000).toString())

        _state.value = load()
    }

    /** Update the cached user without touching the tokens. */
    fun updateUser(user: UserDto) {
        write(KEY_USER_ID, user.id.toString())
        write(KEY_USERNAME, user.username)
        write(KEY_DISPLAY_NAME, user.displayName)
        write(KEY_EMAIL, user.email)
        write(KEY_AVATAR, user.avatar)
        write(KEY_IS_ADMIN, user.isAdmin.toString())

        _state.value = load()
    }

    /** Replace only the tokens, after a refresh. */
    fun updateTokens(access: String, refresh: String, expiresIn: Long) {
        write(KEY_ACCESS, access)
        write(KEY_REFRESH, refresh)
        write(KEY_EXPIRES_AT, (System.currentTimeMillis() + expiresIn * 1000).toString())

        _state.value = load()
    }

    fun clear() {
        prefs?.edit()?.clear()?.apply()
        memoryOnly.clear()
        _state.value = AuthState.SignedOut
    }

    fun accessToken(): String? = read(KEY_ACCESS).takeIf { it.isNotBlank() }

    fun refreshToken(): String? = read(KEY_REFRESH).takeIf { it.isNotBlank() }

    /**
     * Whether the access token is close enough to expiry to refresh now.
     *
     * The skew is what keeps a request from being sent with a token that
     * expires while it is in flight.
     */
    fun needsRefresh(): Boolean {
        val expiresAt = read(KEY_EXPIRES_AT).toLongOrNull() ?: return false
        return System.currentTimeMillis() >= expiresAt - EXPIRY_SKEW_MS
    }

    private fun load(): AuthState {
        val access = read(KEY_ACCESS)
        val userId = read(KEY_USER_ID).toLongOrNull() ?: 0L

        if (access.isBlank() || userId == 0L) return AuthState.SignedOut

        return AuthState.SignedIn(
            UserDto(
                id = userId,
                username = read(KEY_USERNAME),
                displayName = read(KEY_DISPLAY_NAME),
                email = read(KEY_EMAIL),
                avatar = read(KEY_AVATAR),
                isAdmin = read(KEY_IS_ADMIN).toBoolean(),
            )
        )
    }

    private fun read(key: String): String =
        prefs?.getString(key, "").orEmpty().ifEmpty { memoryOnly[key].orEmpty() }

    private fun write(key: String, value: String) {
        val store = prefs
        if (store != null) {
            store.edit().putString(key, value).apply()
        } else {
            memoryOnly[key] = value
        }
    }

    private companion object {
        const val TAG = "SessionStore"
        const val FILE_NAME = "animeh_session"
        const val KEY_ACCESS = "access_token"
        const val KEY_REFRESH = "refresh_token"
        const val KEY_EXPIRES_AT = "expires_at"
        const val KEY_USER_ID = "user_id"
        const val KEY_USERNAME = "username"
        const val KEY_DISPLAY_NAME = "display_name"
        const val KEY_EMAIL = "email"
        const val KEY_AVATAR = "avatar"
        const val KEY_IS_ADMIN = "is_admin"
        const val EXPIRY_SKEW_MS = 60_000L
    }
}

/** Signed in, or not. Nothing in between. */
sealed interface AuthState {
    data object SignedOut : AuthState
    data class SignedIn(val user: UserDto) : AuthState
}

/**
 * The signed-in user, or null.
 *
 * An extension rather than an interface member: [SignedIn] already declares a
 * constructor property of the same name, and a member of that name on the
 * interface would need `override` to coexist with it — for a type that only
 * narrows on `is SignedIn`, the extension is simpler and reads the same at
 * every call site, which only ever holds a plain `AuthState`.
 */
val AuthState.user: UserDto? get() = (this as? AuthState.SignedIn)?.user
val AuthState.isAdmin: Boolean get() = user?.isAdmin == true
