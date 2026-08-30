package com.animeh.app.core

/**
 * A call's outcome: a value, or an [AppError].
 *
 * Kotlin's own `Result` carries a `Throwable`, which puts exception types into
 * every signature and tempts callers into `printStackTrace()`. This carries the
 * error the UI is going to render instead, so a repository cannot hand a screen
 * something it has no string for.
 */
sealed interface AppResult<out T> {
    data class Success<T>(val data: T) : AppResult<T>
    data class Failure(val error: AppError) : AppResult<Nothing>
}

/** The value, or null when the call failed. */
fun <T> AppResult<T>.getOrNull(): T? = (this as? AppResult.Success)?.data

/** The error, or null when the call succeeded. */
fun <T> AppResult<T>.errorOrNull(): AppError? = (this as? AppResult.Failure)?.error

inline fun <T, R> AppResult<T>.map(transform: (T) -> R): AppResult<R> = when (this) {
    is AppResult.Success -> AppResult.Success(transform(data))
    is AppResult.Failure -> this
}

inline fun <T> AppResult<T>.onSuccess(action: (T) -> Unit): AppResult<T> {
    if (this is AppResult.Success) action(data)
    return this
}

inline fun <T> AppResult<T>.onFailure(action: (AppError) -> Unit): AppResult<T> {
    if (this is AppResult.Failure) action(error)
    return this
}

/**
 * What a screen shows while it is loading, loaded or failed.
 *
 * [Success] carries a `fromCache` flag so a screen can say "you are offline,
 * this is what was saved" rather than silently presenting stale data as fresh —
 * the offline state §3 asks for.
 */
sealed interface UiState<out T> {
    data object Loading : UiState<Nothing>
    data class Success<T>(val data: T, val fromCache: Boolean = false) : UiState<T>
    data class Error(val error: AppError) : UiState<Nothing>
    data object Empty : UiState<Nothing>
}
