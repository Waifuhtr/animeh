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
import com.animeh.app.data.remote.dto.ShortSearchDto
import com.animeh.app.data.remote.dto.ShortStatsDto
import com.animeh.app.data.remote.dto.ShortTagDto
import com.animeh.app.data.repository.ShortsRepository
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
    val message: String? = null,
    /** Only a creator page has these; the others leave them null. */
    val creator: ShortCreatorDto? = null,
    val stats: ShortStatsDto? = null,
    val following: Boolean = false,
    val isSelf: Boolean = false,
    val soundId: Long = 0,
)

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

    private val tag: String = handle.get<String>("tag").orEmpty()

    private val _state = MutableStateFlow(ShortGridState(title = "#$tag"))
    val state: StateFlow<ShortGridState> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        _state.update { it.copy(loading = true) }

        viewModelScope.launch {
            when (val result = repository.tagPage(tag)) {
                is AppResult.Success -> _state.update {
                    it.copy(
                        title = "#${result.data.tag}",
                        subtitle = result.data.count.toString(),
                        items = result.data.items,
                        loading = false,
                    )
                }

                is AppResult.Failure -> _state.update {
                    it.copy(loading = false, message = result.error.explain())
                }
            }
        }
    }

    fun messageShown() = _state.update { it.copy(message = null) }
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
        _state.update { it.copy(loading = true) }

        viewModelScope.launch {
            when (val result = repository.soundPage(soundId)) {
                is AppResult.Success -> _state.update {
                    it.copy(
                        title = result.data.sound.title,
                        subtitle = result.data.sound.author,
                        cover = result.data.sound.coverUrl,
                        soundId = result.data.sound.id,
                        items = result.data.items,
                        loading = false,
                    )
                }

                is AppResult.Failure -> _state.update {
                    it.copy(loading = false, message = result.error.explain())
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
        _state.update { it.copy(loading = true) }

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
                        items = result.data.items,
                        loading = false,
                    )
                }

                is AppResult.Failure -> _state.update {
                    it.copy(loading = false, message = result.error.explain())
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
    val progress: Float = 0f,
    val uploading: Boolean = false,
    val done: Boolean = false,
    val message: String? = null,
) {
    /**
     * Whether the picked file is something the server will take.
     *
     * Checked here so a three-hour film is refused before a single byte goes
     * up rather than after three hundred megabytes of it.
     */
    val tooLong: Boolean
        get() = (facts?.durationMs ?: 0) > ShortsRepository.MAX_DURATION_MS

    val tooLarge: Boolean
        get() = (facts?.sizeBytes ?: 0) > ShortsRepository.MAX_SIZE_BYTES

    val canSend: Boolean
        get() = uri != null && facts != null && !tooLong && !tooLarge && !uploading
}

@HiltViewModel
class ShortUploadViewModel @Inject constructor(
    private val repository: ShortsRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(ShortUploadState())
    val state: StateFlow<ShortUploadState> = _state.asStateFlow()

    fun pick(uri: Uri) {
        _state.update { it.copy(uri = uri, facts = null) }

        viewModelScope.launch {
            when (val result = repository.inspect(uri)) {
                is AppResult.Success -> _state.update { it.copy(facts = result.data) }
                is AppResult.Failure -> _state.update {
                    it.copy(uri = null, message = result.error.explain())
                }
            }
        }
    }

    fun setDescription(value: String) = _state.update { it.copy(description = value) }

    fun setSoundTitle(value: String) = _state.update { it.copy(soundTitle = value) }

    fun setAdult(value: Boolean) = _state.update { it.copy(adult = value) }

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
