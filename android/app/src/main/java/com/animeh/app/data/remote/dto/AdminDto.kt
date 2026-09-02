package com.animeh.app.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class DashboardDto(
    val counts: DashboardCountsDto = DashboardCountsDto(),
    val errors: List<ErrorSummaryDto> = emptyList(),
    val storage: StorageStatusDto = StorageStatusDto(),
    val tenrai: TenraiSettingsDto = TenraiSettingsDto(),
)

@Serializable
data class DashboardCountsDto(
    val works: Int = 0,
    @SerialName("works_published") val worksPublished: Int = 0,
    val episodes: Int = 0,
    @SerialName("episodes_published") val episodesPublished: Int = 0,
    @SerialName("video_sources") val videoSources: Int = 0,
    @SerialName("subtitle_sources") val subtitleSources: Int = 0,
    val users: Int = 0,
)

@Serializable
data class ErrorSummaryDto(val code: String = "", val count: Int = 0)

@Serializable
data class StorageStatusDto(
    val configured: Boolean = false,
    val bucket: String = "",
    @SerialName("public_bucket") val publicBucket: Boolean = false,
)

@Serializable
data class TenraiSettingsDto(
    val base: String = "",
    @SerialName("has_key") val hasKey: Boolean = false,
    @SerialName("key_masked") val keyMasked: String = "",
    val enabled: Boolean = true,
)

@Serializable
data class TenraiSettingsEnvelopeDto(val tenrai: TenraiSettingsDto = TenraiSettingsDto())

@Serializable
data class TenraiSearchResultDto(
    @SerialName("tenrai_id") val tenraiId: Long = 0,
    val title: String = "",
    @SerialName("title_english") val titleEnglish: String = "",
    @SerialName("poster_url") val posterUrl: String = "",
    val year: Int = 0,
    val score: Double = 0.0,
    val format: String = "",
    val status: String = "",
    @SerialName("total_episodes") val totalEpisodes: Int = 0,
    val synopsis: String = "",
    /** Non-zero when this title is already in the catalog, so the panel can
     *  offer "update" instead of a second import. */
    @SerialName("imported_id") val importedId: Long = 0,
)

@Serializable
data class TenraiSearchDto(val items: List<TenraiSearchResultDto> = emptyList())

@Serializable
data class TenraiImportRequest(
    @SerialName("tenrai_id") val tenraiId: Long,
    @SerialName("import_episodes") val importEpisodes: Boolean = true,
    val publish: Boolean = false,
)

@Serializable
data class TenraiImportResultDto(
    val work: WorkDto? = null,
    @SerialName("imported_episodes") val importedEpisodes: Int = 0,
    val updated: Boolean = false,
)

@Serializable
data class AdminWorkRequest(
    val id: Long? = null,
    val title: String? = null,
    @SerialName("title_english") val titleEnglish: String? = null,
    val synopsis: String? = null,
    @SerialName("poster_url") val posterUrl: String? = null,
    @SerialName("banner_url") val bannerUrl: String? = null,
    val score: Double? = null,
    val year: Int? = null,
    val season: String? = null,
    val status: String? = null,
    val format: String? = null,
    val studio: String? = null,
    val genres: List<String>? = null,
    @SerialName("total_episodes") val totalEpisodes: Int? = null,
    val published: Boolean? = null,
)

@Serializable
data class AdminWorkEnvelopeDto(val work: WorkDto? = null)

@Serializable
data class AdminEpisodeRequest(
    val id: Long? = null,
    @SerialName("work_id") val workId: Long? = null,
    @SerialName("season_number") val seasonNumber: Int = 1,
    val number: Int = 1,
    val title: String? = null,
    val synopsis: String? = null,
    @SerialName("thumbnail_url") val thumbnailUrl: String? = null,
    @SerialName("duration_seconds") val durationSeconds: Int? = null,
    @SerialName("intro_start") val introStart: Int? = null,
    @SerialName("intro_end") val introEnd: Int? = null,
    @SerialName("outro_start") val outroStart: Int? = null,
    val filler: Boolean? = null,
    val published: Boolean? = null,
)

@Serializable
data class AdminEpisodeEnvelopeDto(
    val episode: EpisodeDto? = null,
    val sources: List<AdminSourceDto> = emptyList(),
)

@Serializable
data class AdminSourceDto(
    val id: Long = 0,
    @SerialName("episode_id") val episodeId: Long = 0,
    @SerialName("work_id") val workId: Long = 0,
    val kind: String = "video",
    val label: String = "",
    val language: String = "",
    @SerialName("storage_key") val storageKey: String = "",
    @SerialName("external_url") val externalUrl: String = "",
    val mime: String = "",
    val height: Int = 0,
    @SerialName("size_bytes") val sizeBytes: Long = 0,
    @SerialName("is_default") val isDefault: Boolean = false,
    @SerialName("sort_order") val sortOrder: Int = 0,
)

@Serializable
data class AdminSourceListDto(val items: List<AdminSourceDto> = emptyList())

@Serializable
data class AdminSourceRequest(
    val id: Long? = null,
    @SerialName("episode_id") val episodeId: Long? = null,
    val kind: String = "video",
    val label: String = "",
    val language: String = "",
    @SerialName("storage_key") val storageKey: String = "",
    @SerialName("external_url") val externalUrl: String = "",
    val mime: String = "",
    val height: Int = 0,
    @SerialName("size_bytes") val sizeBytes: Long = 0,
    @SerialName("is_default") val isDefault: Boolean = false,
    @SerialName("sort_order") val sortOrder: Int = 0,
)

@Serializable
data class AdminUserListDto(
    val items: List<UserDto> = emptyList(),
    val total: Int = 0,
)

@Serializable
data class AdminAnnouncementListDto(val items: List<AdminAnnouncementDto> = emptyList())

@Serializable
data class AdminAnnouncementDto(
    val id: Long = 0,
    val title: String = "",
    val body: String = "",
    val link: String = "",
    val audience: String = "all",
    val published: Boolean = true,
    @SerialName("starts_at") val startsAt: String = "",
    @SerialName("ends_at") val endsAt: String? = null,
)

@Serializable
data class LogEntryDto(
    val id: Long = 0,
    val level: String = "info",
    val code: String = "",
    val message: String = "",
    val context: String = "{}",
    @SerialName("user_id") val userId: Long = 0,
    @SerialName("created_at") val createdAt: String = "",
)

@Serializable
data class LogListDto(
    val items: List<LogEntryDto> = emptyList(),
    val total: Int = 0,
)

/** Storage — the upload pipeline the admin panel drives. */
@Serializable
data class UploadBeginRequest(
    @SerialName("anime_title") val animeTitle: String,
    @SerialName("anime_id") val animeId: Long = 0,
    val season: Int = 1,
    val episode: Int = 1,
    val filename: String,
    val size: Long,
    @SerialName("content_type") val contentType: String = "video/mp4",
)

@Serializable
data class UploadBeginDto(
    val key: String = "",
    @SerialName("upload_id") val uploadId: String = "",
    val parts: List<UploadPartDto> = emptyList(),
    @SerialName("part_size") val partSize: Long = 0,
)

@Serializable
data class UploadPartDto(
    @SerialName("part_number") val partNumber: Int = 0,
    val url: String = "",
)

@Serializable
data class UploadCompleteRequest(
    val key: String,
    @SerialName("upload_id") val uploadId: String,
    val parts: List<UploadedPartDto>,
)

@Serializable
data class UploadedPartDto(
    @SerialName("part_number") val partNumber: Int,
    val etag: String,
)

@Serializable
data class UploadCompleteDto(
    val key: String = "",
    val url: String = "",
)

/**
 * The upload endpoints answer inside an envelope, like the rest of the
 * plugin's REST surface.
 *
 * Reading the body straight into the payload looked like it worked:
 * `ignoreUnknownKeys` dropped the wrapper it did not recognise and every
 * field fell back to its default, so the plan arrived with an empty part
 * list and no upload id. Nothing threw — the upload simply sent no bytes
 * and asked the server to complete an upload of nothing.
 */
@Serializable
data class UploadBeginEnvelopeDto(val upload: UploadBeginDto = UploadBeginDto())

@Serializable
data class UploadCompleteEnvelopeDto(val upload: UploadCompleteDto = UploadCompleteDto())

@Serializable
data class UploadAbortedDto(val aborted: Boolean = false)

@Serializable
data class StorageTestDto(val result: StorageTestResultDto = StorageTestResultDto())

@Serializable
data class StorageTestResultDto(
    val bucket: String = "",
    val endpoint: String = "",
    @SerialName("latency_ms") val latencyMs: Int = 0,
)

@Serializable
data class AdminFontDto(
    val id: Long = 0,
    val family: String = "",
    val filename: String = "",
    val format: String = "",
    @SerialName("size_bytes") val sizeBytes: Long = 0,
    val url: String = "",
)

@Serializable
data class AdminFontListDto(val fonts: List<AdminFontDto> = emptyList())
