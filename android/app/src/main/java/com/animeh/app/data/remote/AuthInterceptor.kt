package com.animeh.app.data.remote

import com.animeh.app.data.prefs.SessionStore
import com.animeh.app.data.prefs.SettingsStore
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.Interceptor
import okhttp3.Response
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Attaches the bearer token, and rewrites the host when the backend moves.
 *
 * The rewrite is here rather than in the Retrofit base URL because Retrofit
 * fixes its base at construction: without this, changing the server address
 * would need the whole object graph rebuilt. Instead Retrofit is built against
 * a placeholder and every request has its scheme, host, port and path prefix
 * swapped for whatever the user currently has configured.
 */
@Singleton
class AuthInterceptor @Inject constructor(
    private val sessionStore: SessionStore,
    private val settingsStore: SettingsStore,
) : Interceptor {

    override fun intercept(chain: Interceptor.Chain): Response {
        val original = chain.request()
        val builder = original.newBuilder()

        retarget(original.url.toString())?.let { builder.url(it) }

        // Not on the auth endpoints: /auth/login and /auth/refresh are how a
        // client gets a token, and sending a stale one there only invites the
        // server to reject the request before reading the body.
        val path = original.url.encodedPath
        val needsToken = !path.endsWith("/auth/login") &&
            !path.endsWith("/auth/register") &&
            !path.endsWith("/auth/refresh") &&
            !path.endsWith("/auth/password/forgot")

        if (needsToken) {
            sessionStore.accessToken()?.let { token ->
                builder.header("Authorization", "Bearer $token")
            }
        }

        builder.header("Accept", "application/json")

        return chain.proceed(builder.build())
    }

    /**
     * Move a request from the placeholder base onto the configured one.
     *
     * @return the rewritten URL, or null when nothing needs changing.
     */
    private fun retarget(requestUrl: String): okhttp3.HttpUrl? {
        val base = settingsStore.currentApiBase.toHttpUrlOrNull() ?: return null
        val current = requestUrl.toHttpUrlOrNull() ?: return null

        if (current.host == base.host && current.encodedPath.startsWith(base.encodedPath)) {
            return null
        }

        // The part of the path after the placeholder base is the endpoint; it
        // is re-hung under the configured base's path.
        val suffix = current.encodedPath.removePrefix(PLACEHOLDER_PATH).trimStart('/')

        return base.newBuilder()
            .encodedPath(base.encodedPath.trimEnd('/') + "/" + suffix)
            .query(current.query)
            .build()
    }

    companion object {
        /**
         * What Retrofit is constructed against. Never contacted: every request
         * is rewritten onto the configured address before it leaves.
         */
        const val PLACEHOLDER_BASE = "https://placeholder.animeh.invalid/api/"
        const val PLACEHOLDER_PATH = "/api"
    }
}
