package com.animeh.app.ui.screens.detail

import androidx.annotation.StringRes
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.R
import com.animeh.app.core.AppError
import com.animeh.app.core.AppResult
import com.animeh.app.core.UiState
import com.animeh.app.data.prefs.SettingsStore
import com.animeh.app.data.remote.dto.ReviewDto
import com.animeh.app.data.repository.CatalogRepository
import com.animeh.app.data.repository.CommunityRepository
import com.animeh.app.data.repository.LibraryRepository
import com.animeh.app.domain.Episode
import com.animeh.app.domain.Work
import com.animeh.app.social.WatchPartySession
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

data class DetailUiState(
    val work: UiState<Work> = UiState.Loading,
    val episodes: UiState<List<Episode>> = UiState.Loading,
    val selectedSeason: Int = 1,
    val isFavorite: Boolean = false,
    val inWatchlist: Boolean = false,
    val following: Boolean = false,
    val fromCache: Boolean = false,
    val reviews: List<ReviewDto> = emptyList(),
    val myReview: ReviewDto? = null,
    val rating: Double = 0.0,
    val ratingCount: Int = 0,
    /**
     * A one-shot line for the snackbar.
     *
     * Two fields rather than one because the two cases differ in where the
     * words come from: success is this app's own string, and a failure is
     * usually a sentence the server wrote — which is more useful than any
     * generic one here.
     */
    @StringRes val messageRes: Int? = null,
    val messageText: String? = null,
    /**
     * Whether the adult-content warning is on screen.
     *
     * Raised when the page opens rather than when something is tapped: the
     * point is to say what this series is before somebody reads the synopsis
     * and looks at the artwork, not to interrupt them on the way to the video.
     * Answered once per series and remembered, so it is a statement about the
     * series rather than a toll on every visit.
     */
    val adultWarning: Boolean = false,
)

@HiltViewModel
class DetailViewModel @Inject constructor(
    private val catalogRepository: CatalogRepository,
    private val libraryRepository: LibraryRepository,
    private val community: CommunityRepository,
    private val party: WatchPartySession,
    private val settingsStore: SettingsStore,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    private val workId: Long = savedStateHandle["workId"] ?: 0L

    private val _state = MutableStateFlow(DetailUiState())
    val state: StateFlow<DetailUiState> = _state.asStateFlow()

    /** Display names, for the genre chips on this screen. */
    val labels = community.terms

    init {
        load()
        observeLibrary()
        loadReviews()
    }

    fun loadReviews() {
        viewModelScope.launch {
            // Silent on failure: reviews are below the fold, and an error
            // banner over the episode list for them would be out of proportion.
            val result = community.reviews(workId)

            if (result is AppResult.Success) {
                _state.update {
                    it.copy(
                        reviews = result.data.items,
                        myReview = result.data.mine,
                        rating = result.data.rating,
                        ratingCount = result.data.ratingCount,
                    )
                }
            }
        }
    }

    fun submitReview(score: Int, body: String, spoiler: Boolean) {
        viewModelScope.launch {
            if (community.save(workId, score, body, spoiler) is AppResult.Success) {
                // Refetched rather than patched in: posting changes the average
                // and the ordering, neither of which can be worked out here.
                loadReviews()
            }
        }
    }

    fun deleteReview(review: ReviewDto) {
        viewModelScope.launch {
            if (community.delete(review.id) is AppResult.Success) loadReviews()
        }
    }

    /**
     * Send a report to the moderators.
     *
     * The list is left alone afterwards. Reporting is not a moderation
     * decision — the review stays visible until someone with the capability
     * decides otherwise, and hiding it here would tell the reporter they had
     * more power than they do.
     */
    fun report(review: ReviewDto, reason: String, note: String) {
        viewModelScope.launch {
            val result = community.report(review.id, reason, note)

            _state.update {
                when (result) {
                    is AppResult.Success ->
                        it.copy(messageRes = R.string.report_sent, messageText = null)

                    is AppResult.Failure -> {
                        val error = result.error
                        it.copy(
                            messageRes = if (error is AppError.Message) null else error.messageRes,
                            messageText = (error as? AppError.Message)?.text,
                        )
                    }
                }
            }
        }
    }

    fun dismissMessage() {
        _state.update { it.copy(messageRes = null, messageText = null) }
    }

    /** "Devam et": remember this series was accepted, and get on with it. */
    fun acknowledgeAdult() {
        _state.update { it.copy(adultWarning = false) }

        viewModelScope.launch { settingsStore.acknowledgeAdult(workId) }
    }

    /** "Vazgeç": the screen leaves. Nothing is remembered. */
    fun dismissAdult() {
        _state.update { it.copy(adultWarning = false) }
    }

    /**
     * Raise the warning if this series is flagged and has not been accepted.
     *
     * Called once the work has loaded, because until then there is nothing to
     * warn about.
     */
    private fun maybeWarn(work: Work) {
        if (!work.adult) return

        viewModelScope.launch {
            if (workId !in settingsStore.acknowledgedAdult.first()) {
                _state.update { it.copy(adultWarning = true) }
            }
        }
    }

    /**
     * Open a watch party on whatever would be played next.
     *
     * The room is opened on a concrete episode rather than on the series: the
     * whole point is that two people are at the same moment of the same video,
     * and "the same series" is not that.
     *
     * @return true when a room was opened and the room screen should show.
     */
    suspend fun openRoom(): Boolean {
        val episode = nextEpisodeToPlay() ?: return false

        return when (val result = party.open(episode.id)) {
            is AppResult.Success -> true

            is AppResult.Failure -> {
                _state.update {
                    it.copy(
                        messageRes = if (result.error is AppError.Message) null else result.error.messageRes,
                        messageText = (result.error as? AppError.Message)?.text,
                    )
                }
                false
            }
        }
    }

    /** Whether this install can offer watch parties at all. */
    val partyAvailable = party.available

    fun vote(review: ReviewDto, vote: Int) {
        viewModelScope.launch {
            val result = community.vote(review.id, vote)

            // One row changed, so only that row is replaced — refetching would
            // reorder the list under a thumb that just tapped it.
            if (result is AppResult.Success) {
                val updated = result.data ?: return@launch
                _state.update { current ->
                    current.copy(
                        reviews = current.reviews.map { if (it.id == updated.id) updated else it }
                    )
                }
            }
        }
    }

    fun load() {
        viewModelScope.launch {
            when (val result = catalogRepository.work(workId.toString())) {
                is AppResult.Success -> {
                    val work = result.data.value
                    _state.update {
                        it.copy(
                            work = UiState.Success(work, result.data.fromCache),
                            fromCache = result.data.fromCache,
                            isFavorite = work.isFavorite,
                            inWatchlist = work.inWatchlist,
                            following = work.following,
                            selectedSeason = work.seasons.firstOrNull()?.number ?: 1,
                        )
                    }
                    maybeWarn(work)
                    loadEpisodes(work.seasons.firstOrNull()?.number ?: 0)
                }
                is AppResult.Failure -> _state.update { it.copy(work = UiState.Error(result.error)) }
            }
        }
    }

    fun selectSeason(season: Int) {
        _state.update { it.copy(selectedSeason = season, episodes = UiState.Loading) }
        loadEpisodes(season)
    }

    fun toggleFavorite() {
        val wanted = !_state.value.isFavorite
        // Flipped immediately; the repository puts it back if the server refuses.
        _state.update { it.copy(isFavorite = wanted) }

        viewModelScope.launch {
            val result = libraryRepository.toggleFavorite(workId, wanted)
            if (result is AppResult.Failure) {
                _state.update { it.copy(isFavorite = !wanted) }
            }
        }
    }

    fun toggleWatchlist() {
        val wanted = !_state.value.inWatchlist
        _state.update { it.copy(inWatchlist = wanted) }

        viewModelScope.launch {
            val result = libraryRepository.toggleWatchlist(workId, wanted)
            if (result is AppResult.Failure) {
                _state.update { it.copy(inWatchlist = !wanted) }
            }
        }
    }

    /**
     * The bell.
     *
     * Turning it on is a promise the server keeps: publishing an episode
     * reads this list and notifies everyone on it. Nothing is scheduled or
     * polled on the phone — a device that is asleep still gets told.
     */
    fun toggleFollow() {
        val wanted = !_state.value.following
        _state.update { it.copy(following = wanted) }

        viewModelScope.launch {
            val result = libraryRepository.toggleFollow(workId, wanted)
            if (result is AppResult.Failure) {
                _state.update { it.copy(following = !wanted) }
            }
        }
    }

    /** The episode "İzle" should open: the first unfinished one. */
    fun nextEpisodeToPlay(): Episode? {
        val episodes = (_state.value.episodes as? UiState.Success)?.data ?: return null

        // An imported series has a row for every episode its metadata knows
        // about, and only some of them have a file behind them. Picking one of
        // the others opens a player with nothing to play — and, when it is a
        // watch party being opened, puts everybody in the room in front of it.
        //
        // So: unwatched is the preference, playable is the rule. The fallback
        // covers a list that reports no sources at all — an older server, or
        // the offline copy — where the old behaviour is still the best guess.
        val candidates = episodes.filter { it.videoSourceCount > 0 }.ifEmpty { episodes }

        return candidates.firstOrNull { it.progress?.completed != true }
            ?: candidates.firstOrNull()
    }

    private fun loadEpisodes(season: Int) {
        viewModelScope.launch {
            when (val result = catalogRepository.episodes(workId, season)) {
                is AppResult.Success -> {
                    val episodes = result.data.value
                    _state.update {
                        it.copy(
                            episodes = if (episodes.isEmpty()) UiState.Empty
                            else UiState.Success(episodes, result.data.fromCache)
                        )
                    }
                }
                is AppResult.Failure -> _state.update { it.copy(episodes = UiState.Error(result.error)) }
            }
        }
    }

    private fun observeLibrary() {
        viewModelScope.launch {
            libraryRepository.isFavorite(workId).collect { value ->
                _state.update { it.copy(isFavorite = value) }
            }
        }
        viewModelScope.launch {
            libraryRepository.isInWatchlist(workId).collect { value ->
                _state.update { it.copy(inWatchlist = value) }
            }
        }
        viewModelScope.launch {
            libraryRepository.isFollowing(workId).collect { value ->
                _state.update { it.copy(following = value) }
            }
        }
    }
}
