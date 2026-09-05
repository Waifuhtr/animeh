package com.animeh.app.ui.screens.social

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Star
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.core.UiState
import com.animeh.app.data.remote.dto.ProfileReviewDto
import com.animeh.app.data.remote.dto.ProfileWorkDto
import com.animeh.app.ui.components.ErrorState
import com.animeh.app.ui.components.formatWatched
import com.animeh.app.ui.theme.PosterShape
import com.animeh.app.ui.theme.StatusWarning
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.SurfaceOverlay
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

/**
 * Somebody's profile, as everyone else sees it.
 *
 * The same screen for your own, with the switches showing. Two screens would
 * mean the thing you edit and the thing other people read could drift apart,
 * which is exactly the surprise nobody wants from a public profile.
 */
@Composable
fun PublicProfileScreen(
    onBack: () -> Unit,
    onOpenWork: (Long) -> Unit,
    viewModel: ProfileDetailViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val message by viewModel.message.collectAsStateWithLifecycle()

    val snackbar = remember { SnackbarHostState() }
    val sent = stringResource(R.string.friends_requested)

    LaunchedEffect(message) {
        message?.let {
            snackbar.showSnackbar(it)
            viewModel.dismissMessage()
        }
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        topBar = {
            TopAppBar(
                title = {
                    Text(
                        (state as? UiState.Success)?.data?.displayName.orEmpty(),
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back))
                    }
                },
            )
        },
    ) { padding ->
        when (val current = state) {
            is UiState.Loading -> Box(Modifier.fillMaxSize().padding(padding), Alignment.Center) {
                CircularProgressIndicator()
            }

            is UiState.Error -> ErrorState(
                current.error,
                onRetry = viewModel::load,
                modifier = Modifier.padding(padding),
            )

            is UiState.Empty -> Unit

            is UiState.Success -> {
                val profile = current.data

                LazyColumn(
                    Modifier.padding(padding),
                    contentPadding = PaddingValues(bottom = 32.dp),
                ) {
                    item {
                        Row(
                            Modifier.fillMaxWidth().padding(16.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            AsyncImage(
                                model = profile.avatar,
                                contentDescription = null,
                                contentScale = ContentScale.Crop,
                                modifier = Modifier
                                    .size(72.dp)
                                    .clip(CircleShape)
                                    .background(SurfaceOverlay),
                            )

                            Spacer(Modifier.width(14.dp))

                            Column(Modifier.weight(1f)) {
                                Text(
                                    profile.displayName.ifBlank { profile.username },
                                    style = MaterialTheme.typography.titleLarge,
                                )
                                Text(
                                    "@${profile.username}",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = TextMuted,
                                )
                            }
                        }
                    }

                    // The button says what the state is rather than offering
                    // an action that would be refused.
                    if (!profile.isSelf) {
                        item {
                            Box(Modifier.fillMaxWidth().padding(horizontal = 16.dp)) {
                                when (profile.friendship) {
                                    "accepted" -> OutlinedButton(
                                        onClick = {},
                                        enabled = false,
                                        modifier = Modifier.fillMaxWidth(),
                                    ) {
                                        Text(stringResource(R.string.profile_friends_already))
                                    }

                                    "requested", "pending" -> OutlinedButton(
                                        onClick = {},
                                        enabled = false,
                                        modifier = Modifier.fillMaxWidth(),
                                    ) {
                                        Text(stringResource(R.string.profile_friend_pending))
                                    }

                                    else -> Button(
                                        onClick = { viewModel.addFriend(sent) },
                                        modifier = Modifier.fillMaxWidth(),
                                    ) {
                                        Text(stringResource(R.string.profile_add_friend))
                                    }
                                }
                            }
                        }
                    }

                    item {
                        Row(
                            Modifier.fillMaxWidth().padding(16.dp),
                            horizontalArrangement = Arrangement.spacedBy(12.dp),
                        ) {
                            Stat(
                                formatWatched(profile.stats.secondsWatched),
                                stringResource(R.string.profile_time_watched),
                                Modifier.weight(1f),
                            )
                            Stat(
                                "${profile.stats.episodesCompleted}",
                                stringResource(R.string.profile_episodes_watched),
                                Modifier.weight(1f),
                            )
                            Stat(
                                "${profile.stats.worksCompleted}",
                                stringResource(R.string.profile_series_completed),
                                Modifier.weight(1f),
                            )
                        }
                    }

                    profile.favoriteWork?.let { favorite ->
                        item { Header(stringResource(R.string.profile_favorite)) }
                        item {
                            Row(
                                Modifier
                                    .fillMaxWidth()
                                    .padding(horizontal = 16.dp)
                                    .clip(RoundedCornerShape(14.dp))
                                    .background(SurfaceCard)
                                    .clickable { onOpenWork(favorite.id) }
                                    .padding(12.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                AsyncImage(
                                    model = favorite.posterUrl,
                                    contentDescription = favorite.title,
                                    contentScale = ContentScale.Crop,
                                    modifier = Modifier
                                        .width(56.dp)
                                        .height(80.dp)
                                        .clip(PosterShape)
                                        .background(SurfaceOverlay),
                                )

                                Spacer(Modifier.width(12.dp))

                                Column(Modifier.weight(1f)) {
                                    Text(
                                        favorite.title,
                                        style = MaterialTheme.typography.titleSmall,
                                        maxLines = 2,
                                        overflow = TextOverflow.Ellipsis,
                                    )

                                    if (favorite.score > 0) {
                                        Spacer(Modifier.height(4.dp))
                                        Row(verticalAlignment = Alignment.CenterVertically) {
                                            Icon(
                                                Icons.Filled.Star,
                                                null,
                                                Modifier.size(14.dp),
                                                tint = StatusWarning,
                                            )
                                            Spacer(Modifier.width(4.dp))
                                            Text(
                                                "%.1f".format(favorite.score),
                                                style = MaterialTheme.typography.labelMedium,
                                            )
                                        }
                                    }
                                }
                            }
                        }
                    }

                    if (profile.topGenres.isNotEmpty()) {
                        item { Header(stringResource(R.string.profile_genres)) }
                        item { GenreWheel(profile.topGenres) }
                    }

                    if (profile.recentWorks.isNotEmpty()) {
                        item { Header(stringResource(R.string.profile_recent)) }
                        item {
                            LazyRow(
                                contentPadding = PaddingValues(horizontal = 16.dp),
                                horizontalArrangement = Arrangement.spacedBy(10.dp),
                            ) {
                                items(profile.recentWorks, key = { it.id }) { work ->
                                    RecentPoster(work, onClick = { onOpenWork(work.id) })
                                }
                            }
                        }
                    }

                    item { Header(stringResource(R.string.profile_reviews)) }

                    if (profile.reviews.isEmpty()) {
                        item {
                            Text(
                                stringResource(R.string.profile_no_reviews),
                                style = MaterialTheme.typography.bodySmall,
                                color = TextMuted,
                                modifier = Modifier.padding(horizontal = 16.dp),
                            )
                        }
                    } else {
                        items(profile.reviews, key = { it.id }) { review ->
                            ProfileReview(review, onClick = { onOpenWork(review.workId) })
                        }
                    }

                    // Your own profile carries the switch that decides whether
                    // anybody else sees any of the above.
                    if (profile.isSelf) {
                        item {
                            Column(Modifier.padding(16.dp)) {
                                HorizontalDivider()
                                Spacer(Modifier.height(8.dp))

                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Switch(
                                        checked = profile.isPublic,
                                        onCheckedChange = viewModel::setVisibility,
                                    )
                                    Spacer(Modifier.width(12.dp))
                                    Column {
                                        Text(stringResource(R.string.profile_visibility))
                                        Text(
                                            stringResource(R.string.profile_visibility_hint),
                                            style = MaterialTheme.typography.labelSmall,
                                            color = TextMuted,
                                        )
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun Header(text: String) {
    Text(
        text,
        style = MaterialTheme.typography.headlineSmall,
        modifier = Modifier.padding(start = 16.dp, top = 20.dp, bottom = 10.dp),
    )
}

@Composable
private fun Stat(value: String, label: String, modifier: Modifier = Modifier) {
    Surface(color = SurfaceCard, shape = RoundedCornerShape(14.dp), modifier = modifier) {
        Column(
            Modifier.padding(vertical = 14.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(value, style = MaterialTheme.typography.titleLarge)
            Spacer(Modifier.height(2.dp))
            Text(
                label,
                style = MaterialTheme.typography.labelSmall,
                color = TextMuted,
                maxLines = 2,
            )
        }
    }
}

@Composable
private fun RecentPoster(work: ProfileWorkDto, onClick: () -> Unit) {
    Column(Modifier.width(96.dp).clickable(onClick = onClick)) {
        AsyncImage(
            model = work.posterUrl,
            contentDescription = work.title,
            contentScale = ContentScale.Crop,
            modifier = Modifier
                .width(96.dp)
                .height(138.dp)
                .clip(PosterShape)
                .background(SurfaceOverlay),
        )

        Spacer(Modifier.height(6.dp))

        Text(
            work.title,
            style = MaterialTheme.typography.labelSmall,
            maxLines = 2,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

@Composable
private fun ProfileReview(review: ProfileReviewDto, onClick: () -> Unit) {
    // Reset per review, so one revealed spoiler does not uncover the next.
    var revealed by remember(review.id) { mutableStateOf(false) }

    Surface(
        color = SurfaceCard,
        shape = RoundedCornerShape(14.dp),
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 5.dp),
    ) {
        Column(Modifier.padding(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    review.workTitle,
                    style = MaterialTheme.typography.titleSmall,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f).clickable(onClick = onClick),
                )

                Icon(Icons.Filled.Star, null, Modifier.size(14.dp), tint = StatusWarning)
                Spacer(Modifier.width(4.dp))
                Text("${review.score}/10", style = MaterialTheme.typography.labelMedium)
            }

            if (review.body.isNotBlank()) {
                Spacer(Modifier.height(8.dp))

                if (review.spoiler && !revealed) {
                    Surface(
                        color = SurfaceOverlay,
                        shape = RoundedCornerShape(10.dp),
                        modifier = Modifier.fillMaxWidth().clickable { revealed = true },
                    ) {
                        Text(
                            stringResource(R.string.reviews_spoiler_hidden),
                            style = MaterialTheme.typography.bodySmall,
                            color = TextMuted,
                            modifier = Modifier.padding(12.dp),
                        )
                    }
                } else {
                    Text(
                        review.body,
                        style = MaterialTheme.typography.bodyMedium,
                        color = TextSecondary,
                        maxLines = 6,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
            }
        }
    }
}
