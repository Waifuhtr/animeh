package com.animeh.app.ui.screens.admin

import android.net.Uri
import androidx.compose.runtime.Immutable
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppError
import com.animeh.app.core.AppResult
import com.animeh.app.core.UiState
import com.animeh.app.data.remote.dto.*
import com.animeh.app.data.repository.AdminRepository
import com.animeh.app.domain.Episode
import com.animeh.app.domain.KIND_ANIME
import com.animeh.app.domain.Work
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

/**
 * The admin panel's view models.
 *
 * Every one of these calls an endpoint that re-checks the capability
 * server-side. Nothing here is trusted to gate anything — §8 — and a user who
 * forced `is_admin` true reaches these screens and then sees a 403 on every
 * action, which is exactly the intended outcome.
 */

@HiltViewModel
class AdminDashboardViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<DashboardDto>>(UiState.Loading)
    val state: StateFlow<UiState<DashboardDto>> = _state.asStateFlow()

    private val _storageTest = MutableStateFlow<String?>(null)
    val storageTest: StateFlow<String?> = _storageTest.asStateFlow()

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val result = repository.dashboard()) {
                is AppResult.Success -> UiState.Success(result.data)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    /** The same "does the bucket answer" check the WordPress panel offers. */
    fun testStorage() {
        viewModelScope.launch {
            _storageTest.value = "…"
            _storageTest.value = when (val result = repository.testStorage()) {
                is AppResult.Success ->
                    "✓ ${result.data.bucket} · ${result.data.latencyMs} ms"
                is AppResult.Failure ->
                    "✗ ${result.error.technical ?: result.error.code}"
            }
        }
    }
}

@HiltViewModel
class AdminWorksViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<List<Work>>>(UiState.Loading)
    val state: StateFlow<UiState<List<Work>>> = _state.asStateFlow()

    private val _query = MutableStateFlow("")
    val query: StateFlow<String> = _query.asStateFlow()

    /**
     * Which library is being managed.
     *
     * Anime and manga share a table and are two different shelves to whoever
     * is looking after them, so the screen says which one it is showing and
     * the same list serves both.
     */
    private var kind: String = KIND_ANIME

    private var searchJob: Job? = null

    init {
        load()
    }

    fun setKind(value: String) {
        if (kind == value) return
        kind = value
        load()
    }

    fun setQuery(value: String) {
        _query.value = value
        searchJob?.cancel()
        searchJob = viewModelScope.launch {
            delay(300)
            load()
        }
    }

    fun load() {
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val result = repository.works(_query.value, kind)) {
                is AppResult.Success ->
                    if (result.data.isEmpty()) UiState.Empty else UiState.Success(result.data)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    fun delete(id: Long) {
        viewModelScope.launch {
            repository.deleteWork(id)
            load()
        }
    }
}

@HiltViewModel
class AdminWorkEditViewModel @Inject constructor(
    private val repository: AdminRepository,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    private val workId: Long = savedStateHandle["workId"] ?: 0L

    /**
     * Which shelf a new work goes on.
     *
     * Only meaningful while creating: an existing work carries its own kind
     * and [load] overwrites this with it. Without it every work made here was
     * an anime, so a manga could not be added from the panel at all.
     */
    private val startingKind: String = savedStateHandle["kind"] ?: KIND_ANIME

    private val _form = MutableStateFlow(
        AdminWorkRequest(id = workId.takeIf { it > 0 }, kind = startingKind)
    )
    val form: StateFlow<AdminWorkRequest> = _form.asStateFlow()

    private val _saving = MutableStateFlow(false)
    val saving: StateFlow<Boolean> = _saving.asStateFlow()

    private val _error = MutableStateFlow<AppError?>(null)
    val error: StateFlow<AppError?> = _error.asStateFlow()

    private val _saved = MutableStateFlow(false)
    val saved: StateFlow<Boolean> = _saved.asStateFlow()

    val isNew: Boolean get() = workId == 0L

    init {
        if (workId > 0) load()
    }

    private fun load() {
        viewModelScope.launch {
            // Asked for by id. It used to search the first page of anime and
            // pick the matching row out of it, so a manga — or anything past
            // page one — opened a blank form and saving it wiped the fields
            // that never arrived.
            when (val result = repository.work(workId)) {
                is AppResult.Success -> {
                    result.data?.let { work ->
                        _form.value = AdminWorkRequest(
                            id = work.id,
                            title = work.title,
                            titleEnglish = work.titleEnglish,
                            synopsis = work.synopsis,
                            posterUrl = work.posterUrl,
                            bannerUrl = work.bannerUrl,
                            score = work.score,
                            year = work.year,
                            season = work.season,
                            status = work.status.name.lowercase(),
                            format = work.format,
                            studio = work.studio,
                            author = work.author,
                            kind = work.kind,
                            genres = work.genres,
                            totalEpisodes = work.totalEpisodes,
                            published = work.published,
                            adult = work.adult,
                        )
                    }
                }
                is AppResult.Failure -> _error.value = result.error
            }
        }
    }

    fun update(block: (AdminWorkRequest) -> AdminWorkRequest) {
        _form.update(block)
    }

    private val _artwork = MutableStateFlow<String?>(null)

    /** A line about the last TMDB fetch: what it filled, or why it did not. */
    val artwork: StateFlow<String?> = _artwork.asStateFlow()

    private val _fetching = MutableStateFlow(false)
    val fetching: StateFlow<Boolean> = _fetching.asStateFlow()

    fun dismissArtwork() {
        _artwork.value = null
    }

    /**
     * Pull the poster, the backdrop and the episode stills from TMDB.
     *
     * Only fills what is empty. A poster someone chose by hand is not a gap,
     * and a run of this quietly replacing it would be the kind of change
     * nobody notices until the wrong artwork is on the shelf.
     *
     * @param doneMessage  With one %d, for how many episodes were filled.
     * @param emptyMessage When there was nothing to fill.
     */
    fun fetchArtwork(doneMessage: (Int) -> String, emptyMessage: String) {
        if (workId <= 0) return

        viewModelScope.launch {
            _fetching.value = true

            when (val result = repository.tmdbArtwork(workId)) {
                is AppResult.Success -> {
                    val data = result.data
                    _artwork.value = if (data.filled.isEmpty() && data.episodesFilled == 0) {
                        emptyMessage
                    } else {
                        doneMessage(data.episodesFilled)
                    }

                    // The row now holds URLs this form does not: re-read it so
                    // the fields show what was actually written.
                    load()
                }

                // The server's own sentence: "no key" and "no match" are the
                // two likely failures and both name their own fix.
                is AppResult.Failure -> _artwork.value = result.error.technical
            }

            _fetching.value = false
        }
    }

    fun save() {
        val current = _form.value

        if (current.title.isNullOrBlank()) {
            _error.value = AppError.Message("Başlık gerekli.")
            return
        }

        viewModelScope.launch {
            _saving.value = true
            _error.value = null

            when (val result = repository.saveWork(current)) {
                is AppResult.Success -> _saved.value = true
                is AppResult.Failure -> _error.value = result.error
            }

            _saving.value = false
        }
    }
}

@HiltViewModel
class AdminEpisodesViewModel @Inject constructor(
    private val repository: AdminRepository,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    private val workId: Long = savedStateHandle["workId"] ?: 0L

    private val _state = MutableStateFlow<UiState<List<Episode>>>(UiState.Loading)
    val state: StateFlow<UiState<List<Episode>>> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val result = repository.episodes(workId)) {
                is AppResult.Success ->
                    if (result.data.isEmpty()) UiState.Empty else UiState.Success(result.data)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    fun delete(id: Long) {
        viewModelScope.launch {
            repository.deleteEpisode(id)
            load()
        }
    }

    fun togglePublished(episode: Episode) {
        viewModelScope.launch {
            repository.saveEpisode(
                workId,
                AdminEpisodeRequest(
                    id = episode.id,
                    seasonNumber = episode.seasonNumber,
                    number = episode.number,
                    published = !episode.published,
                ),
            )
            load()
        }
    }
}

/** State for the episode editor, including the upload in progress. */
data class EpisodeEditState(
    val form: AdminEpisodeRequest = AdminEpisodeRequest(),
    val sources: List<AdminSourceDto> = emptyList(),
    val workTitle: String = "",
    val saving: Boolean = false,
    val uploadProgress: Float? = null,
    val error: AppError? = null,
    val saved: Boolean = false,
)

@HiltViewModel
class AdminEpisodeEditViewModel @Inject constructor(
    private val repository: AdminRepository,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    private val workId: Long = savedStateHandle["workId"] ?: 0L
    private val episodeId: Long = savedStateHandle["episodeId"] ?: 0L

    private val _state = MutableStateFlow(EpisodeEditState())
    val state: StateFlow<EpisodeEditState> = _state.asStateFlow()

    val isNew: Boolean get() = episodeId == 0L

    init {
        if (episodeId > 0) load() else _state.update {
            it.copy(form = AdminEpisodeRequest(workId = workId))
        }

        viewModelScope.launch {
            (repository.works("") as? AppResult.Success)?.data
                ?.firstOrNull { it.id == workId }
                ?.let { work -> _state.update { it.copy(workTitle = work.displayTitle) } }
        }
    }

    fun update(block: (AdminEpisodeRequest) -> AdminEpisodeRequest) {
        _state.update { it.copy(form = block(it.form)) }
    }

    fun save() {
        viewModelScope.launch {
            _state.update { it.copy(saving = true, error = null) }

            when (val result = repository.saveEpisode(workId, _state.value.form)) {
                is AppResult.Success -> _state.update { it.copy(saving = false, saved = true) }
                is AppResult.Failure -> _state.update { it.copy(saving = false, error = result.error) }
            }
        }
    }

    /**
     * Upload a file and attach it as a source.
     *
     * The file goes straight to storage in parts; only the resulting key comes
     * back through this app to be recorded against the episode.
     */
    fun upload(uri: Uri, filename: String, kind: String, contentType: String, height: Int, language: String) {
        val form = _state.value.form

        viewModelScope.launch {
            _state.update { it.copy(uploadProgress = 0f, error = null) }

            val result = repository.uploadFile(
                uri = uri,
                animeTitle = _state.value.workTitle,
                animeId = workId,
                season = form.seasonNumber,
                episode = form.number,
                filename = filename,
                contentType = contentType,
                onProgress = { fraction ->
                    _state.update { it.copy(uploadProgress = fraction) }
                },
            )

            when (result) {
                is AppResult.Success -> {
                    val attached = repository.saveSource(
                        episodeId = episodeId,
                        request = AdminSourceRequest(
                            episodeId = episodeId,
                            kind = kind,
                            label = if (height > 0) "${height}p" else filename,
                            language = language,
                            storageKey = result.data,
                            mime = contentType,
                            height = height,
                            isDefault = _state.value.sources.none { it.kind == kind },
                        ),
                    )

                    _state.update {
                        it.copy(
                            uploadProgress = null,
                            error = (attached as? AppResult.Failure)?.error,
                        )
                    }

                    load()
                }

                is AppResult.Failure -> _state.update {
                    it.copy(uploadProgress = null, error = result.error)
                }
            }
        }
    }

    fun deleteSource(id: Long) {
        viewModelScope.launch {
            repository.deleteSource(id)
            load()
        }
    }

    private fun load() {
        viewModelScope.launch {
            when (val result = repository.episode(episodeId)) {
                is AppResult.Success -> {
                    val episode = result.data.episode
                    _state.update {
                        it.copy(
                            form = AdminEpisodeRequest(
                                id = episodeId,
                                workId = workId,
                                seasonNumber = episode?.seasonNumber ?: 1,
                                number = episode?.number ?: 1,
                                title = episode?.title,
                                synopsis = episode?.synopsis,
                                thumbnailUrl = episode?.thumbnailUrl,
                                durationSeconds = episode?.durationSeconds,
                                published = episode?.published,
                            ),
                            sources = result.data.sources,
                        )
                    }
                }
                is AppResult.Failure -> _state.update { it.copy(error = result.error) }
            }
        }
    }
}

/** Tenrai search and import. */
@HiltViewModel
class AdminTenraiViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    private val _query = MutableStateFlow("")
    val query: StateFlow<String> = _query.asStateFlow()

    private val _results = MutableStateFlow<UiState<List<TenraiSearchResultDto>>>(UiState.Empty)
    val results: StateFlow<UiState<List<TenraiSearchResultDto>>> = _results.asStateFlow()

    private val _importing = MutableStateFlow<Long?>(null)
    val importing: StateFlow<Long?> = _importing.asStateFlow()

    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    private var searchJob: Job? = null

    fun setQuery(value: String) {
        _query.value = value
        searchJob?.cancel()

        if (value.length < MIN_QUERY) {
            _results.value = UiState.Empty
            return
        }

        searchJob = viewModelScope.launch {
            // Debounced harder than the local search: this one goes through to
            // a third-party API whose rate limit is not ours to spend.
            delay(600)
            _results.value = UiState.Loading

            _results.value = when (val result = repository.tenraiSearch(value)) {
                is AppResult.Success ->
                    if (result.data.isEmpty()) UiState.Empty else UiState.Success(result.data)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    fun import(tenraiId: Long, withEpisodes: Boolean = true) {
        viewModelScope.launch {
            _importing.value = tenraiId

            when (val result = repository.tenraiImport(tenraiId, withEpisodes)) {
                is AppResult.Success -> {
                    _message.value = if (result.data.updated) {
                        "Güncellendi · ${result.data.importedEpisodes} bölüm"
                    } else {
                        "İçe aktarıldı · ${result.data.importedEpisodes} bölüm"
                    }
                    // Refresh so the row switches to "update".
                    setQuery(_query.value)
                }
                is AppResult.Failure -> _message.value = result.error.technical ?: "İçe aktarma başarısız"
            }

            _importing.value = null
        }
    }

    fun dismissMessage() {
        _message.value = null
    }

    private companion object {
        const val MIN_QUERY = 3
    }
}

/**
 * Searching TMDB and importing from it.
 *
 * A near-twin of [AdminTenraiViewModel] rather than a shared base class: the
 * two sources answer with different shapes, and the day one of them grows a
 * field the other has not got, a shared parent is where that becomes painful.
 */
@HiltViewModel
class AdminTmdbViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    private val _query = MutableStateFlow("")
    val query: StateFlow<String> = _query.asStateFlow()

    private val _results = MutableStateFlow<UiState<List<TmdbSearchResultDto>>>(UiState.Empty)
    val results: StateFlow<UiState<List<TmdbSearchResultDto>>> = _results.asStateFlow()

    private val _importing = MutableStateFlow<Long?>(null)
    val importing: StateFlow<Long?> = _importing.asStateFlow()

    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    private var searchJob: Job? = null

    fun setQuery(value: String) {
        _query.value = value
        searchJob?.cancel()

        if (!isSearchable(value)) {
            _results.value = UiState.Empty
            return
        }

        searchJob = viewModelScope.launch {
            // Debounced harder than the local search: this one goes through to
            // a third-party API whose rate limit is not ours to spend.
            delay(600)
            _results.value = UiState.Loading

            _results.value = when (val result = repository.tmdbSearch(value)) {
                is AppResult.Success ->
                    if (result.data.isEmpty()) UiState.Empty else UiState.Success(result.data)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    fun import(tmdbId: Long, withEpisodes: Boolean = true) {
        viewModelScope.launch {
            _importing.value = tmdbId

            when (val result = repository.tmdbImport(tmdbId, withEpisodes)) {
                is AppResult.Success -> {
                    _message.value = if (result.data.updated) {
                        "Güncellendi · ${result.data.importedEpisodes} bölüm"
                    } else {
                        "İçe aktarıldı · ${result.data.importedEpisodes} bölüm"
                    }
                    // Refresh so the row switches to "update".
                    setQuery(_query.value)
                }

                // The server's own sentence: "no key" and "TMDB said 401" both
                // name their own fix, and neither has a useful generic form.
                is AppResult.Failure -> _message.value = result.error.technical ?: "İçe aktarma başarısız"
            }

            _importing.value = null
        }
    }

    fun dismissMessage() {
        _message.value = null
    }

    /**
     * Whether this is worth spending a request on.
     *
     * The three-letter floor is there so typing a title does not fire a
     * request per keystroke, but an id is complete the moment it is typed and
     * a short one would never get past that floor — so digits are exempt. The
     * server decides what is an id and what is a title; this only decides
     * whether to ask at all.
     */
    private fun isSearchable(value: String): Boolean {
        val trimmed = value.trim()

        return trimmed.length >= MIN_QUERY ||
            (trimmed.isNotEmpty() && trimmed.all { it.isDigit() })
    }

    private companion object {
        const val MIN_QUERY = 3
    }
}

@HiltViewModel
class AdminUsersViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<List<UserDto>>>(UiState.Loading)
    val state: StateFlow<UiState<List<UserDto>>> = _state.asStateFlow()

    private val _query = MutableStateFlow("")
    val query: StateFlow<String> = _query.asStateFlow()

    /**
     * Which library is being managed.
     *
     * Anime and manga share a table and are two different shelves to whoever
     * is looking after them, so the screen says which one it is showing and
     * the same list serves both.
     */
    private var kind: String = KIND_ANIME

    private var searchJob: Job? = null

    init {
        load()
    }

    fun setKind(value: String) {
        if (kind == value) return
        kind = value
        load()
    }

    fun setQuery(value: String) {
        _query.value = value
        searchJob?.cancel()
        searchJob = viewModelScope.launch {
            delay(300)
            load()
        }
    }

    fun load() {
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val result = repository.users(_query.value)) {
                is AppResult.Success ->
                    if (result.data.items.isEmpty()) UiState.Empty else UiState.Success(result.data.items)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    /**
     * Send points to somebody, or take them back with a negative amount.
     *
     * Nothing in the list changes: a balance is not one of the columns a user
     * row draws, and reloading fifty rows to show a number that is not on
     * screen would be work for nothing.
     */
    fun grantPoints(userId: Long, amount: Int, note: String) {
        viewModelScope.launch { repository.grantPoints(userId, amount, note) }
    }

    /** [days] zero is permanent; anything else suspends for that many days. */
    fun ban(userId: Long, reason: String, days: Int) {
        viewModelScope.launch {
            replace(repository.banUser(userId, reason, days))
        }
    }

    fun liftBan(userId: Long) {
        viewModelScope.launch {
            replace(repository.liftBan(userId))
        }
    }

    /**
     * Swap one row for the version the server just returned.
     *
     * Rather than reloading: the list is searched and paged, and a reload
     * would move the row someone just acted on out from under them.
     */
    private fun replace(result: AppResult<UserDto>) {
        if (result !is AppResult.Success) return

        val updated = result.data
        _state.update { current ->
            if (current !is UiState.Success) current
            else UiState.Success(current.data.map { if (it.id == updated.id) updated else it })
        }
    }
}

/** The report queue: what people flagged, and what to do about it. */
@HiltViewModel
class AdminReportsViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<List<ReportDto>>>(UiState.Loading)
    val state: StateFlow<UiState<List<ReportDto>>> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val result = repository.reports()) {
                is AppResult.Success ->
                    if (result.data.items.isEmpty()) UiState.Empty else UiState.Success(result.data.items)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    /** [action] is "delete" to remove the review, "dismiss" to let it stand. */
    fun handle(reportId: Long, action: String) {
        viewModelScope.launch {
            if (repository.handleReport(reportId, action) !is AppResult.Success) return@launch

            // Dropped locally rather than reloading: the queue is short, and
            // the row that was just dealt with is the one that should go.
            _state.update { current ->
                if (current !is UiState.Success) current
                else {
                    val left = current.data.filterNot { it.id == reportId }
                    if (left.isEmpty()) UiState.Empty else UiState.Success(left)
                }
            }
        }
    }
}

/** Who may moderate, added by the address they gave you. */
@HiltViewModel
class AdminModeratorsViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<List<UserDto>>>(UiState.Loading)
    val state: StateFlow<UiState<List<UserDto>>> = _state.asStateFlow()

    private val _email = MutableStateFlow("")
    val email: StateFlow<String> = _email.asStateFlow()

    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    private val _busy = MutableStateFlow(false)
    val busy: StateFlow<Boolean> = _busy.asStateFlow()

    init {
        load()
    }

    fun setEmail(value: String) {
        _email.value = value
    }

    fun dismissMessage() {
        _message.value = null
    }

    fun load() {
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val result = repository.moderators()) {
                is AppResult.Success ->
                    if (result.data.isEmpty()) UiState.Empty else UiState.Success(result.data)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    fun add() {
        val address = _email.value.trim()
        if (address.isBlank()) return

        viewModelScope.launch {
            _busy.value = true

            when (val result = repository.addModerator(address)) {
                is AppResult.Success -> {
                    _email.value = ""
                    load()
                }

                // The server's own sentence: "no account with that address"
                // and "already an administrator" are both worth reading, and
                // neither has a useful generic equivalent.
                is AppResult.Failure -> _message.value = result.error.technical
            }

            _busy.value = false
        }
    }

    fun remove(userId: Long) {
        viewModelScope.launch {
            if (repository.removeModerator(userId) is AppResult.Success) load()
        }
    }
}

/** Where every client is told to connect, and whether sign-ups are open. */
@HiltViewModel
class AdminServerViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<AdminClientConfigDto>>(UiState.Loading)
    val state: StateFlow<UiState<AdminClientConfigDto>> = _state.asStateFlow()

    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    init {
        load()
    }

    fun dismissMessage() {
        _message.value = null
    }

    fun load() {
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val result = repository.clientConfig()) {
                is AppResult.Success -> UiState.Success(result.data)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    fun save(apiBase: String, registrationOpen: Boolean, savedMessage: String) {
        viewModelScope.launch {
            when (val result = repository.saveClientConfig(apiBase, registrationOpen)) {
                is AppResult.Success -> {
                    _state.value = UiState.Success(result.data)
                    _message.value = savedMessage
                }

                is AppResult.Failure -> _message.value = result.error.technical
            }
        }
    }
}

@HiltViewModel
class AdminAnnouncementsViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<List<AdminAnnouncementDto>>>(UiState.Loading)
    val state: StateFlow<UiState<List<AdminAnnouncementDto>>> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val result = repository.announcements()) {
                is AppResult.Success ->
                    if (result.data.isEmpty()) UiState.Empty else UiState.Success(result.data)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    fun save(title: String, body: String) {
        viewModelScope.launch {
            repository.saveAnnouncement(
                AdminAnnouncementDto(title = title, body = body, published = true)
            )
            load()
        }
    }

    fun delete(id: Long) {
        viewModelScope.launch {
            repository.deleteAnnouncement(id)
            load()
        }
    }
}

@HiltViewModel
class AdminLogsViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<List<LogEntryDto>>>(UiState.Loading)
    val state: StateFlow<UiState<List<LogEntryDto>>> = _state.asStateFlow()

    private val _level = MutableStateFlow("")
    val level: StateFlow<String> = _level.asStateFlow()

    init {
        load()
    }

    fun setLevel(value: String) {
        _level.value = if (_level.value == value) "" else value
        load()
    }

    fun load() {
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val result = repository.logs(level = _level.value)) {
                is AppResult.Success ->
                    if (result.data.items.isEmpty()) UiState.Empty else UiState.Success(result.data.items)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    fun clear() {
        viewModelScope.launch {
            repository.clearLogs()
            load()
        }
    }
}

@HiltViewModel
class AdminFontsViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<AdminFontListDto>>(UiState.Loading)
    val state: StateFlow<UiState<AdminFontListDto>> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val result = repository.fonts()) {
                is AppResult.Success ->
                    // Empty only when there is nothing to show at all. A
                    // library with no fonts but a list of families somebody
                    // needs to upload is the opposite of nothing to do.
                    if (result.data.fonts.isEmpty() && result.data.wanted.isEmpty()) {
                        UiState.Empty
                    } else {
                        UiState.Success(result.data)
                    }

                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    fun delete(id: Long) {
        viewModelScope.launch {
            repository.deleteFont(id)
            load()
        }
    }
}

/**
 * The frame catalogue, from the panel's side.
 *
 * Every change reloads the list rather than patching it in place. The list is
 * a few dozen rows and a reload is one request; keeping a local copy in step
 * with a server that also renames files and assigns ids would be more code
 * for a worse guarantee.
 */
@HiltViewModel
class AdminFramesViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<List<FrameDto>>>(UiState.Loading)
    val state: StateFlow<UiState<List<FrameDto>>> = _state.asStateFlow()

    /** True while a file is on its way up, so the add button waits its turn. */
    private val _busy = MutableStateFlow(false)
    val busy: StateFlow<Boolean> = _busy.asStateFlow()

    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            _state.value = when (val result = repository.frames()) {
                is AppResult.Success ->
                    if (result.data.isEmpty()) UiState.Empty else UiState.Success(result.data)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    fun upload(uris: List<Uri>, name: String, price: Int, rarity: String) {
        if (uris.isEmpty()) return

        viewModelScope.launch {
            _busy.value = true

            when (val result = repository.uploadFrames(uris, name, price, rarity)) {
                is AppResult.Success -> {
                    _message.value = if (result.data.size == 1) {
                        "${result.data.first().name} eklendi"
                    } else {
                        "${result.data.size} çerçeve eklendi"
                    }
                    load()
                }
                // The server's own words: it knows whether the file was the
                // wrong shape, the wrong format or simply too big, and each of
                // those needs a different thing done about it.
                is AppResult.Failure -> _message.value = describe(result.error)
            }

            _busy.value = false
        }
    }

    fun update(id: Long, name: String, price: Int, rarity: String, published: Boolean) {
        viewModelScope.launch {
            when (val result = repository.updateFrame(id, name, price, rarity, published)) {
                is AppResult.Success -> load()
                is AppResult.Failure -> _message.value = describe(result.error)
            }
        }
    }

    fun delete(id: Long) {
        viewModelScope.launch {
            when (val result = repository.deleteFrame(id)) {
                is AppResult.Success -> {
                    _message.value = "Çerçeve silindi"
                    load()
                }
                is AppResult.Failure -> _message.value = describe(result.error)
            }
        }
    }

    fun messageShown() {
        _message.value = null
    }

    private fun describe(error: AppError): String = error.reason() ?: when (error) {
        is AppError.Network -> "İnternet bağlantısı yok."
        is AppError.Timeout -> "Sunucu yanıt vermedi."
        else -> "Bir şeyler ters gitti."
    }
}

/**
 * The manga panel's state.
 *
 * The two long jobs — pulling the library across and copying its images —
 * run as a loop of small calls rather than one long one, because the other
 * end is a shared host whose proxy stops listening after about thirty
 * seconds. Each call reports where it got to and the next one carries on, so
 * a stall is one lost batch rather than a lost run.
 */
@Immutable
data class AdminMangaState(
    val bridgeUrl: String = "",
    val bridgeKey: String = "",
    val hasKey: Boolean = false,
    val site: String = "",
    val connecting: Boolean = false,
    val works: Int = 0,
    val chapters: Int = 0,
    val pages: Int = 0,
    val syncing: Boolean = false,
    val syncProgress: String = "",
    val mirrorTotal: Int = 0,
    val mirrored: Int = 0,
    val mirroring: Boolean = false,
    val query: String = "",
    val source: String = "tenrai",
    val galleryEnabled: Boolean = false,
    val searching: Boolean = false,
    val results: List<MangaSearchItemDto> = emptyList(),
    val importingId: Long = 0,
    /**
     * Why the last import run stopped, as the server put it.
     *
     * Kept on screen rather than shown once: a snackbar is gone before the
     * reason can be acted on, and "köprü anahtarı kabul edilmedi" is a thing
     * to read while fixing the field right above it.
     */
    val lastError: String = "",
)

@HiltViewModel
class AdminMangaViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(AdminMangaState())
    val state: StateFlow<AdminMangaState> = _state.asStateFlow()

    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    private var mirrorJob: Job? = null

    init {
        load()
        loadGallery()
    }

    fun setBridgeUrl(value: String) = _state.update { it.copy(bridgeUrl = value.trim()) }

    fun setBridgeKey(value: String) = _state.update { it.copy(bridgeKey = value.trim()) }

    fun setQuery(value: String) = _state.update { it.copy(query = value) }

    fun setSource(value: String) = _state.update { it.copy(source = value, results = emptyList()) }

    fun load() {
        viewModelScope.launch {
            (repository.mangaBridge() as? AppResult.Success)?.let { apply(it.data) }
        }
    }

    private fun loadGallery() {
        viewModelScope.launch {
            (repository.gallerySource() as? AppResult.Success)?.let { result ->
                _state.update { it.copy(galleryEnabled = result.data.enabled) }
            }
        }
    }

    fun connect() {
        val current = _state.value
        if (current.bridgeUrl.isBlank()) return

        viewModelScope.launch {
            _state.update { it.copy(connecting = true) }

            when (val result = repository.saveMangaBridge(current.bridgeUrl, current.bridgeKey)) {
                is AppResult.Success -> {
                    apply(result.data)
                    // Cleared once stored: it is never sent back, and leaving
                    // it on screen only invites it being pasted somewhere.
                    _state.update { it.copy(bridgeKey = "", connecting = false) }
                    _message.value = result.data.remote?.let {
                        "${it.site}: ${it.manga} manga, ${it.chapters} bölüm"
                    } ?: "Bağlandı"
                }

                is AppResult.Failure -> {
                    _state.update { it.copy(connecting = false) }
                    _message.value = describe(result.error)
                }
            }
        }
    }

    /**
     * Pull the library across, batch after batch, until the server says done.
     *
     * Looped here rather than on the server for one reason: a run that takes
     * four minutes cannot be one HTTP request on a shared host, and a job
     * queue would mean a progress screen that reads a table instead of a
     * reply. This way each batch's result is the progress report.
     */
    fun sync(reset: Boolean) {
        if (_state.value.syncing) return

        viewModelScope.launch {
            _state.update { it.copy(syncing = true, syncProgress = "", lastError = "") }

            var first = true
            while (true) {
                val result = repository.syncManga(reset = reset && first)
                first = false

                if (result !is AppResult.Success) {
                    val reason = describe((result as AppResult.Failure).error)
                    _message.value = reason
                    _state.update { it.copy(lastError = reason) }
                    break
                }

                val data = result.data
                _state.update {
                    it.copy(
                        works = data.counts.works,
                        chapters = data.counts.chapters,
                        pages = data.counts.pages,
                        mirrorTotal = data.mirror.total,
                        mirrored = data.mirror.mirrored,
                        syncProgress = "${data.page} / ${data.pages} sayfa · " +
                            data.imported.joinToString(", ") { row -> row.title }.take(80),
                    )
                }

                if (data.done) {
                    _message.value = "İçe aktarma tamamlandı"
                    break
                }
            }

            _state.update { it.copy(syncing = false) }

            // Copying is not an extra step to remember: a page that is still
            // on the source site does not open in the reader at all, because
            // those hosts refuse an app asking for their images directly. So
            // an import that worked runs straight into the copy.
            if (_state.value.lastError.isBlank() && _state.value.mirrorTotal > _state.value.mirrored) {
                mirror()
            }
        }
    }

    /**
     * Copy pages into our bucket until there are none left.
     *
     * Cancellable, because it is the long one: tens of thousands of images at
     * twenty-five a call. Stopping loses nothing — every copied page is
     * already recorded, and starting again picks up from the first one that
     * is not.
     */
    fun mirror() {
        if (_state.value.mirroring) return

        mirrorJob = viewModelScope.launch {
            _state.update { it.copy(mirroring = true) }

            while (true) {
                val result = repository.mirrorManga()

                if (result !is AppResult.Success) {
                    val reason = describe((result as AppResult.Failure).error)
                    _message.value = reason
                    _state.update { it.copy(lastError = reason) }
                    break
                }

                val data = result.data
                _state.update { it.copy(mirrorTotal = data.total, mirrored = data.mirrored) }

                if (data.failed.isNotEmpty()) {
                    // Named rather than counted: a page that will not copy is
                    // usually one URL that has rotted, and its address is the
                    // only thing that makes it findable.
                    _message.value = data.failed.first().message
                }

                if (data.done) {
                    _message.value = "Kopyalama tamamlandı"
                    break
                }

                // A batch that copied nothing and was not merely out of time
                // has nothing left it can do: asking again would hand back the
                // same rows and the same failures, forever.
                if (data.copied == 0 && !data.partial) {
                    val reason = data.failed.firstOrNull()?.message
                    _message.value = reason?.let { "Kopyalanamıyor: $it" } ?: "Kopyalanacak sayfa kalmadı"
                    if (reason != null) {
                        _state.update { it.copy(lastError = reason) }
                    }
                    break
                }
            }

            _state.update { it.copy(mirroring = false) }
        }
    }

    fun stopMirror() {
        mirrorJob?.cancel()
        _state.update { it.copy(mirroring = false) }
    }

    fun search() {
        val current = _state.value
        if (current.query.isBlank()) return

        viewModelScope.launch {
            _state.update { it.copy(searching = true) }

            when (val result = repository.searchManga(current.query, current.source)) {
                is AppResult.Success ->
                    _state.update { it.copy(searching = false, results = result.data) }

                is AppResult.Failure -> {
                    _state.update { it.copy(searching = false) }
                    _message.value = describe(result.error)
                }
            }
        }
    }

    fun import(item: MangaSearchItemDto) {
        viewModelScope.launch {
            _state.update { it.copy(importingId = item.id) }

            when (val result = repository.importManga(item.id, item.source)) {
                is AppResult.Success -> {
                    _message.value = if (result.data.created) {
                        "${item.title} eklendi"
                    } else {
                        "${item.title} güncellendi"
                    }
                    load()

                    // Its pages are still on the source, which will not serve
                    // them to the app. Copying them is the difference between
                    // a manga that opens and one that does not, so it is not
                    // left as a button to remember.
                    if (result.data.chapters > 0) {
                        mirror()
                    }
                }

                is AppResult.Failure -> _message.value = describe(result.error)
            }

            _state.update { it.copy(importingId = 0) }
        }
    }

    fun setGalleryEnabled(enabled: Boolean) {
        _state.update { it.copy(galleryEnabled = enabled) }

        viewModelScope.launch {
            // The switch moves first because that is what a switch should do,
            // but a refused save has to be visible: leaving it flipped on a
            // server that never stored it made the next search fail with
            // "bu kaynak kapalı" over a control that plainly said it was open.
            when (val result = repository.saveGallerySource(enabled, "")) {
                is AppResult.Success ->
                    _state.update { it.copy(galleryEnabled = result.data.enabled) }

                is AppResult.Failure -> {
                    _state.update { it.copy(galleryEnabled = !enabled) }
                    _message.value = describe(result.error)
                }
            }
        }
    }

    fun messageShown() {
        _message.value = null
    }

    private fun apply(data: MangaBridgeDto) {
        _state.update {
            it.copy(
                bridgeUrl = data.url.ifBlank { it.bridgeUrl },
                hasKey = data.hasKey,
                site = data.site,
                works = data.counts.works,
                chapters = data.counts.chapters,
                pages = data.counts.pages,
                mirrorTotal = data.mirror.total,
                mirrored = data.mirror.mirrored,
                lastError = data.sync.lastError,
            )
        }
    }

    private fun describe(error: AppError): String = error.reason() ?: when (error) {
        is AppError.Network -> "İnternet bağlantısı yok."
        is AppError.Timeout -> "Sunucu yanıt vermedi."
        else -> "Bir şeyler ters gitti."
    }
}

/* ── Manga chapters and their pages ──────────────────────────────────── */

/** One chapter, as the list shows it. */
@Immutable
data class ChapterRow(
    val id: Long,
    val number: String,
    val title: String,
    val pageCount: Int,
) {
    /** "Bölüm 10.5", or the chapter's own title when it has one. */
    val label: String get() = title.ifBlank { "Bölüm $number" }
}

@Immutable
data class AdminChaptersState(
    val title: String = "",
    val chapters: List<ChapterRow> = emptyList(),
    val loading: Boolean = true,
    val saving: Boolean = false,
    val lastError: String = "",
) {
    /**
     * What to put in the box when adding one.
     *
     * The next whole number after the highest chapter, because that is what
     * is being added almost every time.
     */
    fun nextNumber(): String {
        val highest = chapters.mapNotNull { it.number.toDoubleOrNull() }.maxOrNull() ?: 0.0
        return (highest.toInt() + 1).toString()
    }
}

@HiltViewModel
class AdminChaptersViewModel @Inject constructor(
    private val repository: AdminRepository,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    private val workId: Long = savedStateHandle["workId"] ?: 0L

    private val _state = MutableStateFlow(AdminChaptersState())
    val state: StateFlow<AdminChaptersState> = _state.asStateFlow()

    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            _state.update { it.copy(loading = true) }

            when (val result = repository.chapters(workId)) {
                is AppResult.Success -> _state.update {
                    it.copy(
                        title = result.data.work.title,
                        chapters = result.data.items.map { row ->
                            ChapterRow(
                                id = row.id,
                                number = row.numberLabel.ifBlank { row.number.toString() },
                                title = row.title,
                                pageCount = row.pageCount,
                            )
                        },
                        loading = false,
                        lastError = "",
                    )
                }

                is AppResult.Failure -> {
                    val reason = describeAdmin(result.error)
                    _message.value = reason
                    _state.update { it.copy(loading = false, lastError = reason) }
                }
            }
        }
    }

    /**
     * Add a chapter or rename one.
     *
     * The number is read leniently — `10,5` is how it is typed on a Turkish
     * keyboard — and a number already in use lands on that chapter rather
     * than making a second one with the same name.
     */
    fun save(chapterId: Long, number: String, title: String) {
        val parsed = number.trim().replace(',', '.').toDoubleOrNull()

        if (parsed == null || parsed <= 0.0) {
            _message.value = "Bölüm numarası bir sayı olmalı."
            return
        }

        viewModelScope.launch {
            _state.update { it.copy(saving = true) }

            when (val result = repository.saveChapter(workId, parsed, title.trim(), chapterId)) {
                is AppResult.Success -> {
                    _message.value = if (chapterId > 0) "Bölüm güncellendi" else "Bölüm eklendi"
                    load()
                }

                is AppResult.Failure -> {
                    val reason = describeAdmin(result.error)
                    _message.value = reason
                    _state.update { it.copy(lastError = reason) }
                }
            }

            _state.update { it.copy(saving = false) }
        }
    }

    fun delete(chapterId: Long) {
        viewModelScope.launch {
            when (val result = repository.deleteChapter(chapterId)) {
                is AppResult.Success -> {
                    _message.value = "Bölüm silindi"
                    load()
                }

                is AppResult.Failure -> _message.value = describeAdmin(result.error)
            }
        }
    }

    fun messageShown() {
        _message.value = null
    }
}

@Immutable
data class AdminChapterPagesState(
    val pages: Int = 0,
    val uploading: Boolean = false,
    val failed: List<PageFailureDto> = emptyList(),
)

@HiltViewModel
class AdminChapterPagesViewModel @Inject constructor(
    private val repository: AdminRepository,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    private val chapterId: Long = savedStateHandle["chapterId"] ?: 0L

    private val _state = MutableStateFlow(AdminChapterPagesState())
    val state: StateFlow<AdminChapterPagesState> = _state.asStateFlow()

    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            (repository.chapterPages(chapterId) as? AppResult.Success)?.let { result ->
                _state.update { it.copy(pages = result.data.size) }
            }
        }
    }

    /**
     * Send whatever was picked.
     *
     * Loose images or a zip, in one request either way. The order is settled
     * by the file names on the server, not by the order the picker returned.
     */
    fun upload(uris: List<Uri>) {
        if (_state.value.uploading) return

        viewModelScope.launch {
            _state.update { it.copy(uploading = true, failed = emptyList()) }

            when (val result = repository.uploadPages(chapterId, uris)) {
                is AppResult.Success -> {
                    val data = result.data
                    _state.update { it.copy(pages = data.pages, failed = data.failed) }
                    _message.value = when {
                        data.failed.isEmpty() -> "${data.written} sayfa yüklendi"
                        data.written == 0 -> "Hiçbir sayfa yüklenemedi"
                        else -> "${data.written} sayfa yüklendi, ${data.failed.size} tanesi olmadı"
                    }
                }

                is AppResult.Failure -> _message.value = describeAdmin(result.error)
            }

            _state.update { it.copy(uploading = false) }
        }
    }

    fun clear() {
        viewModelScope.launch {
            when (val result = repository.clearChapterPages(chapterId)) {
                is AppResult.Success -> {
                    _state.update { it.copy(pages = 0, failed = emptyList()) }
                    _message.value = "Sayfalar silindi"
                }

                is AppResult.Failure -> _message.value = describeAdmin(result.error)
            }
        }
    }

    fun messageShown() {
        _message.value = null
    }
}

/**
 * The sentence an admin screen shows for a failure.
 *
 * The server's own words when it wrote any — "Bölüm bulunamadı", "Önce
 * depolama ayarlarını yap" — because on this side of the app the person
 * reading is the one who can act on them.
 */
internal fun describeAdmin(error: AppError): String = error.reason() ?: when (error) {
    is AppError.Network -> "İnternet bağlantısı yok."
    is AppError.Timeout -> "Sunucu yanıt vermedi."
    else -> "Bir şeyler ters gitti."
}
