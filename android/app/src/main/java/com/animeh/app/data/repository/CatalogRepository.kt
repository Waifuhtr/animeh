package com.animeh.app.data.repository

import com.animeh.app.core.AppResult
import com.animeh.app.data.local.dao.EpisodeDao
import com.animeh.app.data.local.dao.ProgressDao
import com.animeh.app.data.local.dao.WorkDao
import com.animeh.app.data.remote.ApiErrorMapper
import com.animeh.app.data.remote.PublicApi
import com.animeh.app.data.remote.UserApi
import com.animeh.app.domain.*
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Browsing, with a cache behind it.
 *
 * The pattern is the same everywhere here: try the network, write what comes
 * back into Room, and on failure fall back to Room rather than showing an
 * error. §26 asks for exactly that — the covers and metadata a user has already
 * seen should still be there on a train — and the [Cached] wrapper carries
 * whether the data is live so a screen can say so.
 */
@Singleton
class CatalogRepository @Inject constructor(
    private val publicApi: PublicApi,
    private val userApi: UserApi,
    private val workDao: WorkDao,
    private val episodeDao: EpisodeDao,
    private val progressDao: ProgressDao,
) {

    /** A value plus whether it came from the network or the cache. */
    data class Cached<T>(val value: T, val fromCache: Boolean)

    suspend fun home(): AppResult<Cached<HomeFeed>> {
        val result = ApiErrorMapper.call({ it.toDomain() }) { publicApi.home() }

        return when (result) {
            is AppResult.Success -> {
                cacheHome(result.data)
                AppResult.Success(Cached(result.data, fromCache = false))
            }
            is AppResult.Failure -> {
                val cached = readCachedHome()
                // An empty cache is not a useful answer; the error is.
                if (cached.isEmpty) result else AppResult.Success(Cached(cached, fromCache = true))
            }
        }
    }

    suspend fun works(
        search: String? = null,
        genre: String? = null,
        year: Int? = null,
        season: String? = null,
        status: String? = null,
        minScore: Double? = null,
        sort: String = "recent",
        page: Int = 1,
        perPage: Int = 20,
    ): AppResult<List<Work>> = ApiErrorMapper.call({ dto -> dto.items.map { it.toDomain() } }) {
        publicApi.works(
            search = search?.takeIf { it.isNotBlank() },
            genre = genre?.takeIf { it.isNotBlank() },
            year = year?.takeIf { it > 0 },
            season = season?.takeIf { it.isNotBlank() },
            status = status?.takeIf { it.isNotBlank() },
            minScore = minScore?.takeIf { it > 0 },
            sort = sort,
            page = page,
            perPage = perPage,
        )
    }.also { result ->
        // Cached without a rail: it feeds the detail screen offline, but does
        // not claim a place on the home screen.
        if (result is AppResult.Success) {
            workDao.upsert(result.data.map { it.toEntity() })
        }
    }

    /** One work, by id or slug. */
    suspend fun work(identifier: String): AppResult<Cached<Work>> {
        val result = ApiErrorMapper.call({ it.toDomain() }) { publicApi.work(identifier) }

        return when (result) {
            is AppResult.Success -> {
                workDao.upsert(result.data.toEntity())
                AppResult.Success(Cached(result.data, fromCache = false))
            }
            is AppResult.Failure -> {
                val cached = identifier.toLongOrNull()
                    ?.let { workDao.byId(it) }
                    ?: workDao.bySlug(identifier)

                cached?.let { AppResult.Success(Cached(it.toDomain(), fromCache = true)) } ?: result
            }
        }
    }

    suspend fun episodes(workId: Long, season: Int = 0): AppResult<Cached<List<Episode>>> {
        val result = ApiErrorMapper.call({ dto -> dto.items.map { it.toDomain() } }) {
            publicApi.episodes(workId, season)
        }

        return when (result) {
            is AppResult.Success -> {
                episodeDao.upsert(result.data.map { it.toEntity() })
                AppResult.Success(Cached(result.data, fromCache = false))
            }
            is AppResult.Failure -> {
                val cached = episodeDao.forWorkOnce(workId)
                if (cached.isEmpty()) {
                    result
                } else {
                    // The position comes from the local table, which is
                    // authoritative offline — it is written before the server
                    // hears about it.
                    val episodes = cached.map { entity ->
                        entity.toDomain(progressDao.byEpisode(entity.id)?.toDomain())
                    }
                    AppResult.Success(Cached(episodes, fromCache = true))
                }
            }
        }
    }

    suspend fun genres(): AppResult<List<Genre>> =
        ApiErrorMapper.call({ dto -> dto.genres.map { it.toDomain() } }) { publicApi.genres() }

    suspend fun playback(episodeId: Long): AppResult<Playback> =
        ApiErrorMapper.call({ it.toDomain() }) { userApi.play(episodeId) }

    suspend fun announcements(): AppResult<List<Announcement>> =
        ApiErrorMapper.call({ dto -> dto.announcements.map { it.toDomain() } }) {
            publicApi.announcements()
        }

    private suspend fun cacheHome(feed: HomeFeed) {
        // Each rail is cleared before it is rewritten, so a title dropped
        // upstream disappears from the offline copy too.
        listOf(
            RAIL_HERO to feed.hero,
            RAIL_POPULAR to feed.popular,
            RAIL_AIRING to feed.airing,
            RAIL_RECENT to feed.recentlyAdded,
        ).forEach { (rail, works) ->
            workDao.clearRail(rail)
            workDao.upsert(works.mapIndexed { index, work -> work.toEntity(rail, index) })
        }

        episodeDao.upsert(feed.latestEpisodes.map { it.toEntity() })
    }

    private suspend fun readCachedHome(): HomeFeed = HomeFeed(
        hero = workDao.railOnce(RAIL_HERO).map { it.toDomain() },
        popular = workDao.railOnce(RAIL_POPULAR).map { it.toDomain() },
        airing = workDao.railOnce(RAIL_AIRING).map { it.toDomain() },
        recentlyAdded = workDao.railOnce(RAIL_RECENT).map { it.toDomain() },
        // Continue-watching is rebuilt from the local progress table rather
        // than the server's, so it is right offline.
        continueWatching = localContinueWatching(),
    )

    /**
     * Continue-watching rebuilt from the local tables.
     *
     * Used when the network call failed, and correct in that case precisely
     * because the position is written locally before the server hears about it.
     */
    private suspend fun localContinueWatching(): List<ContinueItem> =
        progressDao.continueWatching(CONTINUE_LIMIT).map { row ->
            ContinueItem(
                workId = row.workId,
                workTitle = row.workTitle,
                workSlug = row.workSlug,
                posterUrl = row.posterUrl,
                episodeId = row.episodeId,
                episodeNumber = row.episodeNumber,
                seasonNumber = row.seasonNumber,
                episodeTitle = row.episodeTitle,
                thumbnailUrl = row.thumbnailUrl.ifBlank { row.posterUrl },
                progress = Progress(row.positionSeconds, row.durationSeconds, completed = false),
            )
        }

    companion object {
        const val RAIL_HERO = "hero"
        const val RAIL_POPULAR = "popular"
        const val RAIL_AIRING = "airing"
        const val RAIL_RECENT = "recent"
        const val CONTINUE_LIMIT = 20
    }
}
