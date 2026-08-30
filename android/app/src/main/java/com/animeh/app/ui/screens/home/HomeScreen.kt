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
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.PlayArrow
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
import com.animeh.app.domain.Work
import com.animeh.app.ui.components.*
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.TextSecondary

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
                    item { HeroCarousel(feed.hero, onWorkClick) }
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
private fun HeroCarousel(works: List<Work>, onClick: (Work) -> Unit) {
    val pagerState = rememberPagerState(pageCount = { works.size })

    Box(Modifier.fillMaxWidth().height(420.dp)) {
        HorizontalPager(state = pagerState) { page ->
            val work = works[page]

            Box(
                Modifier
                    .fillMaxSize()
                    .clickable { onClick(work) },
            ) {
                AsyncImage(
                    model = work.bannerUrl.ifBlank { work.posterUrl },
                    contentDescription = work.displayTitle,
                    contentScale = ContentScale.Crop,
                    modifier = Modifier.fillMaxSize().background(SurfaceCard),
                )

                // The gradient does two jobs: it makes the title readable over
                // any artwork, and it blends the banner into the page rather
                // than cutting it off with a hard edge.
                Box(
                    Modifier
                        .matchParentSize()
                        .background(
                            Brush.verticalGradient(
                                0f to Color.Transparent,
                                0.45f to Color.Black.copy(alpha = 0.35f),
                                1f to MaterialTheme.colorScheme.background,
                            )
                        )
                )

                Column(
                    Modifier
                        .align(Alignment.BottomStart)
                        .padding(20.dp),
                ) {
                    Text(
                        text = work.displayTitle,
                        style = MaterialTheme.typography.displayMedium,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis,
                    )

                    Spacer(Modifier.height(6.dp))

                    Text(
                        text = work.genres.take(3).joinToString(" · "),
                        style = MaterialTheme.typography.labelMedium,
                        color = TextSecondary,
                    )

                    Spacer(Modifier.height(14.dp))

                    Button(onClick = { onClick(work) }) {
                        Icon(Icons.Filled.PlayArrow, null, Modifier.size(18.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(stringResource(R.string.detail_play))
                    }
                }
            }
        }

        Row(
            Modifier
                .align(Alignment.BottomEnd)
                .padding(20.dp),
            horizontalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            repeat(works.size) { index ->
                Box(
                    Modifier
                        .size(if (index == pagerState.currentPage) 8.dp else 6.dp)
                        .clip(CircleShape)
                        .background(
                            if (index == pagerState.currentPage) MaterialTheme.colorScheme.primary
                            else Color.White.copy(alpha = 0.4f)
                        )
                )
            }
        }
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
