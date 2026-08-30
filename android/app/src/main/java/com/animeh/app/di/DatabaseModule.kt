package com.animeh.app.di

import android.content.Context
import androidx.room.Room
import com.animeh.app.data.local.AnimehDatabase
import com.animeh.app.data.local.dao.*
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object DatabaseModule {

    @Provides
    @Singleton
    fun database(@ApplicationContext context: Context): AnimehDatabase =
        Room.databaseBuilder(context, AnimehDatabase::class.java, AnimehDatabase.NAME)
            // Every table is a cache of server state; a schema change is not
            // worth a migration nobody can test against real user data.
            .fallbackToDestructiveMigration(dropAllTables = true)
            .build()

    @Provides fun workDao(db: AnimehDatabase): WorkDao = db.workDao()
    @Provides fun episodeDao(db: AnimehDatabase): EpisodeDao = db.episodeDao()
    @Provides fun progressDao(db: AnimehDatabase): ProgressDao = db.progressDao()
    @Provides fun libraryDao(db: AnimehDatabase): LibraryDao = db.libraryDao()
    @Provides fun fontDao(db: AnimehDatabase): FontDao = db.fontDao()
}
