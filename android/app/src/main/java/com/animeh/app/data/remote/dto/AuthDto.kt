package com.animeh.app.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class SessionDto(
    @SerialName("access_token") val accessToken: String = "",
    @SerialName("refresh_token") val refreshToken: String = "",
    @SerialName("token_type") val tokenType: String = "Bearer",
    @SerialName("expires_in") val expiresIn: Long = 0,
    @SerialName("refresh_expires_in") val refreshExpiresIn: Long = 0,
    val user: UserDto = UserDto(),
)

@Serializable
data class UserDto(
    val id: Long = 0,
    val username: String = "",
    @SerialName("display_name") val displayName: String = "",
    val email: String = "",
    val avatar: String = "",
    val roles: List<String> = emptyList(),
    /**
     * Draws the admin tab and nothing else.
     *
     * §8: the server re-checks the capability on every admin endpoint, so a
     * client that forges this reaches the same 403 as anyone else.
     */
    @SerialName("is_admin") @Serializable(with = LenientBoolean::class) val isAdmin: Boolean = false,
    val registered: String = "",
    val stats: UserStatsDto? = null,
)

@Serializable
data class UserStatsDto(
    @SerialName("episodes_started") val episodesStarted: Int = 0,
    @SerialName("episodes_completed") val episodesCompleted: Int = 0,
    @SerialName("seconds_watched") val secondsWatched: Long = 0,
    @SerialName("works_started") val worksStarted: Int = 0,
    val favorites: Int = 0,
)

@Serializable
data class ProfileDto(
    val user: UserDto = UserDto(),
    val stats: UserStatsDto = UserStatsDto(),
    val settings: AppSettingsDto = AppSettingsDto(),
)

@Serializable
data class AppSettingsDto(
    val theme: String = "dark",
    val language: String = "tr",
    @SerialName("default_quality") val defaultQuality: String = "auto",
    @SerialName("subtitle_language") val subtitleLanguage: String = "tr",
    @SerialName("subtitles_enabled") @Serializable(with = LenientBoolean::class) val subtitlesEnabled: Boolean = true,
    @SerialName("autoplay_next") @Serializable(with = LenientBoolean::class) val autoplayNext: Boolean = true,
    @SerialName("skip_intro") @Serializable(with = LenientBoolean::class) val skipIntro: Boolean = true,
    @SerialName("wifi_only_download") @Serializable(with = LenientBoolean::class) val wifiOnlyDownload: Boolean = true,
    @Serializable(with = LenientBoolean::class) val notifications: Boolean = true,
    @SerialName("data_saver") @Serializable(with = LenientBoolean::class) val dataSaver: Boolean = false,
)

@Serializable
data class SettingsEnvelopeDto(val settings: AppSettingsDto = AppSettingsDto())

@Serializable
data class UserEnvelopeDto(val user: UserDto = UserDto())

@Serializable
data class LoginRequest(
    val login: String,
    val password: String,
    val device: String = "",
)

@Serializable
data class RegisterRequest(
    val username: String,
    val email: String,
    val password: String,
    val device: String = "",
)

@Serializable
data class RefreshRequest(
    @SerialName("refresh_token") val refreshToken: String,
    val device: String = "",
)

@Serializable
data class ForgotPasswordRequest(val login: String)

@Serializable
data class ChangePasswordRequest(
    @SerialName("current_password") val currentPassword: String,
    @SerialName("new_password") val newPassword: String,
)

@Serializable
data class ProgressRequest(
    @SerialName("episode_id") val episodeId: Long,
    @SerialName("position_seconds") val positionSeconds: Int,
    @SerialName("duration_seconds") val durationSeconds: Int = 0,
)

@Serializable
data class UpdateProfileRequest(
    @SerialName("display_name") val displayName: String? = null,
    val email: String? = null,
)

/**
 * WordPress's error body. Every `WP_Error` a REST route returns serialises to
 * exactly this, which is why one shape covers every failure from the backend.
 */
@Serializable
data class ApiErrorDto(
    val code: String = "",
    val message: String = "",
    val data: ApiErrorDataDto? = null,
)

@Serializable
data class ApiErrorDataDto(
    val status: Int = 0,
    @SerialName("retry_after") val retryAfter: Int = 0,
    val reason: String = "",
)
