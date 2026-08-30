package com.animeh.app.data.local.entity

import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

/**
 * The offline cache.
 *
 * §26 asks for covers, metadata, episode info and the last position to survive
 * going offline — and explicitly asks *not* to build a video cache yet. So this
 * stores what a screen needs to draw itself without a network, and nothing
 * larger.
 *
 * [cachedAt] is on every table because staleness is a display decision: a
 * screen showing week-old data should say so rather than pretend it is live.
 */

@Entity(
    tableName = "works",
    indices = [Index("slug"), Index("cachedAt"), Index("rail")],
)
data class WorkEntity(
    @PrimaryKey val id: Long,
    val slug: String,
    val title: String,
    val titleEnglish: String,
    val synopsis: String,
    val posterUrl: String,
    val bannerUrl: String,
    val score: Double,
    val year: Int,
    val season: String,
    val status: String,
    val format: String,
    val studio: String,
    /** Comma-joined; a list column would need a converter for two fields. */
    val genres: String,
    val totalEpisodes: Int,
    val durationSeconds: Int,
    /** Which home rail this row was last seen in, so a rail can be rebuilt offline. */
    val rail: String = "",
    val railOrder: Int = 0,
    val cachedAt: Long = System.currentTimeMillis(),
)

@Entity(
    tableName = "episodes",
    indices = [Index("workId"), Index("workId", "seasonNumber", "number", unique = true)],
)
data class EpisodeEntity(
    @PrimaryKey val id: Long,
    val workId: Long,
    val seasonNumber: Int,
    val number: Int,
    val title: String,
    val synopsis: String,
    val thumbnailUrl: String,
    val durationSeconds: Int,
    val filler: Boolean,
    val publishedAt: String,
    val cachedAt: Long = System.currentTimeMillis(),
)

/**
 * A playback position, held locally first.
 *
 * The app writes here on every progress tick and syncs to the server
 * separately. That ordering is what makes "continue watching" correct on a
 * train: the position is right immediately, and [synced] marks what still owes
 * the server a write.
 */
@Entity(tableName = "progress", indices = [Index("workId"), Index("updatedAt"), Index("synced")])
data class ProgressEntity(
    @PrimaryKey val episodeId: Long,
    val workId: Long,
    val positionSeconds: Int,
    val durationSeconds: Int,
    val completed: Boolean,
    val updatedAt: Long,
    val synced: Boolean = false,
)

@Entity(tableName = "library", indices = [Index("list")])
data class LibraryEntity(
    @PrimaryKey val key: String,
    val workId: Long,
    val list: String,
    val addedAt: Long,
)

/** Downloaded font files, so libass does not re-fetch them per episode. */
@Entity(tableName = "fonts", indices = [Index("family")])
data class FontEntity(
    @PrimaryKey val family: String,
    val url: String,
    val localPath: String,
    val sizeBytes: Long,
    val cachedAt: Long = System.currentTimeMillis(),
)
