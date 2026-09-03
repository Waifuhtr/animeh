package com.animeh.app.data.remote

import com.animeh.app.data.remote.dto.*
import okhttp3.RequestBody
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

    /** The labels the catalog's stored values are shown under. */
    @GET("terms")
    suspend fun terms(): Response<TermsDto>

    /** Reviews are readable signed out; only writing needs an account. */
    @GET("works/{id}/reviews")
    suspend fun reviews(
        @Path("id") workId: Long,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
        @Query("sort") sort: String = "useful",
    ): Response<ReviewListDto>

    /**
     * Where the backend says clients should be talking to it.
     *
     * Asked on the way past rather than configured: when the site moves, the
     * old install answers this with the new address once, and every phone
     * follows without anyone typing anything.
     */
    @GET("config")
    suspend fun clientConfig(): Response<ClientConfigDto>

    /** Readable signed out, so a profile link shared outside the app works. */
    @GET("users/{id}")
    suspend fun profile(@Path("id") userId: Long): Response<PublicProfileDto>
}

interface UserApi {

    @POST("auth/logout")
    suspend fun logout(): Response<OkDto>

    @POST("fonts/wanted")
    suspend fun reportWantedFonts(@Body body: WantedFontsRequest): Response<WantedFontsResultDto>

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

    @POST("works/{id}/reviews")
    suspend fun saveReview(
        @Path("id") workId: Long,
        @Body body: ReviewRequest,
    ): Response<ReviewEnvelopeDto>

    @DELETE("reviews/{id}")
    suspend fun deleteReview(@Path("id") reviewId: Long): Response<OkDto>

    @POST("reviews/{id}/vote")
    suspend fun voteReview(
        @Path("id") reviewId: Long,
        @Body body: VoteRequest,
    ): Response<ReviewEnvelopeDto>

    @POST("reviews/{id}/report")
    suspend fun reportReview(
        @Path("id") reviewId: Long,
        @Body body: ReportRequest,
    ): Response<ReportResultDto>

    /**
     * The picture goes up as the raw bytes rather than multipart: there is one
     * file and the phone already holds it.
     */
    @POST("me/avatar")
    suspend fun uploadAvatar(@Body body: RequestBody): Response<AvatarDto>

    @POST("me/favorite-work")
    suspend fun setFavoriteWork(@Body body: FavoriteWorkRequest): Response<FavoriteWorkResponse>

    @POST("me/profile-visibility")
    suspend fun setProfileVisibility(@Body body: VisibilityRequest): Response<VisibilityRequest>

    @POST("me/devices")
    suspend fun registerDevice(@Body body: DeviceRequest): Response<OkDto>

    @HTTP(method = "DELETE", path = "me/devices", hasBody = true)
    suspend fun forgetDevice(@Body body: DeviceRequest): Response<OkDto>

    @GET("me/friends")
    suspend fun friends(): Response<FriendsDto>

    @POST("me/friends/requests")
    suspend fun requestFriend(@Body body: FriendRequestBody): Response<OkDto>

    @POST("me/friends/{id}")
    suspend fun acceptFriend(@Path("id") userId: Long): Response<OkDto>

    @DELETE("me/friends/{id}")
    suspend fun removeFriend(@Path("id") userId: Long): Response<OkDto>

    @GET("rooms")
    suspend fun rooms(): Response<RoomsDto>

    @POST("rooms")
    suspend fun createRoom(@Body body: CreateRoomRequest): Response<RoomDto>

    @GET("rooms/{code}")
    suspend fun room(@Path("code") code: String): Response<RoomDto>

    @POST("rooms/{code}/join")
    suspend fun joinRoom(@Path("code") code: String): Response<RoomDto>

    @DELETE("rooms/{code}")
    suspend fun closeRoom(@Path("code") code: String): Response<OkDto>

    @POST("rooms/{code}/invite")
    suspend fun invite(
        @Path("code") code: String,
        @Body body: InviteRequest,
    ): Response<InviteResultDto>
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
    suspend fun beginUpload(@Body body: UploadBeginRequest): Response<UploadBeginEnvelopeDto>

    @POST("storage/uploads/complete")
    suspend fun completeUpload(@Body body: UploadCompleteRequest): Response<UploadCompleteEnvelopeDto>

    @POST("storage/uploads/abort")
    suspend fun abortUpload(@Body body: Map<String, String>): Response<UploadAbortedDto>

    /** Every vocabulary value the catalog uses, with its label if it has one. */
    @GET("admin/terms")
    suspend fun terms(): Response<AdminTermListDto>

    @POST("admin/terms")
    suspend fun saveTerm(@Body body: AdminTermRequest): Response<OkDto>

    @GET("admin/tmdb/settings")
    suspend fun tmdbSettings(): Response<TmdbSettingsEnvelopeDto>

    @POST("admin/tmdb/settings")
    suspend fun saveTmdbSettings(@Body body: TmdbSettingsRequest): Response<TmdbSettingsEnvelopeDto>

    @GET("admin/tmdb/search")
    suspend fun tmdbSearch(
        @Query("q") query: String,
        @Query("year") year: Int = 0,
    ): Response<TmdbSearchDto>

    @POST("admin/tmdb/import")
    suspend fun tmdbImport(@Body body: TmdbImportRequest): Response<TmdbImportResultDto>

    @POST("admin/tmdb/artwork")
    suspend fun tmdbArtwork(@Body body: TmdbArtworkRequest): Response<TmdbArtworkResultDto>

    @GET("admin/reports")
    suspend fun reports(@Query("status") status: String = "open"): Response<ReportListDto>

    @POST("admin/reports/{id}")
    suspend fun handleReport(
        @Path("id") reportId: Long,
        @Body body: ReportActionRequest,
    ): Response<ReportHandledDto>

    @POST("admin/users/{id}/ban")
    suspend fun banUser(
        @Path("id") userId: Long,
        @Body body: BanRequest,
    ): Response<AdminUserEnvelopeDto>

    @DELETE("admin/users/{id}/ban")
    suspend fun liftBan(@Path("id") userId: Long): Response<AdminUserEnvelopeDto>

    @GET("admin/moderators")
    suspend fun moderators(): Response<ModeratorListDto>

    @POST("admin/moderators")
    suspend fun addModerator(@Body body: ModeratorRequest): Response<AdminUserEnvelopeDto>

    @DELETE("admin/moderators/{id}")
    suspend fun removeModerator(@Path("id") userId: Long): Response<OkDto>

    @GET("admin/client-config")
    suspend fun clientConfig(): Response<AdminClientConfigDto>

    @POST("admin/client-config")
    suspend fun saveClientConfig(@Body body: AdminClientConfigRequest): Response<AdminClientConfigDto>
}
