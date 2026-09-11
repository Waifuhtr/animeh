package com.animeh.app.ui.screens.discover

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyGridScope
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.grid.rememberLazyGridState
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AutoAwesome
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material.icons.filled.GridView
import androidx.compose.material.icons.filled.MyLocation
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.SortByAlpha
import androidx.compose.material.icons.filled.Tune
import androidx.compose.material.icons.filled.ViewList
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FilterChipDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.R
import com.animeh.app.core.UiState
import com.animeh.app.domain.KIND_ANIME
import com.animeh.app.domain.KIND_MANGA
import com.animeh.app.domain.Work
import com.animeh.app.domain.formatsFor
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.components.ErrorState
import com.animeh.app.ui.components.PosterHeight
import com.animeh.app.ui.components.Shimmer
import com.animeh.app.ui.components.WorkCard
import com.animeh.app.ui.components.WorkRow
import com.animeh.app.ui.theme.AccentBright
import com.animeh.app.ui.theme.AccentPrimary
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

/**
 * Browsing the catalogue.
 *
 * Opens on it rather than on an empty box waiting to be typed into: this tab
 * is where you go to look at what there is, and searching is one of the things
 * you can do once you are here.
 *
 * The five narrow-it-down rows live in a card that starts closed, because most
 * visits use none of them and a screen that leads with five dropdowns is a
 * form. The button that opens it carries a count, so a filter left on from
 * last time is visible without opening anything.
 */
@Composable
fun DiscoverScreen(
    onWorkClick: (Work) -> Unit,
    /**
     * The shelf a "Tümü" was tapped on, if this was opened by one.
     *
     * Applied once and then cleared, so coming back to the tab by hand keeps
     * whatever was last being browsed rather than snapping to manga again.
     */
    startKind: String? = null,
    onStartKindHandled: () -> Unit = {},
    /** A genre tapped on a work's page: browse it, on that work's shelf. */
    startGenre: String? = null,
    onStartGenreHandled: () -> Unit = {},
    viewModel: DiscoverViewModel = hiltViewModel(),
) {
    val filters by viewModel.filters.collectAsStateWithLifecycle()
    val results by viewModel.results.collectAsStateWithLifecycle()
    val genres by viewModel.genres.collectAsStateWithLifecycle()
    val total by viewModel.total.collectAsStateWithLifecycle()

    LaunchedEffect(startGenre, startKind) {
        when {
            startGenre != null -> {
                viewModel.browseGenre(startGenre, startKind ?: filters.kind)
                onStartGenreHandled()
                onStartKindHandled()
            }

            startKind != null -> {
                viewModel.setKind(startKind)
                onStartKindHandled()
            }
        }
    }

    var showFilters by remember { mutableStateOf(false) }
    var asList by remember { mutableStateOf(false) }

    val gridState = rememberLazyGridState()

    // Prefetch one screen ahead rather than at the very bottom, so the next
    // page is usually already there when the user reaches it.
    val shouldLoadMore by remember {
        derivedStateOf {
            val last = gridState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0
            val count = gridState.layoutInfo.totalItemsCount
            count > 0 && last >= count - PREFETCH_DISTANCE
        }
    }

    LaunchedEffect(shouldLoadMore) {
        if (shouldLoadMore) viewModel.loadMore()
    }

    val manga = filters.kind == KIND_MANGA
    val columns = if (asList) 1 else 3

    LazyVerticalGrid(
        columns = GridCells.Fixed(columns),
        state = gridState,
        contentPadding = PaddingValues(start = 16.dp, end = 16.dp, bottom = 24.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
        modifier = Modifier.fillMaxSize(),
    ) {
        header { DiscoverHeader() }

        header {
            SearchRow(
                query = filters.query,
                activeCount = filters.activeCount,
                open = showFilters,
                onQuery = viewModel::setQuery,
                onToggleFilters = { showFilters = !showFilters },
            )
        }

        header {
            KindTabs(kind = filters.kind, onKind = viewModel::setKind)
        }

        header {
            FormatChips(
                // "Tümü" first, then the kind's own formats — the same list
                // the admin form offers, so a chip can never name a format
                // nothing was ever saved as.
                formats = listOf("" to "Tümü") + formatsFor(filters.kind),
                selected = filters.format,
                onSelect = viewModel::setFormat,
            )
        }

        header {
            AnimatedVisibility(
                visible = showFilters,
                enter = fadeIn() + expandVertically(),
                exit = fadeOut() + shrinkVertically(),
            ) {
                FilterCard(
                    filters = filters,
                    genres = genres.map { it.name },
                    manga = manga,
                    onGenre = viewModel::setGenre,
                    onYear = viewModel::setYear,
                    onSeason = viewModel::setSeason,
                    onStatus = viewModel::setStatus,
                    onSort = viewModel::setSort,
                    onReset = viewModel::clearFilters,
                )
            }
        }

        header {
            ResultsBar(
                total = total,
                asList = asList,
                onLayout = { asList = it },
            )
        }

        when (val current = results) {
            is UiState.Loading -> items(9) {
                Column {
                    Shimmer(Modifier.fillMaxWidth().height(PosterHeight))
                    Spacer(Modifier.height(8.dp))
                    Shimmer(Modifier.fillMaxWidth().height(12.dp))
                }
            }

            is UiState.Error -> header {
                ErrorState(
                    error = current.error,
                    onRetry = { viewModel.setQuery(filters.query) },
                    modifier = Modifier.fillMaxWidth().padding(vertical = 48.dp),
                )
            }

            is UiState.Empty -> header {
                EmptyState(
                    message = stringResource(
                        if (filters.isActive) R.string.discover_no_results else R.string.discover_start
                    ),
                    icon = Icons.Filled.Search,
                    modifier = Modifier.fillMaxWidth().padding(vertical = 48.dp),
                )
            }

            is UiState.Success -> items(current.data, key = { it.id }) { work ->
                if (asList) {
                    WorkRow(work = work, onClick = { onWorkClick(work) })
                } else {
                    // The home screen's cover, on purpose: one card shape for
                    // the whole app means a poster is recognisable wherever it
                    // turns up.
                    WorkCard(
                        work = work,
                        onClick = { onWorkClick(work) },
                        width = GRID_POSTER_WIDTH,
                    )
                }
            }
        }
    }
}

/**
 * A row that spans the grid: everything above the results.
 *
 * `maxLineSpan` rather than a column count passed in, so the header stays
 * full-width whatever the grid is currently doing — which it is, since the
 * list view drops it to one column.
 */
private fun LazyGridScope.header(content: @Composable () -> Unit) {
    item(span = { GridItemSpan(maxLineSpan) }, contentType = "header") { content() }
}

@Composable
private fun DiscoverHeader() {
    Box(
        Modifier
            .fillMaxWidth()
            .padding(top = 0.dp)
            .statusBarsPadding(),
    ) {
        Column(Modifier.padding(vertical = 18.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(
                    Icons.Filled.AutoAwesome,
                    null,
                    tint = AccentBright,
                    modifier = Modifier.size(28.dp),
                )
                Spacer(Modifier.width(10.dp))
                Text(
                    stringResource(R.string.nav_discover),
                    style = MaterialTheme.typography.displaySmall,
                    fontWeight = FontWeight.Bold,
                )
            }

            Spacer(Modifier.height(6.dp))

            Text(
                stringResource(R.string.discover_subtitle),
                style = MaterialTheme.typography.bodyMedium,
                color = TextSecondary,
            )
        }
    }
}

@Composable
private fun SearchRow(
    query: String,
    activeCount: Int,
    open: Boolean,
    onQuery: (String) -> Unit,
    onToggleFilters: () -> Unit,
) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        OutlinedTextField(
            value = query,
            onValueChange = onQuery,
            placeholder = { Text(stringResource(R.string.discover_hint)) },
            leadingIcon = { Icon(Icons.Filled.Search, null, tint = TextMuted) },
            trailingIcon = {
                if (query.isNotBlank()) {
                    IconButton(onClick = { onQuery("") }) {
                        Icon(Icons.Filled.Close, stringResource(R.string.close))
                    }
                }
            },
            singleLine = true,
            shape = RoundedCornerShape(28.dp),
            colors = TextFieldDefaults.colors(
                focusedContainerColor = SurfaceCard,
                unfocusedContainerColor = SurfaceCard,
            ),
            keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(imeAction = ImeAction.Search),
            modifier = Modifier.weight(1f),
        )

        Spacer(Modifier.width(10.dp))

        // The count rides on the button so a filter left on from last time is
        // visible without opening the card to look for it.
        BadgedBox(
            badge = { if (activeCount > 0) Badge { Text(activeCount.toString()) } },
        ) {
            Surface(
                shape = RoundedCornerShape(24.dp),
                color = if (open) AccentPrimary else SurfaceCard,
                modifier = Modifier.size(52.dp).clickable(onClick = onToggleFilters),
            ) {
                Box(contentAlignment = Alignment.Center) {
                    Icon(
                        Icons.Filled.Tune,
                        stringResource(R.string.discover_filters),
                        tint = if (open) Color.White else AccentBright,
                    )
                }
            }
        }
    }
}

@Composable
private fun KindTabs(kind: String, onKind: (String) -> Unit) {
    TabRow(
        selectedTabIndex = if (kind == KIND_MANGA) 1 else 0,
        containerColor = Color.Transparent,
        divider = { HorizontalDivider(color = Color.White.copy(alpha = 0.06f)) },
    ) {
        Tab(
            selected = kind == KIND_ANIME,
            onClick = { onKind(KIND_ANIME) },
            text = { Text(stringResource(R.string.nav_home_anime)) },
        )
        Tab(
            selected = kind == KIND_MANGA,
            onClick = { onKind(KIND_MANGA) },
            text = { Text(stringResource(R.string.manga)) },
        )
    }
}

@Composable
private fun FormatChips(
    formats: List<Pair<String, String>>,
    selected: String,
    onSelect: (String) -> Unit,
) {
    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items(formats, key = { it.first }) { (value, label) ->
            FilterChip(
                selected = selected == value || (value.isEmpty() && selected.isEmpty()),
                onClick = { onSelect(value) },
                label = { Text(label) },
                shape = RoundedCornerShape(20.dp),
                colors = FilterChipDefaults.filterChipColors(
                    selectedContainerColor = AccentPrimary,
                    selectedLabelColor = Color.White,
                ),
            )
        }
    }
}

@Composable
private fun FilterCard(
    filters: DiscoverFilters,
    genres: List<String>,
    manga: Boolean,
    onGenre: (String) -> Unit,
    onYear: (Int) -> Unit,
    onSeason: (String) -> Unit,
    onStatus: (String) -> Unit,
    onSort: (String) -> Unit,
    onReset: () -> Unit,
) {
    val all = stringResource(R.string.filter_all)

    Card(
        Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = SurfaceCard),
        shape = RoundedCornerShape(18.dp),
    ) {
        Column(Modifier.padding(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.Tune, null, tint = AccentBright, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text(
                    stringResource(R.string.discover_filters),
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.weight(1f),
                )
                TextButton(onClick = onReset) {
                    Icon(Icons.Filled.Refresh, null, Modifier.size(16.dp))
                    Spacer(Modifier.width(4.dp))
                    Text(stringResource(R.string.discover_reset))
                }
            }

            Spacer(Modifier.height(6.dp))

            FilterDropdown(
                icon = Icons.Filled.GridView,
                label = stringResource(R.string.discover_genre),
                value = filters.genre.ifBlank { all },
                options = listOf("" to all) + genres.map { it to it },
                onSelect = { onGenre(it.ifBlank { filters.genre }) },
            )

            FilterDropdown(
                icon = Icons.Filled.CalendarMonth,
                label = stringResource(R.string.discover_year),
                value = filters.year.takeIf { it > 0 }?.toString() ?: all,
                options = listOf("" to all) + YEARS.map { it.toString() to it.toString() },
                onSelect = { onYear(it.toIntOrNull() ?: 0) },
            )

            // A manga has no broadcast season, so the row is simply not there
            // rather than being there and always saying "Tümü".
            if (!manga) {
                FilterDropdown(
                    icon = Icons.Filled.CalendarMonth,
                    label = stringResource(R.string.discover_season),
                    value = SEASONS.firstOrNull { it.first == filters.season }?.second ?: all,
                    options = listOf("" to all) + SEASONS,
                    onSelect = onSeason,
                )
            }

            FilterDropdown(
                icon = Icons.Filled.MyLocation,
                label = stringResource(R.string.discover_status),
                value = STATUSES.firstOrNull { it.first == filters.status }?.second ?: all,
                options = listOf("" to all) + STATUSES,
                onSelect = onStatus,
            )

            FilterDropdown(
                icon = Icons.Filled.SortByAlpha,
                label = stringResource(R.string.discover_sort),
                value = SORTS.firstOrNull { it.first == filters.sort }?.second.orEmpty(),
                options = SORTS,
                onSelect = onSort,
                last = true,
            )
        }
    }
}

/** One row of the filter card: a label on the left, the choice on the right. */
@Composable
private fun FilterDropdown(
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    label: String,
    value: String,
    options: List<Pair<String, String>>,
    onSelect: (String) -> Unit,
    last: Boolean = false,
) {
    var open by remember { mutableStateOf(false) }

    Box {
        Row(
            Modifier
                .fillMaxWidth()
                .clip(RoundedCornerShape(12.dp))
                .clickable { open = true }
                .padding(vertical = 12.dp, horizontal = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(icon, null, tint = AccentBright, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(10.dp))
            Text(label, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
            Text(
                value,
                style = MaterialTheme.typography.bodyMedium,
                color = TextSecondary,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.widthIn(max = 140.dp),
            )
            Icon(Icons.Filled.ExpandMore, null, tint = TextMuted, modifier = Modifier.size(20.dp))
        }

        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            options.forEach { (optionValue, optionLabel) ->
                DropdownMenuItem(
                    text = { Text(optionLabel) },
                    onClick = {
                        onSelect(optionValue)
                        open = false
                    },
                )
            }
        }
    }

    if (!last) {
        HorizontalDivider(color = Color.White.copy(alpha = 0.05f))
    }
}

@Composable
private fun ResultsBar(total: Int, asList: Boolean, onLayout: (Boolean) -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(top = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Filled.AutoAwesome, null, tint = AccentBright, modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(8.dp))
        Text(
            stringResource(R.string.discover_results),
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.SemiBold,
        )
        Spacer(Modifier.width(6.dp))
        Text(
            "(${formatCount(total)})",
            style = MaterialTheme.typography.titleMedium,
            color = TextMuted,
            modifier = Modifier.weight(1f),
        )

        Surface(
            shape = RoundedCornerShape(14.dp),
            color = SurfaceCard,
        ) {
            Row(Modifier.padding(3.dp)) {
                LayoutToggle(Icons.Filled.GridView, !asList) { onLayout(false) }
                LayoutToggle(Icons.Filled.ViewList, asList) { onLayout(true) }
            }
        }
    }
}

@Composable
private fun LayoutToggle(
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    selected: Boolean,
    onClick: () -> Unit,
) {
    Surface(
        shape = RoundedCornerShape(11.dp),
        color = if (selected) AccentPrimary else Color.Transparent,
        modifier = Modifier.size(38.dp).clickable(onClick = onClick),
    ) {
        Box(contentAlignment = Alignment.Center) {
            Icon(
                icon,
                null,
                tint = if (selected) Color.White else TextMuted,
                modifier = Modifier.size(20.dp),
            )
        }
    }
}

/** 12458 → "12.458", the way a number is written in Turkish. */
private fun formatCount(value: Int): String =
    value.toString().reversed().chunked(3).joinToString(".").reversed()

private const val PREFETCH_DISTANCE = 6

/** Three across on a phone, which is what the cover shape wants. */
private val GRID_POSTER_WIDTH = 108.dp

private val SEASONS = listOf(
    "winter" to "Kış",
    "spring" to "İlkbahar",
    "summer" to "Yaz",
    "fall" to "Sonbahar",
)

private val STATUSES = listOf(
    "airing" to "Yayında",
    "finished" to "Tamamlandı",
    "upcoming" to "Yakında",
)

private val SORTS = listOf(
    "recent" to "En Yeni",
    "popular" to "Popüler",
    "score" to "Puan",
    "year" to "Yıl",
    "title" to "İsim",
)

/** Newest first, back far enough to cover what a catalogue holds. */
private val YEARS = (java.time.Year.now().value downTo 1960).toList()
