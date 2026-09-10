package com.animeh.app.data.repository

import com.animeh.app.core.AppResult
import com.animeh.app.data.remote.ApiErrorMapper
import com.animeh.app.data.remote.UserApi
import com.animeh.app.data.remote.dto.ChapterPagesDto
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Reading.
 *
 * Thin on purpose. A manga is a work and a chapter is one of its episodes, so
 * everything about browsing, favouriting, history and points already goes
 * through [CatalogRepository] and [LibraryRepository]; the only thing that has
 * no equivalent is a chapter's page list, and the only thing that needs
 * translating is progress.
 */
@Singleton
class MangaRepository @Inject constructor(
    private val userApi: UserApi,
    private val library: LibraryRepository,
) {

    suspend fun pages(chapterId: Long): AppResult<ChapterPagesDto> =
        ApiErrorMapper.call { userApi.chapterPages(chapterId) }

    /**
     * Record how far into a chapter somebody has read.
     *
     * A page counts as a second. The history table measures seconds because
     * everything in it used to be watched; rather than a parallel set of
     * tables for reading — which would then have to be joined into "continue",
     * the profile, the points ledger and three leaderboards — a chapter simply
     * declares itself as many seconds long as it has pages.
     *
     * The consequence is that finishing a chapter clears the same completion
     * test an episode does, and therefore earns the same twenty points. That
     * is the intended behaviour: a chapter read is a thing finished.
     */
    suspend fun reportProgress(workId: Long, chapterId: Long, page: Int, pages: Int) {
        if (chapterId <= 0 || pages <= 0) return

        val reached = page.coerceIn(1, pages)

        library.recordProgress(
            episodeId = chapterId,
            workId = workId,
            positionSeconds = reached,
            durationSeconds = pages,
            // The furthest page reached, which is what the caller passes: a
            // reader who scrolls back has not un-read anything.
            watchedSeconds = reached,
        )
    }
}
