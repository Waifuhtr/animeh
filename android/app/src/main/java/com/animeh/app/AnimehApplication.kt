package com.animeh.app

import android.app.Application
import coil.ImageLoader
import coil.ImageLoaderFactory
import coil.disk.DiskCache
import coil.memory.MemoryCache
import com.animeh.app.data.prefs.SettingsStore
import com.animeh.app.data.repository.LibraryRepository
import com.animeh.app.player.NetworkMonitor
import dagger.hilt.android.HiltAndroidApp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.launch
import okhttp3.OkHttpClient
import javax.inject.Inject
import javax.inject.Named

@HiltAndroidApp
class AnimehApplication : Application(), ImageLoaderFactory {

    @Inject lateinit var settingsStore: SettingsStore
    @Inject lateinit var networkMonitor: NetworkMonitor
    @Inject lateinit var libraryRepository: LibraryRepository

    @Inject @Named("base_client") lateinit var okHttpClient: OkHttpClient

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onCreate() {
        super.onCreate()

        // The interceptor reads the backend address synchronously, so it has
        // to be right before the first request rather than after DataStore's
        // first emission.
        scope.launch { settingsStore.primeApiBase() }

        // Positions recorded while offline are pushed as soon as there is a
        // connection again, rather than waiting for the next episode.
        scope.launch {
            networkMonitor.connectionClass.drop(1).collect {
                if (networkMonitor.isOnline()) {
                    libraryRepository.syncPending()
                }
            }
        }
    }

    /**
     * Coil, sharing the app's OkHttp client.
     *
     * Posters are the bulk of what this app downloads, so the disk cache is
     * sized for a browsing session rather than left at the default.
     */
    override fun newImageLoader(): ImageLoader =
        ImageLoader.Builder(this)
            .okHttpClient { okHttpClient }
            .memoryCache {
                MemoryCache.Builder(this)
                    .maxSizePercent(0.20)
                    .build()
            }
            .diskCache {
                DiskCache.Builder()
                    .directory(cacheDir.resolve("images"))
                    .maxSizeBytes(150L * 1024 * 1024)
                    .build()
            }
            .crossfade(true)
            .respectCacheHeaders(false)
            .build()
}
