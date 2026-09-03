package com.animeh.app.social

import com.animeh.app.core.ClientLog
import com.google.firebase.database.DataSnapshot
import com.google.firebase.database.DatabaseError
import com.google.firebase.database.DatabaseReference
import com.google.firebase.database.ServerValue
import com.google.firebase.database.ValueEventListener
import kotlinx.coroutines.channels.awaitClose
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.callbackFlow
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Where a room's playhead lives.
 *
 * Everyone in the room holds the same remote: any member can pause, play or
 * seek, and the last write wins. A host-only design was the alternative and it
 * is worse for two friends watching an episode — the person who needs to pause
 * is whoever answered the door, not whoever opened the room.
 *
 * [by] is what stops a feedback loop. A device applies a state only when
 * somebody else wrote it; without that, applying a remote pause fires the
 * local pause listener, which writes the state again, forever.
 */
data class RoomPlayback(
    val positionMs: Long = 0,
    val playing: Boolean = false,
    /** Uid of whoever last touched the controls. */
    val by: String = "",
    /** Firebase's clock, not the phone's — phones disagree by minutes. */
    val at: Long = 0,
    val episodeId: Long = 0,
)

/** Somebody in the room, as long as their app is connected. */
data class RoomMember(
    val uid: String = "",
    val name: String = "",
    val avatar: String = "",
)

/** One line of room chat. */
data class ChatMessage(
    val id: String = "",
    val uid: String = "",
    val name: String = "",
    val avatar: String = "",
    val text: String = "",
    val at: Long = 0,
)

/**
 * The realtime half of a watch party.
 *
 * The durable half — who may open a room, who may join one, who may be
 * invited — is the WordPress API's. This is only the part that changes several
 * times a second, which is the part a shared-hosting PHP install is the wrong
 * tool for.
 *
 * Presence is what makes "the room deletes itself when everyone leaves" true
 * even when an app is killed: `onDisconnect` is registered on the server the
 * moment a member joins, so the removal happens whether or not the app is
 * alive to do it.
 *
 * That guarantee cuts both ways, and [connected] is the other half of it.
 * Firebase drops its socket when a phone sleeps, changes network or has the
 * app backgrounded long enough — and the server, keeping its promise, deletes
 * the presence row. Announcing yourself once on the way in therefore means
 * disappearing from the room the first time the screen goes off. Presence has
 * to be re-announced on every reconnection, which is what `.info/connected`
 * exists to tell you.
 */
@Singleton
class WatchPartyClient @Inject constructor(
    private val firebase: FirebaseGate,
) {

    /** Longest a message may be, matching what the rules enforce. */
    val messageLimit: Int get() = MESSAGE_LIMIT

    private fun room(code: String): DatabaseReference? =
        firebase.database()?.getReference("rooms")?.child(code)

    /**
     * Announce yourself, and arrange to be forgotten when you drop off.
     *
     * @return true when Firebase accepted the presence write.
     */
    fun join(code: String, uid: String, name: String, avatar: String): Boolean {
        val members = room(code)?.child("members")?.child(uid) ?: return false

        // Registered before the write, so a connection that dies mid-join
        // still cleans up after itself.
        members.onDisconnect().removeValue()

        members.setValue(
            mapOf(
                "uid" to uid,
                "name" to name,
                "avatar" to avatar,
                "at" to ServerValue.TIMESTAMP,
            )
        )

        return true
    }

    /**
     * Whether Firebase currently has a connection.
     *
     * `.info/connected` is a local view of the socket, not a value on the
     * server: it goes false the moment the connection drops and true again
     * once it is back, which is precisely when presence has to be rewritten.
     */
    fun connected(): Flow<Boolean> = callbackFlow {
        val reference = firebase.database()?.getReference(".info/connected")

        if (reference == null) {
            close()
            return@callbackFlow
        }

        val listener = object : ValueEventListener {
            override fun onDataChange(snapshot: DataSnapshot) {
                trySend(snapshot.getValue(Boolean::class.java) ?: false)
            }

            override fun onCancelled(error: DatabaseError) {
                // `.info` is readable regardless of rules, so this should not
                // happen; a room that silently stopped re-announcing itself
                // would be very hard to explain later.
                ClientLog.record("Bağlantı durumu izlenemedi", error.message)
                close(error.toException())
            }
        }

        reference.addValueEventListener(listener)

        awaitClose { reference.removeEventListener(listener) }
    }

    /** Leave deliberately, which is the ordinary case. */
    fun leave(code: String, uid: String) {
        room(code)?.child("members")?.child(uid)?.removeValue()
    }

    /**
     * Delete everything about a room.
     *
     * Called by whoever notices they were the last one in it. The server
     * sweeps anything this misses.
     */
    fun destroy(code: String) {
        room(code)?.removeValue()
    }

    /** Push the playhead to everyone else. */
    fun publish(code: String, uid: String, positionMs: Long, playing: Boolean, episodeId: Long) {
        room(code)?.child("state")?.setValue(
            mapOf(
                "positionMs" to positionMs,
                "playing" to playing,
                "by" to uid,
                "at" to ServerValue.TIMESTAMP,
                "episodeId" to episodeId,
            )
        )
    }

    /** Say something. */
    fun send(code: String, uid: String, name: String, avatar: String, text: String) {
        val trimmed = text.trim().take(MESSAGE_LIMIT)
        if (trimmed.isEmpty()) return

        room(code)?.child("chat")?.push()?.setValue(
            mapOf(
                "uid" to uid,
                "name" to name,
                "avatar" to avatar,
                "text" to trimmed,
                "at" to ServerValue.TIMESTAMP,
            )
        )
    }

    /** The room's playhead, as it changes. */
    fun playback(code: String): Flow<RoomPlayback> = valueFlow(code, "state") { snapshot ->
        RoomPlayback(
            positionMs = snapshot.child("positionMs").longValue(),
            playing = snapshot.child("playing").getValue(Boolean::class.java) ?: false,
            by = snapshot.child("by").getValue(String::class.java).orEmpty(),
            at = snapshot.child("at").longValue(),
            episodeId = snapshot.child("episodeId").longValue(),
        )
    }

    /** Who is in the room right now. */
    fun members(code: String): Flow<List<RoomMember>> = valueFlow(code, "members") { snapshot ->
        snapshot.children.mapNotNull { child ->
            val uid = child.child("uid").getValue(String::class.java) ?: child.key ?: return@mapNotNull null

            RoomMember(
                uid = uid,
                name = child.child("name").getValue(String::class.java).orEmpty(),
                avatar = child.child("avatar").getValue(String::class.java).orEmpty(),
            )
        }
    }

    /** The chat, oldest first, capped to what a screen can show. */
    fun chat(code: String): Flow<List<ChatMessage>> = callbackFlow {
        val reference = room(code)?.child("chat")?.limitToLast(CHAT_WINDOW)

        if (reference == null) {
            close()
            return@callbackFlow
        }

        val listener = object : ValueEventListener {
            override fun onDataChange(snapshot: DataSnapshot) {
                trySend(
                    snapshot.children.map { child ->
                        ChatMessage(
                            id = child.key.orEmpty(),
                            uid = child.child("uid").getValue(String::class.java).orEmpty(),
                            name = child.child("name").getValue(String::class.java).orEmpty(),
                            avatar = child.child("avatar").getValue(String::class.java).orEmpty(),
                            text = child.child("text").getValue(String::class.java).orEmpty(),
                            at = child.child("at").longValue(),
                        )
                    }
                )
            }

            override fun onCancelled(error: DatabaseError) {
                // Almost always the security rules refusing someone who is not
                // in the room. Worth a line in the device log, not a crash.
                ClientLog.record("Oda sohbeti kesildi", error.message)
                close(error.toException())
            }
        }

        reference.addValueEventListener(listener)

        awaitClose { reference.removeEventListener(listener) }
    }

    /** One child of a room, as a flow that detaches when nobody collects it. */
    private fun <T> valueFlow(
        code: String,
        child: String,
        read: (DataSnapshot) -> T,
    ): Flow<T> = callbackFlow {
        val reference = room(code)?.child(child)

        if (reference == null) {
            close()
            return@callbackFlow
        }

        val listener = object : ValueEventListener {
            override fun onDataChange(snapshot: DataSnapshot) {
                trySend(read(snapshot))
            }

            override fun onCancelled(error: DatabaseError) {
                ClientLog.record("Oda bağlantısı kesildi: $child", error.message)
                close(error.toException())
            }
        }

        reference.addValueEventListener(listener)

        awaitClose { reference.removeEventListener(listener) }
    }

    private fun DataSnapshot.longValue(): Long =
        (getValue(Long::class.java) ?: 0L)

    private companion object {
        /** Enough chat to scroll back through without loading a whole night. */
        const val CHAT_WINDOW = 100

        const val MESSAGE_LIMIT = 500
    }
}
