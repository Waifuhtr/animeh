package com.animeh.app.ui.screens.shorts

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyGridScope
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.data.remote.dto.ShortDto
import com.animeh.app.data.remote.dto.ShortStatsDto
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.theme.AccentPrimary
import com.animeh.app.ui.theme.SurfaceVariantDark
import com.animeh.app.ui.theme.TextSecondary

/**
 * The pages around the feed: a hashtag, a sound, a creator, search and upload.
 *
 * The first three are one grid under a different heading, so they share
 * [ShortGridPage] and differ only in the header they put above it.
 */

@Composable
fun ShortTagScreen(
    onBack: () -> Unit,
    onOpenShort: (Long) -> Unit,
    viewModel: ShortTagViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    ShortGridPage(state = state, onBack = onBack, onMessageShown = viewModel::messageShown, onOpenShort = onOpenShort) {
        PageHeader(
            title = state.title,
            subtitle = stringResource(R.string.tok_tag_videos, state.subtitle.toIntOrNull() ?: 0),
            icon = Icons.Filled.Tag,
        )
    }
}

@Composable
fun ShortSoundScreen(
    onBack: () -> Unit,
    onOpenShort: (Long) -> Unit,
    viewModel: ShortSoundViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    ShortGridPage(state = state, onBack = onBack, onMessageShown = viewModel::messageShown, onOpenShort = onOpenShort) {
        PageHeader(
            title = state.title,
            subtitle = state.subtitle,
            cover = state.cover,
            icon = Icons.Filled.MusicNote,
        )
    }
}

@Composable
fun ShortCreatorScreen(
    onBack: () -> Unit,
    onOpenShort: (Long) -> Unit,
    viewModel: ShortCreatorViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    ShortGridPage(state = state, onBack = onBack, onMessageShown = viewModel::messageShown, onOpenShort = onOpenShort) {
        CreatorHeader(state = state, onFollow = viewModel::toggleFollow)
    }
}

/**
 * One grid of covers under a heading.
 *
 * The three pages above are the same screen with a different heading, so the
 * heading arrives as a composable and everything else is written once.
 */
@Composable
private fun ShortGridPage(
    state: ShortGridState,
    onBack: () -> Unit,
    onMessageShown: () -> Unit,
    onOpenShort: (Long) -> Unit,
    header: @Composable () -> Unit,
) {
    val snackbar = remember { SnackbarHostState() }

    LaunchedEffect(state.message) {
        state.message?.let {
            snackbar.showSnackbar(it)
            onMessageShown()
        }
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        topBar = {
            TopAppBar(
                title = { Text(state.title, maxLines = 1) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back))
                    }
                },
            )
        },
    ) { padding ->
        Box(Modifier.padding(padding)) {
            if (state.loading) {
                Box(Modifier.fillMaxSize(), Alignment.Center) { CircularProgressIndicator() }
                return@Box
            }

            LazyVerticalGrid(
                columns = GridCells.Fixed(3),
                horizontalArrangement = Arrangement.spacedBy(2.dp),
                verticalArrangement = Arrangement.spacedBy(2.dp),
                modifier = Modifier.fillMaxSize(),
            ) {
                fullWidth { header() }

                if (state.items.isEmpty()) {
                    fullWidth {
                        EmptyState(
                            message = stringResource(R.string.tok_empty),
                            modifier = Modifier.fillMaxWidth().padding(32.dp),
                        )
                    }
                }

                items(state.items, key = { it.id }) { short ->
                    ShortTile(short = short, onClick = { onOpenShort(short.id) })
                }
            }
        }
    }
}

/** A row that spans every column, for a heading or an empty state. */
private fun LazyGridScope.fullWidth(content: @Composable () -> Unit) {
    item(span = { GridItemSpan(maxLineSpan) }, contentType = "full") { content() }
}

@Composable
private fun PageHeader(
    title: String,
    subtitle: String,
    cover: String = "",
    icon: androidx.compose.ui.graphics.vector.ImageVector,
) {
    Row(
        Modifier.fillMaxWidth().padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (cover.isNotBlank()) {
            AsyncImage(
                model = cover,
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.size(64.dp).clip(RoundedCornerShape(12.dp)).background(SurfaceVariantDark),
            )
        } else {
            Box(
                Modifier.size(64.dp).clip(RoundedCornerShape(12.dp)).background(SurfaceVariantDark),
                Alignment.Center,
            ) {
                Icon(icon, null, tint = AccentPrimary, modifier = Modifier.size(30.dp))
            }
        }

        Spacer(Modifier.width(14.dp))

        Column {
            Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            if (subtitle.isNotBlank()) {
                Text(subtitle, style = MaterialTheme.typography.bodySmall, color = TextSecondary)
            }
        }
    }
}

@Composable
private fun CreatorHeader(state: ShortGridState, onFollow: () -> Unit) {
    Column(
        Modifier.fillMaxWidth().padding(16.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        AsyncImage(
            model = state.cover,
            contentDescription = state.title,
            contentScale = ContentScale.Crop,
            modifier = Modifier.size(88.dp).clip(CircleShape).background(SurfaceVariantDark),
        )

        Spacer(Modifier.height(10.dp))

        Text(state.title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
        Text(state.subtitle, style = MaterialTheme.typography.bodySmall, color = TextSecondary)

        Spacer(Modifier.height(14.dp))

        state.stats?.let { StatRow(it) }

        Spacer(Modifier.height(14.dp))

        // Nobody follows themselves, so the button is simply not there.
        if (!state.isSelf) {
            if (state.following) {
                OutlinedButton(onClick = onFollow) {
                    Text(stringResource(R.string.tok_unfollow))
                }
            } else {
                Button(onClick = onFollow) {
                    Text(stringResource(R.string.tok_follow))
                }
            }
        }
    }
}

@Composable
private fun StatRow(stats: ShortStatsDto) {
    Row(horizontalArrangement = Arrangement.spacedBy(28.dp)) {
        StatCell(stats.videos.toString(), stringResource(R.string.tok_creator_videos))
        StatCell(stats.followers.toString(), stringResource(R.string.tok_creator_followers))
        StatCell(stats.following.toString(), stringResource(R.string.tok_creator_following))
        StatCell(stats.likesReceived.toString(), stringResource(R.string.tok_creator_likes))
    }
}

@Composable
private fun StatCell(value: String, label: String) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Text(value, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
        Text(label, style = MaterialTheme.typography.labelSmall, color = TextSecondary)
    }
}

/** One cover in a grid, with the number of views it has. */
@Composable
private fun ShortTile(short: ShortDto, onClick: () -> Unit) {
    Box(
        Modifier
            .aspectRatio(0.62f)
            .background(SurfaceVariantDark)
            .clickable(onClick = onClick)
    ) {
        AsyncImage(
            model = short.coverUrl,
            contentDescription = short.description.take(60),
            contentScale = ContentScale.Crop,
            modifier = Modifier.fillMaxSize(),
        )

        Row(
            Modifier.align(Alignment.BottomStart).padding(6.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(
                Icons.Filled.PlayArrow,
                contentDescription = null,
                tint = Color.White,
                modifier = Modifier.size(14.dp),
            )
            Spacer(Modifier.width(3.dp))
            Text(
                short.viewCount.toString(),
                color = Color.White,
                style = MaterialTheme.typography.labelSmall,
            )
        }

        if (!short.published) {
            // An unpublished video is only ever visible to its own creator, so
            // it needs to say why it is not in the feed.
            Box(
                Modifier
                    .align(Alignment.TopEnd)
                    .padding(4.dp)
                    .background(Color.Black.copy(alpha = 0.6f), RoundedCornerShape(4.dp))
                    .padding(horizontal = 5.dp, vertical = 2.dp)
            ) {
                Icon(
                    Icons.Filled.VisibilityOff,
                    contentDescription = null,
                    tint = Color.White,
                    modifier = Modifier.size(12.dp),
                )
            }
        }
    }
}

/* ── Search ──────────────────────────────────────────────────────────── */

@Composable
fun ShortSearchScreen(
    onBack: () -> Unit,
    onOpenTag: (String) -> Unit,
    onOpenSound: (Long) -> Unit,
    onOpenCreator: (Long) -> Unit,
    onOpenShort: (Long) -> Unit,
    viewModel: ShortSearchViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }

    LaunchedEffect(state.message) {
        state.message?.let {
            snackbar.showSnackbar(it)
            viewModel.messageShown()
        }
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        topBar = {
            TopAppBar(
                title = {
                    OutlinedTextField(
                        value = state.query,
                        onValueChange = viewModel::setQuery,
                        placeholder = { Text(stringResource(R.string.tok_search_hint)) },
                        singleLine = true,
                        shape = RoundedCornerShape(22.dp),
                        modifier = Modifier.fillMaxWidth(),
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
        LazyColumn(Modifier.padding(padding).fillMaxSize()) {
            if (state.loading) {
                item {
                    Box(Modifier.fillMaxWidth().padding(32.dp), Alignment.Center) {
                        CircularProgressIndicator()
                    }
                }
            }

            // Before anything is typed the trending tags fill the screen, so
            // search opens on something to tap rather than on an empty box.
            if (!state.searched && state.trending.isNotEmpty()) {
                item { SectionLabel(stringResource(R.string.tok_trending)) }

                items(state.trending, key = { "trend-${it.key}" }) { tag ->
                    ListRow(
                        icon = Icons.Filled.Tag,
                        title = "#${tag.tag}",
                        subtitle = stringResource(R.string.tok_tag_videos, tag.count),
                        onClick = { onOpenTag(tag.tag) },
                    )
                }
            }

            if (state.results.creators.isNotEmpty()) {
                item { SectionLabel(stringResource(R.string.tok_search_creators)) }

                items(state.results.creators, key = { "user-${it.creator.id}" }) { row ->
                    ListRow(
                        avatar = row.creator.avatar,
                        title = row.creator.displayName.ifBlank { row.creator.username },
                        subtitle = "@${row.creator.username} · ${row.stats.videos}",
                        onClick = { onOpenCreator(row.creator.id) },
                    )
                }
            }

            if (state.results.tags.isNotEmpty()) {
                item { SectionLabel(stringResource(R.string.tok_search_tags)) }

                items(state.results.tags, key = { "tag-${it.key}" }) { tag ->
                    ListRow(
                        icon = Icons.Filled.Tag,
                        title = "#${tag.tag}",
                        subtitle = stringResource(R.string.tok_tag_videos, tag.count),
                        onClick = { onOpenTag(tag.tag) },
                    )
                }
            }

            if (state.results.sounds.isNotEmpty()) {
                item { SectionLabel(stringResource(R.string.tok_search_sounds)) }

                items(state.results.sounds, key = { "sound-${it.id}" }) { sound ->
                    ListRow(
                        avatar = sound.coverUrl,
                        icon = Icons.Filled.MusicNote,
                        title = sound.title,
                        subtitle = sound.author,
                        onClick = { onOpenSound(sound.id) },
                    )
                }
            }

            if (state.results.videos.isNotEmpty()) {
                item { SectionLabel(stringResource(R.string.tok_search_videos)) }

                items(state.results.videos, key = { "video-${it.id}" }) { short ->
                    ListRow(
                        avatar = short.coverUrl,
                        title = short.description.take(60).ifBlank { short.slug },
                        subtitle = "@${short.creator.username}",
                        onClick = { onOpenShort(short.id) },
                    )
                }
            }
        }
    }
}

@Composable
private fun SectionLabel(text: String) {
    Text(
        text,
        style = MaterialTheme.typography.labelLarge,
        color = TextSecondary,
        modifier = Modifier.padding(start = 16.dp, end = 16.dp, top = 14.dp, bottom = 6.dp),
    )
}

@Composable
private fun ListRow(
    title: String,
    subtitle: String,
    onClick: () -> Unit,
    avatar: String = "",
    icon: androidx.compose.ui.graphics.vector.ImageVector? = null,
) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier.size(44.dp).clip(RoundedCornerShape(10.dp)).background(SurfaceVariantDark),
            Alignment.Center,
        ) {
            if (avatar.isNotBlank()) {
                AsyncImage(
                    model = avatar,
                    contentDescription = null,
                    contentScale = ContentScale.Crop,
                    modifier = Modifier.fillMaxSize(),
                )
            } else if (icon != null) {
                Icon(icon, null, tint = AccentPrimary, modifier = Modifier.size(20.dp))
            }
        }

        Spacer(Modifier.width(12.dp))

        Column(Modifier.weight(1f)) {
            Text(title, style = MaterialTheme.typography.bodyLarge, maxLines = 1)
            Text(subtitle, style = MaterialTheme.typography.bodySmall, color = TextSecondary, maxLines = 1)
        }
    }
}

/* ── Uploading ───────────────────────────────────────────────────────── */

@Composable
fun ShortUploadScreen(
    onBack: () -> Unit,
    onUploaded: () -> Unit,
    viewModel: ShortUploadViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        uri?.let(viewModel::pick)
    }

    LaunchedEffect(state.message) {
        state.message?.let {
            snackbar.showSnackbar(it)
            viewModel.messageShown()
        }
    }

    LaunchedEffect(state.done) { if (state.done) onUploaded() }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.tok_upload)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back))
                    }
                },
            )
        },
    ) { padding ->
        Column(
            Modifier
                .padding(padding)
                .fillMaxSize()
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            OutlinedButton(
                onClick = { picker.launch("video/*") },
                enabled = !state.uploading,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Icon(Icons.Filled.VideoLibrary, null, Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.tok_upload_pick))
            }

            state.facts?.let { facts ->
                Text(
                    "${facts.filename} · ${facts.durationMs / 1000}s · ${facts.sizeBytes / 1024 / 1024} MB",
                    style = MaterialTheme.typography.bodySmall,
                    color = TextSecondary,
                )
            }

            if (state.tooLong) {
                Text(
                    stringResource(R.string.tok_upload_too_long),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.error,
                )
            }

            if (state.tooLarge) {
                Text(
                    stringResource(R.string.tok_upload_too_large),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.error,
                )
            }

            OutlinedTextField(
                value = state.description,
                onValueChange = viewModel::setDescription,
                label = { Text(stringResource(R.string.tok_upload_description)) },
                supportingText = { Text(stringResource(R.string.tok_upload_description_hint)) },
                minLines = 3,
                modifier = Modifier.fillMaxWidth(),
            )

            OutlinedTextField(
                value = state.soundTitle,
                onValueChange = viewModel::setSoundTitle,
                label = { Text(stringResource(R.string.tok_upload_sound)) },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )

            Row(verticalAlignment = Alignment.CenterVertically) {
                Switch(checked = state.adult, onCheckedChange = viewModel::setAdult)
                Spacer(Modifier.width(12.dp))
                Text(stringResource(R.string.tok_upload_adult))
            }

            if (state.uploading) {
                LinearProgressIndicator(
                    progress = { state.progress },
                    modifier = Modifier.fillMaxWidth(),
                )
            }

            Button(
                onClick = viewModel::send,
                enabled = state.canSend,
                modifier = Modifier.fillMaxWidth().height(50.dp),
            ) {
                Text(stringResource(R.string.tok_upload_send))
            }
        }
    }
}

/* ── The profile block ───────────────────────────────────────────────── */

/**
 * The AnimehTok numbers on the profile.
 *
 * Its own card beside the anime and manga ones. Nothing here is watch time and
 * nothing here is worth a point, which is exactly why it is a separate card
 * rather than three more rows in the existing one.
 */
@Composable
fun ShortsProfileCard(
    onOpenFeed: () -> Unit,
    onOpenMine: () -> Unit,
    viewModel: ShortMineViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    Card(
        Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(16.dp),
    ) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.MovieFilter, null, tint = AccentPrimary, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text(
                    stringResource(R.string.tok_stats_title),
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.weight(1f),
                )
                TextButton(onClick = onOpenFeed) { Text(stringResource(R.string.tok_for_you)) }
            }

            Row(horizontalArrangement = Arrangement.spacedBy(28.dp)) {
                StatCell(state.stats.videos.toString(), stringResource(R.string.tok_stats_videos))
                StatCell(state.stats.likesReceived.toString(), stringResource(R.string.tok_stats_likes))
                StatCell(state.stats.views.toString(), stringResource(R.string.tok_stats_views))
                StatCell(state.stats.followers.toString(), stringResource(R.string.tok_creator_followers))
            }

            if (state.mine.isNotEmpty()) {
                LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    items(state.mine.take(8), key = { it.id }) { short ->
                        AsyncImage(
                            model = short.coverUrl,
                            contentDescription = null,
                            contentScale = ContentScale.Crop,
                            modifier = Modifier
                                .size(width = 58.dp, height = 92.dp)
                                .clip(RoundedCornerShape(8.dp))
                                .background(SurfaceVariantDark)
                                .clickable(onClick = onOpenMine),
                        )
                    }
                }
            }
        }
    }
}

/**
 * The offer that appears when the Home tab is tapped while already on Home.
 *
 * A sheet rather than a sixth tab, because AnimehTok is a mode: somebody who
 * never wants it should not have it in front of them every time they open the
 * app, and somebody who does wants it one tap from where they already are.
 */
@Composable
fun ShortsModeSheet(onDismiss: () -> Unit, onEnter: () -> Unit) {
    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(
            Modifier.fillMaxWidth().padding(start = 20.dp, end = 20.dp, bottom = 28.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.MovieFilter, null, tint = AccentPrimary, modifier = Modifier.size(26.dp))
                Spacer(Modifier.width(10.dp))
                Text(
                    stringResource(R.string.tok_title),
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold,
                )
            }

            Text(
                stringResource(R.string.tok_enter_hint),
                style = MaterialTheme.typography.bodyMedium,
                color = TextSecondary,
            )

            Button(
                onClick = onEnter,
                modifier = Modifier.fillMaxWidth().height(50.dp),
            ) {
                Text(stringResource(R.string.tok_enter))
            }

            TextButton(onClick = onDismiss, modifier = Modifier.fillMaxWidth()) {
                Text(stringResource(R.string.tok_stay))
            }
        }
    }
}
