package com.animeh.app.player.ui

import android.content.Context
import android.content.Intent
import android.content.pm.ActivityInfo
import android.os.Bundle
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.media3.ui.AspectRatioFrameLayout
import androidx.media3.ui.PlayerView
import com.animeh.app.core.UiState
import com.animeh.app.ui.components.AdultWarningDialog
import com.animeh.app.player.ass.SubtitleLayer
import com.animeh.app.ui.theme.AnimehTheme
import dagger.hilt.android.AndroidEntryPoint

/**
 * The player screen.
 *
 * Its own activity so immersive mode, orientation and the keep-awake flag are
 * the player's business rather than something every other screen has to undo.
 *
 * `PlayerView` appears here in exactly one role: a video surface. Every control
 * it can draw is switched off (`useController = false`), and the transport,
 * seek bar, quality menu, subtitle layer and gestures are the composables in
 * [PlayerControls] and [SubtitleLayer]. §1 rules out shipping its stock UI, not
 * its output surface — and its surface handles the things a bare `SurfaceView`
 * does not: aspect ratio, secure output, and the switch to a `TextureView`
 * where the device needs one.
 */
@AndroidEntryPoint
class PlayerActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        // Episodes are wider than they are tall, so the player opens turned.
        // SENSOR_LANDSCAPE rather than LANDSCAPE so it still follows which way
        // up the phone is held, and the viewer can rotate back to portrait with
        // the button in the controls.
        requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_SENSOR_LANDSCAPE

        // The system's screen timeout does not know an episode is playing.
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

        WindowCompat.getInsetsController(window, window.decorView).apply {
            systemBarsBehavior = WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
            hide(WindowInsetsCompat.Type.systemBars())
        }

        val episodeId = intent.getLongExtra(EXTRA_EPISODE_ID, 0L)

        setContent {
            AnimehTheme {
                PlayerScreen(
                    episodeId = episodeId,
                    onBack = { finish() },
                )
            }
        }
    }

    override fun onStop() {
        super.onStop()
        window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
    }

    companion object {
        private const val EXTRA_EPISODE_ID = "episode_id"

        fun intent(context: Context, episodeId: Long): Intent =
            Intent(context, PlayerActivity::class.java).putExtra(EXTRA_EPISODE_ID, episodeId)
    }
}

@Composable
fun PlayerScreen(
    episodeId: Long,
    onBack: () -> Unit,
    viewModel: PlayerViewModel = hiltViewModel(),
) {
    val loadState by viewModel.loadState.collectAsStateWithLifecycle()
    val playerState by viewModel.playerState.collectAsStateWithLifecycle()
    val cues by viewModel.cues.collectAsStateWithLifecycle()
    val typefaces by viewModel.typefaces.collectAsStateWithLifecycle()
    val fontIndex by viewModel.fontIndex.collectAsStateWithLifecycle()

    var settingsOpen by remember { mutableStateOf(false) }

    LaunchedEffect(episodeId) {
        if (episodeId > 0 && loadState is UiState.Loading) viewModel.open(episodeId)
    }

    // The last gate before the media loads. Declining leaves nothing playing,
    // so the only sensible thing left is to leave.
    val adultGate by viewModel.adultGate.collectAsStateWithLifecycle()

    if (adultGate != null) {
        AdultWarningDialog(
            onDismiss = {
                viewModel.declineAdult()
                onBack()
            },
            onContinue = viewModel::confirmAdult,
        )
    }

    Box(Modifier.fillMaxSize().background(Color.Black)) {
        val player = viewModel.controller.player

        if (player != null) {
            AndroidView(
                factory = { context ->
                    PlayerView(context).apply {
                        // Every stock control off: this is a surface, not a UI.
                        useController = false
                        setShowBuffering(PlayerView.SHOW_BUFFERING_NEVER)
                        resizeMode = AspectRatioFrameLayout.RESIZE_MODE_FIT
                        setKeepContentOnPlayerReset(true)
                        // Subtitles are rendered by SubtitleLayer with the
                        // fonts the script asked for; PlayerView's own view
                        // has no way to be told about them.
                        subtitleView?.visibility = android.view.View.GONE
                    }
                },
                update = { view -> view.player = player },
                modifier = Modifier.fillMaxSize(),
            )
        }

        if (playerState.subtitlesEnabled) {
            SubtitleLayer(cues = cues, typefaces = typefaces, fonts = fontIndex)
        }

        PlayerControls(
            state = playerState,
            // Play and pause are not reported from here: the view model
            // watches the player's own state, so a pause reaches the room
            // whatever caused it — this button, the notification, a headset,
            // or an incoming call. Seeks are reported here because only the
            // caller knows the playhead moved deliberately.
            onPlayPause = viewModel.controller::togglePlayPause,
            onSeek = { position ->
                viewModel.controller.seekTo(position)
                viewModel.broadcast()
            },
            onSeekBy = { delta ->
                viewModel.controller.seekBy(delta)
                viewModel.broadcast()
            },
            onToggleControls = viewModel.controller::toggleControls,
            onNext = viewModel::playNext,
            onPrevious = viewModel::playPrevious,
            onSkipIntro = viewModel.controller::skipIntro,
            onLock = viewModel.controller::setLocked,
            onBack = onBack,
            onOpenSettings = { settingsOpen = true },
            onRetry = viewModel::retry,
        )

        if (settingsOpen) {
            PlayerSettingsSheet(
                state = playerState,
                onQuality = { viewModel.setQuality(it); settingsOpen = false },
                onSpeed = { viewModel.setSpeed(it); settingsOpen = false },
                onSubtitle = { viewModel.controller.setSubtitle(it); settingsOpen = false },
                onDismiss = { settingsOpen = false },
            )
        }
    }
}
