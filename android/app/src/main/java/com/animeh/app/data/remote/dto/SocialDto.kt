package com.animeh.app.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * Profiles, friends and watch-party rooms.
 *
 * The room DTOs describe only what the server owns: who opened a room, what is
 * playing in it and the code its link carries. The playhead and the chat are
 * Firebase's and never come through here.
 */

@Serializable
data class FirebaseConfigDto(
    @SerialName("api_key") val apiKey: String = "",
    @SerialName("app_id") val appId: String = "",
    @SerialName("project_id") val projectId: String = "",
    @SerialName("database_url") val databaseUrl: String = "",
    @SerialName("sender_id") val senderId: String = "",
) {
    /**
     * Whether Firebase can be started from this.
     *
     * The database URL is the one that decides: an install whose operator has
     * not set Firebase up sends an empty object, and the app then hides watch
     * parties rather than offering a screen that cannot connect.
     */
    val isUsable: Boolean
        get() = databaseUrl.isNotBlank() && appId.isNotBlank() && apiKey.isNotBlank()
}

@Serializable
data class ProfileStatsDto(
    @SerialName("episodes_started") val episodesStarted: Int = 0,
    @SerialName("episodes_completed") val episodesCompleted: Int = 0,
    @SerialName("seconds_watched") val secondsWatched: Long = 0,
    @SerialName("works_started") val worksStarted: Int = 0,
    @SerialName("works_completed") val worksCompleted: Int = 0,
    val favorites: Int = 0,
)

@Serializable
data class GenreSliceDto(val name: String = "", val count: Int = 0)

@Serializable
data class ProfileWorkDto(
    val id: Long = 0,
    val slug: String = "",
    val title: String = "",
    @SerialName("poster_url") val posterUrl: String = "",
    @SerialName("banner_url") val bannerUrl: String = "",
    val score: Double = 0.0,
    @Serializable(with = LenientBoolean::class) val adult: Boolean = false,
)

@Serializable
data class ProfileReviewDto(
    val id: Long = 0,
    @SerialName("work_id") val workId: Long = 0,
    @SerialName("work_title") val workTitle: String = "",
    @SerialName("work_poster") val workPoster: String = "",
    val score: Int = 0,
    val body: String = "",
    @Serializable(with = LenientBoolean::class) val spoiler: Boolean = false,
    @SerialName("up_votes") val upVotes: Int = 0,
    @SerialName("down_votes") val downVotes: Int = 0,
    @SerialName("created_at") val createdAt: String = "",
)

@Serializable
data class PublicProfileDto(
    val id: Long = 0,
    val username: String = "",
    @SerialName("display_name") val displayName: String = "",
    val avatar: String = "",
    val registered: String = "",
    @SerialName("is_self") @Serializable(with = LenientBoolean::class) val isSelf: Boolean = false,
    @SerialName("is_public") @Serializable(with = LenientBoolean::class) val isPublic: Boolean = true,
    val stats: ProfileStatsDto = ProfileStatsDto(),
    @SerialName("favorite_work") val favoriteWork: ProfileWorkDto? = null,
    @SerialName("top_genres") val topGenres: List<GenreSliceDto> = emptyList(),
    @SerialName("recent_works") val recentWorks: List<ProfileWorkDto> = emptyList(),
    val reviews: List<ProfileReviewDto> = emptyList(),
    /** "", "pending", "requested" or "accepted", from the viewer's side. */
    val friendship: String = "",
)

@Serializable
data class FavoriteWorkRequest(@SerialName("work_id") val workId: Long)

@Serializable
data class FavoriteWorkResponse(@SerialName("favorite_work") val favoriteWork: ProfileWorkDto? = null)

@Serializable
data class VisibilityRequest(val public: Boolean)

@Serializable
data class DeviceRequest(val token: String, val platform: String = "android")

@Serializable
data class FriendsDto(
    val friends: List<UserDto> = emptyList(),
    /** Waiting on you to answer. */
    val incoming: List<UserDto> = emptyList(),
    /** Waiting on them. */
    val outgoing: List<UserDto> = emptyList(),
)

@Serializable
data class FriendRequestBody(
    val email: String = "",
    val username: String = "",
    @SerialName("user_id") val userId: Long = 0,
)

@Serializable
data class RoomDto(
    val code: String = "",
    val host: UserDto = UserDto(),
    val work: WorkDto? = null,
    val episode: EpisodeDto? = null,
    @SerialName("created_at") val createdAt: String = "",
    /** The address an invite is shared as. */
    val link: String = "",
    /**
     * How many people have been in the room.
     *
     * Only sent by the listing — everywhere else the live count comes from
     * Firebase, which is the only place that knows who is present *now*.
     */
    val members: Int = 0,
    /** Whether the room is one you opened yourself. */
    @Serializable(with = LenientBoolean::class) val mine: Boolean = false,
)

/** The rooms open among your friends. */
@Serializable
data class RoomsDto(val rooms: List<RoomDto> = emptyList())

@Serializable
data class CreateRoomRequest(@SerialName("episode_id") val episodeId: Long)

@Serializable
data class InviteRequest(@SerialName("user_ids") val userIds: List<Long>)

/**
 * What came of an invitation.
 *
 * Two numbers because they answer different questions: [invited] is who is now
 * expected in the room, [notified] is how many of them had a phone told about
 * it. They differ when the server has no Firebase service account, which is
 * worth saying out loud rather than reporting a silent success.
 */
@Serializable
data class InviteResultDto(
    val invited: Int = 0,
    val notified: Int = 0,
)

/**
 * A chat line as the screen wants it.
 *
 * Not a DTO in the wire sense — chat never goes through the REST API — but it
 * lives here beside the rest of the room's shapes because that is where anyone
 * looking for "what does a room look like" will come.
 */
data class ChatMessageUi(
    val id: String,
    val uid: String,
    val name: String,
    val avatar: String,
    val text: String,
    /** Drawn on the right, in the accent colour. */
    val mine: Boolean,
)
