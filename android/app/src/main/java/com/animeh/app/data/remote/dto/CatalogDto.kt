package com.animeh.app.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * Wire shapes for the catalog endpoints.
 *
 * Every field has a default. The backend is a WordPress plugin that will gain
 * fields over time, and a DTO without defaults turns "the server added a
 * column" into "the app crashes on every response".
 */

@Serializable
data class WorkDto(
    val id: Long = 0,
    val slug: String = "",
    val kind: String = "anime",
    val title: String = "",
    @SerialName("title_english") val titleEnglish: String = "",
    @SerialName("title_japanese") val titleJapanese: String = "",
    val synopsis: String = "",
    @SerialName("poster_url") val posterUrl: String = "",
    @SerialName("banner_url") val bannerUrl: String = "",
    @SerialName("trailer_url") val trailerUrl: String = "",
    val score: Double = 0.0,
    val year: Int = 0,
    val season: String = "",
    val status: String = "",
    val format: String = "",
    val rating: String = "",
    val studio: String = "",
    val genres: List<String> = emptyList(),
    val synonyms: List<String> = emptyList(),
    @SerialName("total_episodes") val totalEpisodes: Int = 0,
    @SerialName("duration_seconds") val durationSeconds: Int = 0,
    val published: Boolean = true,
    @SerialName("updated_at") val updatedAt: String = "",
    // Present only on the detail endpoint.
    val seasons: List<SeasonDto> = emptyList(),
    @SerialName("is_favorite") val isFavorite: Boolean = false,
    @SerialName("in_watchlist") val inWatchlist: Boolean = false,
)

@Serializable
data class SeasonDto(
    val number: Int = 1,
    val title: String = "",
    @SerialName("episode_count") val episodeCount: Int = 0,
)

@Serializable
data class EpisodeDto(
    val id: Long = 0,
    @SerialName("work_id") val workId: Long = 0,
    @SerialName("season_number") val seasonNumber: Int = 1,
    val number: Int = 0,
    val title: String = "",
    val synopsis: String = "",
    @SerialName("thumbnail_url") val thumbnailUrl: String = "",
    @SerialName("duration_seconds") val durationSeconds: Int = 0,
    val filler: Boolean = false,
    val published: Boolean = true,
    @SerialName("published_at") val publishedAt: String = "",
    val progress: ProgressDto? = null,
    // Present on the "new episodes" rail, where the work is joined in.
    @SerialName("work_title") val workTitle: String = "",
    @SerialName("work_slug") val workSlug: String = "",
    @SerialName("work_poster") val workPoster: String = "",
    @SerialName("source_counts") val sourceCounts: SourceCountsDto? = null,
)

@Serializable
data class SourceCountsDto(
    val video: Int = 0,
    val subtitle: Int = 0,
)

@Serializable
data class ProgressDto(
    @SerialName("position_seconds") val positionSeconds: Int = 0,
    @SerialName("duration_seconds") val durationSeconds: Int = 0,
    val completed: Boolean = false,
)

@Serializable
data class WorkListDto(
    val items: List<WorkDto> = emptyList(),
    val total: Int = 0,
    val page: Int = 1,
)

@Serializable
data class EpisodeListDto(
    val items: List<EpisodeDto> = emptyList(),
    val total: Int = 0,
)

@Serializable
data class HomeDto(
    val hero: List<WorkDto> = emptyList(),
    val popular: List<WorkDto> = emptyList(),
    @SerialName("recently_added") val recentlyAdded: List<WorkDto> = emptyList(),
    val airing: List<WorkDto> = emptyList(),
    @SerialName("latest_episodes") val latestEpisodes: List<EpisodeDto> = emptyList(),
    @SerialName("continue") val continueWatching: List<HistoryDto> = emptyList(),
)

@Serializable
data class HistoryDto(
    @SerialName("work_id") val workId: Long = 0,
    @SerialName("work_title") val workTitle: String = "",
    @SerialName("work_slug") val workSlug: String = "",
    @SerialName("poster_url") val posterUrl: String = "",
    @SerialName("episode_id") val episodeId: Long = 0,
    @SerialName("episode_number") val episodeNumber: Int = 0,
    @SerialName("season_number") val seasonNumber: Int = 1,
    @SerialName("episode_title") val episodeTitle: String = "",
    @SerialName("thumbnail_url") val thumbnailUrl: String = "",
    @SerialName("position_seconds") val positionSeconds: Int = 0,
    @SerialName("duration_seconds") val durationSeconds: Int = 0,
    val completed: Boolean = false,
    @SerialName("updated_at") val updatedAt: String = "",
)

@Serializable
data class HistoryListDto(
    val items: List<HistoryDto> = emptyList(),
    val page: Int = 1,
)

@Serializable
data class GenreDto(val name: String = "", val count: Int = 0)

@Serializable
data class GenreListDto(val genres: List<GenreDto> = emptyList())

/**
 * Everything needed to start one episode, in one response.
 *
 * The single round trip is the point: on the connection this app targets, four
 * requests before the first frame is four chances to stall.
 */
@Serializable
data class PlaybackDto(
    val episode: EpisodeDto = EpisodeDto(),
    val work: WorkDto = WorkDto(),
    val videos: List<MediaSourceDto> = emptyList(),
    val subtitles: List<MediaSourceDto> = emptyList(),
    val fonts: List<FontDto> = emptyList(),
    val markers: MarkersDto = MarkersDto(),
    val next: EpisodeDto? = null,
    val previous: EpisodeDto? = null,
    val resume: ProgressDto? = null,
)

@Serializable
data class MediaSourceDto(
    val id: Long = 0,
    val kind: String = "video",
    val label: String = "",
    val language: String = "",
    val mime: String = "",
    val height: Int = 0,
    @SerialName("size_bytes") val sizeBytes: Long = 0,
    @SerialName("is_default") val isDefault: Boolean = false,
    val url: String = "",
    /** Tried in order when [url] refuses to serve — the friendly-to-S3 failover. */
    @SerialName("fallback_urls") val fallbackUrls: List<String> = emptyList(),
    @SerialName("expires_in") val expiresIn: Int = 0,
)

@Serializable
data class FontDto(
    val family: String = "",
    val url: String = "",
    val origin: String = "",
)

/** -1 means "not marked"; 0 is a real marker at the very start. */
@Serializable
data class MarkersDto(
    @SerialName("intro_start") val introStart: Int = -1,
    @SerialName("intro_end") val introEnd: Int = -1,
    @SerialName("outro_start") val outroStart: Int = -1,
)

@Serializable
data class AnnouncementDto(
    val id: Long = 0,
    val title: String = "",
    val body: String = "",
    val link: String = "",
    val audience: String = "all",
)

@Serializable
data class AnnouncementListDto(val announcements: List<AnnouncementDto> = emptyList())

@Serializable
data class OkDto(val ok: Boolean = true)
