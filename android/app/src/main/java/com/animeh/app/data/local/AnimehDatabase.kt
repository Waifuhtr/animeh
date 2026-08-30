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
 */
@Database(
    entities = [
        WorkEntity::class,
        EpisodeEntity::class,
        ProgressEntity::class,
        LibraryEntity::class,
        FontEntity::class,
    ],
    version = 1,
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
