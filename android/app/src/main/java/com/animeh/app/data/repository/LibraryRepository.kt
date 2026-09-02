package com.animeh.app.data.repository

import com.animeh.app.core.AppResult
import com.animeh.app.data.local.dao.LibraryDao
import com.animeh.app.data.local.dao.ProgressDao
import com.animeh.app.data.local.dao.WorkDao
import com.animeh.app.data.local.entity.ProgressEntity
import com.animeh.app.data.remote.ApiErrorMapper
import com.animeh.app.data.remote.UserApi
import com.animeh.app.data.remote.dto.ProgressRequest
import com.animeh.app.domain.*
import com.animeh.app.player.WatchProgress
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Favourites, the watchlist, and watch progress.
 *
 * Progress is written locally first and pushed to the server after. That
 * ordering is deliberate: the player reports a position every few seconds and
 * on a weak connection most of those calls will fail. Writing locally means the
 * resume point is always right on this device, and [syncPending] catches the
 * server up when the connection returns.
 *
 * Library changes are optimistic in the same way — the heart fills immediately
 * — but they are reverted if the server refuses, because a favourite that
 * silently did not save is worse than one that visibly failed.
 */
@Singleton
class LibraryRepository @Inject constructor(
    private val userApi: UserApi,
    private val libraryDao: LibraryDao,
    private val progressDao: ProgressDao,
    private val workDao: WorkDao,
) {

    fun isFavorite(workId: Long): Flow<Boolean> =
        libraryDao.isInList(workId, LIST_FAVORITE).map { it > 0 }

    fun isInWatchlist(workId: Long): Flow<Boolean> =
        libraryDao.isInList(workId, LIST_WATCHLIST).map { it > 0 }

    /**
     * A list as the screen should render it, read from the local table.
     *
     * The library screen renders this rather than the last network response,
     * which is what makes a title favourited on the detail screen appear here
     * at once: [setInList] writes the row, and Room re-emits. Reading the
     * network answer instead meant the list only changed on the next cold
     * start, because the screen's model outlives a tab switch.
     */
    fun observe(list: String): Flow<List<Work>> =
        libraryDao.workIds(list).map { ids ->
            // The ids carry the order (most recently added first); `byIds`
            // does not, so it is reapplied here.
            val order = ids.withIndex().associate { (index, id) -> id to index }
            workDao.byIds(ids)
                .map { it.toDomain() }
                .sortedBy { order[it.id] ?: Int.MAX_VALUE }
        }

    suspend fun list(list: String, page: Int = 1): AppResult<List<Work>> {
        val result = ApiErrorMapper.call({ dto -> dto.items.map { it.toDomain() } }) {
            userApi.library(list, page)
        }

        return when (result) {
            is AppResult.Success -> {
                workDao.upsert(result.data.map { it.toEntity() })
                if (page == 1) {
                    // Replaced rather than merged, so an entry removed on
                    // another device disappears here too.
                    libraryDao.clearList(list)
                    libraryDao.upsertAll(result.data.map { libraryEntry(it.id, list) })
                }
                result
            }
            is AppResult.Failure -> {
                // The cached ids are the offline answer; the works behind them
                // were cached when the list was last fetched. A one-shot query,
                // not the Flow — a Room Flow never completes, so collecting one
                // here would hang rather than return.
                val ids = libraryDao.workIdsOnce(list)
                val order = ids.withIndex().associate { (index, id) -> id to index }
                val cached = workDao.byIds(ids)
                    .map { it.toDomain() }
                    .sortedBy { order[it.id] ?: Int.MAX_VALUE }

                if (cached.isEmpty()) result else AppResult.Success(cached)
            }
        }
    }

    suspend fun setInList(workId: Long, list: String, wanted: Boolean): AppResult<Unit> {
        // Optimistic: the icon changes now, and is put back if the server says no.
        if (wanted) libraryDao.upsert(libraryEntry(workId, list)) else libraryDao.remove(workId, list)

        val result = ApiErrorMapper.call({ Unit }) {
            if (wanted) userApi.addToLibrary(workId, list) else userApi.removeFromLibrary(workId, list)
        }

        if (result is AppResult.Failure) {
            if (wanted) libraryDao.remove(workId, list) else libraryDao.upsert(libraryEntry(workId, list))
        }

        return result
    }

    suspend fun toggleFavorite(workId: Long, wanted: Boolean) = setInList(workId, LIST_FAVORITE, wanted)

    suspend fun toggleWatchlist(workId: Long, wanted: Boolean) = setInList(workId, LIST_WATCHLIST, wanted)

    suspend fun history(page: Int = 1): AppResult<List<ContinueItem>> =
        ApiErrorMapper.call({ dto -> dto.items.map { it.toDomain() } }) { userApi.history(page) }

    suspend fun continueWatching(): AppResult<List<ContinueItem>> =
        ApiErrorMapper.call({ dto -> dto.items.map { it.toDomain() } }) { userApi.continueWatching() }

    /**
     * Record where the viewer is.
     *
     * Always succeeds locally. The network write is best-effort and its failure
     * is not reported: a player that surfaced an error every few seconds on a
     * weak connection would be unusable, and [syncPending] will catch up.
     */
    suspend fun recordProgress(
        episodeId: Long,
        workId: Long,
        positionSeconds: Int,
        durationSeconds: Int,
        watchedSeconds: Int = 0,
    ) {
        val existing = progressDao.byEpisode(episodeId)

        // Only ever grows, matching the server: a report that arrives out of
        // order must not take away time already earned.
        val watched = maxOf(watchedSeconds, existing?.watchedSeconds ?: 0)

        // Decided from time played, never from the playhead — dragging to the
        // credits moves the position without watching any of it.
        val completed = WatchProgress.isComplete(watched, durationSeconds)

        progressDao.upsert(
            ProgressEntity(
                episodeId = episodeId,
                workId = workId,
                positionSeconds = positionSeconds,
                durationSeconds = durationSeconds,
                watchedSeconds = watched,
                // Sticky, matching the server: finishing an episode and then
                // scrubbing back must not mark it unwatched.
                completed = completed || (existing?.completed == true),
                updatedAt = System.currentTimeMillis(),
                synced = false,
            )
        )

        val pushed = ApiErrorMapper.call({ Unit }) {
            userApi.recordProgress(
                ProgressRequest(episodeId, positionSeconds, durationSeconds, watched)
            )
        }

        if (pushed is AppResult.Success) {
            progressDao.markSynced(episodeId, System.currentTimeMillis())
        }
    }

    /** Push positions the server has not heard about. Called on reconnect. */
    suspend fun syncPending(): Int {
        var pushed = 0

        for (entry in progressDao.unsynced()) {
            val result = ApiErrorMapper.call({ Unit }) {
                userApi.recordProgress(
                    ProgressRequest(
                        entry.episodeId,
                        entry.positionSeconds,
                        entry.durationSeconds,
                        entry.watchedSeconds,
                    )
                )
            }

            if (result is AppResult.Success) {
                progressDao.markSynced(entry.episodeId, entry.updatedAt)
                pushed++
            } else {
                // Stop on the first failure: the rest will fail the same way,
                // and hammering a dead connection drains the battery.
                break
            }
        }

        return pushed
    }

    suspend fun localProgress(episodeId: Long): Progress? =
        progressDao.byEpisode(episodeId)?.toDomain()

    suspend fun clearHistory(): AppResult<Unit> {
        progressDao.clear()
        return ApiErrorMapper.call({ Unit }) { userApi.clearHistory() }
    }

    suspend fun forgetEpisode(episodeId: Long): AppResult<Unit> {
        progressDao.forget(episodeId)
        return ApiErrorMapper.call({ Unit }) { userApi.forgetEpisode(episodeId) }
    }

    companion object {
        const val LIST_FAVORITE = "favorite"
        const val LIST_WATCHLIST = "watchlist"
    }
}
