package com.animeh.app.data.remote

import com.animeh.app.data.prefs.SessionStore
import com.animeh.app.data.remote.dto.RefreshRequest
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import okhttp3.Authenticator
import okhttp3.Request
import okhttp3.Response
import okhttp3.Route
import javax.inject.Inject
import javax.inject.Provider
import javax.inject.Singleton

/**
 * Refreshes an expired session once, and retries the request.
 *
 * OkHttp calls an `Authenticator` after a 401, which makes it the right place
 * for this: the alternative — checking the clock before every call — races with
 * a server whose clock differs and refreshes tokens that were still valid.
 *
 * Three things this has to get right, all of which are the usual bugs:
 *
 * 1. **One refresh, not N.** Five requests failing at once must produce one
 *    refresh call. The mutex plus the re-check inside it is what serialises
 *    them; the later four find the new token already in place.
 * 2. **No infinite loop.** If the retried request 401s again, [responseCount]
 *    stops it rather than refreshing forever.
 * 3. **Refresh must not go through the Authenticator.** It uses a separately
 *    built [PublicApi] with no authenticator attached, or a failing refresh
 *    would try to refresh itself.
 */
@Singleton
class TokenAuthenticator @Inject constructor(
    private val sessionStore: SessionStore,
    private val refreshApi: Provider<PublicApi>,
) : Authenticator {

    private val mutex = Mutex()

    override fun authenticate(route: Route?, response: Response): Request? {
        if (responseCount(response) >= MAX_RETRIES) return null

        val presented = response.request.header("Authorization")
            ?.removePrefix("Bearer ")
            ?.trim()

        return runBlocking {
            mutex.withLock {
                val current = sessionStore.accessToken()

                // Another thread already refreshed while this one waited: use
                // the new token rather than spending the refresh token again.
                if (current != null && current != presented) {
                    return@withLock response.request.newBuilder()
                        .header("Authorization", "Bearer $current")
                        .build()
                }

                val refreshToken = sessionStore.refreshToken() ?: run {
                    sessionStore.clear()
                    return@withLock null
                }

                val refreshed = try {
                    refreshApi.get().refresh(RefreshRequest(refreshToken))
                } catch (error: Exception) {
                    // A network failure is not proof the session is dead;
                    // clearing it here would sign the user out every time they
                    // lost signal.
                    return@withLock null
                }

                val body = refreshed.body()
                if (!refreshed.isSuccessful || body == null || body.accessToken.isBlank()) {
                    // The server refused the refresh token: the session really
                    // is over.
                    sessionStore.clear()
                    return@withLock null
                }

                sessionStore.updateTokens(body.accessToken, body.refreshToken, body.expiresIn)

                response.request.newBuilder()
                    .header("Authorization", "Bearer ${body.accessToken}")
                    .build()
            }
        }
    }

    private fun responseCount(response: Response): Int {
        var count = 1
        var prior = response.priorResponse
        while (prior != null) {
            count++
            prior = prior.priorResponse
        }
        return count
    }

    private companion object {
        const val MAX_RETRIES = 2
    }
}
