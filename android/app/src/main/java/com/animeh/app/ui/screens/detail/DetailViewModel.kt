package com.animeh.app.ui.screens.detail

import androidx.annotation.StringRes
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.R
import com.animeh.app.core.AppError
import com.animeh.app.core.AppResult
import com.animeh.app.core.UiState
import com.animeh.app.data.remote.dto.ReviewDto
import com.animeh.app.data.repository.CatalogRepository
import com.animeh.app.data.repository.CommunityRepository
import com.animeh.app.data.repository.LibraryRepository
import com.animeh.app.domain.Episode
import com.animeh.app.domain.Work
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

data class DetailUiState(
    val work: UiState<Work> = UiState.Loading,
    val episodes: UiState<List<Episode>> = UiState.Loading,
    val selectedSeason: Int = 1,
    val isFavorite: Boolean = false,
    val inWatchlist: Boolean = false,
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
)

@HiltViewModel
class DetailViewModel @Inject constructor(
    private val catalogRepository: CatalogRepository,
    private val libraryRepository: LibraryRepository,
    private val community: CommunityRepository,
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
                            selectedSeason = work.seasons.firstOrNull()?.number ?: 1,
                        )
                    }
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

    /** The episode "İzle" should open: the first unfinished one. */
    fun nextEpisodeToPlay(): Episode? {
        val episodes = (_state.value.episodes as? UiState.Success)?.data ?: return null

        return episodes.firstOrNull { it.progress?.completed != true }
            ?: episodes.firstOrNull()
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
    }
}
