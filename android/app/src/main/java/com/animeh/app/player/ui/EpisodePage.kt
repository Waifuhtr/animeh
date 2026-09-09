package com.animeh.app.player.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Groups
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.PlayCircle
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material.icons.filled.Share
import androidx.compose.material.icons.outlined.AddCircleOutline
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.domain.Episode
import com.animeh.app.domain.Work
import com.animeh.app.ui.components.ProgressLine
import com.animeh.app.ui.theme.AccentBright
import com.animeh.app.ui.theme.AccentPrimary
import com.animeh.app.ui.theme.PosterShape
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.SurfaceOverlay
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

/**
 * Everything under the player when the phone is held upright.
 *
 * Opening an episode used to mean the picture and nothing else — turn the
 * phone, watch, come back. That is right once you are watching and wrong on
 * the way in: which episode this is, what it is called, how many there are and
 * which one is next were all somewhere else, and the only way to the next
 * episode was to leave.
 *
 * So the picture keeps the top of the screen and the rest of the page lives
 * under it. The player is pinned rather than scrolled away — it is why anybody
 * is here — and the list beneath it changes episodes without leaving.
 *
 * Turning the phone hands the whole screen back to [PlayerControls]. The two
 * layouts share one engine and one state object, so nothing reloads and the
 * playhead does not move.
 */
@Composable
fun EpisodePageBody(
    work: Work?,
    episode: Episode?,
    episodes: List<Episode>,
    inWatchlist: Boolean,
    onEpisode: (Long) -> Unit,
    onWatchlist: (Boolean) -> Unit,
    onShare: () -> Unit,
    onMore: () -> Unit,
    modifier: Modifier = Modifier,
) {
    var tab by rememberSaveable { mutableStateOf(0) }

    LazyColumn(
        modifier = modifier.fillMaxSize(),
        contentPadding = PaddingValues(bottom = 28.dp),
    ) {
        item(contentType = "header") {
            EpisodeHeader(work = work, episode = episode)
        }

        item(contentType = "actions") {
            ActionRow(
                inWatchlist = inWatchlist,
                onWatchlist = onWatchlist,
                onShare = onShare,
                onMore = onMore,
            )
        }

        item(contentType = "tabs") {
            TabRow(
                selectedTabIndex = tab,
                containerColor = Color.Transparent,
                divider = { HorizontalDivider(color = Color.White.copy(alpha = 0.06f)) },
            ) {
                Tab(
                    selected = tab == 0,
                    onClick = { tab = 0 },
                    text = { TabLabel(Icons.Filled.PlayCircle, stringResource(R.string.episode_tab_list), tab == 0) },
                )
                Tab(
                    selected = tab == 1,
                    onClick = { tab = 1 },
                    text = { TabLabel(Icons.Filled.Info, stringResource(R.string.episode_tab_about), tab == 1) },
                )
            }
        }

        if (tab == 0) {
            items(episodes, key = { it.id }, contentType = { "episode" }) { row ->
                EpisodeRow(
                    episode = row,
                    playing = row.id == episode?.id,
                    onClick = { onEpisode(row.id) },
                )
            }

            if (episodes.isEmpty()) {
                item(contentType = "empty") {
                    Text(
                        stringResource(R.string.episode_list_empty),
                        style = MaterialTheme.typography.bodyMedium,
                        color = TextMuted,
                        modifier = Modifier.fillMaxWidth().padding(24.dp),
                    )
                }
            }
        } else {
            item(contentType = "about") {
                Text(
                    // The episode's own words when it has any, the series' when
                    // it does not: a blank tab is worse than a repeat.
                    episode?.synopsis?.takeIf { it.isNotBlank() }
                        ?: work?.synopsis?.takeIf { it.isNotBlank() }
                        ?: stringResource(R.string.episode_no_synopsis),
                    style = MaterialTheme.typography.bodyMedium,
                    color = TextSecondary,
                    modifier = Modifier.padding(16.dp),
                )
            }
        }
    }
}

/** The poster, the series, and which episode this is. */
@Composable
private fun EpisodeHeader(work: Work?, episode: Episode?) {
    Row(Modifier.fillMaxWidth().padding(16.dp)) {
        AsyncImage(
            model = work?.posterUrl.orEmpty(),
            contentDescription = work?.displayTitle,
            contentScale = ContentScale.Crop,
            modifier = Modifier
                .width(92.dp)
                .height(132.dp)
                .clip(PosterShape)
                .background(SurfaceCard),
        )

        Spacer(Modifier.width(14.dp))

        Column(Modifier.weight(1f)) {
            Text(
                work?.displayTitle.orEmpty(),
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.Bold,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )

            Spacer(Modifier.height(6.dp))

            Text(
                listOfNotNull(
                    episode?.seasonNumber?.takeIf { it > 0 }
                        ?.let { stringResource(R.string.detail_season, it) },
                    work?.totalEpisodes?.takeIf { it > 0 }
                        ?.let { stringResource(R.string.episode_count, it) },
                ).joinToString(" • "),
                style = MaterialTheme.typography.bodySmall,
                color = TextMuted,
            )

            Spacer(Modifier.height(12.dp))

            if (episode != null) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        stringResource(R.string.episode_number, episode.number),
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.SemiBold,
                    )

                    if (episode.title.isNotBlank()) {
                        Text(
                            "  –  ",
                            style = MaterialTheme.typography.titleSmall,
                            color = TextMuted,
                        )
                        Text(
                            episode.title,
                            style = MaterialTheme.typography.titleSmall,
                            color = AccentBright,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun ActionRow(
    inWatchlist: Boolean,
    onWatchlist: (Boolean) -> Unit,
    onShare: () -> Unit,
    onMore: () -> Unit,
) {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        // The one with a state says what pressing it would undo, which is what
        // makes it readable without an explanation underneath.
        ActionButton(
            icon = if (inWatchlist) Icons.Filled.CheckCircle else Icons.Outlined.AddCircleOutline,
            label = stringResource(
                if (inWatchlist) R.string.episode_watchlist_remove else R.string.episode_watchlist_add
            ),
            accent = inWatchlist,
            onClick = { onWatchlist(!inWatchlist) },
            modifier = Modifier.weight(1.3f),
        )

        ActionButton(
            icon = Icons.Filled.Share,
            label = stringResource(R.string.episode_share),
            onClick = onShare,
            modifier = Modifier.weight(1f),
        )

        ActionButton(
            icon = Icons.Filled.MoreVert,
            label = stringResource(R.string.episode_more),
            onClick = onMore,
            modifier = Modifier.weight(1f),
        )
    }
}

@Composable
private fun ActionButton(
    icon: ImageVector,
    label: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    accent: Boolean = false,
) {
    OutlinedButton(
        onClick = onClick,
        modifier = modifier.height(46.dp),
        shape = RoundedCornerShape(14.dp),
        border = androidx.compose.foundation.BorderStroke(
            1.dp,
            if (accent) AccentPrimary.copy(alpha = 0.8f) else Color.White.copy(alpha = 0.12f),
        ),
        contentPadding = PaddingValues(horizontal = 8.dp),
    ) {
        Icon(
            icon,
            null,
            tint = if (accent) AccentBright else Color.White,
            modifier = Modifier.size(17.dp),
        )
        Spacer(Modifier.width(6.dp))
        Text(
            label,
            style = MaterialTheme.typography.labelMedium,
            color = if (accent) AccentBright else Color.White,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

@Composable
private fun TabLabel(icon: ImageVector, label: String, selected: Boolean) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(
            icon,
            null,
            tint = if (selected) AccentPrimary else TextMuted,
            modifier = Modifier.size(16.dp),
        )
        Spacer(Modifier.width(6.dp))
        Text(
            label,
            style = MaterialTheme.typography.labelLarge,
            color = if (selected) AccentBright else TextMuted,
        )
    }
}

/**
 * One episode in the list.
 *
 * The one playing is outlined rather than merely tinted: on a dark theme a
 * shade of purple against a shade of grey is not a difference you find while
 * scrolling.
 */
@Composable
private fun EpisodeRow(episode: Episode, playing: Boolean, onClick: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 5.dp)
            .clip(RoundedCornerShape(14.dp))
            .background(if (playing) AccentPrimary.copy(alpha = 0.12f) else SurfaceCard.copy(alpha = 0.5f))
            .then(
                if (playing) {
                    Modifier.border(1.dp, AccentPrimary.copy(alpha = 0.7f), RoundedCornerShape(14.dp))
                } else {
                    Modifier
                }
            )
            .clickable(onClick = onClick)
            .padding(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            "${episode.number}",
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
            color = if (playing) AccentBright else TextMuted,
            modifier = Modifier.width(26.dp),
        )

        Box {
            AsyncImage(
                model = episode.thumbnailUrl.ifBlank { episode.workPoster },
                contentDescription = episode.label,
                contentScale = ContentScale.Crop,
                modifier = Modifier
                    .width(96.dp)
                    .height(56.dp)
                    .clip(RoundedCornerShape(8.dp))
                    .background(SurfaceOverlay),
            )

            episode.progress?.let { ProgressLine(it.fraction, Modifier.align(Alignment.BottomCenter)) }
        }

        Spacer(Modifier.width(12.dp))

        Column(Modifier.weight(1f)) {
            Text(
                episode.label,
                style = MaterialTheme.typography.bodyMedium,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )

            if (episode.durationSeconds > 0) {
                Spacer(Modifier.height(4.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(
                        Icons.Filled.Schedule,
                        null,
                        tint = TextMuted,
                        modifier = Modifier.size(13.dp),
                    )
                    Spacer(Modifier.width(4.dp))
                    Text(
                        formatTime(episode.durationSeconds * 1000L),
                        style = MaterialTheme.typography.labelSmall,
                        color = TextMuted,
                    )
                }
            }
        }

        // Only on the one playing. The others are opened by pressing the row,
        // which is the whole row and hard to miss; a button on each would be a
        // second target for the same thing.
        if (playing) {
            Box(
                Modifier
                    .size(38.dp)
                    .clip(RoundedCornerShape(19.dp))
                    .background(AccentPrimary),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.Filled.PlayArrow,
                    stringResource(R.string.player_play),
                    tint = Color.White,
                    modifier = Modifier.size(22.dp),
                )
            }
        }
    }
}

/** What the "more" button opens. */
@Composable
fun EpisodeMoreSheet(
    onDetail: () -> Unit,
    onRoom: () -> Unit,
    onDismiss: () -> Unit,
) {
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = SurfaceCard) {
        ListItem(
            headlineContent = { Text(stringResource(R.string.episode_open_series)) },
            leadingContent = { Icon(Icons.Filled.Info, null) },
            modifier = Modifier.clickable { onDetail() },
            colors = ListItemDefaults.colors(containerColor = Color.Transparent),
        )
        ListItem(
            headlineContent = { Text(stringResource(R.string.room_create)) },
            leadingContent = { Icon(Icons.Filled.Groups, null) },
            modifier = Modifier.clickable { onRoom() },
            colors = ListItemDefaults.colors(containerColor = Color.Transparent),
        )
        Spacer(Modifier.height(24.dp))
    }
}
