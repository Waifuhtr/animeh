package com.animeh.app.core

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * What went wrong on this device, kept where it can be read and copied.
 *
 * The server's log table only knows what reached the server. A response the
 * app could not parse, a request that never left the phone, a timeout — none
 * of those appear there, and on a phone they are otherwise only visible as a
 * truncated line of red text under a form.
 *
 * So every failure that passes through `ApiErrorMapper` is recorded here in
 * full, and the logs screen shows it beside the server's own. Memory only:
 * this is for reading an error that just happened, not an audit trail, and a
 * crash report that survives a restart is not worth writing a user's request
 * bodies to disk for.
 */
object ClientLog {

    /** Enough to cover a session's worth of failures without growing forever. */
    private const val CAPACITY = 200

    /** How much of a response body is worth keeping to identify a bad field. */
    private const val DETAIL_LIMIT = 4000

    data class Entry(
        val at: Long,
        val label: String,
        val detail: String,
    ) {
        val time: String
            get() = TIME_FORMAT.format(Date(at))
    }

    private val _entries = MutableStateFlow<List<Entry>>(emptyList())
    val entries: StateFlow<List<Entry>> = _entries.asStateFlow()

    fun record(label: String, detail: String) {
        val entry = Entry(
            at = System.currentTimeMillis(),
            label = label,
            detail = detail.take(DETAIL_LIMIT),
        )

        // Newest first: the error being investigated is the one that just
        // happened, and it should not need scrolling to.
        _entries.update { current -> (listOf(entry) + current).take(CAPACITY) }
    }

    fun clear() = _entries.update { emptyList() }

    /** The whole journal as one block, for the clipboard. */
    fun asText(): String = entries.value.joinToString("\n\n") { entry ->
        "[${entry.time}] ${entry.label}\n${entry.detail}"
    }

    private val TIME_FORMAT = SimpleDateFormat("HH:mm:ss", Locale.getDefault())
}
