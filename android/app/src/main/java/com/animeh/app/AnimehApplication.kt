package com.animeh.app

import android.app.Application
import coil.ImageLoader
import coil.ImageLoaderFactory
import coil.disk.DiskCache
import coil.memory.MemoryCache
import com.animeh.app.data.prefs.AuthState
import com.animeh.app.data.prefs.SessionStore
import com.animeh.app.data.prefs.SettingsStore
import com.animeh.app.data.repository.AuthRepository
import com.animeh.app.data.repository.LibraryRepository
import com.animeh.app.data.repository.ServerConfigRepository
import com.animeh.app.player.NetworkMonitor
import com.animeh.app.social.PushRegistrar
import dagger.hilt.android.HiltAndroidApp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
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
    @Inject lateinit var serverConfig: ServerConfigRepository
    @Inject lateinit var pushRegistrar: PushRegistrar
    @Inject lateinit var sessionStore: SessionStore

    @Inject lateinit var authRepository: AuthRepository

    @Inject @Named("image_client") lateinit var imageClient: OkHttpClient

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onCreate() {
        super.onCreate()

        // The interceptor reads the backend address synchronously, so it has
        // to be right before the first request rather than after DataStore's
        // first emission.
        scope.launch { settingsStore.primeApiBase() }

        // Then ask that server where clients should be connecting, and which
        // Firebase project to use. When the backend has moved, this is the
        // moment every phone finds out — see ServerConfigRepository for why it
        // has to be asked rather than told.
        scope.launch {
            serverConfig.refresh()

            // What the account is allowed to do, asked once on the way in and
            // after the address is settled.
            //
            // The roles were written down at sign-in and never looked at
            // again, so somebody made a moderator while signed in stayed a
            // viewer until they signed out and back — with no way of knowing
            // that was the trick. Failure is silent: this decides which tab is
            // drawn, and a launch is not the place to report a slow server.
            if (sessionStore.state.value is AuthState.SignedIn) {
                authRepository.refreshRoles()
            }

            // Registered after Firebase is configured, and again on every
            // sign-in. A token belongs to an install but a notification is
            // addressed to an account, so the pairing has to be restated
            // whenever either side changes — registering only at launch left
            // anyone who signed in afterwards with no token on the server,
            // which is an invitation that never arrives.
            sessionStore.state.collect { state ->
                if (state is AuthState.SignedIn) pushRegistrar.register()
            }
        }

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
     * Coil, on its own connection and its own share of the CPU.
     *
     * Posters are the bulk of what this app downloads, so the disk cache is
     * sized for a browsing session rather than left at the default.
     *
     * The two limits below are what keep a screenful of covers from being felt
     * as a stutter. Poster URLs are typed in by hand in the admin panel, so
     * nothing constrains how large the file behind one is; a home screen opens
     * a dozen image slots at once, and by default every one of those decodes on
     * its own thread the moment its bytes land. On a phone with four slow cores
     * that is the frame budget, spent on images nobody is looking at yet.
     */
    @OptIn(ExperimentalCoroutinesApi::class)
    override fun newImageLoader(): ImageLoader =
        ImageLoader.Builder(this)
            .okHttpClient { imageClient }
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
            // Decoding is the expensive half and it is pure CPU, so it is
            // capped rather than left to `Dispatchers.IO`'s sixty-four. The
            // covers arrive a moment later and the list keeps scrolling, which
            // is the trade worth making.
            .decoderDispatcher(Dispatchers.IO.limitedParallelism(decodeThreads()))
            // Long enough to read as a fade, short enough that a dozen of them
            // overlapping is not a dozen animations competing for the frame.
            .crossfade(160)
            .respectCacheHeaders(false)
            .build()

    /**
     * How many covers may decode at once.
     *
     * Half the cores, because the other half are drawing. Never fewer than
     * two — one would make a slow image block every image behind it — and
     * never more than four, past which the phone is only queueing work it
     * cannot do any faster.
     */
    private fun decodeThreads(): Int =
        (Runtime.getRuntime().availableProcessors() / 2).coerceIn(2, 4)
}
