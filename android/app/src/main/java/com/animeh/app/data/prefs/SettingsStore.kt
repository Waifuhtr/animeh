package com.animeh.app.data.prefs

import android.content.Context
import androidx.datastore.preferences.core.*
import androidx.datastore.preferences.preferencesDataStore
import com.animeh.app.BuildConfig
import com.animeh.app.data.remote.dto.AppSettingsDto
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map
import javax.inject.Inject
import javax.inject.Singleton

private val Context.dataStore by preferencesDataStore(name = "animeh_settings")

/**
 * Preferences that must work signed out and offline.
 *
 * The server also stores these (so they follow the user to a new device), but
 * the player reads them on every launch and cannot wait for a network call to
 * know whether subtitles are on. So DataStore is the source of truth for
 * reading, and the server copy is a sync target.
 *
 * [apiBase] is here rather than in BuildConfig alone because §5.4 of the
 * migration design has the backend moving hosts: the user must be able to
 * point the app at a new WordPress without a new APK.
 */
@Singleton
class SettingsStore @Inject constructor(
    @ApplicationContext private val context: Context,
) {

    val settings: Flow<LocalSettings> = context.dataStore.data.map { prefs ->
        LocalSettings(
            defaultQuality = prefs[KEY_QUALITY] ?: "auto",
            subtitleLanguage = prefs[KEY_SUBTITLE_LANG] ?: "tr",
            subtitlesEnabled = prefs[KEY_SUBTITLES_ON] ?: true,
            autoplayNext = prefs[KEY_AUTOPLAY] ?: true,
            skipIntro = prefs[KEY_SKIP_INTRO] ?: true,
            dataSaver = prefs[KEY_DATA_SAVER] ?: false,
            wifiOnlyDownload = prefs[KEY_WIFI_ONLY] ?: true,
            notifications = prefs[KEY_NOTIFICATIONS] ?: true,
            playbackSpeed = prefs[KEY_SPEED] ?: 1.0f,
        )
    }

    val apiBase: Flow<String> = context.dataStore.data.map { prefs ->
        normaliseBase(prefs[KEY_API_BASE] ?: BuildConfig.DEFAULT_API_BASE)
    }

    /**
     * The address read synchronously, for the OkHttp interceptor.
     *
     * Blocking on DataStore inside an interceptor would deadlock the
     * dispatcher, so the current value is mirrored here whenever it changes.
     */
    @Volatile
    var currentApiBase: String = normaliseBase(BuildConfig.DEFAULT_API_BASE)
        private set

    suspend fun setApiBase(value: String) {
        val normalised = normaliseBase(value)
        context.dataStore.edit { it[KEY_API_BASE] = normalised }
        currentApiBase = normalised
    }

    /**
     * The stored address, read once and mirrored into [currentApiBase].
     *
     * [primeApiBase] keeps collecting for the life of the process and so never
     * returns; anything that has to know the address is right *before* it makes
     * a request needs this instead.
     */
    suspend fun awaitApiBase(): String {
        val stored = context.dataStore.data.first()[KEY_API_BASE]
        val value = normaliseBase(stored ?: BuildConfig.DEFAULT_API_BASE)
        currentApiBase = value

        return value
    }

    /** Called once at startup so [currentApiBase] is right before any request. */
    suspend fun primeApiBase() {
        val stored = context.dataStore.data.map { it[KEY_API_BASE] }
        stored.collect { value ->
            currentApiBase = normaliseBase(value ?: BuildConfig.DEFAULT_API_BASE)
        }
    }

    /** Series this viewer has already accepted the adult warning for. */
    val acknowledgedAdult: Flow<Set<Long>> = context.dataStore.data.map { prefs ->
        prefs[KEY_ADULT_OK].orEmpty().mapNotNull { it.toLongOrNull() }.toSet()
    }

    /**
     * Remember that this series was accepted.
     *
     * Stored as a string set because DataStore Preferences has no long-set
     * type; the ids are round-tripped through their decimal form.
     */
    suspend fun acknowledgeAdult(workId: Long) {
        context.dataStore.edit { prefs ->
            prefs[KEY_ADULT_OK] = prefs[KEY_ADULT_OK].orEmpty() + workId.toString()
        }
    }

    /**
     * The name the sign-in form last filled itself with, if it was asked to.
     *
     * "Beni hatırla" cannot mean "keep me signed in" here — the session is
     * already kept, on this device, until somebody signs out. What it is
     * actually asked for is not having to type the same name again, so that
     * is what it does. Blank means the box was unticked, and unticking it
     * also forgets what was stored.
     */
    val rememberedLogin: Flow<String> = context.dataStore.data.map { prefs ->
        prefs[KEY_REMEMBERED_LOGIN].orEmpty()
    }

    suspend fun rememberLogin(value: String) = edit { it[KEY_REMEMBERED_LOGIN] = value.trim() }

    suspend fun setQuality(value: String) = edit { it[KEY_QUALITY] = value }
    suspend fun setSubtitleLanguage(value: String) = edit { it[KEY_SUBTITLE_LANG] = value }
    suspend fun setSubtitlesEnabled(value: Boolean) = edit { it[KEY_SUBTITLES_ON] = value }
    suspend fun setAutoplayNext(value: Boolean) = edit { it[KEY_AUTOPLAY] = value }
    suspend fun setSkipIntro(value: Boolean) = edit { it[KEY_SKIP_INTRO] = value }
    suspend fun setDataSaver(value: Boolean) = edit { it[KEY_DATA_SAVER] = value }
    suspend fun setWifiOnlyDownload(value: Boolean) = edit { it[KEY_WIFI_ONLY] = value }
    suspend fun setNotifications(value: Boolean) = edit { it[KEY_NOTIFICATIONS] = value }
    suspend fun setPlaybackSpeed(value: Float) = edit { it[KEY_SPEED] = value }

    /** Overwrite local preferences with what the server has. */
    suspend fun applyRemote(remote: AppSettingsDto) = edit { prefs ->
        prefs[KEY_QUALITY] = remote.defaultQuality
        prefs[KEY_SUBTITLE_LANG] = remote.subtitleLanguage
        prefs[KEY_SUBTITLES_ON] = remote.subtitlesEnabled
        prefs[KEY_AUTOPLAY] = remote.autoplayNext
        prefs[KEY_SKIP_INTRO] = remote.skipIntro
        prefs[KEY_DATA_SAVER] = remote.dataSaver
        prefs[KEY_WIFI_ONLY] = remote.wifiOnlyDownload
        prefs[KEY_NOTIFICATIONS] = remote.notifications
    }

    private suspend fun edit(block: (MutablePreferences) -> Unit) {
        context.dataStore.edit(block)
    }

    private fun normaliseBase(value: String): String {
        val trimmed = value.trim()
        if (trimmed.isEmpty()) return BuildConfig.DEFAULT_API_BASE

        // Retrofit requires a trailing slash on a base URL and throws at
        // construction without one — a crash on launch rather than a bad
        // request, so it is fixed here instead of validated.
        var base = if (trimmed.endsWith("/")) trimmed else "$trimmed/"

        // Accept what someone would actually paste: the site root, or the REST
        // root, and complete it to this plugin's namespace.
        if (!base.contains("/wp-json/")) {
            base += "wp-json/animeh/v1/"
        } else if (base.endsWith("/wp-json/")) {
            base += "animeh/v1/"
        }

        return base
    }

    private companion object {
        val KEY_API_BASE = stringPreferencesKey("api_base")
        val KEY_QUALITY = stringPreferencesKey("default_quality")
        val KEY_SUBTITLE_LANG = stringPreferencesKey("subtitle_language")
        val KEY_SUBTITLES_ON = booleanPreferencesKey("subtitles_enabled")
        val KEY_AUTOPLAY = booleanPreferencesKey("autoplay_next")
        val KEY_SKIP_INTRO = booleanPreferencesKey("skip_intro")
        val KEY_DATA_SAVER = booleanPreferencesKey("data_saver")
        val KEY_WIFI_ONLY = booleanPreferencesKey("wifi_only_download")
        val KEY_NOTIFICATIONS = booleanPreferencesKey("notifications")

        /**
         * Which flagged series this viewer has already said yes to.
         *
         * Persisted rather than per-session: the ask is meant to be once per
         * anime, and a warning that comes back every time the app restarts is
         * a warning people learn to tap through without reading.
         */
        val KEY_ADULT_OK = stringSetPreferencesKey("adult_acknowledged")
        val KEY_SPEED = floatPreferencesKey("playback_speed")

        /** What the sign-in field pre-fills with; blank when not remembered. */
        val KEY_REMEMBERED_LOGIN = stringPreferencesKey("remembered_login")
    }
}

data class LocalSettings(
    val defaultQuality: String = "auto",
    val subtitleLanguage: String = "tr",
    val subtitlesEnabled: Boolean = true,
    val autoplayNext: Boolean = true,
    val skipIntro: Boolean = true,
    val dataSaver: Boolean = false,
    val wifiOnlyDownload: Boolean = true,
    val notifications: Boolean = true,
    val playbackSpeed: Float = 1.0f,
)
