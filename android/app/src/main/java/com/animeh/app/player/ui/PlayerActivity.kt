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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.LifecycleStartEffect
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.media3.ui.AspectRatioFrameLayout
import androidx.media3.ui.PlayerView
import com.animeh.app.core.AppError
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

    /** The episode this screen has been asked to play, most recent last. */
    private val request = mutableStateOf(PlayRequest())

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

        request.value = requestFrom(intent)

        setContent {
            AnimehTheme {
                PlayerScreen(
                    request = request.value,
                    onBack = { finish() },
                )
            }
        }
    }

    /**
     * A second "play this" arriving at the activity that is already open.
     *
     * This screen is `singleTask`, which is what stops two players existing at
     * once — but it also means a second launch does not run [onCreate]. It
     * arrives here instead, and an activity that ignores it keeps showing
     * whatever it was showing: the previous episode, or, if the engine was
     * torn down while it was in the background, nothing at all. Every episode
     * asked for has to reach the composition, which is what [request] is for.
     */
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)

        // So `getIntent()` and anything reading it later agree with what is on
        // screen; Android does not replace it on its own.
        setIntent(intent)

        request.value = requestFrom(intent)
    }

    private fun requestFrom(intent: Intent) = PlayRequest(
        episodeId = intent.getLongExtra(EXTRA_EPISODE_ID, 0L),
        // Two taps on the same episode are two requests. Without something
        // that differs, the second is indistinguishable from the first and
        // the screen would ignore it — which is the case that matters, since
        // it is how somebody re-opens a player that went blank.
        nonce = request.value.nonce + 1,
    )

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

/**
 * One request to play an episode.
 *
 * [nonce] is what makes a repeat of the same episode a new request rather than
 * the same one, so re-opening the player re-opens the episode.
 */
data class PlayRequest(val episodeId: Long = 0L, val nonce: Int = 0)

@Composable
fun PlayerScreen(
    request: PlayRequest,
    onBack: () -> Unit,
    viewModel: PlayerViewModel = hiltViewModel(),
) {
    val loadState by viewModel.loadState.collectAsStateWithLifecycle()
    val playerState by viewModel.playerState.collectAsStateWithLifecycle()
    val cues by viewModel.cues.collectAsStateWithLifecycle()
    val typefaces by viewModel.typefaces.collectAsStateWithLifecycle()
    val assLines by viewModel.assLines.collectAsStateWithLifecycle()
    val script by viewModel.script.collectAsStateWithLifecycle()

    var settingsOpen by remember { mutableStateOf(false) }

    // Keyed on the whole request, not just the episode: asking for the same
    // episode again is a new request, and it is the one that has to work —
    // it is how a player that came back empty gets told to load again.
    LaunchedEffect(request) {
        viewModel.open(request.episodeId)
    }

    // The engine belongs to whichever screen is in front. This one can be
    // brought back to the front without being created again, so it re-takes
    // the engine — and reloads the episode when it finds it empty — every time
    // it starts, not only the first time.
    LifecycleStartEffect(Unit) {
        viewModel.restore()
        onStopOrDispose { }
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
            SubtitleLayer(
                lines = assLines,
                script = script,
                cues = cues,
                typefaces = typefaces,
            )
        }

        PlayerControls(
            state = playerState,
            // Neither of these is something the phase can say: before the
            // payload arrives there is nothing loaded, and `Idle` draws a
            // black screen with a play button whether the fetch is still
            // running, has failed, or was never made.
            loading = loadState is UiState.Loading,
            loadError = (loadState as? UiState.Error)?.error?.let { error ->
                if (error is AppError.Message) error.text else stringResource(error.messageRes)
            },
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
