package com.animeh.app.player.ui

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.*
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.animeh.app.R
import com.animeh.app.player.PlaybackPhase
import com.animeh.app.player.PlayerUiState
import com.animeh.app.player.QualitySelection
import com.animeh.app.ui.theme.AccentPrimary
import com.animeh.app.ui.theme.SurfaceOverlay
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary
import kotlin.math.roundToLong

/**
 * The player's controls, drawn from scratch.
 *
 * §1 rules out reusing a stock `PlayerView`, and this is the alternative: every
 * control is a composable over the video surface, driven by [PlayerUiState].
 *
 * The interaction rule that matters most, because it is the one the concept got
 * wrong: **tapping empty space only shows or hides the controls.** It never
 * pauses. Pausing happens on the pause button and nowhere else — a mis-tap
 * while adjusting the phone should not stop the episode.
 */
@Composable
fun PlayerControls(
    state: PlayerUiState,
    onPlayPause: () -> Unit,
    onSeek: (Long) -> Unit,
    onSeekBy: (Long) -> Unit,
    onToggleControls: () -> Unit,
    onNext: () -> Unit,
    onPrevious: () -> Unit,
    onSkipIntro: () -> Unit,
    onLock: (Boolean) -> Unit,
    onBack: () -> Unit,
    onOpenSettings: () -> Unit,
    onRetry: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Box(
        modifier = modifier
            .fillMaxSize()
            .pointerInput(state.locked) {
                detectTapGestures(
                    // A single tap toggles the controls and nothing else.
                    onTap = { onToggleControls() },
                    // A double tap seeks, on the half of the screen it landed
                    // on — the gesture people already expect.
                    onDoubleTap = { offset ->
                        if (!state.locked) {
                            if (offset.x < size.width / 2) onSeekBy(-10_000L) else onSeekBy(10_000L)
                        }
                    },
                )
            }
    ) {
        // Locked: one button to unlock, nothing else reachable, so a pocket
        // cannot seek or close the episode.
        if (state.locked) {
            AnimatedVisibility(
                visible = state.controlsVisible,
                enter = fadeIn(),
                exit = fadeOut(),
                modifier = Modifier.align(Alignment.CenterEnd).padding(24.dp),
            ) {
                FilledIconButton(
                    onClick = { onLock(false) },
                    colors = IconButtonDefaults.filledIconButtonColors(containerColor = SurfaceOverlay),
                ) {
                    Icon(Icons.Filled.LockOpen, stringResource(R.string.player_unlock))
                }
            }
            return@Box
        }

        AnimatedVisibility(
            visible = state.controlsVisible,
            enter = fadeIn(),
            exit = fadeOut(),
        ) {
            Box(Modifier.fillMaxSize()) {
                // Scrims top and bottom so white controls stay readable over a
                // bright frame without dimming the middle of the picture.
                Box(
                    Modifier
                        .fillMaxWidth()
                        .height(140.dp)
                        .align(Alignment.TopCenter)
                        .background(
                            Brush.verticalGradient(listOf(Color.Black.copy(alpha = 0.75f), Color.Transparent))
                        )
                )
                Box(
                    Modifier
                        .fillMaxWidth()
                        .height(200.dp)
                        .align(Alignment.BottomCenter)
                        .background(
                            Brush.verticalGradient(listOf(Color.Transparent, Color.Black.copy(alpha = 0.85f)))
                        )
                )

                TopBar(
                    state = state,
                    onBack = onBack,
                    onLock = { onLock(true) },
                    onOpenSettings = onOpenSettings,
                    modifier = Modifier.align(Alignment.TopCenter),
                )

                CenterControls(
                    state = state,
                    onPlayPause = onPlayPause,
                    onSeekBy = onSeekBy,
                    onNext = onNext,
                    onPrevious = onPrevious,
                    modifier = Modifier.align(Alignment.Center),
                )

                BottomBar(
                    state = state,
                    onSeek = onSeek,
                    modifier = Modifier.align(Alignment.BottomCenter),
                )
            }
        }

        // These sit outside the controls' visibility: a skip button that
        // disappears with the controls is a skip button nobody finds.
        if (state.showSkipIntro) {
            SkipIntroButton(
                onClick = onSkipIntro,
                modifier = Modifier
                    .align(Alignment.BottomEnd)
                    .padding(end = 24.dp, bottom = if (state.controlsVisible) 120.dp else 48.dp),
            )
        }

        if (state.showUpNext && state.next != null) {
            UpNextCard(
                title = state.next.label,
                autoplay = state.autoplayNext,
                onPlay = onNext,
                modifier = Modifier
                    .align(Alignment.BottomEnd)
                    .padding(end = 24.dp, bottom = if (state.controlsVisible) 120.dp else 48.dp),
            )
        }

        when (val phase = state.phase) {
            // Buffering and preparing are drawn by the centre control itself,
            // which swaps to a spinner; a second one over the top was the
            // overlap. Only states the controls cannot express land here.
            is PlaybackPhase.Buffering, is PlaybackPhase.Preparing ->
                if (!state.controlsVisible) {
                    LoadingIndicator(
                        label = stringResource(R.string.player_buffering),
                        modifier = Modifier.align(Alignment.Center),
                    )
                }

            is PlaybackPhase.Reconnecting ->
                if (!state.controlsVisible) {
                    LoadingIndicator(
                        label = stringResource(R.string.player_reconnecting) + " (${phase.attempt})",
                        modifier = Modifier.align(Alignment.Center),
                    )
                }

            is PlaybackPhase.Failed ->
                PlayerErrorPanel(
                    message = stringResource(phase.error.messageRes),
                    canRetry = phase.canRetry,
                    onRetry = onRetry,
                    onBack = onBack,
                    modifier = Modifier.align(Alignment.Center),
                )

            else -> Unit
        }
    }
}

@Composable
private fun TopBar(
    state: PlayerUiState,
    onBack: () -> Unit,
    onLock: () -> Unit,
    onOpenSettings: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier
            .fillMaxWidth()
            .statusBarsPadding()
            .padding(horizontal = 8.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconButton(onClick = onBack) {
            Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back), tint = Color.White)
        }

        Column(Modifier.weight(1f).padding(horizontal = 8.dp)) {
            Text(
                text = state.work?.displayTitle.orEmpty(),
                style = MaterialTheme.typography.titleMedium,
                color = Color.White,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            state.episode?.let { episode ->
                Text(
                    text = "${episode.seasonNumber}. Sezon · ${episode.number}. Bölüm",
                    style = MaterialTheme.typography.bodySmall,
                    color = TextSecondary,
                    maxLines = 1,
                )
            }
        }

        IconButton(onClick = onLock) {
            Icon(Icons.Filled.Lock, stringResource(R.string.player_lock), tint = Color.White)
        }

        IconButton(onClick = onOpenSettings) {
            Icon(Icons.Filled.Tune, stringResource(R.string.player_settings), tint = Color.White)
        }
    }
}

@Composable
private fun CenterControls(
    state: PlayerUiState,
    onPlayPause: () -> Unit,
    onSeekBy: (Long) -> Unit,
    onNext: () -> Unit,
    onPrevious: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier,
        horizontalArrangement = Arrangement.spacedBy(28.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconButton(
            onClick = onPrevious,
            enabled = state.previous != null,
            modifier = Modifier.size(48.dp),
        ) {
            Icon(
                Icons.Filled.SkipPrevious,
                stringResource(R.string.player_previous_episode),
                tint = if (state.previous != null) Color.White else TextMuted,
                modifier = Modifier.size(32.dp),
            )
        }

        IconButton(onClick = { onSeekBy(-10_000L) }, modifier = Modifier.size(48.dp)) {
            Icon(
                Icons.Filled.Replay10,
                stringResource(R.string.player_seek_back),
                tint = Color.White,
                modifier = Modifier.size(34.dp),
            )
        }

        // While the episode is being fetched the spinner stands in the button's
        // place rather than on top of it: there is nothing to play yet, so
        // offering the control and covering it at once was the worst of both.
        val busy = state.phase is PlaybackPhase.Preparing ||
            state.phase is PlaybackPhase.Buffering ||
            state.phase is PlaybackPhase.Reconnecting

        if (busy) {
            Box(Modifier.size(72.dp), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(
                    color = AccentPrimary,
                    strokeWidth = 3.dp,
                    modifier = Modifier.size(46.dp),
                )
            }
        } else {
            // The only control that pauses.
            FilledIconButton(
                onClick = onPlayPause,
                modifier = Modifier.size(72.dp),
                shape = CircleShape,
                colors = IconButtonDefaults.filledIconButtonColors(containerColor = AccentPrimary),
            ) {
                Icon(
                    imageVector = if (state.phase.isPlaying) Icons.Filled.Pause else Icons.Filled.PlayArrow,
                    contentDescription = stringResource(
                        if (state.phase.isPlaying) R.string.player_pause else R.string.player_play
                    ),
                    tint = Color.White,
                    modifier = Modifier.size(38.dp),
                )
            }
        }

        IconButton(onClick = { onSeekBy(10_000L) }, modifier = Modifier.size(48.dp)) {
            Icon(
                Icons.Filled.Forward10,
                stringResource(R.string.player_seek_forward),
                tint = Color.White,
                modifier = Modifier.size(34.dp),
            )
        }

        IconButton(
            onClick = onNext,
            enabled = state.next != null,
            modifier = Modifier.size(48.dp),
        ) {
            Icon(
                Icons.Filled.SkipNext,
                stringResource(R.string.player_next_episode),
                tint = if (state.next != null) Color.White else TextMuted,
                modifier = Modifier.size(32.dp),
            )
        }
    }
}

@Composable
private fun BottomBar(
    state: PlayerUiState,
    onSeek: (Long) -> Unit,
    modifier: Modifier = Modifier,
) {
    // While a drag is in progress the bar follows the finger rather than the
    // playhead; snapping back to the real position on every frame makes it
    // impossible to land on a target.
    var scrubbing by remember { mutableStateOf(false) }
    var scrubPosition by remember { mutableFloatStateOf(0f) }

    val fraction = if (scrubbing) scrubPosition else state.progressFraction

    Column(
        modifier = modifier
            .fillMaxWidth()
            .navigationBarsPadding()
            .padding(horizontal = 16.dp, vertical = 12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                text = formatTime(((if (scrubbing) scrubPosition * state.durationMs else state.positionMs.toFloat()).roundToLong())),
                style = MaterialTheme.typography.labelMedium,
                color = Color.White,
            )

            Box(Modifier.weight(1f).padding(horizontal = 12.dp)) {
                // The buffered track sits behind the slider so the viewer can
                // see how much runway there is before a seek stalls.
                LinearProgressIndicator(
                    progress = { state.bufferedFraction },
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(4.dp)
                        .align(Alignment.Center)
                        .clip(RoundedCornerShape(2.dp)),
                    color = Color.White.copy(alpha = 0.35f),
                    trackColor = Color.White.copy(alpha = 0.15f),
                    drawStopIndicator = {},
                )

                Slider(
                    value = fraction,
                    onValueChange = {
                        scrubbing = true
                        scrubPosition = it
                    },
                    onValueChangeFinished = {
                        scrubbing = false
                        onSeek((scrubPosition * state.durationMs).roundToLong())
                    },
                    enabled = state.canSeek,
                    colors = SliderDefaults.colors(
                        thumbColor = AccentPrimary,
                        activeTrackColor = AccentPrimary,
                        inactiveTrackColor = Color.Transparent,
                    ),
                    modifier = Modifier.fillMaxWidth(),
                )
            }

            Text(
                text = formatTime(state.durationMs),
                style = MaterialTheme.typography.labelMedium,
                color = TextSecondary,
            )
        }
    }
}

@Composable
private fun SkipIntroButton(onClick: () -> Unit, modifier: Modifier = Modifier) {
    Surface(
        onClick = onClick,
        modifier = modifier,
        shape = RoundedCornerShape(8.dp),
        color = Color.Black.copy(alpha = 0.7f),
        border = androidx.compose.foundation.BorderStroke(1.dp, Color.White.copy(alpha = 0.4f)),
    ) {
        Row(
            Modifier.padding(horizontal = 18.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Filled.FastForward, null, tint = Color.White, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            Text(
                stringResource(R.string.player_skip_intro),
                style = MaterialTheme.typography.labelLarge,
                color = Color.White,
            )
        }
    }
}

@Composable
private fun UpNextCard(
    title: String,
    autoplay: Boolean,
    onPlay: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Surface(
        onClick = onPlay,
        modifier = modifier.widthIn(max = 300.dp),
        shape = RoundedCornerShape(12.dp),
        color = Color.Black.copy(alpha = 0.82f),
        border = androidx.compose.foundation.BorderStroke(1.dp, AccentPrimary.copy(alpha = 0.6f)),
    ) {
        Row(
            Modifier.padding(14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(
                    stringResource(R.string.player_up_next),
                    style = MaterialTheme.typography.labelSmall,
                    color = TextSecondary,
                )
                Text(
                    title,
                    style = MaterialTheme.typography.titleSmall,
                    color = Color.White,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
                if (autoplay) {
                    Text(
                        "Otomatik oynatılacak",
                        style = MaterialTheme.typography.labelSmall,
                        color = TextMuted,
                    )
                }
            }
            Spacer(Modifier.width(12.dp))
            Icon(Icons.Filled.PlayCircle, null, tint = AccentPrimary, modifier = Modifier.size(36.dp))
        }
    }
}

@Composable
private fun LoadingIndicator(label: String, modifier: Modifier = Modifier) {
    Column(
        modifier = modifier,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        CircularProgressIndicator(color = AccentPrimary, strokeWidth = 3.dp)
        Spacer(Modifier.height(12.dp))
        Text(label, style = MaterialTheme.typography.labelMedium, color = Color.White)
    }
}

@Composable
private fun PlayerErrorPanel(
    message: String,
    canRetry: Boolean,
    onRetry: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Surface(
        modifier = modifier.widthIn(max = 340.dp),
        shape = RoundedCornerShape(16.dp),
        color = SurfaceOverlay,
    ) {
        Column(
            Modifier.padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Icon(Icons.Outlined.ErrorOutline, null, tint = MaterialTheme.colorScheme.error, modifier = Modifier.size(40.dp))
            Spacer(Modifier.height(12.dp))
            Text(
                message,
                style = MaterialTheme.typography.bodyMedium,
                color = Color.White,
                textAlign = androidx.compose.ui.text.style.TextAlign.Center,
            )
            Spacer(Modifier.height(20.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                OutlinedButton(onClick = onBack) { Text(stringResource(R.string.back)) }
                if (canRetry) {
                    Button(onClick = onRetry) { Text(stringResource(R.string.retry)) }
                }
            }
        }
    }
}

/** mm:ss, or h:mm:ss for anything over an hour. */
fun formatTime(millis: Long): String {
    if (millis <= 0) return "0:00"

    val totalSeconds = millis / 1000
    val hours = totalSeconds / 3600
    val minutes = (totalSeconds % 3600) / 60
    val seconds = totalSeconds % 60

    return if (hours > 0) {
        "%d:%02d:%02d".format(hours, minutes, seconds)
    } else {
        "%d:%02d".format(minutes, seconds)
    }
}
