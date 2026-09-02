package com.animeh.app.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Star
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.domain.ContinueItem
import com.animeh.app.domain.Episode
import com.animeh.app.domain.Work
import com.animeh.app.domain.WorkStatus
import com.animeh.app.ui.theme.*

/** Poster width used everywhere, so rails line up across screens. */
val PosterWidth = 128.dp
val PosterHeight = 186.dp

@Composable
fun WorkCard(
    work: Work,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    width: androidx.compose.ui.unit.Dp = PosterWidth,
) {
    Column(
        modifier = modifier
            .width(width)
            .clickable(onClick = onClick),
    ) {
        Box {
            AsyncImage(
                model = work.posterUrl,
                contentDescription = work.displayTitle,
                contentScale = ContentScale.Crop,
                modifier = Modifier
                    .width(width)
                    .height(width * POSTER_RATIO)
                    .clip(PosterShape)
                    .background(SurfaceCard),
            )

            // The status reads as a word in the top right, where the score used
            // to sit: "is this finished" is the question someone scanning a
            // shelf is actually asking, and a coloured dot never answered it.
            StatusTag(
                status = work.status,
                modifier = Modifier.align(Alignment.TopEnd).padding(6.dp),
            )

            if (work.hasScore) {
                ScoreBadge(
                    score = work.score,
                    modifier = Modifier.align(Alignment.TopStart).padding(6.dp),
                )
            }
        }

        Spacer(Modifier.height(8.dp))

        Text(
            text = work.displayTitle,
            style = MaterialTheme.typography.titleSmall,
            maxLines = TITLE_MAX_LINES,
            overflow = TextOverflow.Ellipsis,
        )

        val subtitle = listOfNotNull(
            work.year.takeIf { it > 0 }?.toString(),
            work.format.takeIf { it.isNotBlank() },
        ).joinToString(" · ")

        if (subtitle.isNotBlank()) {
            Text(
                text = subtitle,
                style = MaterialTheme.typography.labelSmall,
                color = TextMuted,
                maxLines = 1,
            )
        }
    }
}

@Composable
fun ContinueCard(
    item: ContinueItem,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .width(240.dp)
            .clickable(onClick = onClick),
    ) {
        Box {
            AsyncImage(
                model = item.thumbnailUrl,
                contentDescription = item.workTitle,
                contentScale = ContentScale.Crop,
                modifier = Modifier
                    .fillMaxWidth()
                    .height(135.dp)
                    .clip(PosterShape)
                    .background(SurfaceCard),
            )

            // A scrim under the play affordance so it stays visible over a
            // bright thumbnail.
            Box(
                Modifier
                    .matchParentSize()
                    .clip(PosterShape)
                    .background(
                        Brush.verticalGradient(
                            listOf(Color.Transparent, Color.Black.copy(alpha = 0.55f))
                        )
                    )
            )

            Icon(
                Icons.Filled.PlayArrow,
                contentDescription = null,
                tint = Color.White,
                modifier = Modifier.align(Alignment.Center).size(40.dp),
            )

            ProgressLine(
                fraction = item.progress.fraction,
                modifier = Modifier.align(Alignment.BottomCenter),
            )
        }

        Spacer(Modifier.height(8.dp))

        Text(
            text = item.workTitle,
            style = MaterialTheme.typography.titleSmall,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
        Text(
            text = "${item.seasonNumber}. Sezon · ${item.episodeNumber}. Bölüm",
            style = MaterialTheme.typography.labelSmall,
            color = TextMuted,
        )
    }
}

@Composable
fun EpisodeRowCard(
    episode: Episode,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box {
            AsyncImage(
                model = episode.thumbnailUrl.ifBlank { episode.workPoster },
                contentDescription = episode.label,
                contentScale = ContentScale.Crop,
                modifier = Modifier
                    .width(132.dp)
                    .height(74.dp)
                    .clip(RoundedCornerShape(8.dp))
                    .background(SurfaceCard),
            )

            episode.progress?.let { progress ->
                ProgressLine(progress.fraction, Modifier.align(Alignment.BottomCenter))
            }
        }

        Spacer(Modifier.width(12.dp))

        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    text = "${episode.number}",
                    style = MaterialTheme.typography.titleMedium,
                    color = AccentBright,
                    fontWeight = FontWeight.Bold,
                )
                Spacer(Modifier.width(10.dp))
                Text(
                    text = episode.title.ifBlank { "Bölüm ${episode.number}" },
                    style = MaterialTheme.typography.titleSmall,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f),
                )
            }

            val meta = listOfNotNull(
                episode.durationSeconds.takeIf { it > 0 }?.let { "${it / 60} dk" },
                "Dolgu".takeIf { episode.filler },
            ).joinToString(" · ")

            if (meta.isNotBlank()) {
                Text(meta, style = MaterialTheme.typography.labelSmall, color = TextMuted)
            }
        }
    }
}

@Composable
fun ScoreBadge(score: Double, modifier: Modifier = Modifier) {
    Surface(
        modifier = modifier,
        shape = RoundedCornerShape(6.dp),
        color = Color.Black.copy(alpha = 0.72f),
    ) {
        Row(
            Modifier.padding(horizontal = 6.dp, vertical = 3.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Filled.Star, null, tint = StatusWarning, modifier = Modifier.size(11.dp))
            Spacer(Modifier.width(3.dp))
            Text(
                text = "%.1f".format(score),
                style = MaterialTheme.typography.labelSmall,
                color = Color.White,
            )
        }
    }
}

@Composable
fun StatusDot(color: Color, modifier: Modifier = Modifier) {
    Box(
        modifier
            .size(9.dp)
            .clip(androidx.compose.foundation.shape.CircleShape)
            .background(color)
    )
}

/**
 * Where a series stands, as a word.
 *
 * Nothing is drawn for an unknown status: a tag saying "bilinmiyor" is worse
 * than no tag, because it takes up the corner to say nothing.
 */
@Composable
fun StatusTag(status: WorkStatus, modifier: Modifier = Modifier) {
    val label = when (status) {
        WorkStatus.AIRING -> R.string.status_airing
        WorkStatus.FINISHED -> R.string.status_finished
        WorkStatus.UPCOMING -> R.string.status_upcoming
        WorkStatus.UNKNOWN -> return
    }

    val tint = when (status) {
        WorkStatus.AIRING -> BadgeAiring
        WorkStatus.FINISHED -> BadgeFinished
        WorkStatus.UPCOMING -> BadgeUpcoming
        WorkStatus.UNKNOWN -> BadgeFinished
    }

    Surface(
        modifier = modifier,
        shape = RoundedCornerShape(6.dp),
        color = Color.Black.copy(alpha = 0.72f),
    ) {
        Row(
            Modifier.padding(horizontal = 6.dp, vertical = 3.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            StatusDot(color = tint, modifier = Modifier.size(6.dp))
            Spacer(Modifier.width(4.dp))
            Text(
                text = stringResource(label),
                style = MaterialTheme.typography.labelSmall,
                color = Color.White,
                maxLines = 1,
            )
        }
    }
}

/** A horizontal rail of works, with a skeleton while it loads. */
@Composable
fun WorkRail(
    works: List<Work>,
    onClick: (Work) -> Unit,
    loading: Boolean = false,
    modifier: Modifier = Modifier,
) {
    LazyRow(
        modifier = modifier,
        contentPadding = PaddingValues(horizontal = 16.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        if (loading) {
            items(6) {
                Column(Modifier.width(PosterWidth)) {
                    Shimmer(Modifier.size(PosterWidth, PosterHeight), PosterShape)
                    Spacer(Modifier.height(8.dp))
                    Shimmer(Modifier.fillMaxWidth().height(12.dp))
                }
            }
        } else {
            items(works, key = { it.id }) { work ->
                WorkCard(work = work, onClick = { onClick(work) })
            }
        }
    }
}

private const val POSTER_RATIO = 1.45f
