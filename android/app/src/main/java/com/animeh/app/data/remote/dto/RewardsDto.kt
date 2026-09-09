package com.animeh.app.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * Points, frames and the standings.
 *
 * One file because they are one feature on the server too: watching earns
 * points, points buy frames, and the standings are the reason to care.
 */

@Serializable
data class FrameDto(
    val id: Long = 0,
    val slug: String = "",
    val name: String = "",
    val url: String = "",
    /**
     * Whether the file actually moves.
     *
     * Decided by the server when the file was uploaded, by looking at its
     * bytes — an `acTL` chunk, an `ANMF` chunk, a second image descriptor.
     * The app uses it to pick a decoder: running a still picture through the
     * animated one costs more than it should on every avatar in a list.
     */
    val animated: Boolean = false,
    val rarity: String = "common",
    val price: Int = 0,
    val owned: Boolean = false,
    val equipped: Boolean = false,
    /** Owned, but no longer for sale. */
    val retired: Boolean = false,
    // Panel-only fields; absent from the shop payload.
    val published: Boolean = true,
    @SerialName("sort_order") val sortOrder: Int = 0,
    val width: Int = 0,
    @SerialName("size_bytes") val sizeBytes: Long = 0,
    val format: String = "",
)

@Serializable
data class WalletDto(
    val balance: Int = 0,
    /** What has been earned in total, ignoring what has been spent. */
    val earned: Int = 0,
    @SerialName("per_episode") val perEpisode: Int = 20,
    val entries: List<PointEntryDto> = emptyList(),
    /**
     * Where this viewer stands on each board.
     *
     * Absent from `/me`, present here: counting three ranks is six queries,
     * and `/me` runs on every launch while this runs when somebody opens
     * their own profile — which is when a rank is worth the work.
     */
    val ranks: RanksDto = RanksDto(),
)

@Serializable
data class RanksDto(
    val works: Int = 0,
    val seconds: Int = 0,
    val episodes: Int = 0,
)

@Serializable
data class PointEntryDto(
    val id: Long = 0,
    val delta: Int = 0,
    val reason: String = "",
    val label: String = "",
    @SerialName("created_at") val createdAt: String = "",
)

@Serializable
data class FrameShopDto(
    val balance: Int = 0,
    val equipped: Long = 0,
    val frames: List<FrameDto> = emptyList(),
)

@Serializable
data class FramePurchaseDto(
    val owned: Boolean = false,
    val balance: Int = 0,
)

@Serializable
data class EquipFrameDto(
    val equipped: Long = 0,
    val frame: FrameDto? = null,
)

@Serializable
data class ThemeDto(val theme: String = "amethyst")

@Serializable
data class FrameEnvelopeDto(val frame: FrameDto = FrameDto())

@Serializable
data class FrameListDto(val frames: List<FrameDto> = emptyList())

@Serializable
data class LeaderboardDto(
    val metric: String = "",
    val entries: List<LeaderboardEntryDto> = emptyList(),
    /** Where the viewer stands, whether or not they made the visible list. */
    val me: StandingDto? = null,
)

@Serializable
data class LeaderboardEntryDto(
    val rank: Int = 0,
    val value: Long = 0,
    @SerialName("user_id") val userId: Long = 0,
    @SerialName("display_name") val displayName: String = "",
    val username: String = "",
    val avatar: String = "",
    val frame: FrameDto? = null,
    val theme: String = "amethyst",
    @SerialName("is_me") val isMe: Boolean = false,
)

@Serializable
data class StandingDto(
    val rank: Int = 0,
    val value: Long = 0,
    /** How many people have a score on this board at all. */
    val total: Int = 0,
)

@Serializable
data class GrantPointsDto(
    val balance: Int = 0,
    val granted: Int = 0,
)

@Serializable
data class EquipFrameRequest(@SerialName("frame_id") val frameId: Long)

@Serializable
data class ProfileThemeRequest(val theme: String)

@Serializable
data class FrameUpdateRequest(
    val name: String? = null,
    val price: Int? = null,
    val rarity: String? = null,
    @SerialName("sort_order") val sortOrder: Int? = null,
    val published: Boolean? = null,
)

@Serializable
data class GrantPointsRequest(
    val amount: Int,
    val note: String = "",
)
