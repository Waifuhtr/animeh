package com.animeh.app.social

import com.animeh.app.core.ClientLog
import com.animeh.app.data.prefs.SessionStore
import com.animeh.app.data.prefs.AuthState
import com.animeh.app.data.repository.SocialRepository
import kotlinx.coroutines.suspendCancellableCoroutine
import javax.inject.Inject
import javax.inject.Singleton
import kotlin.coroutines.resume

/**
 * Telling the server where to send this install's notifications.
 *
 * Run on launch and again after every sign-in. A token belongs to an install
 * but a notification is addressed to an account, so the pairing has to be
 * re-stated whenever either side changes — a phone that was signed in as
 * somebody else would otherwise keep receiving their invitations.
 *
 * Every failure here is silent. A missing token means notifications do not
 * arrive, which is a worse experience; it is not a reason to put an error in
 * front of somebody who was only opening the app.
 */
@Singleton
class PushRegistrar @Inject constructor(
    private val firebase: FirebaseGate,
    private val social: SocialRepository,
    private val sessionStore: SessionStore,
) {

    /** Fetch the token and hand it to the server. */
    suspend fun register() {
        if (sessionStore.state.value !is AuthState.SignedIn) return

        val token = currentToken() ?: return

        social.registerDevice(token)
    }

    /** Stop this install receiving the account's notifications. */
    suspend fun unregister() {
        val token = currentToken() ?: return

        social.forgetDevice(token)
    }

    /**
     * The FCM token, or null when Firebase is not configured on this install.
     *
     * Wrapped in a coroutine rather than awaited with `Tasks.await`, which
     * blocks the calling thread and is called from a coroutine here.
     */
    private suspend fun currentToken(): String? {
        val messaging = firebase.messaging() ?: return null

        return suspendCancellableCoroutine { continuation ->
            messaging.token
                .addOnSuccessListener { token -> continuation.resume(token) }
                .addOnFailureListener { error ->
                    ClientLog.record("Bildirim jetonu alınamadı", error.message ?: "(mesaj yok)")
                    continuation.resume(null)
                }
        }
    }
}
