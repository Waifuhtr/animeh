package com.animeh.app.data.remote

import com.animeh.app.data.remote.dto.*
import retrofit2.Response
import retrofit2.http.*

/**
 * The backend, as Kotlin.
 *
 * Split into three interfaces rather than one because they differ in what they
 * require: [PublicApi] works signed out, [UserApi] needs a session, and
 * [AdminApi] needs the capability. Keeping them apart makes it obvious at a
 * call site which one is in play — and the server enforces all three
 * independently, so this split is documentation, not security.
 *
 * Every method returns `Response<T>` rather than `T`, so the error body is
 * available to [ApiErrorMapper] instead of arriving as an exception with the
 * server's own message thrown away.
 */
interface PublicApi {

    @POST("auth/login")
    suspend fun login(@Body body: LoginRequest): Response<SessionDto>

    @POST("auth/register")
    suspend fun register(@Body body: RegisterRequest): Response<SessionDto>

    @POST("auth/refresh")
    suspend fun refresh(@Body body: RefreshRequest): Response<SessionDto>

    @POST("auth/password/forgot")
    suspend fun forgotPassword(@Body body: ForgotPasswordRequest): Response<OkDto>

    @GET("catalog/home")
    suspend fun home(): Response<HomeDto>

    @GET("catalog/works")
    suspend fun works(
        @Query("search") search: String? = null,
        @Query("genre") genre: String? = null,
        @Query("year") year: Int? = null,
        @Query("season") season: String? = null,
        @Query("status") status: String? = null,
        @Query("min_score") minScore: Double? = null,
        @Query("sort") sort: String = "recent",
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
    ): Response<WorkListDto>

    /** Accepts an id or a slug, so a deep link and an internal reference are
     *  the same call. */
    @GET("catalog/works/{id}")
    suspend fun work(@Path("id") id: String): Response<WorkDto>

    @GET("catalog/works/{id}/episodes")
    suspend fun episodes(
        @Path("id") workId: Long,
        @Query("season") season: Int = 0,
    ): Response<EpisodeListDto>

    @GET("catalog/genres")
    suspend fun genres(): Response<GenreListDto>

    @GET("announcements")
    suspend fun announcements(): Response<AnnouncementListDto>
}

interface UserApi {

    @POST("auth/logout")
    suspend fun logout(): Response<OkDto>

    @POST("auth/password/change")
    suspend fun changePassword(@Body body: ChangePasswordRequest): Response<SessionDto>

    @GET("me")
    suspend fun profile(): Response<ProfileDto>

    @PUT("me")
    suspend fun updateProfile(@Body body: UpdateProfileRequest): Response<UserEnvelopeDto>

    @GET("me/settings")
    suspend fun settings(): Response<SettingsEnvelopeDto>

    @POST("me/settings")
    suspend fun saveSettings(@Body body: AppSettingsDto): Response<SettingsEnvelopeDto>

    @GET("me/library")
    suspend fun library(
        @Query("list") list: String = "favorite",
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
    ): Response<WorkListDto>

    @POST("me/library/{workId}")
    suspend fun addToLibrary(
        @Path("workId") workId: Long,
        @Query("list") list: String = "favorite",
    ): Response<OkDto>

    @DELETE("me/library/{workId}")
    suspend fun removeFromLibrary(
        @Path("workId") workId: Long,
        @Query("list") list: String = "favorite",
    ): Response<OkDto>

    @GET("me/history")
    suspend fun history(
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
    ): Response<HistoryListDto>

    @POST("me/history")
    suspend fun recordProgress(@Body body: ProgressRequest): Response<OkDto>

    @DELETE("me/history")
    suspend fun clearHistory(): Response<OkDto>

    @DELETE("me/history/{episodeId}")
    suspend fun forgetEpisode(@Path("episodeId") episodeId: Long): Response<OkDto>

    @GET("me/continue")
    suspend fun continueWatching(): Response<HistoryListDto>

    @GET("episodes/{id}/play")
    suspend fun play(@Path("id") episodeId: Long): Response<PlaybackDto>
}

interface AdminApi {

    @GET("admin/dashboard")
    suspend fun dashboard(): Response<DashboardDto>

    @GET("admin/works")
    suspend fun works(
        @Query("search") search: String = "",
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
    ): Response<WorkListDto>

    @POST("admin/works")
    suspend fun createWork(@Body body: AdminWorkRequest): Response<AdminWorkEnvelopeDto>

    @PUT("admin/works/{id}")
    suspend fun updateWork(@Path("id") id: Long, @Body body: AdminWorkRequest): Response<AdminWorkEnvelopeDto>

    @DELETE("admin/works/{id}")
    suspend fun deleteWork(@Path("id") id: Long): Response<OkDto>

    @GET("admin/works/{workId}/episodes")
    suspend fun episodes(@Path("workId") workId: Long): Response<EpisodeListDto>

    @POST("admin/works/{workId}/episodes")
    suspend fun createEpisode(
        @Path("workId") workId: Long,
        @Body body: AdminEpisodeRequest,
    ): Response<AdminEpisodeEnvelopeDto>

    @GET("admin/episodes/{id}")
    suspend fun episode(@Path("id") id: Long): Response<AdminEpisodeEnvelopeDto>

    @PUT("admin/episodes/{id}")
    suspend fun updateEpisode(@Path("id") id: Long, @Body body: AdminEpisodeRequest): Response<AdminEpisodeEnvelopeDto>

    @DELETE("admin/episodes/{id}")
    suspend fun deleteEpisode(@Path("id") id: Long): Response<OkDto>

    @GET("admin/episodes/{episodeId}/sources")
    suspend fun sources(@Path("episodeId") episodeId: Long): Response<AdminSourceListDto>

    @POST("admin/episodes/{episodeId}/sources")
    suspend fun createSource(
        @Path("episodeId") episodeId: Long,
        @Body body: AdminSourceRequest,
    ): Response<AdminSourceListDto>

    @DELETE("admin/sources/{id}")
    suspend fun deleteSource(@Path("id") id: Long): Response<OkDto>

    @GET("admin/tenrai/search")
    suspend fun tenraiSearch(
        @Query("q") query: String,
        @Query("page") page: Int = 1,
    ): Response<TenraiSearchDto>

    @POST("admin/tenrai/import")
    suspend fun tenraiImport(@Body body: TenraiImportRequest): Response<TenraiImportResultDto>

    @GET("admin/tenrai/settings")
    suspend fun tenraiSettings(): Response<TenraiSettingsEnvelopeDto>

    @GET("admin/users")
    suspend fun users(
        @Query("search") search: String = "",
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
    ): Response<AdminUserListDto>

    @GET("admin/announcements")
    suspend fun announcements(): Response<AdminAnnouncementListDto>

    @POST("admin/announcements")
    suspend fun saveAnnouncement(@Body body: AdminAnnouncementDto): Response<OkDto>

    @DELETE("admin/announcements/{id}")
    suspend fun deleteAnnouncement(@Path("id") id: Long): Response<OkDto>

    @GET("admin/logs")
    suspend fun logs(
        @Query("level") level: String = "",
        @Query("search") search: String = "",
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 50,
    ): Response<LogListDto>

    @DELETE("admin/logs")
    suspend fun clearLogs(): Response<OkDto>

    @GET("fonts")
    suspend fun fonts(): Response<AdminFontListDto>

    @DELETE("fonts/{id}")
    suspend fun deleteFont(@Path("id") id: Long): Response<OkDto>

    @POST("storage/test")
    suspend fun testStorage(): Response<StorageTestDto>

    @POST("storage/uploads")
    suspend fun beginUpload(@Body body: UploadBeginRequest): Response<UploadBeginDto>

    @POST("storage/uploads/complete")
    suspend fun completeUpload(@Body body: UploadCompleteRequest): Response<UploadCompleteDto>

    @POST("storage/uploads/abort")
    suspend fun abortUpload(@Body body: Map<String, String>): Response<OkDto>
}
