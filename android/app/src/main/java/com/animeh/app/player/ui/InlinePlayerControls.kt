package com.animeh.app.player.ui

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Fullscreen
import androidx.compose.material.icons.filled.Forward10
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.Pause
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Replay10
import androidx.compose.material.icons.filled.Subtitles
import androidx.compose.material.icons.filled.SubtitlesOff
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.animeh.app.R
import com.animeh.app.player.PlaybackPhase
import com.animeh.app.player.PlayerUiState
import com.animeh.app.ui.theme.AccentPrimary
import kotlin.math.roundToLong

/**
 * The controls over the small player at the top of an episode page.
 *
 * A second, deliberately smaller set rather than the fullscreen ones reused at
 * a different size. The two are used differently: fullscreen is somebody
 * watching, and everything gets out of the way; this one sits above a page
 * being read, so its controls are close together, always within a thumb's
 * reach of the bottom edge, and there is no locking, no gesture seeking and no
 * up-next card — all of which belong to a picture that fills the screen.
 *
 * What it shares with [PlayerControls] is the state object, so the two are
 * never out of step and switching between them keeps the playhead, the chosen
 * quality and whether the controls are showing.
 */
@Composable
fun InlinePlayerControls(
    state: PlayerUiState,
    loading: Boolean,
    loadError: String?,
    onPlayPause: () -> Unit,
    onSeek: (Long) -> Unit,
    onSeekBy: (Long) -> Unit,
    onToggleControls: () -> Unit,
    onToggleSubtitles: () -> Unit,
    onOpenSettings: () -> Unit,
    onFullscreen: () -> Unit,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Box(
        modifier
            .fillMaxSize()
            .pointerInput(Unit) {
                // One tap, one job. Double-tap seeking belongs to the
                // fullscreen layout, where there is room to miss.
                detectTapGestures(onTap = { onToggleControls() })
            },
    ) {
        AnimatedVisibility(
            visible = state.controlsVisible,
            enter = fadeIn(),
            exit = fadeOut(),
        ) {
            Box(Modifier.fillMaxSize()) {
                Box(
                    Modifier
                        .matchParentSize()
                        .background(
                            Brush.verticalGradient(
                                listOf(
                                    Color.Black.copy(alpha = 0.55f),
                                    Color.Black.copy(alpha = 0.15f),
                                    Color.Black.copy(alpha = 0.7f),
                                )
                            )
                        )
                )

                // Top: out of here on the left, what and how on the right.
                Row(
                    Modifier
                        .align(Alignment.TopCenter)
                        .fillMaxWidth()
                        .padding(horizontal = 6.dp, vertical = 2.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    IconButton(onClick = onBack) {
                        Icon(
                            Icons.AutoMirrored.Filled.ArrowBack,
                            stringResource(R.string.back),
                            tint = Color.White,
                        )
                    }

                    Spacer(Modifier.weight(1f))

                    if (state.activeHeight > 0) {
                        QualityChip(text = "${state.activeHeight}p", onClick = onOpenSettings)
                        Spacer(Modifier.width(6.dp))
                    }

                    IconButton(onClick = onOpenSettings) {
                        Icon(Icons.Filled.MoreVert, stringResource(R.string.player_settings), tint = Color.White)
                    }
                }

                // Centre: the three that get used most.
                Row(
                    Modifier.align(Alignment.Center),
                    horizontalArrangement = Arrangement.spacedBy(22.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    RoundControl(
                        icon = Icons.Filled.Replay10,
                        description = stringResource(R.string.player_seek_back),
                        onClick = { onSeekBy(-10_000L) },
                    )

                    val busy = loading ||
                        state.phase is PlaybackPhase.Preparing ||
                        state.phase is PlaybackPhase.Buffering ||
                        state.phase is PlaybackPhase.Reconnecting

                    if (busy) {
                        Box(Modifier.size(56.dp), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(
                                Modifier.size(34.dp),
                                color = AccentPrimary,
                                strokeWidth = 3.dp,
                            )
                        }
                    } else {
                        FilledIconButton(
                            onClick = onPlayPause,
                            modifier = Modifier.size(56.dp),
                            shape = CircleShape,
                            colors = IconButtonDefaults.filledIconButtonColors(
                                containerColor = AccentPrimary,
                            ),
                        ) {
                            Icon(
                                if (state.phase.isPlaying) Icons.Filled.Pause else Icons.Filled.PlayArrow,
                                stringResource(
                                    if (state.phase.isPlaying) R.string.player_pause else R.string.player_play
                                ),
                                tint = Color.White,
                                modifier = Modifier.size(30.dp),
                            )
                        }
                    }

                    RoundControl(
                        icon = Icons.Filled.Forward10,
                        description = stringResource(R.string.player_seek_forward),
                        onClick = { onSeekBy(10_000L) },
                    )
                }

                // Bottom: where we are, and the three switches.
                InlineSeekRow(
                    state = state,
                    onSeek = onSeek,
                    onToggleSubtitles = onToggleSubtitles,
                    onOpenSettings = onOpenSettings,
                    onFullscreen = onFullscreen,
                    modifier = Modifier.align(Alignment.BottomCenter),
                )
            }
        }

        // The two things that must be visible whether or not the controls are.
        when {
            loadError != null -> InlineMessage(
                message = loadError,
                actionLabel = stringResource(R.string.retry),
                onAction = onRetry,
                modifier = Modifier.align(Alignment.Center),
            )

            state.phase is PlaybackPhase.Failed -> InlineMessage(
                message = stringResource((state.phase as PlaybackPhase.Failed).error.messageRes),
                actionLabel = stringResource(R.string.retry),
                onAction = onRetry,
                modifier = Modifier.align(Alignment.Center),
            )

            else -> Unit
        }
    }
}

@Composable
private fun InlineSeekRow(
    state: PlayerUiState,
    onSeek: (Long) -> Unit,
    onToggleSubtitles: () -> Unit,
    onOpenSettings: () -> Unit,
    onFullscreen: () -> Unit,
    modifier: Modifier = Modifier,
) {
    // While a drag is in progress the bar follows the finger rather than the
    // playhead, exactly as the fullscreen bar does; snapping back on every
    // frame makes a target impossible to land on.
    var scrubbing by remember { mutableStateOf(false) }
    var scrubPosition by remember { mutableFloatStateOf(0f) }

    val fraction = if (scrubbing) scrubPosition else state.progressFraction

    Column(modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 4.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                formatTime(if (scrubbing) (scrubPosition * state.durationMs).roundToLong() else state.positionMs),
                style = MaterialTheme.typography.labelMedium,
                color = Color.White,
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
                    inactiveTrackColor = Color.White.copy(alpha = 0.25f),
                ),
                modifier = Modifier.weight(1f).padding(horizontal = 8.dp),
            )

            Text(
                formatTime(state.durationMs),
                style = MaterialTheme.typography.labelMedium,
                color = Color.White,
            )
        }

        Row(
            Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.End,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            IconButton(onClick = onToggleSubtitles, modifier = Modifier.size(38.dp)) {
                Icon(
                    if (state.subtitlesEnabled) Icons.Filled.Subtitles else Icons.Filled.SubtitlesOff,
                    stringResource(R.string.player_subtitles),
                    tint = if (state.subtitlesEnabled) Color.White else Color.White.copy(alpha = 0.5f),
                    modifier = Modifier.size(21.dp),
                )
            }

            Spacer(Modifier.width(10.dp))

            IconButton(onClick = onOpenSettings, modifier = Modifier.size(38.dp)) {
                Icon(
                    Icons.Outlined.Settings,
                    stringResource(R.string.player_settings),
                    tint = Color.White,
                    modifier = Modifier.size(21.dp),
                )
            }

            Spacer(Modifier.width(10.dp))

            IconButton(onClick = onFullscreen, modifier = Modifier.size(38.dp)) {
                Icon(
                    Icons.Filled.Fullscreen,
                    stringResource(R.string.player_fullscreen),
                    tint = Color.White,
                    modifier = Modifier.size(23.dp),
                )
            }
        }
    }
}

/** A quality badge that is also the way into the quality menu. */
@Composable
private fun QualityChip(text: String, onClick: () -> Unit) {
    Surface(
        shape = RoundedCornerShape(20.dp),
        color = Color.Transparent,
        border = androidx.compose.foundation.BorderStroke(1.dp, Color.White.copy(alpha = 0.45f)),
        modifier = Modifier.clickable(onClick = onClick),
    ) {
        Text(
            text,
            style = MaterialTheme.typography.labelMedium,
            color = Color.White,
            modifier = Modifier.padding(horizontal = 12.dp, vertical = 5.dp),
        )
    }
}

@Composable
private fun RoundControl(
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    description: String,
    onClick: () -> Unit,
) {
    Box(
        Modifier
            .size(44.dp)
            .clip(CircleShape)
            .clickable(
                interactionSource = remember { MutableInteractionSource() },
                indication = null,
                onClick = onClick,
            ),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, description, tint = Color.White, modifier = Modifier.size(30.dp))
    }
}

/**
 * A failure, said inside the picture.
 *
 * Small enough to fit in a 16:9 box on a phone held upright, which the
 * fullscreen panel is not.
 */
@Composable
private fun InlineMessage(
    message: String,
    actionLabel: String,
    onAction: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Surface(
        modifier = modifier.padding(16.dp),
        shape = RoundedCornerShape(12.dp),
        color = Color.Black.copy(alpha = 0.8f),
    ) {
        Column(
            Modifier.padding(horizontal = 16.dp, vertical = 12.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                message,
                style = MaterialTheme.typography.bodySmall,
                color = Color.White,
                textAlign = androidx.compose.ui.text.style.TextAlign.Center,
            )
            Spacer(Modifier.height(8.dp))
            TextButton(onClick = onAction) {
                Text(actionLabel, color = AccentPrimary)
            }
        }
    }
}
