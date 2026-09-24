package com.animeh.app.data.repository

import com.animeh.app.core.AppResult
import com.animeh.app.data.local.SnapshotCache
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
 * A balance that is one screen out of date is a balance somebody has already
 * spent, and a board read from disk is a board showing yesterday — both are
 * numbers whose whole value is being current, so nothing here is ever *only*
 * read from the cache. What [SnapshotCache] adds is a screen having something
 * to draw on the frame it opens, rather than a spinner until the request that
 * follows in the very same breath comes back; the server's own five-minute
 * cache is still what decides how current that first paint can be.
 */
@Singleton
class RewardsRepository @Inject constructor(
    private val publicApi: PublicApi,
    private val userApi: UserApi,
    private val snapshotCache: SnapshotCache,
) {

    suspend fun cachedLeaderboard(metric: String): LeaderboardDto? =
        snapshotCache.read(leaderboardKey(metric))

    suspend fun leaderboard(metric: String, limit: Int = 25): AppResult<LeaderboardDto> =
        ApiErrorMapper.call { publicApi.leaderboard(metric, limit) }
            .also { result ->
                if (result is AppResult.Success) snapshotCache.write(leaderboardKey(metric), result.data)
            }

    suspend fun cachedWallet(): WalletDto? = snapshotCache.read(WALLET_KEY)

    suspend fun wallet(limit: Int = 30): AppResult<WalletDto> =
        ApiErrorMapper.call { userApi.wallet(limit) }
            .also { result ->
                if (result is AppResult.Success) snapshotCache.write(WALLET_KEY, result.data)
            }

    suspend fun cachedFrames(): FrameShopDto? = snapshotCache.read(FRAMES_KEY)

    suspend fun frames(): AppResult<FrameShopDto> =
        ApiErrorMapper.call { userApi.frames() }
            .also { result ->
                if (result is AppResult.Success) snapshotCache.write(FRAMES_KEY, result.data)
            }

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
        const val METRIC_POINTS = "points"

        private const val WALLET_KEY = "wallet"
        private const val FRAMES_KEY = "frames"

        /** One snapshot per board — switching tabs must not overwrite another. */
        private fun leaderboardKey(metric: String) = "leaderboard:$metric"
    }
}
