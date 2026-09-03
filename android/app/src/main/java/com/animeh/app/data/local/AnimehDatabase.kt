package com.animeh.app.data.local

import androidx.room.Database
import androidx.room.RoomDatabase
import com.animeh.app.data.local.dao.*
import com.animeh.app.data.local.entity.*

/**
 * The offline database.
 *
 * `fallbackToDestructiveMigration` is set where this is built (DatabaseModule)
 * and is correct here specifically because every table is a cache: the
 * authoritative copy is on the server, and the one row that is not — an unsynced
 * playback position — is worth less than the crash a failed migration causes.
 *
 * The version still has to move on every schema change. Room stores an identity
 * hash of the entities and throws at open time when they disagree with the
 * stored one, and the destructive fallback only runs for a version it was told
 * about — so a changed column with an unchanged version is a crash on the first
 * database access of an upgraded install, not a quiet re-create. Version 2
 * covers `progress.watchedSeconds` and `works.adult`.
 */
@Database(
    entities = [
        WorkEntity::class,
        EpisodeEntity::class,
        ProgressEntity::class,
        LibraryEntity::class,
        FontEntity::class,
    ],
    version = 2,
    exportSchema = false,
)
abstract class AnimehDatabase : RoomDatabase() {
    abstract fun workDao(): WorkDao
    abstract fun episodeDao(): EpisodeDao
    abstract fun progressDao(): ProgressDao
    abstract fun libraryDao(): LibraryDao
    abstract fun fontDao(): FontDao

    companion object {
        const val NAME = "animeh.db"
    }
}
