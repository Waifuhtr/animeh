package com.animeh.app.ui.screens.detail

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
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
import com.animeh.app.ui.theme.*
import kotlinx.coroutines.launch

@Composable
fun DetailScreen(
    workId: Long,
    signedIn: Boolean,
    onBack: () -> Unit,
    onPlayEpisode: (Long) -> Unit,
    onSignIn: () -> Unit,
    onOpenRoom: () -> Unit = {},
    viewModel: DetailViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    // A report's outcome is the one thing on this screen with nothing of its
    // own to show for it: the list looks identical either way, so it has to
    // be said out loud.
    val snackbar = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()
    val partyAvailable by viewModel.partyAvailable.collectAsStateWithLifecycle()
    val messageText = state.messageText
        ?: state.messageRes?.let { stringResource(it) }

    LaunchedEffect(messageText) {
        messageText?.let {
            snackbar.showSnackbar(it)
            viewModel.dismissMessage()
        }
    }

    val play: (Long) -> Unit = { episodeId ->
        if (signedIn) onPlayEpisode(episodeId) else onSignIn()
    }

    // Raised by the view model when the page opens on a flagged series, not
    // by tapping anything: the warning is about the series, so it comes before
    // the artwork and the synopsis rather than on the way to the video.
    // Declining leaves the page, because the only thing left to do here is
    // read about something you just said you did not want to see.
    if (state.adultWarning) {
        AdultWarningDialog(
            onDismiss = {
                viewModel.dismissAdult()
                onBack()
            },
            onContinue = viewModel::acknowledgeAdult,
        )
    }

    Box(Modifier.fillMaxSize()) {
        when (val workState = state.work) {
            is UiState.Loading -> Box(Modifier.fillMaxSize(), Alignment.Center) {
                CircularProgressIndicator()
            }

            is UiState.Error -> ErrorState(
                error = workState.error,
                onRetry = viewModel::load,
                modifier = Modifier.fillMaxSize(),
            )

            is UiState.Empty -> EmptyState(stringResource(R.string.error_not_found))

            is UiState.Success -> {
                val work = workState.data

                LazyColumn(contentPadding = PaddingValues(bottom = 32.dp)) {
                    item {
                        DetailHeader(
                            work = work,
                            isFavorite = state.isFavorite,
                            inWatchlist = state.inWatchlist,
                            following = state.following,
                            signedIn = signedIn,
                            onBack = onBack,
                            onFavorite = { if (signedIn) viewModel.toggleFavorite() else onSignIn() },
                            onWatchlist = { if (signedIn) viewModel.toggleWatchlist() else onSignIn() },
                            onFollow = { if (signedIn) viewModel.toggleFollow() else onSignIn() },
                            onPlay = {
                                viewModel.nextEpisodeToPlay()?.let { episode ->
                                    play(episode.id)
                                }
                            },
                        )
                    }

                    // Below İzle rather than beside it: watching together is
                    // the occasional choice, and putting it level with the
                    // ordinary one makes every viewer stop and decide.
                    if (partyAvailable && signedIn) {
                        item {
                            OutlinedButton(
                                onClick = { scope.launch { if (viewModel.openRoom()) onOpenRoom() } },
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .padding(horizontal = 16.dp, vertical = 4.dp),
                            ) {
                                Icon(Icons.Filled.Groups, null, Modifier.size(18.dp))
                                Spacer(Modifier.width(8.dp))
                                Text(stringResource(R.string.room_create))
                            }
                        }
                    }

                    if (state.fromCache) {
                        item { OfflineBanner() }
                    }

                    if (work.synopsis.isNotBlank()) {
                        item { Synopsis(work.synopsis) }
                    }

                    item { MetaGrid(work) }

                    if (work.seasons.size > 1) {
                        item {
                            LazyRow(
                                contentPadding = PaddingValues(horizontal = 16.dp),
                                horizontalArrangement = Arrangement.spacedBy(8.dp),
                            ) {
                                items(work.seasons, key = { it.number }) { season ->
                                    FilterChip(
                                        selected = state.selectedSeason == season.number,
                                        onClick = { viewModel.selectSeason(season.number) },
                                        label = {
                                            Text(stringResource(R.string.detail_season, season.number))
                                        },
                                    )
                                }
                            }
                        }
                    }

                    item { SectionHeader(stringResource(R.string.detail_episodes)) }

                    when (val episodesState = state.episodes) {
                        is UiState.Loading -> items(4) {
                            Shimmer(
                                Modifier
                                    .padding(horizontal = 16.dp, vertical = 6.dp)
                                    .fillMaxWidth()
                                    .height(74.dp)
                            )
                        }

                        is UiState.Error -> item {
                            ErrorState(episodesState.error, onRetry = { viewModel.selectSeason(state.selectedSeason) })
                        }

                        is UiState.Empty -> item {
                            EmptyState(stringResource(R.string.detail_no_episodes))
                        }

                        is UiState.Success -> items(episodesState.data, key = { it.id }) { episode ->
                            EpisodeRowCard(
                                episode = episode,
                                onClick = { play(episode.id) },
                            )
                        }
                    }

                    item { Spacer(Modifier.height(24.dp)) }

                    item {
                        ReviewSection(
                            reviews = state.reviews,
                            mine = state.myReview,
                            average = state.rating,
                            ratingCount = state.ratingCount,
                            signedIn = signedIn,
                            onSubmit = viewModel::submitReview,
                            onDelete = viewModel::deleteReview,
                            onVote = viewModel::vote,
                            onReport = viewModel::report,
                            onSignIn = onSignIn,
                        )
                    }

                    item { Spacer(Modifier.height(32.dp)) }
                }
            }
        }

        SnackbarHost(snackbar, Modifier.align(Alignment.BottomCenter))
    }
}

@Composable
private fun DetailHeader(
    work: Work,
    isFavorite: Boolean,
    inWatchlist: Boolean,
    following: Boolean,
    signedIn: Boolean,
    onBack: () -> Unit,
    onFavorite: () -> Unit,
    onWatchlist: () -> Unit,
    onFollow: () -> Unit,
    onPlay: () -> Unit,
) {
    Box(Modifier.fillMaxWidth().height(340.dp)) {
        AsyncImage(
            model = work.bannerUrl.ifBlank { work.posterUrl },
            contentDescription = null,
            contentScale = ContentScale.Crop,
            modifier = Modifier.fillMaxSize().background(SurfaceCard),
        )

        Box(
            Modifier
                .matchParentSize()
                .background(
                    Brush.verticalGradient(
                        0f to Color.Black.copy(alpha = 0.5f),
                        0.5f to Color.Transparent,
                        1f to MaterialTheme.colorScheme.background,
                    )
                )
        )

        IconButton(
            onClick = onBack,
            modifier = Modifier.align(Alignment.TopStart).statusBarsPadding().padding(8.dp),
        ) {
            Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back), tint = Color.White)
        }

        // Visible before anything is tapped, so the dialog is a confirmation
        // rather than a surprise.
        if (work.adult) {
            Surface(
                color = StatusError,
                shape = RoundedCornerShape(8.dp),
                modifier = Modifier
                    .align(Alignment.TopEnd)
                    .statusBarsPadding()
                    .padding(14.dp),
            ) {
                Text(
                    stringResource(R.string.adult_badge),
                    style = MaterialTheme.typography.labelSmall,
                    color = Color.White,
                    modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
                )
            }
        }

        Row(
            Modifier
                .align(Alignment.BottomStart)
                .padding(16.dp),
            verticalAlignment = Alignment.Bottom,
        ) {
            AsyncImage(
                model = work.posterUrl,
                contentDescription = work.displayTitle,
                contentScale = ContentScale.Crop,
                modifier = Modifier
                    .width(104.dp)
                    .height(152.dp)
                    .clip(PosterShape)
                    .background(SurfaceCard),
            )

            Spacer(Modifier.width(14.dp))

            Column(Modifier.weight(1f)) {
                Text(
                    text = work.displayTitle,
                    style = MaterialTheme.typography.headlineMedium,
                    maxLines = 3,
                    overflow = TextOverflow.Ellipsis,
                )

                if (work.titleEnglish.isNotBlank() && work.titleEnglish != work.title) {
                    Text(
                        text = work.titleEnglish,
                        style = MaterialTheme.typography.bodySmall,
                        color = TextMuted,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }

                Spacer(Modifier.height(8.dp))

                Row(verticalAlignment = Alignment.CenterVertically) {
                    if (work.hasScore) {
                        ScoreBadge(work.score)
                        Spacer(Modifier.width(8.dp))
                    }
                    Text(
                        text = listOfNotNull(
                            work.year.takeIf { it > 0 }?.toString(),
                            work.format.takeIf { it.isNotBlank() },
                            work.totalEpisodes.takeIf { it > 0 }?.let { "$it bölüm" },
                        ).joinToString(" · "),
                        style = MaterialTheme.typography.labelMedium,
                        color = TextSecondary,
                    )
                }
            }
        }
    }

    Row(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Button(onClick = onPlay, modifier = Modifier.weight(1f)) {
            Icon(Icons.Filled.PlayArrow, null, Modifier.size(18.dp))
            Spacer(Modifier.width(6.dp))
            Text(stringResource(R.string.detail_play))
        }

        FilledTonalIconButton(onClick = onFavorite) {
            Icon(
                if (isFavorite) Icons.Filled.Favorite else Icons.Outlined.FavoriteBorder,
                contentDescription = stringResource(
                    if (isFavorite) R.string.detail_favorite_remove else R.string.detail_favorite_add
                ),
                tint = if (isFavorite) MaterialTheme.colorScheme.primary else LocalContentColor.current,
            )
        }

        FilledTonalIconButton(onClick = onWatchlist) {
            Icon(
                if (inWatchlist) Icons.Filled.Bookmark else Icons.Outlined.BookmarkBorder,
                contentDescription = stringResource(
                    if (inWatchlist) R.string.detail_watchlist_remove else R.string.detail_watchlist_add
                ),
                tint = if (inWatchlist) MaterialTheme.colorScheme.primary else LocalContentColor.current,
            )
        }

        // The bell. Beside the heart rather than in a menu, because the moment
        // to ask for it is the moment somebody decides they care about a
        // series — which is the same moment they reach for the heart.
        FilledTonalIconButton(onClick = onFollow) {
            Icon(
                if (following) Icons.Filled.Notifications else Icons.Outlined.NotificationsNone,
                contentDescription = stringResource(
                    if (following) R.string.detail_follow_off else R.string.detail_follow_on
                ),
                tint = if (following) MaterialTheme.colorScheme.primary else LocalContentColor.current,
            )
        }
    }
}

@Composable
private fun Synopsis(text: String) {
    var expanded by remember { mutableStateOf(false) }

    Column(
        Modifier
            .fillMaxWidth()
            .padding(16.dp)
    ) {
        Text(
            text = text,
            style = MaterialTheme.typography.bodyMedium,
            color = TextSecondary,
            maxLines = if (expanded) Int.MAX_VALUE else 4,
            overflow = TextOverflow.Ellipsis,
        )
        TextButton(onClick = { expanded = !expanded }) {
            Text(if (expanded) "Daha az" else "Devamı")
        }
    }
}

@Composable
private fun MetaGrid(work: Work) {
    Column(Modifier.padding(horizontal = 16.dp)) {
        if (work.genres.isNotEmpty()) {
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(work.genres) { genre ->
                    AssistChip(onClick = {}, label = { Text(genre) })
                }
            }
            Spacer(Modifier.height(12.dp))
        }

        if (work.studio.isNotBlank()) {
            MetaRow(stringResource(R.string.detail_studio), work.studio)
        }
        if (work.season.isNotBlank() && work.year > 0) {
            MetaRow(stringResource(R.string.discover_season), "${work.season.replaceFirstChar { it.uppercase() }} ${work.year}")
        }
    }
}

@Composable
private fun MetaRow(label: String, value: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
        Text(label, style = MaterialTheme.typography.bodySmall, color = TextMuted, modifier = Modifier.width(90.dp))
        Text(value, style = MaterialTheme.typography.bodySmall)
    }
}

