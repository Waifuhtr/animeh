package com.animeh.app

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.core.AccountGate
import com.animeh.app.data.prefs.SessionStore
import com.animeh.app.data.repository.AuthRepository
import com.animeh.app.ui.navigation.AnimehApp
import com.animeh.app.ui.screens.auth.BannedScreen
import com.animeh.app.ui.theme.AnimehTheme
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.launch
import javax.inject.Inject

@AndroidEntryPoint
class MainActivity : ComponentActivity() {

    @Inject lateinit var sessionStore: SessionStore

    @Inject lateinit var authRepository: AuthRepository

    /** The code this activity was opened with, if it was. */
    private var pendingRoomCode: String? = null

    /** Set by the composition, so a later link reaches the same screen. */
    private var onNewRoomCode: ((String) -> Unit)? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        pendingRoomCode = roomCodeFrom(intent)

        setContent {
            AnimehTheme {
                // Read from the store rather than a ViewModel so that a token
                // refresh or a sign-out anywhere in the app immediately changes
                // which tabs are drawn.
                val authState by sessionStore.state.collectAsStateWithLifecycle()

                // A suspension is checked here rather than inside the graph:
                // it applies to every screen at once, and putting it above the
                // NavHost means there is no route that can be reached around
                // it — including one restored from the back stack.
                val ban by AccountGate.ban.collectAsStateWithLifecycle()
                val scope = rememberCoroutineScope()

                // A room code from an invite link or a notification tap.
                // Held in state rather than read once, because `singleTask`
                // means a second link arrives at onNewIntent rather than as a
                // fresh activity.
                var roomCode by remember { mutableStateOf(pendingRoomCode) }

                DisposableEffect(Unit) {
                    onNewRoomCode = { code -> roomCode = code }
                    onDispose { onNewRoomCode = null }
                }

                val current = ban
                if (current != null) {
                    BannedScreen(
                        ban = current,
                        onSignOut = { scope.launch { authRepository.logout() } },
                    )
                } else {
                    AnimehApp(
                        authState = authState,
                        roomCode = roomCode,
                        onRoomHandled = { roomCode = null },
                    )
                }
            }
        }
    }

    /**
     * A second link while the app is already open.
     *
     * `singleTask` means Android reuses this activity rather than starting
     * another, so without this a link tapped while the app is running would do
     * nothing at all.
     */
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)

        roomCodeFrom(intent)?.let { code ->
            pendingRoomCode = code
            onNewRoomCode?.invoke(code)
        }
    }

    /** The room code in `animeh://oda/{code}`, if this intent carries one. */
    private fun roomCodeFrom(intent: Intent?): String? {
        val data = intent?.data ?: return null

        if (data.scheme != "animeh" || data.host != "oda") return null

        return data.pathSegments.firstOrNull()?.takeIf { it.isNotBlank() }
    }
}
