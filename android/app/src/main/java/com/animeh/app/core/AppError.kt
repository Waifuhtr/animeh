package com.animeh.app.core

import androidx.annotation.StringRes
import com.animeh.app.R

/**
 * Every failure the app can show, as one closed set.
 *
 * §25 asks for a machine-readable error vocabulary and for the UI never to
 * print a stack trace. Both fall out of modelling errors as a sealed type: the
 * screen renders [messageRes] and can never accidentally render an exception,
 * and code that wants to react to a specific failure — a 401 triggering a
 * refresh, say — matches on the type rather than on a string.
 *
 * [technical] carries the detail for the log and the admin's debug screen. It
 * is deliberately not what the user sees.
 */
sealed class AppError(
    @StringRes val messageRes: Int,
    val code: String,
    val technical: String? = null,
) {
    /** No usable connection at all. */
    class Network(technical: String? = null) :
        AppError(R.string.error_network, "NETWORK_ERROR", technical)

    /** A connection that exists but never answered. */
    class Timeout(technical: String? = null) :
        AppError(R.string.error_timeout, "NETWORK_ERROR", technical)

    /** 401: the session is gone and signing in again is the fix. */
    class Unauthorized(technical: String? = null) :
        AppError(R.string.error_auth, "AUTH_ERROR", technical)

    /** 403: signed in, but not allowed. Signing in again will not help. */
    class Forbidden(technical: String? = null) :
        AppError(R.string.error_forbidden, "AUTH_ERROR", technical)

    /** 404. */
    class NotFound(technical: String? = null) :
        AppError(R.string.error_not_found, "NOT_FOUND", technical)

    /** 5xx, or a response the client could not parse. */
    class Server(technical: String? = null) :
        AppError(R.string.error_server, "WORDPRESS_ERROR", technical)

    /** 429, with the server's own Retry-After so the UI can say how long. */
    class RateLimited(val retryAfterSeconds: Int, technical: String? = null) :
        AppError(R.string.error_rate_limited, "RATE_LIMITED", technical)

    /** Playback could not start or could not continue. */
    class Video(technical: String? = null) :
        AppError(R.string.error_video, "VIDEO_ERROR", technical)

    /** A subtitle track failed to load or parse. */
    class Subtitle(technical: String? = null) :
        AppError(R.string.error_subtitle, "SUBTITLE_ERROR", technical)

    /** Storage refused every address offered for an asset. */
    class Storage(technical: String? = null) :
        AppError(R.string.error_storage, "STORAGE_ERROR", technical)

    /** The metadata source is unreachable — admin-facing only. */
    class Tenrai(technical: String? = null) :
        AppError(R.string.error_tenrai, "TENRAI_ERROR", technical)

    /**
     * A message the server wrote and the user should see verbatim.
     *
     * Validation failures are the case: "this email is already registered" is
     * more useful than any generic string this file could hold.
     */
    class Message(val text: String, code: String = "VALIDATION_ERROR") :
        AppError(R.string.error_unknown, code, text)

    /** Anything unclassified. */
    class Unknown(technical: String? = null) :
        AppError(R.string.error_unknown, "UNKNOWN_ERROR", technical)

    /** Whether retrying the same call could plausibly succeed. */
    val isRetryable: Boolean
        get() = this is Network || this is Timeout || this is Server || this is RateLimited

    /** Whether this should send the user to the sign-in screen. */
    val requiresLogin: Boolean
        get() = this is Unauthorized
}
