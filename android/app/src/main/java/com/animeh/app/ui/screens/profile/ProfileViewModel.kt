package com.animeh.app.ui.screens.profile

import android.content.ContentResolver
import android.content.Context
import android.net.Uri
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppResult
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import com.animeh.app.data.remote.dto.FrameDto
import com.animeh.app.data.remote.dto.UserStatsDto
import com.animeh.app.data.remote.dto.WalletDto
import coil.imageLoader
import coil.request.ImageRequest
import com.animeh.app.data.repository.AuthRepository
import com.animeh.app.data.repository.RewardsRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

@HiltViewModel
class ProfileViewModel @Inject constructor(
    private val repository: AuthRepository,
    private val rewards: RewardsRepository,
    private val contentResolver: ContentResolver,
    @ApplicationContext private val context: Context,
) : ViewModel() {

    private val _stats = MutableStateFlow<UserStatsDto?>(null)
    val stats: StateFlow<UserStatsDto?> = _stats.asStateFlow()

    private val _wallet = MutableStateFlow(WalletDto())
    val wallet: StateFlow<WalletDto> = _wallet.asStateFlow()

    /** Applied the moment it is tapped; the server call only confirms it. */
    private val _theme = MutableStateFlow("amethyst")
    val theme: StateFlow<String> = _theme.asStateFlow()

    private val _frame = MutableStateFlow<FrameDto?>(null)
    val frame: StateFlow<FrameDto?> = _frame.asStateFlow()

    private val _uploadingAvatar = MutableStateFlow(false)
    val uploadingAvatar: StateFlow<Boolean> = _uploadingAvatar.asStateFlow()

    /**
     * The picture that was just picked, drawn while the uploaded one arrives.
     *
     * Uploading gives back a new address for an object nobody has ever
     * fetched, so the picture on screen went black and stayed black until it
     * had been downloaded — a change that had already happened, shown as if it
     * had not. The file on this phone is the same picture and is already
     * local, so it stands in until the screen is left.
     */
    private val _pendingAvatar = MutableStateFlow<Uri?>(null)
    val pendingAvatar: StateFlow<Uri?> = _pendingAvatar.asStateFlow()

    // No `init { refresh() }`: the screen calls [refresh] whenever it comes
    // back into view, which covers the first time as well as every return
    // from the shop — where the balance and the frame have just changed.

    /**
     * Send a picked image as the profile picture.
     *
     * Read whole rather than streamed: the endpoint caps this at 3 MB, which
     * fits in memory, and a streamed body would need a length the content
     * resolver does not always know.
     */
    fun uploadAvatar(uri: Uri) {
        viewModelScope.launch {
            // On screen before the upload starts, not after it finishes.
            _pendingAvatar.value = uri
            _uploadingAvatar.value = true

            val bytes = withContext(Dispatchers.IO) {
                runCatching { contentResolver.openInputStream(uri)?.use { it.readBytes() } }
                    .getOrNull()
            }

            val result = if (bytes != null && bytes.isNotEmpty()) {
                repository.uploadAvatar(bytes)
            } else {
                null
            }

            _uploadingAvatar.value = false

            when (result) {
                is AppResult.Success -> warm(result.data)
                // Nothing was stored, so nothing should look as though it was.
                else -> _pendingAvatar.value = null
            }
        }
    }

    /**
     * Pull the new picture into the image cache before anything asks for it.
     *
     * The profile screen is covered by the local copy, but the same face
     * appears in rooms, in the friends list and on a public profile — and each
     * of those would otherwise be the first to fetch it, one blank circle at a
     * time. Fetching it here, while somebody is still looking at the local
     * copy, means it is already in hand wherever it turns up next.
     */
    private fun warm(url: String) {
        if (url.isBlank()) return

        context.imageLoader.enqueue(
            ImageRequest.Builder(context)
                .data(url)
                .build()
        )
    }

    fun refresh() {
        if (!repository.isSignedIn) return

        viewModelScope.launch {
            // A failed refresh leaves the cached user on screen rather than
            // blanking a profile that was perfectly readable a moment ago.
            (repository.refreshProfile() as? AppResult.Success)?.let {
                _stats.value = it.data.stats
                // Balance, rate and cosmetics arrive with the profile; the
                // history and the three ranks are a second, heavier call, so
                // the screen fills in twice rather than waiting for both.
                _wallet.value = it.data.points
                _theme.value = it.data.user.theme
                _frame.value = it.data.user.frame
            }

            (rewards.wallet() as? AppResult.Success)?.let { _wallet.value = it.data }
        }
    }

    /**
     * Choose a profile colour.
     *
     * Set here first and sent afterwards. A colour is not a thing worth a
     * spinner, and the palette is free — the worst a failed request can do is
     * leave the wrong colour until the next refresh, which is a great deal
     * better than a swatch that does nothing for half a second.
     */
    fun chooseTheme(slug: String) {
        if (_theme.value == slug) return

        _theme.value = slug
        viewModelScope.launch { rewards.setTheme(slug) }
    }

    /** Called by the shop when a frame is put on, so the header follows. */
    fun frameChanged(frame: FrameDto?) {
        _frame.value = frame
    }

    /** Called after a purchase, so the balance on the profile is not stale. */
    fun walletChanged(balance: Int) {
        _wallet.value = _wallet.value.copy(balance = balance)
    }

    fun logout() {
        viewModelScope.launch { repository.logout() }
    }
}
