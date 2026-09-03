package com.animeh.app.data.repository

import com.animeh.app.core.AppResult
import com.animeh.app.data.remote.ApiErrorMapper
import com.animeh.app.data.remote.PublicApi
import com.animeh.app.data.remote.UserApi
import com.animeh.app.data.remote.dto.CreateRoomRequest
import com.animeh.app.data.remote.dto.DeviceRequest
import com.animeh.app.data.remote.dto.FavoriteWorkRequest
import com.animeh.app.data.remote.dto.FriendRequestBody
import com.animeh.app.data.remote.dto.FriendsDto
import com.animeh.app.data.remote.dto.InviteRequest
import com.animeh.app.data.remote.dto.InviteResultDto
import com.animeh.app.data.remote.dto.PublicProfileDto
import com.animeh.app.data.remote.dto.ProfileWorkDto
import com.animeh.app.data.remote.dto.RoomDto
import com.animeh.app.data.remote.dto.VisibilityRequest
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Profiles, friends and the durable half of a watch party.
 *
 * The realtime half is [com.animeh.app.social.WatchPartyClient]. The split is
 * the whole design: this decides who may open a room and who may be invited to
 * it, because those are decisions that have to be made somewhere a client
 * cannot lie about them; Firebase carries the playhead and the chat, because
 * those change several times a second.
 */
@Singleton
class SocialRepository @Inject constructor(
    private val publicApi: PublicApi,
    private val userApi: UserApi,
) {

    suspend fun profile(userId: Long): AppResult<PublicProfileDto> =
        ApiErrorMapper.call { publicApi.profile(userId) }

    /** Zero clears it, which is how someone stops showing one. */
    suspend fun setFavoriteWork(workId: Long): AppResult<ProfileWorkDto?> =
        ApiErrorMapper.call({ it.favoriteWork }) { userApi.setFavoriteWork(FavoriteWorkRequest(workId)) }

    suspend fun setProfileVisibility(public: Boolean): AppResult<Boolean> =
        ApiErrorMapper.call({ it.public }) { userApi.setProfileVisibility(VisibilityRequest(public)) }

    suspend fun registerDevice(token: String): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { userApi.registerDevice(DeviceRequest(token)) }

    suspend fun forgetDevice(token: String): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { userApi.forgetDevice(DeviceRequest(token)) }

    // -- Friends ------------------------------------------------------------

    suspend fun friends(): AppResult<FriendsDto> =
        ApiErrorMapper.call { userApi.friends() }

    /**
     * Ask to be someone's friend, by whichever handle you have.
     *
     * The server decides which of the three to use and refuses if none of them
     * names an account.
     */
    suspend fun requestFriend(
        email: String = "",
        username: String = "",
        userId: Long = 0,
    ): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) {
            userApi.requestFriend(FriendRequestBody(email.trim(), username.trim(), userId))
        }

    suspend fun acceptFriend(userId: Long): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { userApi.acceptFriend(userId) }

    /** Declining a request and dropping a friend are the same call. */
    suspend fun removeFriend(userId: Long): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { userApi.removeFriend(userId) }

    // -- Rooms --------------------------------------------------------------

    /**
     * The rooms open among your friends, and your own.
     *
     * The list the Odalar tab draws. It exists because a push notification is
     * not a delivery guarantee — it needs Firebase configured, the notification
     * permission granted and a reachable phone — and a room somebody opened
     * should still be findable when none of that held.
     */
    suspend fun rooms(): AppResult<List<RoomDto>> =
        ApiErrorMapper.call({ it.rooms }) { userApi.rooms() }

    suspend fun createRoom(episodeId: Long): AppResult<RoomDto> =
        ApiErrorMapper.call { userApi.createRoom(CreateRoomRequest(episodeId)) }

    suspend fun room(code: String): AppResult<RoomDto> =
        ApiErrorMapper.call { userApi.room(code) }

    /** Entering a room is also what tells the server it is still alive. */
    suspend fun joinRoom(code: String): AppResult<RoomDto> =
        ApiErrorMapper.call { userApi.joinRoom(code) }

    suspend fun closeRoom(code: String): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { userApi.closeRoom(code) }

    suspend fun invite(code: String, userIds: List<Long>): AppResult<InviteResultDto> =
        ApiErrorMapper.call { userApi.invite(code, InviteRequest(userIds)) }
}
