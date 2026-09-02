package com.animeh.app.di

import android.content.Context
import com.animeh.app.BuildConfig
import com.animeh.app.data.prefs.SessionStore
import com.animeh.app.data.prefs.SettingsStore
import com.animeh.app.data.remote.AdminApi
import com.animeh.app.data.remote.AuthInterceptor
import com.animeh.app.data.remote.PublicApi
import com.animeh.app.data.remote.TokenAuthenticator
import com.animeh.app.data.remote.UserApi
import com.jakewharton.retrofit2.converter.kotlinx.serialization.asConverterFactory
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import kotlinx.serialization.json.Json
import okhttp3.Cache
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import java.util.concurrent.TimeUnit
import javax.inject.Named
import javax.inject.Provider
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object NetworkModule {

    @Provides
    @Singleton
    fun json(): Json = Json {
        // The backend gains fields; an app that rejects unknown ones breaks on
        // every deploy.
        ignoreUnknownKeys = true
        isLenient = true
        coerceInputValues = true
        explicitNulls = false
        // Without this a field holding its default value is left out of the
        // body entirely, so uploading episode 1 omitted `episode` and the
        // server refused the request as a missing parameter. Optional fields
        // stay omitted regardless: they are nullable and hold null, which
        // `explicitNulls = false` drops.
        encodeDefaults = true
    }

    @Provides
    @Singleton
    fun sessionStore(@ApplicationContext context: Context): SessionStore = SessionStore(context)

    @Provides
    @Singleton
    fun settingsStore(@ApplicationContext context: Context): SettingsStore = SettingsStore(context)

    @Provides
    @Singleton
    @Named("http_cache")
    fun cache(@ApplicationContext context: Context): Cache =
        // 20 MB of catalog JSON and nothing else; images have their own cache
        // in Coil, and video is never cached here.
        Cache(context.cacheDir.resolve("http"), 20L * 1024 * 1024)

    @Provides
    @Singleton
    @Named("base_client")
    fun baseClient(@Named("http_cache") cache: Cache): OkHttpClient =
        OkHttpClient.Builder()
            .cache(cache)
            // Generous read timeout: this app is built for slow connections,
            // and a 10-second default turns a working link into an error.
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .retryOnConnectionFailure(true)
            .build()

    /**
     * The client the refresh call uses: no authenticator, so a failing refresh
     * cannot recurse into refreshing itself.
     */
    @Provides
    @Singleton
    @Named("refresh_client")
    fun refreshClient(
        @Named("base_client") base: OkHttpClient,
        authInterceptor: AuthInterceptor,
    ): OkHttpClient = base.newBuilder()
        .addInterceptor(authInterceptor)
        .build()

    @Provides
    @Singleton
    @Named("api_client")
    fun apiClient(
        @Named("base_client") base: OkHttpClient,
        authInterceptor: AuthInterceptor,
        authenticator: TokenAuthenticator,
    ): OkHttpClient = base.newBuilder()
        .addInterceptor(authInterceptor)
        .authenticator(authenticator)
        .apply {
            if (BuildConfig.DEBUG) {
                // BASIC, not BODY: a body log would print the access token in
                // every login response into logcat.
                addInterceptor(
                    HttpLoggingInterceptor().apply { level = HttpLoggingInterceptor.Level.BASIC }
                )
            }
        }
        .build()

    @Provides
    @Singleton
    fun authInterceptor(
        sessionStore: SessionStore,
        settingsStore: SettingsStore,
    ): AuthInterceptor = AuthInterceptor(sessionStore, settingsStore)

    @Provides
    @Singleton
    fun tokenAuthenticator(
        sessionStore: SessionStore,
        refreshApi: Provider<PublicApi>,
    ): TokenAuthenticator = TokenAuthenticator(sessionStore, refreshApi)

    @Provides
    @Singleton
    @Named("api_retrofit")
    fun retrofit(@Named("api_client") client: OkHttpClient, json: Json): Retrofit =
        Retrofit.Builder()
            // A placeholder: AuthInterceptor rewrites every request onto the
            // address the user has configured, so the backend can move without
            // rebuilding the object graph.
            .baseUrl(AuthInterceptor.PLACEHOLDER_BASE)
            .client(client)
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()

    @Provides
    @Singleton
    fun publicApi(@Named("refresh_client") client: OkHttpClient, json: Json): PublicApi =
        Retrofit.Builder()
            .baseUrl(AuthInterceptor.PLACEHOLDER_BASE)
            .client(client)
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()
            .create(PublicApi::class.java)

    @Provides
    @Singleton
    fun userApi(@Named("api_retrofit") retrofit: Retrofit): UserApi =
        retrofit.create(UserApi::class.java)

    @Provides
    @Singleton
    fun adminApi(@Named("api_retrofit") retrofit: Retrofit): AdminApi =
        retrofit.create(AdminApi::class.java)
}
