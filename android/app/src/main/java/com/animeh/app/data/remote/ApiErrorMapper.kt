package com.animeh.app.data.remote

import com.animeh.app.core.AccountGate
import com.animeh.app.core.AppError
import com.animeh.app.core.AppResult
import com.animeh.app.core.ClientLog
import com.animeh.app.data.remote.dto.ApiErrorDto
import kotlinx.serialization.json.Json
import retrofit2.Response
import java.io.IOException
import java.net.SocketTimeoutException
import java.net.UnknownHostException
import javax.net.ssl.SSLException

/**
 * One place where an HTTP outcome becomes an [AppResult].
 *
 * Every repository call goes through [call], so no screen ever sees a
 * `Response`, an exception, or a raw status code. Two things this buys:
 * the server's own validation message reaches the user when it is the useful
 * one, and a transport failure is classified rather than flattened to "error".
 */
object ApiErrorMapper {

    private val json = Json {
        ignoreUnknownKeys = true
        isLenient = true
    }

    /**
     * Run a call and classify whatever comes back.
     *
     * @param transform maps a successful body to the domain type; runs only on 2xx.
     */
    suspend fun <T, R> call(
        transform: (T) -> R,
        block: suspend () -> Response<T>,
    ): AppResult<R> = try {
        val response = block()
        val body = response.body()

        if (response.isSuccessful && body != null) {
            AppResult.Success(transform(body))
        } else if (response.isSuccessful) {
            // 204, or a body that failed to deserialise into anything.
            AppResult.Failure(AppError.Server("empty body for ${response.code()}"))
        } else {
            AppResult.Failure(fromResponse(response))
        }
    } catch (error: Exception) {
        AppResult.Failure(fromException(error))
    }

    /** [call] for endpoints whose body is already the domain type. */
    suspend fun <T> call(block: suspend () -> Response<T>): AppResult<T> =
        call({ it }, block)

    /** Classify a failed response, preferring the server's own message. */
    fun fromResponse(response: Response<*>): AppError {
        val raw = try {
            response.errorBody()?.string().orEmpty()
        } catch (error: IOException) {
            ""
        }

        val parsed = raw.takeIf { it.isNotBlank() }?.let {
            try {
                json.decodeFromString<ApiErrorDto>(it)
            } catch (error: Exception) {
                null
            }
        }

        val status = parsed?.data?.status?.takeIf { it > 0 } ?: response.code()
        val message = parsed?.message.orEmpty()
        val code = parsed?.code.orEmpty()

        // The body is kept whole here even though the screen shows a sentence:
        // when the server's message is not the useful part, the raw response is.
        ClientLog.record(
            "HTTP $status · ${response.raw().request.method} ${response.raw().request.url.encodedPath}",
            raw.ifBlank { "(gövde yok)" },
        )

        return when {
            // Before the plain 403: a suspended account gets a screen of its
            // own, and "yetkin yok" would be both wrong and unhelpful.
            code == "ACCOUNT_BANNED" -> AppError.Banned(
                reason = parsed?.data?.reason.orEmpty(),
                expiresAt = parsed?.data?.expiresAt.orEmpty(),
                permanent = parsed?.data?.permanent ?: true,
                technical = message,
            ).also { AccountGate.record(it) }

            status == 401 -> AppError.Unauthorized(message.ifBlank { "401" })
            status == 403 -> AppError.Forbidden(message.ifBlank { "403" })
            status == 404 -> AppError.NotFound(message.ifBlank { "404" })
            status == 429 -> AppError.RateLimited(parsed?.data?.retryAfter ?: 60, message)
            status == 409 && code == "VIDEO_ERROR" -> AppError.Video(message)

            // 4xx with a message the server wrote: a validation failure says
            // something this app has no better string for.
            status in 400..499 && message.isNotBlank() ->
                AppError.Message(message, code.ifBlank { "VALIDATION_ERROR" })

            status in 500..599 -> AppError.Server(message.ifBlank { "HTTP $status" })
            else -> AppError.Unknown("HTTP $status $message")
        }
    }

    /** Classify a transport failure. */
    fun fromException(error: Exception): AppError {
        // A response the app could not parse arrives here, and its message
        // names the offending field and offset — the one thing worth copying.
        ClientLog.record(error::class.java.simpleName, error.message ?: "(mesaj yok)")

        return classify(error)
    }

    private fun classify(error: Exception): AppError = when (error) {
        is SocketTimeoutException -> AppError.Timeout(error.message)
        // No DNS: almost always no connectivity rather than a dead server.
        is UnknownHostException -> AppError.Network(error.message)
        is SSLException -> AppError.Network("TLS: ${error.message}")
        is IOException -> AppError.Network(error.message)
        else -> AppError.Unknown(error.message ?: error::class.java.simpleName)
    }
}
