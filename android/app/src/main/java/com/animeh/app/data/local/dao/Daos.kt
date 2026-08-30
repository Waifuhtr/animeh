package com.animeh.app.data.local.dao

import androidx.room.*
import com.animeh.app.data.local.entity.*
import kotlinx.coroutines.flow.Flow

@Dao
interface WorkDao {

    @Query("SELECT * FROM works WHERE id = :id")
    suspend fun byId(id: Long): WorkEntity?

    @Query("SELECT * FROM works WHERE slug = :slug")
    suspend fun bySlug(slug: String): WorkEntity?

    @Query("SELECT * FROM works WHERE rail = :rail ORDER BY railOrder ASC")
    fun rail(rail: String): Flow<List<WorkEntity>>

    @Query("SELECT * FROM works WHERE rail = :rail ORDER BY railOrder ASC")
    suspend fun railOnce(rail: String): List<WorkEntity>

    @Query("SELECT * FROM works WHERE id IN (:ids)")
    suspend fun byIds(ids: List<Long>): List<WorkEntity>

    @Upsert
    suspend fun upsert(works: List<WorkEntity>)

    @Upsert
    suspend fun upsert(work: WorkEntity)

    /** Clear a rail before writing it, so a title dropped upstream disappears. */
    @Query("UPDATE works SET rail = '', railOrder = 0 WHERE rail = :rail")
    suspend fun clearRail(rail: String)

    @Query("DELETE FROM works WHERE cachedAt < :cutoff AND rail = ''")
    suspend fun pruneOlderThan(cutoff: Long)
}

@Dao
interface EpisodeDao {

    @Query("SELECT * FROM episodes WHERE workId = :workId ORDER BY seasonNumber ASC, number ASC")
    fun forWork(workId: Long): Flow<List<EpisodeEntity>>

    @Query("SELECT * FROM episodes WHERE workId = :workId ORDER BY seasonNumber ASC, number ASC")
    suspend fun forWorkOnce(workId: Long): List<EpisodeEntity>

    @Query("SELECT * FROM episodes WHERE id = :id")
    suspend fun byId(id: Long): EpisodeEntity?

    @Upsert
    suspend fun upsert(episodes: List<EpisodeEntity>)

    @Query("DELETE FROM episodes WHERE workId = :workId")
    suspend fun deleteForWork(workId: Long)
}

/**
 * A continue-watching row, assembled offline from the three local tables.
 *
 * Room projects a join straight into this, so the offline rail is one query
 * rather than N lookups per work.
 */
data class ContinueRow(
    val episodeId: Long,
    val workId: Long,
    val positionSeconds: Int,
    val durationSeconds: Int,
    val updatedAt: Long,
    val episodeNumber: Int,
    val seasonNumber: Int,
    val episodeTitle: String,
    val thumbnailUrl: String,
    val workTitle: String,
    val workSlug: String,
    val posterUrl: String,
)

@Dao
interface ProgressDao {

    @Query("SELECT * FROM progress WHERE episodeId = :episodeId")
    suspend fun byEpisode(episodeId: Long): ProgressEntity?

    @Query("SELECT * FROM progress WHERE workId = :workId ORDER BY updatedAt DESC")
    fun forWork(workId: Long): Flow<List<ProgressEntity>>

    @Query("SELECT * FROM progress ORDER BY updatedAt DESC LIMIT :limit")
    fun recent(limit: Int): Flow<List<ProgressEntity>>

    @Query("SELECT * FROM progress WHERE synced = 0 ORDER BY updatedAt ASC LIMIT :limit")
    suspend fun unsynced(limit: Int = 50): List<ProgressEntity>

    /**
     * The newest unfinished episode of each work, offline.
     *
     * The inner select picks one row per work; without it a series with fifty
     * watched episodes would fill the whole rail. The 30-second floor matches
     * [Progress.RESUME_THRESHOLD_SECONDS] — below that, "continue" would drop
     * the viewer back at the start anyway.
     */
    @Query(
        """
        SELECT p.episodeId AS episodeId, p.workId AS workId,
               p.positionSeconds AS positionSeconds, p.durationSeconds AS durationSeconds,
               p.updatedAt AS updatedAt,
               e.number AS episodeNumber, e.seasonNumber AS seasonNumber,
               e.title AS episodeTitle, e.thumbnailUrl AS thumbnailUrl,
               w.title AS workTitle, w.slug AS workSlug, w.posterUrl AS posterUrl
        FROM progress p
        INNER JOIN episodes e ON e.id = p.episodeId
        INNER JOIN works w ON w.id = p.workId
        INNER JOIN (
            SELECT workId, MAX(updatedAt) AS latest
            FROM progress
            WHERE completed = 0 AND positionSeconds > 30
            GROUP BY workId
        ) newest ON newest.workId = p.workId AND newest.latest = p.updatedAt
        WHERE p.completed = 0
        ORDER BY p.updatedAt DESC
        LIMIT :limit
        """
    )
    suspend fun continueWatching(limit: Int = 20): List<ContinueRow>

    @Upsert
    suspend fun upsert(progress: ProgressEntity)

    @Query("UPDATE progress SET synced = 1 WHERE episodeId = :episodeId AND updatedAt <= :updatedAt")
    suspend fun markSynced(episodeId: Long, updatedAt: Long)

    @Query("DELETE FROM progress")
    suspend fun clear()

    @Query("DELETE FROM progress WHERE episodeId = :episodeId")
    suspend fun forget(episodeId: Long)
}

@Dao
interface LibraryDao {

    @Query("SELECT workId FROM library WHERE list = :list ORDER BY addedAt DESC")
    fun workIds(list: String): Flow<List<Long>>

    @Query("SELECT workId FROM library WHERE list = :list ORDER BY addedAt DESC")
    suspend fun workIdsOnce(list: String): List<Long>

    @Query("SELECT COUNT(*) FROM library WHERE workId = :workId AND list = :list")
    fun isInList(workId: Long, list: String): Flow<Int>

    @Upsert
    suspend fun upsert(entry: LibraryEntity)

    @Upsert
    suspend fun upsertAll(entries: List<LibraryEntity>)

    @Query("DELETE FROM library WHERE workId = :workId AND list = :list")
    suspend fun remove(workId: Long, list: String)

    @Query("DELETE FROM library WHERE list = :list")
    suspend fun clearList(list: String)

    @Query("DELETE FROM library")
    suspend fun clear()
}

@Dao
interface FontDao {

    @Query("SELECT * FROM fonts")
    suspend fun all(): List<FontEntity>

    @Query("SELECT * FROM fonts WHERE family = :family")
    suspend fun byFamily(family: String): FontEntity?

    @Upsert
    suspend fun upsert(font: FontEntity)

    @Query("DELETE FROM fonts")
    suspend fun clear()
}
