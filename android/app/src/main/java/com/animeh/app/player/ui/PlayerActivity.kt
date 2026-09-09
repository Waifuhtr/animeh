package com.animeh.app.player.ui

import android.content.Context
import android.content.Intent
import android.content.pm.ActivityInfo
import android.content.res.Configuration
import android.net.Uri
import android.os.Bundle
import android.view.OrientationEventListener
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.material3.MaterialTheme
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
import com.animeh.app.R
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

    /**
     * Whether the picture has the whole screen.
     *
     * The layout is decided by this and not by the window's shape. Those two
     * are the same thing most of the time, but only this one survives the
     * moment between asking for a rotation and getting it — and only this one
     * knows the difference between "the window is portrait because the viewer
     * left the theatre" and "the window is portrait because the system turned
     * it back".
     */
    private val fullscreen = mutableStateOf(false)

    /**
     * Watches the phone itself, while the window is pinned to portrait.
     *
     * Null whenever nothing is pinned. See [setFullscreen].
     */
    private var uprightWatcher: OrientationEventListener? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        // Opened upright, on the episode page. The screen used to be turned
        // for you the moment it opened, which is right for watching and wrong
        // for arriving: which episode this is and what else there is to watch
        // are things you read, and reading happens the way the phone is held.
        // Turning it — or pressing the fullscreen button — is what asks for
        // the picture to fill the screen.
        requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_USER
        fullscreen.value = resources.configuration.orientation == Configuration.ORIENTATION_LANDSCAPE

        // The system's screen timeout does not know an episode is playing.
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

        WindowCompat.getInsetsController(window, window.decorView).systemBarsBehavior =
            WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE

        request.value = requestFrom(intent)

        setContent {
            AnimehTheme {
                PlayerScreen(
                    request = request.value,
                    fullscreen = fullscreen.value,
                    onBack = { finish() },
                    onRequestLandscape = ::setFullscreen,
                )
            }
        }
    }

    /**
     * Enter or leave the theatre, and turn the screen to match.
     *
     * The lock is **held** for as long as fullscreen lasts. It used to be let
     * go of the instant the window had turned, on the reasoning that a lock
     * left on is a phone that will not turn back — but the thing it was handed
     * back to is `SCREEN_ORIENTATION_USER`, and that means "whatever the
     * viewer's rotation setting says". On a phone with auto-rotate switched
     * off, which is most of them, that setting says portrait: the screen
     * turned, the lock came off, and the system turned it straight back.
     * Pressing fullscreen worked for about one frame and then undid itself.
     *
     * Leaving is pinned too, and for the mirror-image reason: hand rotation
     * back while the phone is still being held sideways and the window returns
     * to landscape, which is the thing that puts it back in the theatre — the
     * back button would appear to do nothing at all. So the pin comes off when
     * the phone itself is upright, which is what [uprightWatcher] waits for.
     */
    fun setFullscreen(value: Boolean) {
        fullscreen.value = value

        if (value) {
            stopUprightWatch()
            // Either way up: a phone turned the "wrong" way is still a
            // request for a landscape picture.
            requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_SENSOR_LANDSCAPE
        } else {
            requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_PORTRAIT
            startUprightWatch()
        }
    }

    /**
     * Give rotation back once the phone is being held upright.
     *
     * Without a sensor to ask — an emulator, a television — there is nothing
     * to wait for, so the pin comes off immediately; that device was never
     * going to rotate underneath us anyway.
     */
    private fun startUprightWatch() {
        if (uprightWatcher != null) return

        val watcher = object : OrientationEventListener(this) {
            override fun onOrientationChanged(orientation: Int) {
                if (orientation == OrientationEventListener.ORIENTATION_UNKNOWN) return

                // Within 30° of upright, either way up. Lying flat on a table
                // reads as unknown and is ignored above, which is right: a
                // phone on a table has no opinion about this.
                val upright = orientation <= 30 || orientation >= 330 || orientation in 150..210
                if (upright) releaseOrientationLock()
            }
        }

        if (watcher.canDetectOrientation()) {
            uprightWatcher = watcher
            watcher.enable()
        } else {
            releaseOrientationLock()
        }
    }

    private fun stopUprightWatch() {
        uprightWatcher?.disable()
        uprightWatcher = null
    }

    /** Hand orientation back to the viewer's own rotation setting. */
    private fun releaseOrientationLock() {
        stopUprightWatch()
        if (requestedOrientation != ActivityInfo.SCREEN_ORIENTATION_USER) {
            requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_USER
        }
    }

    /**
     * The phone was turned by hand.
     *
     * Only counted while nothing is pinned — that is exactly the state in
     * which a configuration change means the viewer turned the phone rather
     * than us having asked for it.
     */
    override fun onConfigurationChanged(newConfig: Configuration) {
        super.onConfigurationChanged(newConfig)

        if (requestedOrientation == ActivityInfo.SCREEN_ORIENTATION_USER) {
            fullscreen.value = newConfig.orientation == Configuration.ORIENTATION_LANDSCAPE
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        stopUprightWatch()
    }

    /**
     * Show or hide the status and navigation bars.
     *
     * Not `setImmersive`: `Activity` already has one of those, with the same
     * signature and a different job — it tells the system whether this window
     * is in immersive mode for the purpose of its own confirmation prompt.
     * Taking that name over would be overriding a platform method to mean
     * something else.
     */
    fun applyImmersive(immersive: Boolean) {
        WindowCompat.getInsetsController(window, window.decorView).apply {
            if (immersive) hide(WindowInsetsCompat.Type.systemBars())
            else show(WindowInsetsCompat.Type.systemBars())
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

    override fun onStart() {
        super.onStart()
        // Picked back up where it left off: a pin that is still on is still
        // waiting for the phone to be held upright.
        if (requestedOrientation == ActivityInfo.SCREEN_ORIENTATION_PORTRAIT) startUprightWatch()
    }

    override fun onStop() {
        super.onStop()
        window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        // Nothing to watch for while the screen is somebody else's.
        stopUprightWatch()
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
    fullscreen: Boolean,
    onBack: () -> Unit,
    onRequestLandscape: (Boolean) -> Unit = {},
    viewModel: PlayerViewModel = hiltViewModel(),
) {
    val loadState by viewModel.loadState.collectAsStateWithLifecycle()
    val playerState by viewModel.playerState.collectAsStateWithLifecycle()
    val cues by viewModel.cues.collectAsStateWithLifecycle()
    val typefaces by viewModel.typefaces.collectAsStateWithLifecycle()
    val assLines by viewModel.assLines.collectAsStateWithLifecycle()
    val script by viewModel.script.collectAsStateWithLifecycle()
    val subtitleScale by viewModel.subtitleScale.collectAsStateWithLifecycle()
    val episodes by viewModel.episodes.collectAsStateWithLifecycle()
    val inWatchlist by viewModel.inWatchlist.collectAsStateWithLifecycle()

    var settingsOpen by remember { mutableStateOf(false) }
    var moreOpen by remember { mutableStateOf(false) }

    val context = LocalContext.current
    val activity = context as? PlayerActivity

    // Which layout is showing comes from the activity, which is the one place
    // that can tell the two ways into landscape apart — the fullscreen button
    // and the viewer turning the phone — and the one place that knows the
    // window has been asked to turn before it has finished turning. Reading
    // the window's shape here instead is what let a rotation the system
    // undid look like a viewer leaving the theatre.
    val landscape = fullscreen

    LaunchedEffect(landscape) {
        viewModel.setFullscreen(landscape)
        // The bars belong to the page, not to the picture.
        activity?.applyImmersive(landscape)
    }

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

    // In the theatre, back is the way out of it rather than out of the
    // episode: the page is still there underneath.
    BackHandler(enabled = landscape) { onRequestLandscape(false) }

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

    val loading = loadState is UiState.Loading
    val loadError = (loadState as? UiState.Error)?.error?.let { error ->
        if (error is AppError.Message) error.text else stringResource(error.messageRes)
    }

    // One surface, drawn in whichever box the layout gives it. Built here and
    // passed down rather than declared twice, so turning the phone moves the
    // picture instead of tearing it down and starting again.
    val surface: @Composable (Modifier) -> Unit = { boxModifier ->
        Box(boxModifier.background(Color.Black)) {
            val player = viewModel.controller.player

            if (player != null) {
                AndroidView(
                    factory = { ctx ->
                        PlayerView(ctx).apply {
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
                    fontScale = subtitleScale,
                )
            }
        }
    }

    if (landscape) {
        Box(Modifier.fillMaxSize().background(Color.Black)) {
            surface(Modifier.fillMaxSize())

            PlayerControls(
                state = playerState,
                // Neither of these is something the phase can say: before the
                // payload arrives there is nothing loaded, and `Idle` draws a
                // black screen with a play button whether the fetch is still
                // running, has failed, or was never made.
                loading = loading,
                loadError = loadError,
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
                // Out of the theatre, not out of the episode.
                onBack = { onRequestLandscape(false) },
                onOpenSettings = { settingsOpen = true },
                onRetry = viewModel::retry,
            )
        }
    } else {
        Column(
            Modifier
                .fillMaxSize()
                .background(MaterialTheme.colorScheme.background)
                // The bars are showing in this layout, so the page begins
                // under them rather than behind them. The theatre keeps the
                // whole window: there, they are hidden.
                .statusBarsPadding()
                .navigationBarsPadding(),
        ) {
            // Pinned rather than scrolled away: it is why anybody is on this
            // screen, and a list that pushes the picture off the top would
            // need a second little player to put it back.
            Box(
                Modifier
                    .fillMaxWidth()
                    .aspectRatio(16f / 9f),
            ) {
                surface(Modifier.fillMaxSize())

                InlinePlayerControls(
                    state = playerState,
                    loading = loading,
                    loadError = loadError,
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
                    onToggleSubtitles = {
                        // Null turns them off; the first track turns them back
                        // on, which is the only sensible thing to return to.
                        viewModel.controller.setSubtitle(
                            if (playerState.subtitlesEnabled) {
                                null
                            } else {
                                playerState.subtitleSources.firstOrNull()?.id
                            }
                        )
                    },
                    onOpenSettings = { settingsOpen = true },
                    onFullscreen = { onRequestLandscape(true) },
                    onBack = onBack,
                    onRetry = viewModel::retry,
                )
            }

            EpisodePageBody(
                work = playerState.work,
                episode = playerState.episode,
                episodes = episodes,
                inWatchlist = inWatchlist,
                onEpisode = viewModel::open,
                onWatchlist = viewModel::setWatchlisted,
                onShare = {
                    val title = playerState.work?.displayTitle.orEmpty()
                    val number = playerState.episode?.number ?: 0
                    val text = context.getString(R.string.episode_share_text, title, number)

                    context.startActivity(
                        Intent.createChooser(
                            Intent(Intent.ACTION_SEND).apply {
                                type = "text/plain"
                                putExtra(Intent.EXTRA_TEXT, text)
                            },
                            null,
                        )
                    )
                },
                onMore = { moreOpen = true },
                modifier = Modifier.weight(1f),
            )
        }
    }

    if (settingsOpen) {
        PlayerSettingsSheet(
            state = playerState,
            subtitleScale = subtitleScale,
            onQuality = { viewModel.setQuality(it); settingsOpen = false },
            onSpeed = { viewModel.setSpeed(it); settingsOpen = false },
            onSubtitle = { viewModel.controller.setSubtitle(it); settingsOpen = false },
            // The only control here that does not close the sheet: a size is
            // found by moving it and looking, not by one decisive tap.
            onSubtitleScale = viewModel::setSubtitleScale,
            onDismiss = { settingsOpen = false },
        )
    }

    if (moreOpen) {
        EpisodeMoreSheet(
            onDetail = {
                moreOpen = false

                // Both of these live in the other activity, and both are
                // reached the same way a notification or a shared link would
                // reach them — through the address, not through a second set
                // of extras nobody else knows about.
                playerState.work?.id?.let { workId ->
                    context.startActivity(
                        Intent(Intent.ACTION_VIEW, Uri.parse("animeh://anime/$workId"))
                    )
                }
                onBack()
            },
            onRoom = {
                moreOpen = false

                viewModel.openRoom { code ->
                    context.startActivity(
                        Intent(Intent.ACTION_VIEW, Uri.parse("animeh://oda/$code"))
                    )
                    onBack()
                }
            },
            onDismiss = { moreOpen = false },
        )
    }
}
