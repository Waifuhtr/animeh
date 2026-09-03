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

        // A second app rather than the default one, named: the default
        // instance is what an accidental `FirebaseApp.getInstance()` elsewhere
        // would pick up, and this app has no default to pick up.
        val options = FirebaseOptions.Builder()
            .setApplicationId(config.appId)
            .setApiKey(config.apiKey)
            .setProjectId(config.projectId)
            .setDatabaseUrl(config.databaseUrl)
            .setGcmSenderId(config.senderId)
            .build()

        app = try {
            // Re-initialising under the same name throws, so an existing one
            // is deleted first — this only happens when the project changed.
            runCatching { FirebaseApp.getInstance(APP_NAME).delete() }
            FirebaseApp.initializeApp(context, options, APP_NAME)
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

    /** Messaging, for the push token. */
    fun messaging(): FirebaseMessaging? = app?.let(FirebaseMessaging::getInstance)

    private companion object {
        const val APP_NAME = "animeh"
    }
}
