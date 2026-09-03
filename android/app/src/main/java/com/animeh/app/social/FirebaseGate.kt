package com.animeh.app.social

import android.content.Context
import com.animeh.app.core.ClientLog
import com.animeh.app.data.remote.dto.FirebaseConfigDto
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import com.google.firebase.database.FirebaseDatabase
import com.google.firebase.messaging.FirebaseMessaging
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Firebase, started from what the server says rather than from a bundled file.
 *
 * The usual setup compiles `google-services.json` into the APK, which would
 * mean this project could not be built by anyone who does not have the site
 * owner's Firebase project — and that pointing the app at a different project
 * would need a new release. So the config comes down `/config` beside the
 * server address, and Firebase is initialised here the first time something
 * needs it.
 *
 * None of those values is a secret. They are exactly what is inside every
 * google-services.json that ships in an app store, and Firebase's own
 * documentation says the config identifies the project rather than protecting
 * it: the security rules do that.
 *
 * An install whose server has no Firebase configured simply never becomes
 * [ready], and the app hides watch parties rather than showing screens that
 * cannot work.
 */
@Singleton
class FirebaseGate @Inject constructor(
    @ApplicationContext private val context: Context,
) {

    private val _ready = MutableStateFlow(false)

    /** Whether watch parties can be offered at all. */
    val ready: StateFlow<Boolean> = _ready.asStateFlow()

    private var app: FirebaseApp? = null
    private var configured: FirebaseConfigDto? = null

    /**
     * Start Firebase, or re-start it when the server names a different project.
     *
     * Safe to call repeatedly: the common case is the same config as last time
     * and returns immediately.
     */
    @Synchronized
    fun configure(config: FirebaseConfigDto?) {
        if (config == null || !config.isUsable) {
            return
        }

        if (config == configured && app != null) {
            return
        }

        val options = FirebaseOptions.Builder()
            .setApplicationId(config.appId)
            .setApiKey(config.apiKey)
            .setProjectId(config.projectId)
            .setDatabaseUrl(config.databaseUrl)
            .setGcmSenderId(config.senderId)
            .build()

        // The default app, not a named one. Messaging is only wired up for the
        // default instance — `FirebaseMessaging.getInstance(app)` is not even
        // public — and `FirebaseMessagingService` delivers for that instance
        // alone, so a named app would give a working database and a token that
        // never arrives. Nothing else creates a default here: without a
        // google-services.json there is no auto-initialisation to collide with.
        app = try {
            // Re-initialising throws, so an existing one is torn down first.
            // Only happens when the server names a different project.
            runCatching { FirebaseApp.getInstance().delete() }
            FirebaseApp.initializeApp(context, options)
        } catch (error: IllegalStateException) {
            ClientLog.record("Firebase başlatılamadı", error.message ?: "(mesaj yok)")
            null
        }

        configured = config
        _ready.value = app != null
    }

    /**
     * The realtime database, or null when Firebase is not configured.
     *
     * Offline persistence is deliberately off. Everything here is a live
     * position in a video and a chat that stops existing when the room does;
     * replaying a stale playhead from disk on the next launch would be worse
     * than having nothing.
     */
    fun database(): FirebaseDatabase? = app?.let(FirebaseDatabase::getInstance)

    /** Messaging, for the push token. Null until Firebase is configured. */
    fun messaging(): FirebaseMessaging? =
        if (app == null) null else FirebaseMessaging.getInstance()

}
