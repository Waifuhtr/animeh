package com.animeh.app.ui.screens.shorts

import androidx.compose.runtime.Immutable
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppError
import com.animeh.app.core.AppResult
import com.animeh.app.core.explain
import com.animeh.app.data.remote.dto.ShortCommentDto
import com.animeh.app.data.remote.dto.ShortDto
import com.animeh.app.data.repository.ShortsRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

@Immutable
data class ShortsFeedState(
    val items: List<ShortDto> = emptyList(),
    val tab: String = ShortsRepository.TAB_FOR_YOU,
    val loading: Boolean = true,
    val appending: Boolean = false,
    val endReached: Boolean = false,
    val error: AppError? = null,
    /** Shown as a snackbar and cleared once read. */
    val message: String? = null,
) {
    val isEmpty: Boolean get() = items.isEmpty() && !loading && error == null
}

/**
 * The swipe feed.
 *
 * Reactions are written to the list first and sent after, because a like that
 * waits for a round trip on a phone connection reads as a broken button. The
 * server's own row replaces the guess when it lands, so a refused like — the
 * account signed out, say — corrects itself rather than lying.
 */
@HiltViewModel
class ShortsViewModel @Inject constructor(
    private val repository: ShortsRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(ShortsFeedState())
    val state: StateFlow<ShortsFeedState> = _state.asStateFlow()

    private var loadJob: Job? = null

    /** Which videos have already been counted, so a swipe back does not recount. */
    private val counted = mutableSetOf<Long>()

    init {
        load(ShortsRepository.TAB_FOR_YOU)
    }

    fun setTab(tab: String) {
        if (_state.value.tab == tab) return
        load(tab)
    }

    fun retry() {
        load(_state.value.tab)
    }

    private fun load(tab: String) {
        loadJob?.cancel()

        _state.update {
            it.copy(tab = tab, loading = true, error = null, items = emptyList(), endReached = false)
        }

        loadJob = viewModelScope.launch {
            when (val result = repository.feed(tab, offset = 0)) {
                is AppResult.Success -> _state.update {
                    it.copy(
                        items = result.data,
                        loading = false,
                        endReached = result.data.size < ShortsRepository.FEED_PAGE,
                    )
                }

                is AppResult.Failure -> _state.update {
                    it.copy(loading = false, error = result.error)
                }
            }
        }
    }

    /**
     * Fetch the next page as the end comes into view.
     *
     * Called from the pager rather than from a scroll listener: the feed is one
     * video per page, so "three from the end" is a page index and not a pixel
     * offset.
     */
    fun loadMoreIfNeeded(page: Int) {
        val current = _state.value

        if (current.appending || current.endReached || current.loading) return
        if (page < current.items.size - PREFETCH_AHEAD) return

        _state.update { it.copy(appending = true) }

        viewModelScope.launch {
            when (val result = repository.feed(current.tab, offset = current.items.size)) {
                is AppResult.Success -> _state.update { state ->
                    // Ids already on screen are dropped: the For You feed sorts
                    // by what the viewer has seen, and a video counted between
                    // two pages can move across the boundary and arrive twice.
                    val known = state.items.mapTo(mutableSetOf()) { it.id }
                    val fresh = result.data.filterNot { it.id in known }

                    state.copy(
                        items = state.items + fresh,
                        appending = false,
                        endReached = result.data.isEmpty(),
                    )
                }

                // A failed page keeps what is on screen; the next swipe retries.
                is AppResult.Failure -> _state.update { it.copy(appending = false) }
            }
        }
    }

    /** Count a view once the video has actually been on screen. */
    fun watched(id: Long) {
        if (!counted.add(id)) return

        viewModelScope.launch { repository.countView(id) }
    }

    fun toggleLike(short: ShortDto) {
        val wanted = !short.liked

        // Guessed on screen immediately, then replaced by the server's row.
        replace(
            short.copy(
                liked = wanted,
                likeCount = (short.likeCount + if (wanted) 1 else -1).coerceAtLeast(0),
            )
        )

        viewModelScope.launch {
            when (val result = repository.setLiked(short.id, wanted)) {
                is AppResult.Success -> replace(result.data)
                is AppResult.Failure -> {
                    replace(short)
                    say(result.error.explain())
                }
            }
        }
    }

    fun toggleSave(short: ShortDto) {
        val wanted = !short.saved

        replace(
            short.copy(
                saved = wanted,
                saveCount = (short.saveCount + if (wanted) 1 else -1).coerceAtLeast(0),
            )
        )

        viewModelScope.launch {
            when (val result = repository.setSaved(short.id, wanted)) {
                is AppResult.Success -> replace(result.data)
                is AppResult.Failure -> {
                    replace(short)
                    say(result.error.explain())
                }
            }
        }
    }

    fun toggleFollow(short: ShortDto) {
        val wanted = !short.following

        // Every video by the same creator moves together: following somebody
        // from one video and seeing "Takip et" on the next is a bug the viewer
        // can see.
        setFollowing(short.creator.id, wanted)

        viewModelScope.launch {
            when (val result = repository.setFollowing(short.creator.id, wanted)) {
                is AppResult.Success -> setFollowing(short.creator.id, result.data.following)
                is AppResult.Failure -> {
                    setFollowing(short.creator.id, !wanted)
                    say(result.error.explain())
                }
            }
        }
    }

    fun delete(short: ShortDto) {
        viewModelScope.launch {
            when (val result = repository.delete(short.id)) {
                is AppResult.Success -> _state.update { state ->
                    state.copy(items = state.items.filterNot { it.id == short.id })
                }

                is AppResult.Failure -> say(result.error.explain())
            }
        }
    }

    /** The comment sheet writes back here so the count under the video moves. */
    fun setCommentCount(shortId: Long, count: Long) {
        _state.update { state ->
            state.copy(
                items = state.items.map {
                    if (it.id == shortId) it.copy(commentCount = count.coerceAtLeast(0)) else it
                }
            )
        }
    }

    fun messageShown() {
        _state.update { it.copy(message = null) }
    }

    private fun replace(short: ShortDto) {
        _state.update { state ->
            state.copy(items = state.items.map { if (it.id == short.id) short else it })
        }
    }

    private fun setFollowing(creatorId: Long, following: Boolean) {
        _state.update { state ->
            state.copy(
                items = state.items.map {
                    if (it.creator.id == creatorId) it.copy(following = following) else it
                }
            )
        }
    }

    private fun say(message: String) {
        _state.update { it.copy(message = message) }
    }

    private companion object {
        /** How many videos from the end to start fetching the next page. */
        const val PREFETCH_AHEAD = 3
    }
}

/* ── Comments ────────────────────────────────────────────────────────── */

@Immutable
data class ShortCommentsState(
    val shortId: Long = 0,
    val comments: List<ShortCommentDto> = emptyList(),
    /** Replies, by the id of the comment they hang under. */
    val replies: Map<Long, List<ShortCommentDto>> = emptyMap(),
    val expanded: Set<Long> = emptySet(),
    val total: Long = 0,
    val loading: Boolean = true,
    val sending: Boolean = false,
    /** The comment being replied to, or null for a new top-level one. */
    val replyingTo: ShortCommentDto? = null,
    val message: String? = null,
)

/**
 * One video's comments.
 *
 * Its own view model rather than more state on the feed's: the sheet is opened
 * for one video at a time, and keeping a map of every video's comments in the
 * feed would mean holding all of them for a session of scrolling.
 */
@HiltViewModel
class ShortCommentsViewModel @Inject constructor(
    private val repository: ShortsRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(ShortCommentsState())
    val state: StateFlow<ShortCommentsState> = _state.asStateFlow()

    private var loadJob: Job? = null

    fun open(shortId: Long) {
        if (_state.value.shortId == shortId && !_state.value.loading) return

        loadJob?.cancel()
        _state.value = ShortCommentsState(shortId = shortId, loading = true)

        loadJob = viewModelScope.launch {
            when (val result = repository.comments(shortId)) {
                is AppResult.Success -> _state.update {
                    it.copy(comments = result.data.items, total = result.data.total, loading = false)
                }

                is AppResult.Failure -> _state.update {
                    it.copy(loading = false, message = result.error.explain())
                }
            }
        }
    }

    /** Show or hide the replies under one comment, fetching them the first time. */
    fun toggleReplies(comment: ShortCommentDto) {
        val current = _state.value

        if (comment.id in current.expanded) {
            _state.update { it.copy(expanded = it.expanded - comment.id) }
            return
        }

        _state.update { it.copy(expanded = it.expanded + comment.id) }

        if (current.replies.containsKey(comment.id)) return

        viewModelScope.launch {
            when (val result = repository.comments(current.shortId, parent = comment.id)) {
                is AppResult.Success -> _state.update {
                    it.copy(replies = it.replies + (comment.id to result.data.items))
                }

                is AppResult.Failure -> _state.update {
                    it.copy(message = result.error.explain())
                }
            }
        }
    }

    fun replyTo(comment: ShortCommentDto?) {
        _state.update { it.copy(replyingTo = comment) }
    }

    /**
     * Post a comment.
     *
     * @param onCounted the new total, so the number under the video moves too.
     */
    fun send(body: String, onCounted: (Long) -> Unit) {
        val text = body.trim()
        if (text.isEmpty() || _state.value.sending) return

        val current = _state.value
        val parent = current.replyingTo

        _state.update { it.copy(sending = true) }

        viewModelScope.launch {
            val result = repository.comment(current.shortId, text, parent?.id ?: 0)

            when (result) {
                is AppResult.Success -> {
                    _state.update { state ->
                        val added = result.data

                        if (parent == null) {
                            state.copy(
                                comments = listOf(added) + state.comments,
                                total = state.total + 1,
                                sending = false,
                                replyingTo = null,
                            )
                        } else {
                            // A reply lands under its parent and opens it, so
                            // the person who wrote it can see that it arrived.
                            val under = state.replies[parent.id].orEmpty() + added

                            state.copy(
                                replies = state.replies + (parent.id to under),
                                expanded = state.expanded + parent.id,
                                comments = state.comments.map {
                                    if (it.id == parent.id) it.copy(replyCount = it.replyCount + 1) else it
                                },
                                total = state.total + 1,
                                sending = false,
                                replyingTo = null,
                            )
                        }
                    }

                    onCounted(_state.value.total)
                }

                is AppResult.Failure -> _state.update {
                    it.copy(sending = false, message = result.error.explain())
                }
            }
        }
    }

    fun toggleLike(comment: ShortCommentDto) {
        val wanted = !comment.liked

        replace(
            comment.copy(
                liked = wanted,
                likeCount = (comment.likeCount + if (wanted) 1 else -1).coerceAtLeast(0),
            )
        )

        viewModelScope.launch {
            when (val result = repository.setCommentLiked(comment.id, wanted)) {
                is AppResult.Success -> replace(result.data)
                is AppResult.Failure -> {
                    replace(comment)
                    _state.update { it.copy(message = result.error.explain()) }
                }
            }
        }
    }

    fun delete(comment: ShortCommentDto, onCounted: (Long) -> Unit) {
        viewModelScope.launch {
            when (val result = repository.deleteComment(comment.id)) {
                is AppResult.Success -> {
                    _state.update { state ->
                        if (comment.parentId > 0) {
                            val under = state.replies[comment.parentId].orEmpty()
                                .filterNot { it.id == comment.id }

                            state.copy(
                                replies = state.replies + (comment.parentId to under),
                                comments = state.comments.map {
                                    if (it.id == comment.parentId) {
                                        it.copy(replyCount = (it.replyCount - 1).coerceAtLeast(0))
                                    } else {
                                        it
                                    }
                                },
                                total = (state.total - 1).coerceAtLeast(0),
                            )
                        } else {
                            // Deleting a comment takes its replies with it on
                            // the server, so the total loses all of them.
                            val gone = 1 + state.replies[comment.id].orEmpty().size

                            state.copy(
                                comments = state.comments.filterNot { it.id == comment.id },
                                replies = state.replies - comment.id,
                                total = (state.total - gone).coerceAtLeast(0),
                            )
                        }
                    }

                    onCounted(_state.value.total)
                }

                is AppResult.Failure -> _state.update {
                    it.copy(message = result.error.explain())
                }
            }
        }
    }

    fun messageShown() {
        _state.update { it.copy(message = null) }
    }

    private fun replace(comment: ShortCommentDto) {
        _state.update { state ->
            if (comment.parentId > 0) {
                val under = state.replies[comment.parentId].orEmpty()
                    .map { if (it.id == comment.id) comment else it }

                state.copy(replies = state.replies + (comment.parentId to under))
            } else {
                state.copy(comments = state.comments.map { if (it.id == comment.id) comment else it })
            }
        }
    }
}
