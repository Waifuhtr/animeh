package com.animeh.app.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * AnimehTok, over the wire.
 *
 * A short is not a [WorkDto] and deliberately shares nothing with one. The
 * manga reader was built the other way — a chapter is an episode row — and
 * that bought a great deal for free, at the cost of every "watched" number
 * being wrong until each query learned to say `kind`. This shelf does not take
 * that trade: its own tables on the server, its own shapes here, its own
 * numbers on the profile.
 */

@Serializable
data class ShortDto(
    val id: Long = 0,
    val slug: String = "",
    val description: String = "",
    /** Parsed out of the description by the server, in the order written. */
    val tags: List<String> = emptyList(),
    @SerialName("video_url") val videoUrl: String = "",
    @SerialName("cover_url") val coverUrl: String = "",
    @SerialName("duration_ms") val durationMs: Long = 0,
    val width: Int = 0,
    val height: Int = 0,
    @Serializable(with = LenientBoolean::class) val adult: Boolean = false,
    @Serializable(with = LenientBoolean::class) val published: Boolean = true,
    @SerialName("view_count") val viewCount: Long = 0,
    @SerialName("like_count") val likeCount: Long = 0,
    @SerialName("comment_count") val commentCount: Long = 0,
    @SerialName("save_count") val saveCount: Long = 0,
    @Serializable(with = LenientBoolean::class) val liked: Boolean = false,
    @Serializable(with = LenientBoolean::class) val saved: Boolean = false,
    @SerialName("created_at") val createdAt: String = "",
    val creator: ShortCreatorDto = ShortCreatorDto(),
    @Serializable(with = LenientBoolean::class) val following: Boolean = false,
    @SerialName("is_mine") @Serializable(with = LenientBoolean::class) val isMine: Boolean = false,
    val sound: ShortSoundDto? = null,
)

@Serializable
data class ShortCreatorDto(
    val id: Long = 0,
    val username: String = "",
    @SerialName("display_name") val displayName: String = "",
    val avatar: String = "",
)

/**
 * A sound.
 *
 * It has no file of its own: it is the audio of the video it came from, and
 * [originId] is the video the sound page plays. Storing a second copy of the
 * same bytes would double the storage bill for nothing.
 */
@Serializable
data class ShortSoundDto(
    val id: Long = 0,
    val title: String = "",
    val author: String = "",
    @SerialName("use_count") val useCount: Long = 0,
    @SerialName("origin_id") val originId: Long = 0,
    @SerialName("cover_url") val coverUrl: String = "",
)

@Serializable
data class ShortFeedDto(
    val items: List<ShortDto> = emptyList(),
    val tab: String = "foryou",
)

@Serializable
data class ShortCommentDto(
    val id: Long = 0,
    @SerialName("short_id") val shortId: Long = 0,
    @SerialName("parent_id") val parentId: Long = 0,
    val body: String = "",
    @SerialName("like_count") val likeCount: Long = 0,
    @SerialName("reply_count") val replyCount: Long = 0,
    @Serializable(with = LenientBoolean::class) val liked: Boolean = false,
    @SerialName("is_mine") @Serializable(with = LenientBoolean::class) val isMine: Boolean = false,
    @SerialName("created_at") val createdAt: String = "",
    val author: ShortCreatorDto = ShortCreatorDto(),
)

@Serializable
data class ShortCommentListDto(
    val items: List<ShortCommentDto> = emptyList(),
    val total: Long = 0,
)

@Serializable
data class ShortTagDto(
    val tag: String = "",
    val key: String = "",
    val count: Int = 0,
)

@Serializable
data class ShortTagListDto(val items: List<ShortTagDto> = emptyList())

@Serializable
data class ShortTagPageDto(
    val tag: String = "",
    val key: String = "",
    val count: Int = 0,
    val items: List<ShortDto> = emptyList(),
)

@Serializable
data class ShortSoundPageDto(
    val sound: ShortSoundDto = ShortSoundDto(),
    val items: List<ShortDto> = emptyList(),
)

@Serializable
data class ShortCreatorPageDto(
    val creator: ShortCreatorDto = ShortCreatorDto(),
    val stats: ShortStatsDto = ShortStatsDto(),
    @Serializable(with = LenientBoolean::class) val following: Boolean = false,
    @SerialName("is_self") @Serializable(with = LenientBoolean::class) val isSelf: Boolean = false,
    val items: List<ShortDto> = emptyList(),
)

/**
 * The AnimehTok numbers.
 *
 * Its own block in its own units, beside the anime and manga ones rather than
 * added into them. Nothing here is watch time and nothing here is worth a
 * point: scrolling a feed is not watching an episode.
 */
@Serializable
data class ShortStatsDto(
    val videos: Int = 0,
    @SerialName("likes_received") val likesReceived: Long = 0,
    @SerialName("likes_given") val likesGiven: Long = 0,
    val views: Long = 0,
    val comments: Long = 0,
    val followers: Int = 0,
    val following: Int = 0,
)

@Serializable
data class ShortStatsEnvelopeDto(
    val stats: ShortStatsDto = ShortStatsDto(),
    val creator: ShortCreatorDto = ShortCreatorDto(),
)

@Serializable
data class ShortListDto(val items: List<ShortDto> = emptyList())

@Serializable
data class ShortSearchDto(
    val videos: List<ShortDto> = emptyList(),
    val tags: List<ShortTagDto> = emptyList(),
    val sounds: List<ShortSoundDto> = emptyList(),
    val creators: List<ShortCreatorResultDto> = emptyList(),
)

@Serializable
data class ShortCreatorResultDto(
    val creator: ShortCreatorDto = ShortCreatorDto(),
    val stats: ShortStatsDto = ShortStatsDto(),
)

@Serializable
data class ShortFollowDto(
    @Serializable(with = LenientBoolean::class) val following: Boolean = false,
    val stats: ShortStatsDto = ShortStatsDto(),
)

/* ── Requests ────────────────────────────────────────────────────────── */

@Serializable
data class ShortUploadBeginRequest(
    val filename: String,
    val size: Long,
    @SerialName("content_type") val contentType: String = "video/mp4",
    /**
     * Sent up front because the slug is derived from it.
     *
     * The slug is the file name in the bucket and the video's address, and
     * neither is the phone's to choose.
     */
    val description: String = "",
)

/**
 * The upload plan, plus the slug the server picked.
 *
 * Shaped like [UploadBeginDto] because it is the same B2 multipart plan, but
 * it is its own type: this one carries the slug and lives outside the
 * envelope the storage endpoints wrap theirs in.
 */
@Serializable
data class ShortUploadPlanDto(
    val key: String = "",
    @SerialName("upload_id") val uploadId: String = "",
    @SerialName("part_size") val partSize: Long = 0,
    val parts: List<UploadPartDto> = emptyList(),
    val slug: String = "",
)

@Serializable
data class ShortUploadCompleteRequest(
    val key: String,
    @SerialName("upload_id") val uploadId: String,
    val slug: String,
    val parts: List<UploadedPartDto>,
    val description: String = "",
    @SerialName("duration_ms") val durationMs: Long = 0,
    val width: Int = 0,
    val height: Int = 0,
    val size: Long = 0,
    @SerialName("sound_title") val soundTitle: String = "",
    @SerialName("sound_id") val soundId: Long = 0,
    val adult: Boolean = false,
)

@Serializable
data class ShortCommentRequest(
    val body: String,
    val parent: Long = 0,
)

@Serializable
data class ShortUpdateRequest(val description: String)

/**
 * Every video, for a moderator.
 *
 * Its own envelope because this one carries a total: the moderation list pages
 * through everything ever uploaded, and the feed never does.
 */
@Serializable
data class AdminShortListDto(
    val items: List<ShortDto> = emptyList(),
    val total: Int = 0,
)
