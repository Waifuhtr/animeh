package com.animeh.app.data.repository

import com.animeh.app.core.AppResult
import com.animeh.app.core.ClientLog
import com.animeh.app.data.prefs.SettingsStore
import com.animeh.app.data.remote.ApiErrorMapper
import com.animeh.app.data.remote.PublicApi
import com.animeh.app.data.remote.dto.ClientConfigDto
import com.animeh.app.social.FirebaseGate
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Following the backend when it moves.
 *
 * The address is asked for rather than configured. On every launch the app
 * asks the server it currently knows about where clients should be talking to
 * it; if the answer is a different address, it switches and stays switched.
 * That is what carries every phone across a migration without anyone typing
 * anything — the address only has to be set in one place, on the site that is
 * still answering.
 *
 * Two limits worth knowing, because they decide how a migration has to be run:
 *
 * 1. The new address has to be published while the old site still answers. A
 *    server that is already gone cannot tell anyone where it went.
 * 2. Only `https` is followed. A downgrade to plain http is the one answer
 *    that could be someone else's, and this app sends a bearer token on
 *    almost every request.
 */
@Singleton
class ServerConfigRepository @Inject constructor(
    private val publicApi: PublicApi,
    private val settingsStore: SettingsStore,
    private val firebase: FirebaseGate,
) {

    /**
     * Ask where to connect, and move if the answer differs.
     *
     * Silent on failure by design: this runs at launch, and a server that is
     * briefly unreachable is not a reason to show anyone an error about a
     * setting they did not touch. The app keeps using the address it has.
     *
     * @return true when the address changed.
     */
    suspend fun refresh(): Boolean {
        // The request has to go to the address this app already knows, so the
        // stored value is read first: the interceptor reads it synchronously
        // and would otherwise still hold the compiled-in default.
        val current = settingsStore.awaitApiBase()

        val result: AppResult<ClientConfigDto> =
            ApiErrorMapper.call { publicApi.clientConfig() }

        if (result !is AppResult.Success) return false

        // Firebase first: watch parties should light up on this launch rather
        // than the one after, and the config is in the same response.
        firebase.configure(result.data.firebase)

        val announced = result.data.apiBase.trim()
        if (!isAcceptable(announced)) return false

        if (sameAddress(announced, current)) return false

        settingsStore.setApiBase(announced)

        // Worth a line in the device log: an app that silently starts talking
        // to a different host is the kind of thing someone will want to be
        // able to see happened.
        ClientLog.record("Sunucu adresi değişti", "$current → $announced")

        return true
    }

    /** Https, and an address rather than a fragment of one. */
    private fun isAcceptable(value: String): Boolean =
        value.startsWith("https://", ignoreCase = true) && value.length > "https://".length + 3

    /** Same address bar a trailing slash, which the store normalises anyway. */
    private fun sameAddress(a: String, b: String): Boolean =
        a.trimEnd('/').equals(b.trimEnd('/'), ignoreCase = true)
}
