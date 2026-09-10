package com.animeh.app.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * Reading a chapter, and the two ways manga get into the catalogue.
 *
 * A manga is a [WorkDto] with `kind = "manga"` and a chapter is an
 * [EpisodeDto] of it, so there is far less here than the feature suggests:
 * browsing, the library, history, progress and the points for finishing
 * something all came for free the moment those rows existed.
 */

@Serializable
data class ChapterPagesDto(
    val chapter: EpisodeDto = EpisodeDto(),
    val work: WorkDto = WorkDto(),
    val pages: List<PageDto> = emptyList(),
    val next: EpisodeDto? = null,
    val previous: EpisodeDto? = null,
    val progress: ReadProgressDto? = null,
)

@Serializable
data class PageDto(
    val position: Int = 0,
    val url: String = "",
    /**
     * Every other address for the same image, in the order to try them.
     *
     * Backblaze answers one object at a friendly URL and an S3 one, and the
     * friendly host fails on its own for minutes at a time. The reader moves
     * down this list when a load fails rather than leaving a hole in the
     * middle of a chapter.
     */
    @SerialName("fallback_urls") val fallbackUrls: List<String> = emptyList(),
    val mime: String = "",
    @SerialName("size_bytes") val sizeBytes: Long = 0,
    /** True once the file is in our own bucket rather than the source's. */
    @Serializable(with = LenientBoolean::class) val mirrored: Boolean = false,
) {
    /** Where to look, best first. */
    val candidates: List<String> get() = (listOf(url) + fallbackUrls).filter { it.isNotBlank() }
}

@Serializable
data class ReadProgressDto(
    val position: Int = 0,
    val total: Int = 0,
    @Serializable(with = LenientBoolean::class) val completed: Boolean = false,
)

/* ── Admin ───────────────────────────────────────────────────────────── */

@Serializable
data class MangaBridgeDto(
    val url: String = "",
    /** Whether a key is stored. The key itself is never sent back. */
    @SerialName("has_key") @Serializable(with = LenientBoolean::class) val hasKey: Boolean = false,
    @SerialName("connected_at") val connectedAt: String = "",
    val site: String = "",
    val sync: MangaSyncStateDto = MangaSyncStateDto(),
    val mirror: MirrorProgressDto = MirrorProgressDto(),
    val counts: MangaCountsDto = MangaCountsDto(),
    val remote: BridgeRemoteDto? = null,
)

@Serializable
data class BridgeRemoteDto(
    val site: String = "",
    val home: String = "",
    val manga: Int = 0,
    val chapters: Int = 0,
)

@Serializable
data class MangaSyncStateDto(
    val page: Int = 1,
    val pages: Int = 0,
    val total: Int = 0,
    val imported: Int = 0,
    val chapters: Int = 0,
    @SerialName("finished_at") val finishedAt: String = "",
    @SerialName("last_error") val lastError: String = "",
)

@Serializable
data class MirrorProgressDto(
    val total: Int = 0,
    val mirrored: Int = 0,
    val pending: Int = 0,
) {
    val fraction: Float get() = if (total > 0) mirrored.toFloat() / total else 0f
}

@Serializable
data class MangaCountsDto(
    val works: Int = 0,
    val chapters: Int = 0,
    val pages: Int = 0,
)

@Serializable
data class MangaSyncResultDto(
    @Serializable(with = LenientBoolean::class) val done: Boolean = false,
    val page: Int = 0,
    val pages: Int = 0,
    val total: Int = 0,
    val chapters: Int = 0,
    val next: Int = 0,
    val imported: List<ImportedMangaDto> = emptyList(),
    val counts: MangaCountsDto = MangaCountsDto(),
    val mirror: MirrorProgressDto = MirrorProgressDto(),
)

@Serializable
data class ImportedMangaDto(
    @SerialName("work_id") val workId: Long = 0,
    val title: String = "",
    val chapters: Int = 0,
)

@Serializable
data class MirrorResultDto(
    @Serializable(with = LenientBoolean::class) val done: Boolean = false,
    val copied: Int = 0,
    val total: Int = 0,
    val mirrored: Int = 0,
    val pending: Int = 0,
    val failed: List<MirrorFailureDto> = emptyList(),
)

@Serializable
data class MirrorFailureDto(
    val id: Long = 0,
    val url: String = "",
    val message: String = "",
)

@Serializable
data class MangaSearchDto(
    val source: String = "",
    val items: List<MangaSearchItemDto> = emptyList(),
)

@Serializable
data class MangaSearchItemDto(
    val source: String = "",
    val id: Long = 0,
    val title: String = "",
    @SerialName("title_english") val titleEnglish: String = "",
    @SerialName("poster_url") val posterUrl: String = "",
    val year: Int = 0,
    val score: Double = 0.0,
    val chapters: Int = 0,
    val format: String = "",
    @Serializable(with = LenientBoolean::class) val adult: Boolean = false,
)

@Serializable
data class MangaImportResultDto(
    @SerialName("work_id") val workId: Long = 0,
    @Serializable(with = LenientBoolean::class) val created: Boolean = false,
    val chapters: Int = 0,
    // Nullable because the server sends null when it could not read the row
    // back: a non-null default does not save a property that arrives as null.
    val work: WorkDto? = null,
)

@Serializable
data class GallerySourceDto(
    @Serializable(with = LenientBoolean::class) val enabled: Boolean = false,
    @SerialName("has_key") @Serializable(with = LenientBoolean::class) val hasKey: Boolean = false,
)

/* ── Requests ────────────────────────────────────────────────────────── */

@Serializable
data class BridgeSaveRequest(
    val url: String,
    val key: String = "",
    val test: Boolean = true,
)

@Serializable
data class MangaSyncRequest(
    val page: Int = 0,
    val reset: Boolean = false,
)

@Serializable
data class MangaImportRequest(
    val id: Long,
    val source: String,
    @SerialName("with_pages") val withPages: Boolean = true,
)

@Serializable
data class GallerySourceRequest(
    val enabled: Boolean,
    val key: String = "",
)
