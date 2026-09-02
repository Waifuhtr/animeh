package com.animeh.app.ui.screens.admin

import android.net.Uri
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppError
import com.animeh.app.core.AppResult
import com.animeh.app.core.UiState
import com.animeh.app.data.remote.dto.*
import com.animeh.app.data.repository.AdminRepository
import com.animeh.app.domain.Episode
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

    private var searchJob: Job? = null

    init {
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
            _state.value = when (val result = repository.works(_query.value)) {
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

    private val _form = MutableStateFlow(AdminWorkRequest(id = workId.takeIf { it > 0 }))
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
            when (val result = repository.works("")) {
                is AppResult.Success -> {
                    result.data.firstOrNull { it.id == workId }?.let { work ->
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
                            genres = work.genres,
                            totalEpisodes = work.totalEpisodes,
                            published = work.published,
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

        if (value.length < MIN_QUERY) {
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

    private var searchJob: Job? = null

    init {
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

    private val _state = MutableStateFlow<UiState<List<AdminFontDto>>>(UiState.Loading)
    val state: StateFlow<UiState<List<AdminFontDto>>> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val result = repository.fonts()) {
                is AppResult.Success ->
                    if (result.data.isEmpty()) UiState.Empty else UiState.Success(result.data)
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
