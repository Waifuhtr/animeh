package com.animeh.app.ui.screens.leaderboard

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.EmojiEvents
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.rotate
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.data.remote.dto.LeaderboardEntryDto
import com.animeh.app.ui.components.AvatarWithFrame
import com.animeh.app.ui.components.formatWatched
import com.animeh.app.ui.theme.AccentBright
import com.animeh.app.ui.theme.AccentPrimary
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary
import com.animeh.app.ui.theme.profileTheme

/**
 * The standings.
 *
 * Three boards over the same history: most episodes finished, most time
 * played, most series started. Each is its own tab and its own request, and
 * the server keeps each for five minutes — a leaderboard that updates on every
 * refresh is a leaderboard that costs a full table scan on every refresh.
 *
 * ### The podium, and what it costs
 *
 * The top three get a rotating conic ring, a slow glow and a medal. All of it
 * is drawn rather than composed: the rotation is read inside `drawBehind`, so
 * a frame of animation is a frame of drawing and not a recomposition of the
 * card. Three of them run at once and nothing else on the screen animates —
 * the rows below are still, and a frame in a row is a still first frame, for
 * the same reason the shop's grid is.
 */
@Composable
fun LeaderboardScreen(
    onBack: () -> Unit,
    onProfile: (Long) -> Unit,
    viewModel: LeaderboardViewModel = hiltViewModel(),
) {
    val metric by viewModel.metric.collectAsStateWithLifecycle()
    val boards by viewModel.boards.collectAsStateWithLifecycle()
    val state = boards[metric] ?: BoardState()

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Sıralama") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, "Geri")
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Color.Transparent),
            )
        },
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            TabRow(
                selectedTabIndex = BoardMetric.entries.indexOf(metric),
                containerColor = Color.Transparent,
                divider = { HorizontalDivider(color = Color.White.copy(alpha = 0.06f)) },
            ) {
                BoardMetric.entries.forEach { option ->
                    Tab(
                        selected = option == metric,
                        onClick = { viewModel.select(option) },
                        text = {
                            Text(
                                option.label,
                                color = if (option == metric) AccentBright else TextMuted,
                                fontWeight = if (option == metric) FontWeight.SemiBold else FontWeight.Normal,
                            )
                        },
                    )
                }
            }

            when {
                state.loading && state.entries.isEmpty() ->
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator()
                    }

                state.error != null && state.entries.isEmpty() ->
                    Box(Modifier.fillMaxSize().padding(32.dp), contentAlignment = Alignment.Center) {
                        Text(state.error, color = TextMuted, textAlign = TextAlign.Center)
                    }

                state.entries.isEmpty() ->
                    Box(Modifier.fillMaxSize().padding(32.dp), contentAlignment = Alignment.Center) {
                        Text(
                            "Henüz kimse listeye girmemiş. İlk sen ol.",
                            color = TextMuted,
                            textAlign = TextAlign.Center,
                        )
                    }

                else -> Board(
                    entries = state.entries,
                    metric = metric,
                    myRank = state.me?.rank ?: 0,
                    myValue = state.me?.value ?: 0,
                    total = state.me?.total ?: 0,
                    onProfile = onProfile,
                )
            }
        }
    }
}

@Composable
private fun Board(
    entries: List<LeaderboardEntryDto>,
    metric: BoardMetric,
    myRank: Int,
    myValue: Long,
    total: Int,
    onProfile: (Long) -> Unit,
) {
    val podium = entries.take(3)
    val rest = entries.drop(3)

    LazyColumn(contentPadding = PaddingValues(bottom = 28.dp)) {
        if (podium.isNotEmpty()) {
            item(contentType = "podium") {
                Podium(podium, metric, onProfile)
            }
        }

        // Their own line, when they are not already up there. Shown before the
        // rest of the list rather than at the bottom of fifty rows nobody
        // scrolls to.
        if (myRank > 3) {
            item(contentType = "me") {
                MyStanding(myRank, myValue, total, metric)
            }
        }

        items(rest, key = { it.userId }, contentType = { "row" }) { entry ->
            BoardRow(entry, metric, onProfile)
        }
    }
}

/**
 * First, second and third — the tall one in the middle.
 *
 * Their frames animate, because there are exactly three of them and they are
 * the reason anybody opened this screen.
 */
@Composable
private fun Podium(
    top: List<LeaderboardEntryDto>,
    metric: BoardMetric,
    onProfile: (Long) -> Unit,
) {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 20.dp),
        verticalAlignment = Alignment.Bottom,
        horizontalArrangement = Arrangement.Center,
    ) {
        // Second on the left, first in the middle, third on the right — the
        // arrangement everybody already reads without a legend.
        top.getOrNull(1)?.let { PodiumPlace(it, 2, metric, 84.dp, onProfile, Modifier.weight(1f)) }
        top.getOrNull(0)?.let { PodiumPlace(it, 1, metric, 112.dp, onProfile, Modifier.weight(1.15f)) }
        top.getOrNull(2)?.let { PodiumPlace(it, 3, metric, 76.dp, onProfile, Modifier.weight(1f)) }
    }
}

@Composable
private fun PodiumPlace(
    entry: LeaderboardEntryDto,
    place: Int,
    metric: BoardMetric,
    size: Dp,
    onProfile: (Long) -> Unit,
    modifier: Modifier = Modifier,
) {
    val medal = medalColor(place)

    // One transition per place. The two values it drives are read in the draw
    // phase below, never at composition — reading them here with `by` would
    // turn every frame of this animation into a recomposition of the card and
    // everything in it.
    val transition = rememberInfiniteTransition(label = "podium$place")
    val spin = transition.animateFloat(
        initialValue = 0f,
        targetValue = 360f,
        animationSpec = infiniteRepeatable(
            // Slower for the lower places, so three rings turning at once
            // read as three things rather than one flicker.
            animation = tween(4000 + place * 1200, easing = LinearEasing),
            repeatMode = RepeatMode.Restart,
        ),
        label = "spin",
    )
    val pulse = transition.animateFloat(
        initialValue = 0.35f,
        targetValue = 0.85f,
        animationSpec = infiniteRepeatable(
            animation = tween(1800 + place * 300, easing = LinearEasing),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "pulse",
    )

    Column(
        modifier
            .clickable { onProfile(entry.userId) }
            .padding(horizontal = 4.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(contentAlignment = Alignment.Center) {
            Box(
                Modifier
                    .size(size + 26.dp)
                    .drawBehind {
                        val glow = pulse.value
                        val angle = spin.value

                        // A soft halo whose strength breathes.
                        drawCircle(
                            brush = Brush.radialGradient(
                                colors = listOf(
                                    medal.copy(alpha = 0.30f * glow),
                                    Color.Transparent,
                                ),
                                center = center,
                                radius = this.size.minDimension / 2f,
                            ),
                            radius = this.size.minDimension / 2f,
                        )

                        // And a conic sweep turning inside it. Rotated in the
                        // draw scope rather than by a graphics layer: it is
                        // one arc, and a layer would allocate a whole texture
                        // for it.
                        rotate(angle) {
                            drawCircle(
                                brush = Brush.sweepGradient(
                                    listOf(
                                        Color.Transparent,
                                        medal.copy(alpha = 0.15f),
                                        medal,
                                        medal.copy(alpha = 0.15f),
                                        Color.Transparent,
                                    )
                                ),
                                radius = this.size.minDimension / 2f - 6f,
                                style = Stroke(width = if (place == 1) 7f else 5f),
                            )
                        }
                    },
            )

            AvatarWithFrame(
                avatarUrl = entry.avatar,
                frame = entry.frame,
                size = size,
                // Three, at the top of one screen. This is the budget.
                animate = true,
                contentDescription = entry.displayName,
            )
        }

        Spacer(Modifier.height(8.dp))

        Box(
            Modifier
                .size(26.dp)
                .clip(CircleShape)
                .background(medal),
            contentAlignment = Alignment.Center,
        ) {
            Text(
                "$place",
                style = MaterialTheme.typography.labelLarge,
                fontWeight = FontWeight.Black,
                color = Color(0xFF1B1926),
            )
        }

        Spacer(Modifier.height(6.dp))

        Text(
            entry.displayName.ifBlank { entry.username },
            style = MaterialTheme.typography.labelLarge,
            fontWeight = if (place == 1) FontWeight.Bold else FontWeight.SemiBold,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            textAlign = TextAlign.Center,
        )

        Text(
            formatValue(entry.value, metric),
            style = MaterialTheme.typography.labelSmall,
            color = medal,
        )
    }
}

@Composable
private fun BoardRow(
    entry: LeaderboardEntryDto,
    metric: BoardMetric,
    onProfile: (Long) -> Unit,
) {
    val theme = profileTheme(entry.theme)

    Row(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 4.dp)
            .clip(RoundedCornerShape(14.dp))
            .background(
                if (entry.isMe) AccentPrimary.copy(alpha = 0.14f) else SurfaceCard.copy(alpha = 0.45f)
            )
            .then(
                if (entry.isMe) {
                    Modifier.border(1.dp, AccentPrimary.copy(alpha = 0.6f), RoundedCornerShape(14.dp))
                } else {
                    Modifier
                }
            )
            .clickable { onProfile(entry.userId) }
            .padding(horizontal = 12.dp, vertical = 9.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            "${entry.rank}",
            style = MaterialTheme.typography.titleSmall,
            fontWeight = FontWeight.Bold,
            color = if (entry.isMe) AccentBright else TextMuted,
            textAlign = TextAlign.Center,
            modifier = Modifier.width(34.dp),
        )

        // Never animated: this is a list, and a list of animations is where
        // the frame rate goes.
        AvatarWithFrame(
            avatarUrl = entry.avatar,
            frame = entry.frame,
            size = 46.dp,
            animate = false,
        )

        Spacer(Modifier.width(12.dp))

        Column(Modifier.weight(1f)) {
            Text(
                entry.displayName.ifBlank { entry.username },
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = if (entry.isMe) FontWeight.SemiBold else FontWeight.Normal,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                "@${entry.username}",
                style = MaterialTheme.typography.labelSmall,
                color = TextMuted,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }

        Text(
            formatValue(entry.value, metric),
            style = MaterialTheme.typography.labelLarge,
            fontWeight = FontWeight.SemiBold,
            color = theme.accent,
        )
    }
}

@Composable
private fun MyStanding(rank: Int, value: Long, total: Int, metric: BoardMetric) {
    Row(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 10.dp)
            .clip(RoundedCornerShape(14.dp))
            .background(AccentPrimary.copy(alpha = 0.12f))
            .padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Filled.EmojiEvents, null, tint = AccentBright, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(10.dp))

        Column(Modifier.weight(1f)) {
            Text(
                "Senin sıran: #$rank",
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
            )
            if (total > 0) {
                Text(
                    "$total kişi arasında",
                    style = MaterialTheme.typography.labelSmall,
                    color = TextSecondary,
                )
            }
        }

        Text(
            formatValue(value, metric),
            style = MaterialTheme.typography.titleSmall,
            fontWeight = FontWeight.Bold,
            color = AccentBright,
        )
    }
}

/** Gold, silver, bronze. */
private fun medalColor(place: Int): Color = when (place) {
    1 -> Color(0xFFFBBF24)
    2 -> Color(0xFFCBD5E1)
    else -> Color(0xFFD97757)
}

/**
 * The number, in the unit its board is counted in.
 *
 * Seconds go through the same formatter the profile uses, so "37 saat 12
 * dakika" means the same thing in both places.
 */
private fun formatValue(value: Long, metric: BoardMetric): String = when (metric) {
    BoardMetric.SECONDS -> formatWatched(value)
    BoardMetric.EPISODES -> "$value bölüm"
    BoardMetric.WORKS -> "$value anime"
}
