package com.animeh.app.ui.screens.library

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Download
import androidx.compose.material.icons.outlined.Favorite
import androidx.compose.material.icons.outlined.History
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.R
import com.animeh.app.core.UiState
import coil.compose.AsyncImage
import com.animeh.app.domain.ContinueItem
import com.animeh.app.domain.Work
import com.animeh.app.ui.components.*

/**
 * Favourites, watchlist, history and downloads.
 *
 * Downloads is present and honest: the tab exists because §21 lists it, and it
 * says the feature is not built rather than showing an empty list that looks
 * broken. §26 explicitly asks not to build video caching before the player is
 * stable.
 */
@Composable
fun LibraryScreen(
    signedIn: Boolean,
    onWorkClick: (Work) -> Unit,
    onEpisodeClick: (Long) -> Unit,
    onSignIn: () -> Unit,
    viewModel: LibraryViewModel = hiltViewModel(),
) {
    if (!signedIn) {
        EmptyState(
            message = stringResource(R.string.auth_login_required),
            icon = Icons.Outlined.Favorite,
            actionLabel = stringResource(R.string.auth_login),
            onAction = onSignIn,
            modifier = Modifier.fillMaxSize(),
        )
        return
    }

    val state by viewModel.state.collectAsStateWithLifecycle()
    val tabs = LibraryTab.entries

    Column(Modifier.fillMaxSize().statusBarsPadding()) {
        TabRow(selectedTabIndex = state.tab.ordinal) {
            tabs.forEach { tab ->
                Tab(
                    selected = state.tab == tab,
                    onClick = { viewModel.selectTab(tab) },
                    text = { Text(stringResource(tab.labelRes)) },
                )
            }
        }

        when (state.tab) {
            LibraryTab.DOWNLOADS -> EmptyState(
                message = stringResource(R.string.library_downloads_soon),
                icon = Icons.Outlined.Download,
                modifier = Modifier.fillMaxSize(),
            )

            LibraryTab.HISTORY -> when (val history = state.history) {
                is UiState.Loading -> LoadingList()
                is UiState.Error -> ErrorState(history.error, onRetry = viewModel::reload)
                is UiState.Empty -> EmptyState(
                    stringResource(R.string.library_empty_history),
                    Icons.Outlined.History,
                )
                is UiState.Success -> LazyColumn(contentPadding = PaddingValues(vertical = 8.dp)) {
                    items(history.data, key = { it.episodeId }) { item ->
                        ContinueRow(item = item, onClick = { onEpisodeClick(item.episodeId) })
                    }
                }
            }

            else -> when (val works = state.works) {
                is UiState.Loading -> LoadingGrid()
                is UiState.Error -> ErrorState(works.error, onRetry = viewModel::reload)
                is UiState.Empty -> EmptyState(
                    stringResource(
                        if (state.tab == LibraryTab.FAVORITES) R.string.library_empty_favorites
                        else R.string.library_empty_watchlist
                    ),
                    Icons.Outlined.Favorite,
                )
                is UiState.Success -> LazyVerticalGrid(
                    columns = GridCells.Adaptive(120.dp),
                    contentPadding = PaddingValues(16.dp),
                    horizontalArrangement = Arrangement.spacedBy(12.dp),
                    verticalArrangement = Arrangement.spacedBy(16.dp),
                ) {
                    items(works.data, key = { it.id }) { work ->
                        WorkCard(work = work, onClick = { onWorkClick(work) }, width = 120.dp)
                    }
                }
            }
        }
    }
}

@Composable
private fun ContinueRow(item: ContinueItem, onClick: () -> Unit) {
    ListItem(
        headlineContent = { Text(item.workTitle) },
        supportingContent = {
            Column {
                Text("${item.seasonNumber}. Sezon · ${item.episodeNumber}. Bölüm")
                Spacer(Modifier.height(6.dp))
                LinearProgressIndicator(
                    progress = { item.progress.fraction },
                    modifier = Modifier.fillMaxWidth().height(3.dp),
                )
            }
        },
        leadingContent = {
            AsyncImage(
                model = item.thumbnailUrl,
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier
                    .width(88.dp)
                    .height(50.dp)
                    .clip(MaterialTheme.shapes.small),
            )
        },
        modifier = Modifier.clickable(onClick = onClick),
    )
}

@Composable
private fun LoadingGrid() {
    LazyVerticalGrid(
        columns = GridCells.Adaptive(120.dp),
        contentPadding = PaddingValues(16.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        items(6) {
            Column {
                Shimmer(Modifier.fillMaxWidth().height(PosterHeight))
                Spacer(Modifier.height(8.dp))
                Shimmer(Modifier.fillMaxWidth().height(12.dp))
            }
        }
    }
}

@Composable
private fun LoadingList() {
    LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        items(6) {
            Shimmer(Modifier.fillMaxWidth().height(64.dp))
        }
    }
}
