package com.animeh.app.ui.screens.shorts

import android.net.Uri
import androidx.compose.runtime.Immutable
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppResult
import com.animeh.app.core.explain
import com.animeh.app.data.remote.dto.ShortCreatorDto
import com.animeh.app.data.remote.dto.ShortDto
import com.animeh.app.data.remote.dto.ShortNotificationDto
import com.animeh.app.data.remote.dto.ShortSearchDto
import com.animeh.app.data.remote.dto.ShortStatsDto
import com.animeh.app.data.remote.dto.ShortTagDto
import com.animeh.app.data.repository.ShortsCompressor
import com.animeh.app.data.repository.ShortsRepository
import com.animeh.app.data.repository.ShortTrim
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

/* ── A grid of videos, however it was reached ────────────────────────── */

@Immutable
data class ShortGridState(
    val title: String = "",
    val subtitle: String = "",
    val cover: String = "",
    val items: List<ShortDto> = emptyList(),
    val loading: Boolean = true,
    /**
     * Whether the last load came back with nothing because it failed.
     *
     * Kept apart from [message]: a snackbar is gone in four seconds and what
     * is left behind is a page that looks exactly like a tag nobody has ever
     * used. This is what lets the page say "could not be loaded" and offer to
     * try again instead of "no videos yet".
     */
    val failed: Boolean = false,
    /** How many the server says there are in total, when it says. */
    val total: Int = -1,
    val appending: Boolean = false,
    val endReached: Boolean = false,
    val message: String? = null,
    /** Only a creator page has these; the others leave them null. */
    val creator: ShortCreatorDto? = null,
    val stats: ShortStatsDto? = null,
    val following: Boolean = false,
    val isSelf: Boolean = false,
    val soundId: Long = 0,
) {
    /**
     * The page counted videos and then showed none of them.
     *
     * Its own state because it is its own fault: an empty grid under a heading
     * that says there are two is the page contradicting itself, and telling
     * somebody "no videos yet" underneath that number is worse than saying
     * nothing. Offering to load it again is the only honest thing left.
     */
    val countedButEmpty: Boolean
        get() = !loading && !failed && total > 0 && items.isEmpty()
}

/**
 * The tag page.
 *
 * Three pages — tag, sound, creator — are the same grid over a different
 * heading, so they share [ShortGridState] and differ only in what fills it.
 */
@HiltViewModel
class ShortTagViewModel @Inject constructor(
    private val repository: ShortsRepository,
    handle: SavedStateHandle,
) : ViewModel() {

    /**
     * Decoded on the way in, because the route encodes it on the way out.
     *
     * `Routes.shortsTag()` percent-encodes the tag so a route stays one path
     * segment, and if that value arrives here still encoded, it is encoded a
     * second time by the HTTP path — at which point the server sees `%25C5`
     * and there is nothing left to decode it back from. Decoding here costs
     * nothing when it has already been done: a tag holds letters, digits and
     * underscores, so a `%` in one is never anything but an encoding.
     */
    private val tag: String = Uri.decode(handle.get<String>("tag").orEmpty())

    private val _state = MutableStateFlow(ShortGridState(title = "#$tag"))
    val state: StateFlow<ShortGridState> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        _state.update { it.copy(loading = true, failed = false) }

        viewModelScope.launch {
            when (val result = repository.tagPage(tag)) {
                is AppResult.Success -> _state.update {
                    it.copy(
                        title = "#${result.data.tag}",
                        subtitle = result.data.count.toString(),
                        total = result.data.count,
                        items = result.data.items,
                        endReached = result.data.items.size < ShortsRepository.GRID_PAGE,
                        loading = false,
                        failed = false,
                    )
                }

                is AppResult.Failure -> _state.update {
                    it.copy(loading = false, failed = true, message = result.error.explain())
                }
            }
        }
    }

    /** The next page, when the grid has been scrolled to the end of this one. */
    fun loadMore() {
        val current = _state.value
        if (current.loading || current.appending || current.endReached) return

        _state.update { it.copy(appending = true) }

        viewModelScope.launch {
            when (val result = repository.tagPage(tag, offset = current.items.size)) {
                is AppResult.Success -> _state.update { it.append(result.data.items) }
                is AppResult.Failure -> _state.update {
                    it.copy(appending = false, message = result.error.explain())
                }
            }
        }
    }

    fun messageShown() = _state.update { it.copy(message = null) }
}

/**
 * One more page onto the end.
 *
 * Ids already on screen are dropped rather than trusted: a page boundary is
 * not a fixed line when the ordering can move under it, and a duplicate key is
 * a crash in a lazy grid rather than a cosmetic fault.
 */
private fun ShortGridState.append(more: List<ShortDto>): ShortGridState {
    val known = items.mapTo(mutableSetOf()) { it.id }
    val fresh = more.filterNot { it.id in known }

    return copy(
        items = items + fresh,
        appending = false,
        endReached = more.size < ShortsRepository.GRID_PAGE,
    )
}

/** The sound page: everything using one piece of audio. */
@HiltViewModel
class ShortSoundViewModel @Inject constructor(
    private val repository: ShortsRepository,
    handle: SavedStateHandle,
) : ViewModel() {

    private val soundId: Long = handle.get<String>("soundId")?.toLongOrNull() ?: 0L

    private val _state = MutableStateFlow(ShortGridState())
    val state: StateFlow<ShortGridState> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        _state.update { it.copy(loading = true, failed = false) }

        viewModelScope.launch {
            when (val result = repository.soundPage(soundId)) {
                is AppResult.Success -> _state.update {
                    it.copy(
                        title = result.data.sound.title,
                        subtitle = result.data.sound.author,
                        cover = result.data.sound.coverUrl,
                        soundId = result.data.sound.id,
                        // No total here on purpose. A tag's count is a COUNT
                        // run on the spot; a sound's is a stored counter, and
                        // a counter that has drifted would have this page
                        // insisting there are videos it can never fetch.
                        items = result.data.items,
                        endReached = result.data.items.size < ShortsRepository.GRID_PAGE,
                        loading = false,
                        failed = false,
                    )
                }

                is AppResult.Failure -> _state.update {
                    it.copy(loading = false, failed = true, message = result.error.explain())
                }
            }
        }
    }

    fun loadMore() {
        val current = _state.value
        if (current.loading || current.appending || current.endReached) return

        _state.update { it.copy(appending = true) }

        viewModelScope.launch {
            when (val result = repository.soundPage(soundId, offset = current.items.size)) {
                is AppResult.Success -> _state.update { it.append(result.data.items) }
                is AppResult.Failure -> _state.update {
                    it.copy(appending = false, message = result.error.explain())
                }
            }
        }
    }

    fun messageShown() = _state.update { it.copy(message = null) }
}

/** One creator's page: their grid, their numbers, and the follow button. */
@HiltViewModel
class ShortCreatorViewModel @Inject constructor(
    private val repository: ShortsRepository,
    handle: SavedStateHandle,
) : ViewModel() {

    private val creatorId: Long = handle.get<String>("creatorId")?.toLongOrNull() ?: 0L

    private val _state = MutableStateFlow(ShortGridState())
    val state: StateFlow<ShortGridState> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        _state.update { it.copy(loading = true, failed = false) }

        viewModelScope.launch {
            when (val result = repository.creatorPage(creatorId)) {
                is AppResult.Success -> _state.update {
                    it.copy(
                        title = result.data.creator.displayName.ifBlank { result.data.creator.username },
                        subtitle = "@${result.data.creator.username}",
                        cover = result.data.creator.avatar,
                        creator = result.data.creator,
                        stats = result.data.stats,
                        following = result.data.following,
                        isSelf = result.data.isSelf,
                        total = result.data.stats.videos,
                        items = result.data.items,
                        endReached = result.data.items.size < ShortsRepository.GRID_PAGE,
                        loading = false,
                        failed = false,
                    )
                }

                is AppResult.Failure -> _state.update {
                    it.copy(loading = false, failed = true, message = result.error.explain())
                }
            }
        }
    }

    fun loadMore() {
        val current = _state.value
        if (current.loading || current.appending || current.endReached) return

        _state.update { it.copy(appending = true) }

        viewModelScope.launch {
            when (val result = repository.creatorPage(creatorId, offset = current.items.size)) {
                is AppResult.Success -> _state.update { it.append(result.data.items) }
                is AppResult.Failure -> _state.update {
                    it.copy(appending = false, message = result.error.explain())
                }
            }
        }
    }

    fun toggleFollow() {
        val wanted = !_state.value.following

        _state.update { it.copy(following = wanted) }

        viewModelScope.launch {
            when (val result = repository.setFollowing(creatorId, wanted)) {
                is AppResult.Success -> _state.update {
                    it.copy(following = result.data.following, stats = result.data.stats)
                }

                is AppResult.Failure -> _state.update {
                    it.copy(following = !wanted, message = result.error.explain())
                }
            }
        }
    }

    fun messageShown() = _state.update { it.copy(message = null) }
}

/* ── Search ──────────────────────────────────────────────────────────── */

@Immutable
data class ShortSearchState(
    val query: String = "",
    val results: ShortSearchDto = ShortSearchDto(),
    val trending: List<ShortTagDto> = emptyList(),
    val loading: Boolean = false,
    val searched: Boolean = false,
    val message: String? = null,
)

@HiltViewModel
class ShortSearchViewModel @Inject constructor(
    private val repository: ShortsRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(ShortSearchState())
    val state: StateFlow<ShortSearchState> = _state.asStateFlow()

    private var searchJob: Job? = null

    init {
        // The trending tags fill the screen before anything is typed, so search
        // opens on something to tap rather than on an empty box.
        viewModelScope.launch {
            (repository.trendingTags() as? AppResult.Success)?.let { result ->
                _state.update { it.copy(trending = result.data) }
            }
        }
    }

    fun setQuery(query: String) {
        _state.update { it.copy(query = query) }

        searchJob?.cancel()

        if (query.isBlank()) {
            _state.update { it.copy(results = ShortSearchDto(), searched = false, loading = false) }
            return
        }

        searchJob = viewModelScope.launch {
            // Debounced: typing "naruto" is otherwise six requests and the
            // first five are wasted before the last one lands.
            delay(DEBOUNCE_MS)

            _state.update { it.copy(loading = true) }

            when (val result = repository.search(query)) {
                is AppResult.Success -> _state.update {
                    it.copy(results = result.data, loading = false, searched = true)
                }

                is AppResult.Failure -> _state.update {
                    it.copy(loading = false, message = result.error.explain())
                }
            }
        }
    }

    fun messageShown() = _state.update { it.copy(message = null) }

    private companion object {
        const val DEBOUNCE_MS = 350L
    }
}

/* ── Uploading ───────────────────────────────────────────────────────── */

@Immutable
data class ShortUploadState(
    val uri: Uri? = null,
    val facts: ShortsRepository.VideoFacts? = null,
    val description: String = "",
    val soundTitle: String = "",
    val adult: Boolean = false,
    /** Where the kept part starts and ends, in ms from the start of the file. */
    val trimStartMs: Long = 0,
    val trimEndMs: Long = 0,
    /** See `ShortsRepository.FIT_*`. */
    val fitMode: String = ShortsRepository.FIT_ORIGINAL,
    val progress: Float = 0f,
    val uploading: Boolean = false,
    val done: Boolean = false,
    val message: String? = null,
) {
    /** How long the video will be once the cut is applied. */
    val keptMs: Long
        get() = (trimEndMs - trimStartMs).coerceAtLeast(0)

    /**
     * The cut to send to the compressor, or null for the whole video.
     *
     * Null rather than a full-length range on purpose: a video nobody trimmed
     * should not be re-encoded just to prove it was left alone.
     */
    val trim: ShortTrim?
        get() {
            val whole = facts?.durationMs ?: 0

            if (whole <= 0) return null
            if (trimStartMs <= 0 && trimEndMs >= whole) return null

            return ShortTrim(trimStartMs, trimEndMs)
        }

    /**
     * Roughly what will actually be uploaded.
     *
     * The share of the file the cut keeps, at the file's own bitrate. It takes
     * no account of the re-encode, which only ever makes it smaller — so this
     * over-estimates, which is the right direction for a limit to err in. It
     * is also why trimming lets a long recording through: fifteen seconds of a
     * four-hundred-megabyte video is not four hundred megabytes.
     */
    val estimatedBytes: Long
        get() {
            val whole = facts?.durationMs ?: 0
            val size = facts?.sizeBytes ?: 0

            if (whole <= 0 || keptMs >= whole) return size

            return size * keptMs / whole
        }

    /**
     * Whether what would go up is something the server will take.
     *
     * Checked here so a three-hour film is refused before a single byte goes
     * up rather than after three hundred megabytes of it — and checked against
     * the trimmed length, so cutting one down is a way past the limit rather
     * than a thing the limit ignores.
     */
    val tooLong: Boolean
        get() = keptMs > ShortsRepository.MAX_DURATION_MS

    val tooLarge: Boolean
        get() = estimatedBytes > ShortsRepository.MAX_SIZE_BYTES

    /** A cut that keeps nothing. Only reachable once a duration was readable. */
    val emptyCut: Boolean
        get() = (facts?.durationMs ?: 0) > 0 && keptMs < ShortsRepository.MIN_DURATION_MS

    val canSend: Boolean
        get() = uri != null && facts != null && !emptyCut && !tooLong && !tooLarge && !uploading
}

@HiltViewModel
class ShortUploadViewModel @Inject constructor(
    private val repository: ShortsRepository,
    private val compressor: ShortsCompressor,
) : ViewModel() {

    private val _state = MutableStateFlow(ShortUploadState())
    val state: StateFlow<ShortUploadState> = _state.asStateFlow()

    init {
        // An upload killed mid-encode leaves a part-written mp4 in the cache.
        // Android clears that directory under pressure, but not before it has
        // sat there for a week taking up room on somebody's full phone.
        compressor.sweep()
    }

    fun pick(uri: Uri) {
        _state.update { it.copy(uri = uri, facts = null, trimStartMs = 0, trimEndMs = 0) }

        viewModelScope.launch {
            when (val result = repository.inspect(uri)) {
                // The handles start at the two ends, so the slider shows what
                // will be uploaded if nobody touches it: all of it.
                is AppResult.Success -> _state.update {
                    it.copy(
                        facts = result.data,
                        trimStartMs = 0,
                        trimEndMs = result.data.durationMs,
                    )
                }
                is AppResult.Failure -> _state.update {
                    it.copy(uri = null, message = result.error.explain())
                }
            }
        }
    }

    fun setDescription(value: String) = _state.update { it.copy(description = value) }

    fun setSoundTitle(value: String) = _state.update { it.copy(soundTitle = value) }

    fun setAdult(value: Boolean) = _state.update { it.copy(adult = value) }

    fun setFitMode(value: String) = _state.update { it.copy(fitMode = value) }

    /**
     * Move the cut's handles.
     *
     * Clamped rather than trusted: a slider hands back whatever the finger did,
     * and an end before its start is a range the control cannot draw and the
     * encoder cannot cut. Videos shorter than the minimum keep both handles at
     * the ends, where they can do no harm.
     */
    fun setTrim(startMs: Long, endMs: Long) = _state.update { current ->
        val whole = current.facts?.durationMs ?: 0

        if (whole <= 0) return@update current
        if (whole <= ShortsRepository.MIN_DURATION_MS) {
            return@update current.copy(trimStartMs = 0, trimEndMs = whole)
        }

        val start = startMs.coerceIn(0, whole - ShortsRepository.MIN_DURATION_MS)
        val end = endMs.coerceIn(start + ShortsRepository.MIN_DURATION_MS, whole)

        current.copy(trimStartMs = start, trimEndMs = end)
    }

    fun send() {
        val current = _state.value
        val uri = current.uri ?: return
        val facts = current.facts ?: return

        if (!current.canSend) return

        _state.update { it.copy(uploading = true, progress = 0f) }

        viewModelScope.launch {
            val result = repository.upload(
                uri = uri,
                facts = facts,
                description = current.description,
                soundTitle = current.soundTitle,
                adult = current.adult,
                trim = current.trim,
                fitMode = current.fitMode,
                onProgress = { fraction -> _state.update { it.copy(progress = fraction) } },
            )

            when (result) {
                is AppResult.Success -> _state.update { it.copy(uploading = false, done = true) }
                is AppResult.Failure -> _state.update {
                    it.copy(uploading = false, message = result.error.explain())
                }
            }
        }
    }

    fun messageShown() = _state.update { it.copy(message = null) }
}

/* ── The bell ────────────────────────────────────────────────────────── */

@Immutable
data class ShortNotificationsState(
    val items: List<ShortNotificationDto> = emptyList(),
    val unread: Int = 0,
    val loading: Boolean = true,
    val message: String? = null,
)

/**
 * Who followed you, who liked something, who said something.
 *
 * The count is asked for on its own so the bell can carry a badge without the
 * list being open; opening the list is what marks it read.
 */
@HiltViewModel
class ShortNotificationsViewModel @Inject constructor(
    private val repository: ShortsRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(ShortNotificationsState())
    val state: StateFlow<ShortNotificationsState> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        _state.update { it.copy(loading = true) }

        viewModelScope.launch {
            when (val result = repository.notifications()) {
                is AppResult.Success -> _state.update {
                    it.copy(items = result.data.items, unread = result.data.unread, loading = false)
                }

                is AppResult.Failure -> _state.update {
                    it.copy(loading = false, message = result.error.explain())
                }
            }
        }
    }

    /**
     * Mark everything read.
     *
     * The badge is cleared here rather than waiting for the server to agree:
     * the list is on screen, so it has been read whatever the network says,
     * and a badge that lingers after you looked is worse than one that clears
     * a moment early.
     */
    fun markSeen() {
        if (_state.value.unread == 0) return

        _state.update { it.copy(unread = 0) }

        viewModelScope.launch { repository.notificationsSeen() }
    }

    fun messageShown() = _state.update { it.copy(message = null) }
}

/* ── This account's AnimehTok profile ────────────────────────────────── */

@Immutable
data class ShortProfileState(
    val bio: String = "",
    val link: String = "",
    val loading: Boolean = true,
    val saving: Boolean = false,
    val saved: Boolean = false,
    val message: String? = null,
)

/**
 * The bio and the link under it.
 *
 * Its own profile, kept apart from the account's: what somebody writes for a
 * short-video audience is not what they wrote for the anime side, and neither
 * should overwrite the other.
 */
@HiltViewModel
class ShortProfileViewModel @Inject constructor(
    private val repository: ShortsRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(ShortProfileState())
    val state: StateFlow<ShortProfileState> = _state.asStateFlow()

    init {
        viewModelScope.launch {
            when (val result = repository.profile()) {
                is AppResult.Success -> _state.update {
                    it.copy(bio = result.data.bio, link = result.data.link, loading = false)
                }

                is AppResult.Failure -> _state.update {
                    it.copy(loading = false, message = result.error.explain())
                }
            }
        }
    }

    fun setBio(value: String) = _state.update { it.copy(bio = value.take(MAX_BIO)) }

    fun setLink(value: String) = _state.update { it.copy(link = value.trim().take(MAX_LINK)) }

    fun save() {
        val current = _state.value
        if (current.saving) return

        _state.update { it.copy(saving = true) }

        viewModelScope.launch {
            when (val result = repository.saveProfile(current.bio, current.link)) {
                // Read back from what the server kept, not from what was
                // typed: a link it refused has to disappear from the field
                // rather than sit there looking saved.
                is AppResult.Success -> _state.update {
                    it.copy(
                        bio = result.data.bio,
                        link = result.data.link,
                        saving = false,
                        saved = true,
                    )
                }

                is AppResult.Failure -> _state.update {
                    it.copy(saving = false, message = result.error.explain())
                }
            }
        }
    }

    fun messageShown() = _state.update { it.copy(message = null) }

    private companion object {
        /** The same ceilings the server keeps, so nothing is typed to be cut. */
        const val MAX_BIO = 300
        const val MAX_LINK = 300
    }
}

/* ── The profile's AnimehTok block ───────────────────────────────────── */

@Immutable
data class ShortMineState(
    val stats: ShortStatsDto = ShortStatsDto(),
    val mine: List<ShortDto> = emptyList(),
    val saved: List<ShortDto> = emptyList(),
    val loading: Boolean = true,
    val message: String? = null,
)

/**
 * What the signed-in account has on AnimehTok.
 *
 * Its own numbers in its own units, beside the anime and manga ones rather
 * than added into them: a short is not an episode and scrolling is not watch
 * time, so none of this belongs in "izlenen bölüm".
 */
@HiltViewModel
class ShortMineViewModel @Inject constructor(
    private val repository: ShortsRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(ShortMineState())
    val state: StateFlow<ShortMineState> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        _state.update { it.copy(loading = true) }

        viewModelScope.launch {
            when (val result = repository.stats()) {
                is AppResult.Success -> _state.update { it.copy(stats = result.data.stats) }
                is AppResult.Failure -> _state.update { it.copy(message = result.error.explain()) }
            }

            (repository.mine() as? AppResult.Success)?.let { result ->
                _state.update { it.copy(mine = result.data) }
            }

            (repository.saved() as? AppResult.Success)?.let { result ->
                _state.update { it.copy(saved = result.data) }
            }

            _state.update { it.copy(loading = false) }
        }
    }

    fun messageShown() = _state.update { it.copy(message = null) }
}
