package com.animeh.app.social

import com.animeh.app.core.AppResult
import com.animeh.app.data.prefs.SessionStore
import com.animeh.app.data.prefs.user
import com.animeh.app.data.remote.dto.InviteResultDto
import com.animeh.app.data.remote.dto.RoomDto
import com.animeh.app.data.repository.SocialRepository
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.emptyFlow
import kotlinx.coroutines.launch
import javax.inject.Inject
import javax.inject.Singleton

/**
 * The room this device is currently in, if any.
 *
 * A singleton rather than per-screen state because a watch party outlives the
 * screen that started it: a room is opened from the anime page, joined from a
 * link or a notification, and then lived in inside the player. Threading it
 * through navigation would mean the room ending every time somebody backed out
 * of a screen.
 *
 * Leaving is deliberately thorough. Presence in Firebase disappears on its own
 * when the connection drops, but the room row and the room's data do not, and
 * the promise made was that a room leaves nothing behind.
 *
 * Which is also why membership is a standing job rather than a single write.
 * Firebase deletes presence the moment the socket goes — and the socket goes
 * whenever the phone is put down — so a room joined once was a room you fell
 * out of as soon as you looked away. [presenceJob] rewrites it on every
 * reconnection, and only a deliberate [leave] ends it.
 */
@Singleton
class WatchPartySession @Inject constructor(
    private val social: SocialRepository,
    private val party: WatchPartyClient,
    private val sessionStore: SessionStore,
    private val firebase: FirebaseGate,
) {

    private val _room = MutableStateFlow<RoomDto?>(null)
    val room: StateFlow<RoomDto?> = _room.asStateFlow()

    /** Whether watch parties can be offered at all on this install. */
    val available: StateFlow<Boolean> get() = firebase.ready

    /** Who this device is, in Firebase's terms. */
    val uid: String get() = sessionStore.state.value.user?.id?.toString().orEmpty()

    private val displayName: String
        get() = sessionStore.state.value.user?.let { it.displayName.ifBlank { it.username } }.orEmpty()

    private val avatar: String get() = sessionStore.state.value.user?.avatar.orEmpty()

    /**
     * Where the presence heartbeat runs.
     *
     * Not a screen's scope and not a ViewModel's: a room outlives both, and
     * presence tied to a composition would end the moment somebody opened the
     * player over the top of it.
     */
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    private var presenceJob: Job? = null

    /** Open a room on an episode and step into it. */
    suspend fun open(episodeId: Long): AppResult<RoomDto> {
        val result = social.createRoom(episodeId)

        if (result is AppResult.Success) {
            enter(result.data)
        }

        return result
    }

    /** Step into a room somebody shared. */
    suspend fun join(code: String): AppResult<RoomDto> {
        val result = social.joinRoom(code)

        if (result is AppResult.Success) {
            enter(result.data)
        }

        return result
    }

    /**
     * Leave, and take the room with you when you were the last one out.
     *
     * The server sweeps rooms nobody has reported in for an hour, so a crash
     * does not leave one for ever — but a room that outlives its last member
     * by an hour is still a room somebody can walk into and find empty, so it
     * is worth closing properly on the way out.
     */
    suspend fun leave() {
        val current = _room.value ?: return
        val me = uid

        // First, so the heartbeat cannot re-announce presence a moment after
        // it has been removed.
        presenceJob?.cancel()
        presenceJob = null

        party.leave(current.code, me)

        // Only the host can close the room server-side, which is also who the
        // link belongs to. Everyone else just stops being present.
        if (current.host.id.toString() == me) {
            party.destroy(current.code)
            social.closeRoom(current.code)
        }

        _room.value = null
    }

    /** Say where the playhead is, for everyone else to follow. */
    fun publish(positionMs: Long, playing: Boolean, episodeId: Long) {
        val current = _room.value ?: return

        party.publish(current.code, uid, positionMs, playing, episodeId)
    }

    /** What everyone else is doing. Empty when not in a room. */
    fun playback(): Flow<RoomPlayback> =
        _room.value?.let { party.playback(it.code) } ?: emptyFlow()

    fun members(): Flow<List<RoomMember>> =
        _room.value?.let { party.members(it.code) } ?: emptyFlow()

    fun chat(): Flow<List<ChatMessage>> =
        _room.value?.let { party.chat(it.code) } ?: emptyFlow()

    fun send(text: String) {
        val current = _room.value ?: return

        party.send(current.code, uid, displayName, avatar, text)
    }

    suspend fun invite(userIds: List<Long>): AppResult<InviteResultDto> {
        val current = _room.value ?: return AppResult.Success(InviteResultDto())

        return social.invite(current.code, userIds)
    }

    /**
     * Step into a room and stay in it.
     *
     * The presence write is repeated on every reconnection rather than done
     * once. Firebase's `onDisconnect` removes the member the instant the
     * socket closes — which is what makes an abandoned room disappear — and a
     * backgrounded app closes that socket like any other. Without this, going
     * to answer a message dropped you out of the room and left everyone else
     * watching alone.
     */
    private fun enter(room: RoomDto) {
        _room.value = room

        presenceJob?.cancel()
        presenceJob = scope.launch {
            // The server's own idle sweep is the other thing that can end a
            // room out from under the people in it: it deletes anything
            // nobody has reported in for an hour, and joining once is one
            // report. A film is longer than an hour.
            launch {
                while (isActive) {
                    delay(KEEP_ALIVE_MS)
                    social.joinRoom(room.code)
                }
            }

            party.connected().collect { online ->
                if (online) {
                    party.join(room.code, uid, displayName, avatar)
                }
            }
        }

        // Announced immediately as well: `.info/connected` may take a moment
        // to arrive on a cold start, and the room should not look empty in
        // the meantime.
        party.join(room.code, uid, displayName, avatar)
    }

    private companion object {
        /**
         * How often the server is told the room is still in use.
         *
         * Comfortably inside the hour the sweep allows, and rare enough that a
         * long evening costs a handful of requests.
         */
        const val KEEP_ALIVE_MS = 15L * 60L * 1000L
    }
}
