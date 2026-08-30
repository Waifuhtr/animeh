package com.animeh.app.ui.screens.discover

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.grid.rememberLazyGridState
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.R
import com.animeh.app.core.UiState
import com.animeh.app.domain.Work
import com.animeh.app.ui.components.*

@Composable
fun DiscoverScreen(
    onWorkClick: (Work) -> Unit,
    viewModel: DiscoverViewModel = hiltViewModel(),
) {
    val filters by viewModel.filters.collectAsStateWithLifecycle()
    val results by viewModel.results.collectAsStateWithLifecycle()
    val genres by viewModel.genres.collectAsStateWithLifecycle()

    val gridState = rememberLazyGridState()

    // Prefetch one screen ahead rather than at the very bottom, so the next
    // page is usually already there when the user reaches it.
    val shouldLoadMore by remember {
        derivedStateOf {
            val last = gridState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0
            val total = gridState.layoutInfo.totalItemsCount
            total > 0 && last >= total - PREFETCH_DISTANCE
        }
    }

    LaunchedEffect(shouldLoadMore) {
        if (shouldLoadMore) viewModel.loadMore()
    }

    Column(Modifier.fillMaxSize().statusBarsPadding()) {
        OutlinedTextField(
            value = filters.query,
            onValueChange = viewModel::setQuery,
            placeholder = { Text(stringResource(R.string.discover_hint)) },
            leadingIcon = { Icon(Icons.Filled.Search, null) },
            trailingIcon = {
                if (filters.isActive) {
                    IconButton(onClick = viewModel::clearFilters) {
                        Icon(Icons.Filled.Close, stringResource(R.string.close))
                    }
                }
            },
            singleLine = true,
            keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(imeAction = ImeAction.Search),
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 16.dp, vertical = 8.dp),
        )

        if (genres.isNotEmpty()) {
            LazyRow(
                contentPadding = PaddingValues(horizontal = 16.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
                modifier = Modifier.padding(vertical = 4.dp),
            ) {
                items(genres, key = { it.name }) { genre ->
                    FilterChip(
                        selected = filters.genre == genre.name,
                        onClick = { viewModel.setGenre(genre.name) },
                        label = { Text(genre.name) },
                    )
                }
            }
        }

        LazyRow(
            contentPadding = PaddingValues(horizontal = 16.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            modifier = Modifier.padding(vertical = 4.dp),
        ) {
            items(STATUSES) { (value, label) ->
                FilterChip(
                    selected = filters.status == value,
                    onClick = { viewModel.setStatus(value) },
                    label = { Text(label) },
                )
            }
            items(SORTS) { (value, label) ->
                FilterChip(
                    selected = filters.sort == value,
                    onClick = { viewModel.setSort(value) },
                    label = { Text(label) },
                )
            }
        }

        when (val current = results) {
            is UiState.Loading -> LazyVerticalGrid(
                columns = GridCells.Adaptive(120.dp),
                contentPadding = PaddingValues(16.dp),
                horizontalArrangement = Arrangement.spacedBy(12.dp),
                verticalArrangement = Arrangement.spacedBy(16.dp),
            ) {
                items(9) {
                    Column {
                        Shimmer(Modifier.fillMaxWidth().height(PosterHeight))
                        Spacer(Modifier.height(8.dp))
                        Shimmer(Modifier.fillMaxWidth().height(12.dp))
                    }
                }
            }

            is UiState.Error -> ErrorState(
                error = current.error,
                onRetry = { viewModel.setQuery(filters.query) },
                modifier = Modifier.fillMaxSize(),
            )

            is UiState.Empty -> EmptyState(
                message = stringResource(
                    if (filters.isActive) R.string.discover_no_results else R.string.discover_start
                ),
                icon = Icons.Filled.Search,
                modifier = Modifier.fillMaxSize(),
            )

            is UiState.Success -> LazyVerticalGrid(
                columns = GridCells.Adaptive(120.dp),
                state = gridState,
                contentPadding = PaddingValues(16.dp),
                horizontalArrangement = Arrangement.spacedBy(12.dp),
                verticalArrangement = Arrangement.spacedBy(16.dp),
            ) {
                items(current.data, key = { it.id }) { work ->
                    WorkCard(
                        work = work,
                        onClick = { onWorkClick(work) },
                        width = 120.dp,
                    )
                }
            }
        }
    }
}

private const val PREFETCH_DISTANCE = 6

private val STATUSES = listOf(
    "airing" to "Yayında",
    "finished" to "Tamamlandı",
    "upcoming" to "Yakında",
)

private val SORTS = listOf(
    "score" to "Puan",
    "popular" to "Popüler",
    "year" to "Yıl",
)
