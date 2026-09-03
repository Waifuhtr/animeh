package com.animeh.app.ui.screens.social

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppError
import com.animeh.app.core.AppResult
import com.animeh.app.core.UiState
import com.animeh.app.data.remote.dto.ChatMessageUi
import com.animeh.app.data.remote.dto.FriendsDto
import com.animeh.app.data.remote.dto.PublicProfileDto
import com.animeh.app.data.remote.dto.RoomDto
import com.animeh.app.data.remote.dto.UserDto
import com.animeh.app.data.repository.SocialRepository
import com.animeh.app.social.RoomMember
import com.animeh.app.social.WatchPartySession
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

/** Somebody's profile, theirs or your own. */
@HiltViewModel
class ProfileDetailViewModel @Inject constructor(
    private val social: SocialRepository,
    savedStateHandle: SavedStateHandle,
) : ViewModel() {

    private val userId: Long = savedStateHandle["userId"] ?: 0L

    private val _state = MutableStateFlow<UiState<PublicProfileDto>>(UiState.Loading)
    val state: StateFlow<UiState<PublicProfileDto>> = _state.asStateFlow()

    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    init {
        load()
    }

    fun dismissMessage() {
        _message.value = null
    }

    fun load() {
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val result = social.profile(userId)) {
                is AppResult.Success -> UiState.Success(result.data)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    /** Send a friend request from the profile you are looking at. */
    fun addFriend(sentMessage: String) {
        viewModelScope.launch {
            when (val result = social.requestFriend(userId = userId)) {
                is AppResult.Success -> {
                    _message.value = sentMessage
                    // Reloaded so the button becomes "istek bekliyor" rather
                    // than staying an invitation to send a second one.
                    load()
                }

                is AppResult.Failure -> _message.value = result.error.technical
            }
        }
    }

    /** Choose the work shown at the top of your own profile. */
    fun setFavorite(workId: Long) {
        viewModelScope.launch {
            if (social.setFavoriteWork(workId) is AppResult.Success) load()
        }
    }

    fun setVisibility(public: Boolean) {
        viewModelScope.launch {
            if (social.setProfileVisibility(public) is AppResult.Success) load()
        }
    }
}

/** The friend list and both directions of what is outstanding. */
@HiltViewModel
class FriendsViewModel @Inject constructor(
    private val social: SocialRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<FriendsDto>>(UiState.Loading)
    val state: StateFlow<UiState<FriendsDto>> = _state.asStateFlow()

    private val _handle = MutableStateFlow("")
    val handle: StateFlow<String> = _handle.asStateFlow()

    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    private val _busy = MutableStateFlow(false)
    val busy: StateFlow<Boolean> = _busy.asStateFlow()

    init {
        load()
    }

    fun setHandle(value: String) {
        _handle.value = value
    }

    fun dismissMessage() {
        _message.value = null
    }

    fun load() {
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val result = social.friends()) {
                is AppResult.Success -> UiState.Success(result.data)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    /**
     * Add by whichever handle was typed.
     *
     * An address goes in as an address and anything else as a username; the
     * server refuses whichever names no account, so guessing wrong here costs
     * one clear error rather than a wrong match.
     */
    fun add(sentMessage: String) {
        val typed = _handle.value.trim()
        if (typed.isBlank()) return

        viewModelScope.launch {
            _busy.value = true

            val looksLikeEmail = typed.contains('@')
            val result = social.requestFriend(
                email = if (looksLikeEmail) typed else "",
                username = if (looksLikeEmail) "" else typed,
            )

            when (result) {
                is AppResult.Success -> {
                    _handle.value = ""
                    _message.value = sentMessage
                    load()
                }

                is AppResult.Failure -> _message.value = result.error.technical
            }

            _busy.value = false
        }
    }

    fun accept(userId: Long) {
        viewModelScope.launch {
            if (social.acceptFriend(userId) is AppResult.Success) load()
        }
    }

    /** Declining a request and dropping a friend are the same call. */
    fun remove(userId: Long) {
        viewModelScope.launch {
            if (social.removeFriend(userId) is AppResult.Success) load()
        }
    }
}

/** What the room screen draws. */
data class RoomUiState(
    val room: RoomDto? = null,
    val members: List<RoomMember> = emptyList(),
    val chat: List<ChatMessageUi> = emptyList(),
    val friends: List<UserDto> = emptyList(),
    val message: String? = null,
)

/**
 * The room, its chat and who is in it.
 *
 * The playhead is not here — that belongs to the player, which is where the
 * video is. This is the sociable half: who is present, what is being said, and
 * who else could be invited.
 */
@HiltViewModel
class RoomViewModel @Inject constructor(
    private val party: WatchPartySession,
    private val social: SocialRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(RoomUiState())
    val state: StateFlow<RoomUiState> = _state.asStateFlow()

    private val _draft = MutableStateFlow("")
    val draft: StateFlow<String> = _draft.asStateFlow()

    /** Whether this install can offer watch parties at all. */
    val available = party.available

    val uid: String get() = party.uid

    init {
        observe()

        viewModelScope.launch {
            val result = social.friends()

            if (result is AppResult.Success) {
                _state.update { it.copy(friends = result.data.friends) }
            }
        }
    }

    fun setDraft(value: String) {
        _draft.value = value
    }

    fun dismissMessage() {
        _state.update { it.copy(message = null) }
    }

    fun send() {
        val text = _draft.value.trim()
        if (text.isEmpty()) return

        party.send(text)
        _draft.value = ""
    }

    /**
     * Invite friends, and say honestly what happened.
     *
     * The server reports how many were invited and how many actually had a
     * device told about it, and those differ whenever Firebase is not set up.
     * Reporting only the first number is how "davet gönderildi" ended up on
     * screen while nothing arrived on anybody's phone.
     */
    fun invite(userIds: List<Long>, message: (invited: Int, notified: Int) -> String) {
        viewModelScope.launch {
            when (val result = party.invite(userIds)) {
                is AppResult.Success -> _state.update {
                    it.copy(message = message(result.data.invited, result.data.notified))
                }

                is AppResult.Failure -> _state.update { it.copy(message = result.error.technical) }
            }
        }
    }

    fun leave() {
        viewModelScope.launch { party.leave() }
    }

    private fun observe() {
        viewModelScope.launch {
            party.room.collect { room ->
                _state.update { it.copy(room = room) }

                if (room == null) return@collect

                // Re-subscribed per room rather than once: the flows are
                // built against a code, and a new room is a different path.
                launch {
                    party.members().collect { members ->
                        _state.update { it.copy(members = members) }
                    }
                }

                launch {
                    party.chat().collect { messages ->
                        _state.update { current ->
                            current.copy(
                                chat = messages.map { message ->
                                    ChatMessageUi(
                                        id = message.id,
                                        uid = message.uid,
                                        name = message.name,
                                        avatar = message.avatar,
                                        text = message.text,
                                        mine = message.uid == party.uid,
                                    )
                                }
                            )
                        }
                    }
                }
            }
        }
    }
}

/**
 * Joining a room from a link, before any room screen exists.
 *
 * Separate from [RoomViewModel] because it runs at the app shell, where there
 * is no room yet: an invite link has to be answered before the screen that
 * shows a room can be navigated to at all.
 */
@HiltViewModel
class RoomJoinViewModel @Inject constructor(
    private val party: WatchPartySession,
) : ViewModel() {

    /**
     * Step into a room by its code.
     *
     * @return true when the room was still open and was entered.
     */
    suspend fun join(code: String): Boolean = party.join(code) is AppResult.Success
}

/** What the rooms tab draws. */
data class RoomsUiState(
    val rooms: List<RoomDto> = emptyList(),
    val loading: Boolean = true,
    val error: AppError? = null,
    val message: String? = null,
    val joining: Boolean = false,
)

/**
 * The open rooms, and a way into one by its code.
 *
 * A room is reachable three ways and this screen is the one that does not
 * depend on anything arriving: a link can be swallowed by a chat app and a
 * notification can be refused, but a list of what your friends have open is
 * always there to be looked at.
 */
@HiltViewModel
class RoomsViewModel @Inject constructor(
    private val party: WatchPartySession,
    private val social: SocialRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(RoomsUiState())
    val state: StateFlow<RoomsUiState> = _state.asStateFlow()

    private val _code = MutableStateFlow("")
    val code: StateFlow<String> = _code.asStateFlow()

    /** Whether this install can offer watch parties at all. */
    val available = party.available

    init {
        load()
    }

    /**
     * A code as typed, or as pasted.
     *
     * Half of what lands in this field will be a whole invite link rather than
     * a code, because that is what somebody was sent — so the last path
     * segment is taken and the rest thrown away. Case and stray whitespace go
     * the same way: the alphabet codes are drawn from is lower case, and
     * refusing a pasted capital would be a puzzle rather than a correction.
     */
    fun setCode(value: String) {
        val tail = value.trim().substringAfterLast('/').substringBefore('?')

        _code.value = tail.lowercase().filter { it.isLetterOrDigit() }.take(CODE_LIMIT)
    }

    fun dismissMessage() {
        _state.update { it.copy(message = null) }
    }

    fun load() {
        viewModelScope.launch {
            _state.update { it.copy(loading = true, error = null) }

            when (val result = social.rooms()) {
                is AppResult.Success -> _state.update {
                    it.copy(rooms = result.data, loading = false, error = null)
                }

                is AppResult.Failure -> _state.update {
                    it.copy(loading = false, error = result.error)
                }
            }
        }
    }

    /**
     * Enter a room by code.
     *
     * @param code    The code, from the field or from a card that was tapped.
     * @param onEntered Called when the room was open and has been entered.
     */
    fun join(code: String, gone: String, onEntered: () -> Unit) {
        val trimmed = code.trim()
        if (trimmed.isEmpty() || _state.value.joining) return

        viewModelScope.launch {
            _state.update { it.copy(joining = true) }

            when (party.join(trimmed)) {
                is AppResult.Success -> {
                    _code.value = ""
                    _state.update { it.copy(joining = false) }
                    onEntered()
                }

                is AppResult.Failure -> {
                    // A code that names no open room is the ordinary failure —
                    // rooms delete themselves — so it is said in those terms
                    // rather than as a status code.
                    _state.update { it.copy(joining = false, message = gone) }
                    load()
                }
            }
        }
    }

    private companion object {
        /** The length the server generates. */
        const val CODE_LIMIT = 10
    }
}
