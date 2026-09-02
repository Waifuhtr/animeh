package com.animeh.app.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * Reviews, the votes on them, and the labels the catalog is shown under.
 */

@Serializable
data class ReviewDto(
    val id: Long = 0,
    @SerialName("work_id") val workId: Long = 0,
    @SerialName("user_id") val userId: Long = 0,
    val author: String = "",
    @SerialName("author_avatar") val authorAvatar: String = "",
    val score: Int = 0,
    val body: String = "",
    @Serializable(with = LenientBoolean::class) val spoiler: Boolean = false,
    @SerialName("up_votes") val upVotes: Int = 0,
    @SerialName("down_votes") val downVotes: Int = 0,
    /** How this reader voted: -1, 0 or 1. */
    @SerialName("my_vote") val myVote: Int = 0,
    @Serializable(with = LenientBoolean::class) val mine: Boolean = false,
    @SerialName("created_at") val createdAt: String = "",
    @SerialName("updated_at") val updatedAt: String = "",
)

@Serializable
data class ReviewListDto(
    val items: List<ReviewDto> = emptyList(),
    val total: Int = 0,
    /** The community's average out of ten, 0 when nobody has scored it. */
    val rating: Double = 0.0,
    @SerialName("rating_count") val ratingCount: Int = 0,
    /** This reader's own review, so the form opens filled in. */
    val mine: ReviewDto? = null,
)

@Serializable
data class ReviewEnvelopeDto(val review: ReviewDto? = null)

@Serializable
data class ReviewRequest(
    val score: Int,
    val body: String = "",
    val spoiler: Boolean = false,
)

@Serializable
data class VoteRequest(val vote: Int)

/**
 * Display names, keyed by vocabulary and then by the stored value.
 *
 * The stored value stays the key everywhere — filters, Room, the URL — and
 * this only says what to draw instead.
 */
@Serializable
data class TermsDto(
    val terms: Map<String, Map<String, String>> = emptyMap(),
)

@Serializable
data class AdminTermDto(
    val kind: String = "genre",
    val source: String = "",
    /** Empty when this value has no override and is shown as imported. */
    val display: String = "",
)

@Serializable
data class AdminTermListDto(val items: List<AdminTermDto> = emptyList())

@Serializable
data class AdminTermRequest(
    val kind: String,
    val source: String,
    val display: String,
)

@Serializable
data class AvatarDto(
    val avatar: String = "",
    val user: UserDto? = null,
)
