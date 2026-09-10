package com.animeh.app.data.remote

import okhttp3.Interceptor
import okhttp3.Response
import java.util.concurrent.TimeUnit

/**
 * More patience for the handful of calls that are slow by nature.
 *
 * Thirty seconds is the right read timeout for a catalogue request and the
 * wrong one for copying twenty-five manga pages into the bucket: each of those
 * is a download from somebody else's server and an upload to ours, and the
 * whole batch answers once. The phone gave up first — `SocketTimeoutException`
 * — and the copy loop stopped even though the server was still working.
 *
 * The server now bounds its own batches by the clock so a request normally
 * answers in seconds. This covers the rest: one slow image inside an otherwise
 * finished batch should not end the run.
 *
 * Matched on the path rather than an annotation because the timeout belongs to
 * what the endpoint does, not to how it happens to be declared.
 */
class SlowOperationInterceptor : Interceptor {

    override fun intercept(chain: Interceptor.Chain): Response {
        val path = chain.request().url.encodedPath

        if (SLOW.none { path.endsWith(it) || path.contains(it) }) {
            return chain.proceed(chain.request())
        }

        return chain
            .withReadTimeout(SLOW_SECONDS, TimeUnit.SECONDS)
            .withWriteTimeout(SLOW_SECONDS, TimeUnit.SECONDS)
            .proceed(chain.request())
    }

    private companion object {
        /**
         * Long enough for a bounded batch whose last image was the slow one:
         * the server stops starting new work at twenty seconds, and a single
         * image can still spend two fifteen-second attempts plus its upload.
         */
        const val SLOW_SECONDS = 120

        /**
         * Endpoints that fetch, copy or upload rather than read a table.
         */
        val SLOW = listOf(
            "/admin/manga/mirror",
            "/admin/manga/sync",
            "/admin/manga/import",
            "/pages",
            "/admin/tenrai/import",
            "/admin/tmdb/import",
            "/storage/images",
        )
    }
}
