package com.animeh.app.data.repository

import com.animeh.app.core.AppResult
import com.animeh.app.data.remote.ApiErrorMapper
import com.animeh.app.data.remote.PublicApi
import com.animeh.app.data.remote.UserApi
import com.animeh.app.data.remote.dto.EquipFrameRequest
import com.animeh.app.data.remote.dto.FrameDto
import com.animeh.app.data.remote.dto.FrameShopDto
import com.animeh.app.data.remote.dto.LeaderboardDto
import com.animeh.app.data.remote.dto.ProfileThemeRequest
import com.animeh.app.data.remote.dto.WalletDto
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Points, frames and the standings.
 *
 * Nothing is cached in Room. A balance that is one screen out of date is a
 * balance somebody has already spent, and a leaderboard read from disk is a
 * leaderboard showing yesterday — both of them are numbers whose whole value
 * is being current. The server caches the expensive one for five minutes,
 * which is the right place for it.
 */
@Singleton
class RewardsRepository @Inject constructor(
    private val publicApi: PublicApi,
    private val userApi: UserApi,
) {

    suspend fun leaderboard(metric: String, limit: Int = 25): AppResult<LeaderboardDto> =
        ApiErrorMapper.call { publicApi.leaderboard(metric, limit) }

    suspend fun wallet(limit: Int = 30): AppResult<WalletDto> =
        ApiErrorMapper.call { userApi.wallet(limit) }

    suspend fun frames(): AppResult<FrameShopDto> =
        ApiErrorMapper.call { userApi.frames() }

    /** The new balance, or the error the server refused with. */
    suspend fun buy(frameId: Long): AppResult<Int> =
        ApiErrorMapper.call({ it.balance }) { userApi.buyFrame(frameId) }

    /** Zero takes the frame off, which is how somebody goes back to none. */
    suspend fun equip(frameId: Long): AppResult<FrameDto?> =
        ApiErrorMapper.call({ it.frame }) { userApi.equipFrame(EquipFrameRequest(frameId)) }

    suspend fun setTheme(theme: String): AppResult<String> =
        ApiErrorMapper.call({ it.theme }) { userApi.setProfileTheme(ProfileThemeRequest(theme)) }

    companion object {
        const val METRIC_WORKS = "works"
        const val METRIC_SECONDS = "seconds"
        const val METRIC_EPISODES = "episodes"
    }
}
