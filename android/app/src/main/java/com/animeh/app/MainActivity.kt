package com.animeh.app

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.Composable
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.core.AccountGate
import com.animeh.app.core.LaunchGate
import com.animeh.app.data.prefs.AuthState
import com.animeh.app.data.prefs.SessionStore
import com.animeh.app.data.repository.AuthRepository
import com.animeh.app.ui.navigation.AnimehApp
import com.animeh.app.ui.screens.SplashOverlay
import com.animeh.app.ui.screens.auth.BannedScreen
import com.animeh.app.ui.theme.AnimehTheme
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.launch
import javax.inject.Inject

@AndroidEntryPoint
class MainActivity : ComponentActivity() {

    @Inject lateinit var sessionStore: SessionStore

    @Inject lateinit var authRepository: AuthRepository

    @Inject lateinit var launchGate: LaunchGate

    /** The code this activity was opened with, if it was. */
    private var pendingRoomCode: String? = null

    /** The anime a notification tap asked for, if it was one. */
    private var pendingWorkId: Long? = null

    /** Set by the composition, so a later link reaches the same screen. */
    private var onNewRoomCode: ((String) -> Unit)? = null

    private var onNewWorkId: ((Long) -> Unit)? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        pendingRoomCode = roomCodeFrom(intent)
        pendingWorkId = workIdFrom(intent)

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

                // The anime a "new episode" notification was about.
                var workId by remember { mutableStateOf(pendingWorkId) }

                DisposableEffect(Unit) {
                    onNewRoomCode = { code -> roomCode = code }
                    onNewWorkId = { id -> workId = id }
                    onDispose {
                        onNewRoomCode = null
                        onNewWorkId = null
                    }
                }

                // Android 13 turned notifications into a runtime
                // permission, and a permission that is never asked for is a
                // permission that is never granted: every invitation was being
                // built, handed to the notification manager and dropped. Asked
                // once somebody is signed in, because that is the first moment
                // there is anything to notify them about — asking on the very
                // first launch, before there are any friends, is the request
                // people refuse.
                NotificationPermission(signedIn = authState is AuthState.SignedIn)

                val current = ban
                if (current != null) {
                    BannedScreen(
                        ban = current,
                        onSignOut = { scope.launch { authRepository.logout() } },
                    )
                } else {
                    // The launch screen sits *over* the app rather than
                    // before it: everything underneath composes and loads the
                    // whole time it is up, so it hides the skeleton without
                    // delaying what replaces it.
                    val ready by launchGate.ready.collectAsStateWithLifecycle()

                    Box(Modifier.fillMaxSize()) {
                        AnimehApp(
                            authState = authState,
                            roomCode = roomCode,
                            onRoomHandled = { roomCode = null },
                            workId = workId,
                            onWorkHandled = { workId = null },
                        )

                        SplashOverlay(ready = ready)
                    }
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

        workIdFrom(intent)?.let { id ->
            pendingWorkId = id
            onNewWorkId?.invoke(id)
        }
    }

    /**
     * The room code this intent carries, in either of the two shapes.
     *
     * `animeh://oda/{code}` is what the handover page and a notification tap
     * use; `https://site/oda/{code}` is what a verified App Link delivers,
     * which is the same address a friend actually pasted into a chat. Both
     * arrive here, so both are read — the second one used to fall through and
     * open the app on the home screen with the invitation lost.
     */
    private fun roomCodeFrom(intent: Intent?): String? {
        val data = intent?.data ?: return null

        val segments = data.pathSegments.orEmpty()

        val code = when {
            data.scheme == "animeh" && data.host == "oda" -> segments.firstOrNull()

            (data.scheme == "https" || data.scheme == "http") &&
                segments.firstOrNull() == "oda" -> segments.getOrNull(1)

            else -> null
        }

        return code?.takeIf { it.isNotBlank() }
    }

    /**
     * The anime id in `animeh://anime/{id}`, if this intent carries one.
     *
     * What a "new episode" notification taps through to. The episode itself
     * would be the more direct destination, but the series page is where the
     * episode list is, and somebody who has been away for a week wants the
     * list rather than one episode.
     */
    private fun workIdFrom(intent: Intent?): Long? {
        val data = intent?.data ?: return null

        if (data.scheme != "animeh" || data.host != "anime") return null

        return data.pathSegments.firstOrNull()?.toLongOrNull()?.takeIf { it > 0 }
    }
}

/**
 * Ask for the notification permission, once, after sign-in.
 *
 * There is no way to ask again from here if it is refused — Android stops
 * showing the dialog after the second refusal — and that is the right
 * behaviour: the app keeps working, invitations just arrive when it is next
 * opened instead of in the tray.
 */
@Composable
private fun NotificationPermission(signedIn: Boolean) {
    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return

    val context = LocalContext.current
    var asked by rememberSaveable { mutableStateOf(false) }

    val launcher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { /* Granted or not, there is nothing more to do here. */ }

    LaunchedEffect(signedIn, asked) {
        if (!signedIn || asked) return@LaunchedEffect

        val granted = ContextCompat.checkSelfPermission(
            context,
            Manifest.permission.POST_NOTIFICATIONS,
        ) == PackageManager.PERMISSION_GRANTED

        asked = true

        if (!granted) launcher.launch(Manifest.permission.POST_NOTIFICATIONS)
    }
}
