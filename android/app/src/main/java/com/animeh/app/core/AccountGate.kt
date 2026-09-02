package com.animeh.app.core

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * Whether this account is allowed to use the app at all.
 *
 * A suspension is not like other failures. Every other error belongs to the
 * screen that caused it — a list that would not load, a form that would not
 * submit — and is shown there. This one belongs to the whole session: whatever
 * the user tries next will be refused the same way, so the app has to stop and
 * say so once rather than putting the same red line under every screen.
 *
 * The state is set from [com.animeh.app.data.remote.ApiErrorMapper], which
 * every response already passes through, so it does not matter which call
 * happened to be the one refused. It is a singleton for the same reason
 * [ClientLog] is: the shell that renders it is above the object graph the
 * repositories live in.
 */
object AccountGate {

    private val _ban = MutableStateFlow<AppError.Banned?>(null)

    /** Non-null while the server is refusing this account. */
    val ban: StateFlow<AppError.Banned?> = _ban.asStateFlow()

    fun record(error: AppError.Banned) {
        _ban.value = error
    }

    /**
     * Forget the sanction.
     *
     * Called on sign-out, and when a suspension's clock runs out: the server
     * stops refusing on its own then, and the app should stop believing it
     * without needing to be reinstalled.
     */
    fun clear() {
        _ban.value = null
    }
}
