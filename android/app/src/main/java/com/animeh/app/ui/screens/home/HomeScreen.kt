package com.animeh.app.ui.screens.home

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.outlined.Info
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.core.UiState
import com.animeh.app.data.repository.label
import com.animeh.app.domain.Work
import com.animeh.app.ui.components.*
import com.animeh.app.ui.theme.StatusWarning
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.SurfaceOverlay
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary
import java.util.Locale

/**
 * The home screen.
 *
 * One request produces every rail — the backend assembles them — because on the
 * connection this app targets, six requests to draw one screen is six chances
 * to leave it half-populated.
 */
@Composable
fun HomeScreen(
    onWorkClick: (Work) -> Unit,
    onEpisodeClick: (Long) -> Unit,
    onSeeAll: () -> Unit,
    viewModel: HomeViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val announcements by viewModel.announcements.collectAsStateWithLifecycle()
    val labels by viewModel.labels.collectAsStateWithLifecycle()

    when (val current = state) {
        is UiState.Loading -> HomeSkeleton()

        is UiState.Error -> ErrorState(
            error = current.error,
            onRetry = viewModel::load,
            modifier = Modifier.fillMaxSize(),
        )

        is UiState.Empty -> EmptyState(
            message = stringResource(R.string.home_empty),
            modifier = Modifier.fillMaxSize(),
        )

        is UiState.Success -> {
            val feed = current.data

            LazyColumn(
                contentPadding = PaddingValues(bottom = 24.dp),
                verticalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                if (current.fromCache) {
                    item { OfflineBanner() }
                }

                if (announcements.isNotEmpty()) {
                    item {
                        AnnouncementBar(
                            title = announcements.first().title,
                            body = announcements.first().body,
                        )
                    }
                }

                if (feed.hero.isNotEmpty()) {
                    item { HeroCarousel(feed.hero, onWorkClick, labels) }
                }

                if (feed.continueWatching.isNotEmpty()) {
                    item { SectionHeader(stringResource(R.string.home_continue)) }
                    item {
                        LazyRow(
                            contentPadding = PaddingValues(horizontal = 16.dp),
                            horizontalArrangement = Arrangement.spacedBy(12.dp),
                        ) {
                            items(feed.continueWatching, key = { it.episodeId }) { item ->
                                ContinueCard(item = item, onClick = { onEpisodeClick(item.episodeId) })
                            }
                        }
                    }
                }

                if (feed.latestEpisodes.isNotEmpty()) {
                    item { SectionHeader(stringResource(R.string.home_new_episodes)) }
                    items(feed.latestEpisodes.take(6), key = { "ep-${it.id}" }) { episode ->
                        EpisodeRowCard(episode = episode, onClick = { onEpisodeClick(episode.id) })
                    }
                }

                if (feed.popular.isNotEmpty()) {
                    item { SectionHeader(stringResource(R.string.home_popular), onSeeAll = onSeeAll) }
                    item { WorkRail(feed.popular, onWorkClick) }
                }

                if (feed.airing.isNotEmpty()) {
                    item { SectionHeader(stringResource(R.string.home_airing), onSeeAll = onSeeAll) }
                    item { WorkRail(feed.airing, onWorkClick) }
                }

                if (feed.recentlyAdded.isNotEmpty()) {
                    item { SectionHeader(stringResource(R.string.home_recently_added), onSeeAll = onSeeAll) }
                    item { WorkRail(feed.recentlyAdded, onWorkClick) }
                }
            }
        }
    }
}

@Composable
private fun HeroCarousel(
    works: List<Work>,
    onClick: (Work) -> Unit,
    labels: Map<String, Map<String, String>>,
) {
    val pagerState = rememberPagerState(pageCount = { works.size })

    Column(Modifier.fillMaxWidth().padding(top = 8.dp, bottom = 4.dp)) {
        HorizontalPager(
            state = pagerState,
            pageSpacing = 12.dp,
            contentPadding = PaddingValues(horizontal = 16.dp),
        ) { page ->
            HeroCard(works[page], page, works.size, onClick, labels)
        }

        Spacer(Modifier.height(12.dp))

        Row(
            Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.Center,
        ) {
            repeat(works.size) { index ->
                val selected = index == pagerState.currentPage

                // The current page's dot stretches rather than just brightening:
                // at this size a colour change alone is easy to miss.
                Box(
                    Modifier
                        .padding(horizontal = 3.dp)
                        .height(6.dp)
                        .width(if (selected) 20.dp else 6.dp)
                        .clip(CircleShape)
                        .background(
                            if (selected) MaterialTheme.colorScheme.primary
                            else TextMuted.copy(alpha = 0.5f)
                        )
                )
            }
        }
    }
}

/**
 * One slide.
 *
 * The artwork sits to the right and is faded out towards the left rather than
 * darkened all over, so the text has solid colour behind it while the picture
 * still reads as a picture. A blanket scrim would cost the artwork and still
 * leave the longest titles hard to read.
 */
@Composable
private fun HeroCard(
    work: Work,
    index: Int,
    total: Int,
    onClick: (Work) -> Unit,
    labels: Map<String, Map<String, String>>,
) {
    val card = SurfaceCard

    Box(
        Modifier
            .fillMaxWidth()
            .height(300.dp)
            .clip(RoundedCornerShape(24.dp))
            .background(card)
            .clickable { onClick(work) },
    ) {
        AsyncImage(
            model = work.bannerUrl.ifBlank { work.posterUrl },
            contentDescription = work.displayTitle,
            contentScale = ContentScale.Crop,
            modifier = Modifier
                .fillMaxHeight()
                .fillMaxWidth(0.68f)
                .align(Alignment.CenterEnd),
        )

        Box(
            Modifier
                .matchParentSize()
                .background(
                    Brush.horizontalGradient(
                        0f to card,
                        0.42f to card,
                        0.72f to card.copy(alpha = 0.75f),
                        1f to Color.Transparent,
                    )
                )
        )

        Column(
            Modifier
                .align(Alignment.CenterStart)
                .fillMaxWidth(0.66f)
                .padding(start = 20.dp, end = 8.dp, top = 20.dp, bottom = 20.dp),
        ) {
            Surface(
                color = MaterialTheme.colorScheme.primary,
                shape = RoundedCornerShape(10.dp),
            ) {
                Text(
                    stringResource(R.string.hero_new_episode),
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.onPrimary,
                    modifier = Modifier.padding(horizontal = 12.dp, vertical = 6.dp),
                )
            }

            Spacer(Modifier.height(14.dp))

            Text(
                text = work.displayTitle,
                style = MaterialTheme.typography.headlineMedium,
                maxLines = 3,
                overflow = TextOverflow.Ellipsis,
            )

            if (work.synopsis.isNotBlank()) {
                Spacer(Modifier.height(10.dp))
                Text(
                    text = work.synopsis,
                    style = MaterialTheme.typography.bodySmall,
                    color = TextSecondary,
                    maxLines = 3,
                    overflow = TextOverflow.Ellipsis,
                )
            }

            if (work.genres.isNotEmpty()) {
                Spacer(Modifier.height(12.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    work.genres.take(3).forEach { genre ->
                        Surface(
                            color = SurfaceOverlay,
                            shape = RoundedCornerShape(8.dp),
                        ) {
                            Text(
                                labels.label("genre", genre),
                                style = MaterialTheme.typography.labelSmall,
                                color = TextSecondary,
                                maxLines = 1,
                                modifier = Modifier.padding(horizontal = 10.dp, vertical = 5.dp),
                            )
                        }
                    }
                }
            }

            Spacer(Modifier.height(16.dp))

            Row(verticalAlignment = Alignment.CenterVertically) {
                Button(
                    onClick = { onClick(work) },
                    shape = RoundedCornerShape(16.dp),
                    contentPadding = PaddingValues(horizontal = 22.dp, vertical = 12.dp),
                ) {
                    Icon(Icons.Filled.PlayArrow, null, Modifier.size(20.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(R.string.detail_play))
                }

                Spacer(Modifier.width(10.dp))

                OutlinedIconButton(
                    onClick = { onClick(work) },
                    modifier = Modifier.size(46.dp),
                ) {
                    Icon(
                        Icons.Outlined.Info,
                        contentDescription = stringResource(R.string.hero_details),
                    )
                }
            }
        }

        if (work.score > 0) {
            Surface(
                color = Color.Black.copy(alpha = 0.55f),
                shape = RoundedCornerShape(12.dp),
                modifier = Modifier.align(Alignment.TopEnd).padding(14.dp),
            ) {
                Row(
                    Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(
                        Icons.Filled.Star,
                        null,
                        Modifier.size(15.dp),
                        tint = StatusWarning,
                    )
                    Spacer(Modifier.width(4.dp))
                    Text(
                        String.format(Locale.getDefault(), "%.1f", work.score),
                        style = MaterialTheme.typography.labelMedium,
                        color = Color.White,
                    )
                }
            }
        }

        Text(
            "${index + 1} / $total",
            style = MaterialTheme.typography.labelSmall,
            color = TextMuted,
            modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp),
        )
    }
}

@Composable
private fun AnnouncementBar(title: String, body: String) {
    Surface(
        color = MaterialTheme.colorScheme.primaryContainer,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(horizontal = 16.dp, vertical = 10.dp)) {
            Text(title, style = MaterialTheme.typography.titleSmall)
            if (body.isNotBlank()) {
                Text(
                    body,
                    style = MaterialTheme.typography.bodySmall,
                    color = TextSecondary,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
            }
        }
    }
}

@Composable
private fun HomeSkeleton() {
    LazyColumn(verticalArrangement = Arrangement.spacedBy(16.dp)) {
        item { Shimmer(Modifier.fillMaxWidth().height(420.dp)) }
        repeat(3) {
            item {
                Column {
                    Shimmer(Modifier.padding(horizontal = 16.dp).width(160.dp).height(22.dp))
                    Spacer(Modifier.height(12.dp))
                    WorkRail(works = emptyList(), onClick = {}, loading = true)
                }
            }
        }
    }
}
