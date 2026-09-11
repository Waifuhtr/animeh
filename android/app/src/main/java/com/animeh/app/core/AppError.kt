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

    /**
     * The account has been suspended or banned.
     *
     * Its own type rather than a [Forbidden] because it is the one refusal
     * that has to interrupt whatever the user was doing and explain itself:
     * every other 403 means "not this, try something else".
     */
    class Banned(
        val reason: String,
        val expiresAt: String,
        val permanent: Boolean,
        technical: String? = null,
    ) : AppError(R.string.error_banned, "ACCOUNT_BANNED", technical)

    /** Anything unclassified. */
    class Unknown(technical: String? = null) :
        AppError(R.string.error_unknown, "UNKNOWN_ERROR", technical)

    /**
     * The server's own explanation, when it wrote one.
     *
     * [messageRes] is the sentence for a viewer, and it is deliberately vague:
     * "bir şeyler ters gitti" is the right thing to tell someone watching an
     * episode. It is the wrong thing to tell whoever is running the panel,
     * where the server has usually said something precise — "Köprü anahtarı
     * kabul edilmedi", "Manga sitesine ulaşılamadı: …", "Kaynak 403 döndürdü"
     * — and that sentence is the entire content of the report.
     *
     * Only [Message] used to survive the trip: every other classified failure
     * carried the server's words in [technical] and no screen ever read them,
     * so a 401, a 404 and a 502 all arrived looking identical.
     *
     * Null when the only detail is a transport exception's English text, which
     * names a socket and a class and helps nobody; the caller's own wording is
     * better in that case.
     */
    fun reason(): String? = when (this) {
        is Message -> text
        // Exception text, not a sentence anybody wrote to be read.
        is Network, is Timeout, is Unknown -> null
        // A bare "403" is the placeholder the mapper falls back to on an empty
        // body and says nothing the caller does not already know. "HTTP 502"
        // is kept: when the body was empty, the number is the whole finding.
        else -> technical?.trim()?.takeIf { detail -> detail.isNotEmpty() && !detail.all(Char::isDigit) }
    }

    /** Whether retrying the same call could plausibly succeed. */
    val isRetryable: Boolean
        get() = this is Network || this is Timeout || this is Server || this is RateLimited

    /** Whether this should send the user to the sign-in screen. */
    val requiresLogin: Boolean
        get() = this is Unauthorized
}

/**
 * The one sentence a view model puts in a snackbar.
 *
 * [AppError.reason] first, because the server's own words are almost always
 * better than anything assembled from a status code — "Bu çerçeve için yeterli
 * puanın yok", "Bölüm bulunamadı", "Önce depolama ayarlarını yap" — and they
 * are already in Turkish.
 *
 * Lives here rather than in each view model because it had drifted into five
 * identical private copies (two in the admin panel, plus the manga panel, the
 * frame shop and the recommend sheet); a sixth would have been written the
 * next time somebody needed it.
 *
 * Not [messageRes]: a view model has no Context, and these three fallbacks are
 * deliberately shorter than the full-screen strings — a snackbar is one line.
 */
fun AppError.explain(): String = reason() ?: when (this) {
    is AppError.Network -> "İnternet bağlantısı yok."
    is AppError.Timeout -> "Sunucu yanıt vermedi."
    else -> "Bir şeyler ters gitti."
}
