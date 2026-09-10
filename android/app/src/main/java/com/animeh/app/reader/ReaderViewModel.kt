package com.animeh.app.reader

import androidx.compose.runtime.Immutable
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppError
import com.animeh.app.core.AppResult
import com.animeh.app.core.UiState
import com.animeh.app.data.remote.dto.ChapterPagesDto
import com.animeh.app.data.remote.dto.PageDto
import com.animeh.app.data.repository.LibraryRepository
import com.animeh.app.data.repository.MangaRepository
import com.animeh.app.domain.Episode
import com.animeh.app.domain.Work
import com.animeh.app.domain.toDomain
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

@Immutable
data class ReaderState(
    val work: Work? = null,
    val chapter: Episode? = null,
    val pages: List<PageDto> = emptyList(),
    val next: Episode? = null,
    val previous: Episode? = null,
    /** The page the reader was last on, one-based. */
    val resumeAt: Int = 1,
)

/**
 * The reader's state, and the progress it reports.
 *
 * ### Why a chapter reports seconds
 *
 * The history table counts seconds, because until now everything in it was
 * watched. A chapter has pages instead — so a page is reported as a second,
 * and a chapter's "length" is its page count. That is not a fudge to make the
 * numbers fit: it makes one chapter worth one unit of progress in exactly the
 * way one episode is, which is what lets reading count towards the same
 * history, the same "continue", the same twenty points and the same
 * standings, without a second set of tables that would have to be joined into
 * every one of those.
 *
 * The one place it shows is the profile's "time watched", where a chapter adds
 * as many seconds as it has pages. Twenty pages is twenty seconds against
 * totals measured in hours; it is not a number anybody will notice, and the
 * alternative was a reader that earned nothing.
 */
@HiltViewModel
class ReaderViewModel @Inject constructor(
    private val manga: MangaRepository,
    private val library: LibraryRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<ReaderState>>(UiState.Loading)
    val state: StateFlow<UiState<ReaderState>> = _state.asStateFlow()

    /** Which page is under the reader's eye, one-based. */
    private val _page = MutableStateFlow(1)
    val page: StateFlow<Int> = _page.asStateFlow()

    private var chapterId: Long = 0
    private var furthest: Int = 0
    private var reportJob: Job? = null

    fun open(id: Long) {
        if (id <= 0 || (id == chapterId && _state.value is UiState.Success)) return

        chapterId = id
        furthest = 0
        _page.value = 1
        _state.value = UiState.Loading

        viewModelScope.launch {
            _state.value = when (val result = manga.pages(id)) {
                is AppResult.Success -> UiState.Success(result.data.toState())
                is AppResult.Failure -> UiState.Error(result.error)
            }

            (_state.value as? UiState.Success)?.data?.let { state ->
                _page.value = state.resumeAt
                furthest = state.resumeAt
            }
        }
    }

    /**
     * Called as the list scrolls.
     *
     * The furthest page reached is what counts, not the current one: scrolling
     * back to re-read something should not take progress away, exactly as
     * seeking backwards in an episode does not.
     */
    fun onPage(position: Int) {
        if (position <= 0) return

        _page.value = position

        if (position > furthest) {
            furthest = position
            scheduleReport()
        }
    }

    /**
     * Report progress, but not on every page turn.
     *
     * Somebody flicking through a chapter turns twenty pages in ten seconds,
     * and twenty writes for one chapter is twenty round trips for a number
     * that only has to be roughly right. The delay collapses a burst into one.
     */
    private fun scheduleReport() {
        val state = (_state.value as? UiState.Success)?.data ?: return
        val chapter = state.chapter ?: return

        reportJob?.cancel()
        reportJob = viewModelScope.launch {
            delay(REPORT_DELAY_MS)
            manga.reportProgress(
                workId = chapter.workId,
                chapterId = chapter.id,
                page = furthest,
                pages = state.pages.size,
            )
        }
    }

    /** Send whatever has not been sent, on the way out. */
    fun flush() {
        val state = (_state.value as? UiState.Success)?.data ?: return
        val chapter = state.chapter ?: return

        reportJob?.cancel()
        viewModelScope.launch {
            manga.reportProgress(
                workId = chapter.workId,
                chapterId = chapter.id,
                page = furthest,
                pages = state.pages.size,
            )
        }
    }

    fun toggleFavourite() {
        val work = (_state.value as? UiState.Success)?.data?.work ?: return

        viewModelScope.launch { library.toggleFavorite(work.id, !work.isFavorite) }
    }

    private fun ChapterPagesDto.toState(): ReaderState {
        val chapter = this.chapter.toDomain()
        val total = pages.size

        return ReaderState(
            work = work.toDomain(),
            chapter = chapter,
            pages = pages,
            next = next?.toDomain(),
            previous = previous?.toDomain(),
            // Where they left off, clamped to a page that exists — a chapter
            // whose pages were re-imported can be shorter than it was.
            resumeAt = progress
                ?.takeIf { !it.completed }
                ?.position
                ?.coerceIn(1, maxOf(1, total))
                ?: 1,
        )
    }

    private companion object {
        const val REPORT_DELAY_MS = 1500L
    }
}
