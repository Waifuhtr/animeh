package com.animeh.app.ui.screens.admin

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.core.UiState
import com.animeh.app.data.remote.dto.AdminEpisodeRequest
import com.animeh.app.data.remote.dto.AdminWorkRequest
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.components.ErrorState
import com.animeh.app.ui.navigation.Routes
import com.animeh.app.ui.theme.*

/**
 * The admin panel, inside the app.
 *
 * §22's menu, as screens. Everything here talks to the WordPress API, and the
 * server checks the capability on every call — so this is a convenient UI over
 * an authorised API, not a privileged client.
 */

@Composable
fun AdminDashboardScreen(
    isAdmin: Boolean,
    onSection: (String) -> Unit,
    viewModel: AdminDashboardViewModel = hiltViewModel(),
) {
    if (!isAdmin) {
        EmptyState(
            message = stringResource(R.string.admin_no_permission),
            icon = Icons.Filled.Lock,
            modifier = Modifier.fillMaxSize(),
        )
        return
    }

    val state by viewModel.state.collectAsStateWithLifecycle()
    val storageTest by viewModel.storageTest.collectAsStateWithLifecycle()

    LazyColumn(
        Modifier.fillMaxSize().statusBarsPadding(),
        contentPadding = PaddingValues(bottom = 32.dp),
    ) {
        item {
            Text(
                stringResource(R.string.admin_dashboard),
                style = MaterialTheme.typography.headlineMedium,
                modifier = Modifier.padding(16.dp),
            )
        }

        when (val current = state) {
            is UiState.Loading -> item {
                Box(Modifier.fillMaxWidth().padding(32.dp), Alignment.Center) {
                    CircularProgressIndicator()
                }
            }

            is UiState.Error -> item { ErrorState(current.error, onRetry = viewModel::load) }

            is UiState.Success -> {
                val counts = current.data.counts

                item {
                    Row(
                        Modifier.fillMaxWidth().padding(horizontal = 16.dp),
                        horizontalArrangement = Arrangement.spacedBy(12.dp),
                    ) {
                        StatTile("${counts.worksPublished}/${counts.works}", "Anime", Modifier.weight(1f))
                        StatTile("${counts.episodesPublished}/${counts.episodes}", "Bölüm", Modifier.weight(1f))
                        StatTile("${counts.users}", "Kullanıcı", Modifier.weight(1f))
                    }
                }

                item {
                    // The same connection check the WordPress panel has: an
                    // operator debugging playback should not have to leave the
                    // app to find out whether storage answers.
                    ListItem(
                        headlineContent = { Text("Depolama") },
                        supportingContent = {
                            Text(
                                storageTest ?: if (current.data.storage.configured) {
                                    "${current.data.storage.bucket} · yapılandırıldı"
                                } else {
                                    "yapılandırılmadı"
                                }
                            )
                        },
                        trailingContent = {
                            TextButton(onClick = viewModel::testStorage) { Text("Sına") }
                        },
                    )
                }

                item {
                    ListItem(
                        headlineContent = { Text(stringResource(R.string.admin_tenrai)) },
                        supportingContent = {
                            Text(
                                if (current.data.tenrai.hasKey) "${current.data.tenrai.base} · anahtar var"
                                else current.data.tenrai.base.ifBlank { "yapılandırılmadı" }
                            )
                        },
                    )
                }

                item {
                    ListItem(
                        headlineContent = { Text("TMDB") },
                        supportingContent = {
                            Text(
                                if (current.data.tmdb.hasKey) {
                                    "${current.data.tmdb.language} · anahtar var"
                                } else {
                                    stringResource(R.string.admin_tmdb_no_key)
                                }
                            )
                        },
                    )
                }

                if (current.data.reports > 0) {
                    item {
                        ListItem(
                            headlineContent = { Text(stringResource(R.string.admin_reports)) },
                            supportingContent = {
                                Text(stringResource(R.string.admin_reports_open, current.data.reports))
                            },
                            leadingContent = { Icon(Icons.Filled.Flag, null, tint = StatusWarning) },
                            trailingContent = { Icon(Icons.Filled.ChevronRight, null) },
                            modifier = Modifier.clickable { onSection(Routes.ADMIN_REPORTS) },
                        )
                    }
                }

                if (current.data.errors.isNotEmpty()) {
                    item {
                        Text(
                            "Son 7 gün · hatalar",
                            style = MaterialTheme.typography.titleSmall,
                            color = MaterialTheme.colorScheme.error,
                            modifier = Modifier.padding(16.dp),
                        )
                    }
                    items(current.data.errors) { error ->
                        ListItem(
                            headlineContent = { Text(error.code) },
                            trailingContent = { Text("${error.count}") },
                        )
                    }
                }
            }

            is UiState.Empty -> Unit
        }

        item { HorizontalDivider(Modifier.padding(vertical = 8.dp)) }

        items(SECTIONS) { (route, labelRes, icon) ->
            ListItem(
                headlineContent = { Text(stringResource(labelRes)) },
                leadingContent = { Icon(icon, null) },
                trailingContent = { Icon(Icons.Filled.ChevronRight, null) },
                modifier = Modifier.clickable { onSection(route) },
            )
        }
    }
}

@Composable
fun AdminWorksScreen(
    onBack: () -> Unit,
    onEdit: (Long) -> Unit,
    onEpisodes: (Long) -> Unit,
    onNew: () -> Unit,
    onImport: () -> Unit,
    viewModel: AdminWorksViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val query by viewModel.query.collectAsStateWithLifecycle()

    AdminScaffold(
        title = stringResource(R.string.admin_anime),
        onBack = onBack,
        actions = {
            IconButton(onClick = onImport) {
                Icon(Icons.Filled.CloudDownload, stringResource(R.string.admin_tenrai_import))
            }
            IconButton(onClick = onNew) {
                Icon(Icons.Filled.Add, stringResource(R.string.admin_new_anime))
            }
        },
    ) { padding ->
        Column(Modifier.padding(padding)) {
            OutlinedTextField(
                value = query,
                onValueChange = viewModel::setQuery,
                placeholder = { Text(stringResource(R.string.search)) },
                leadingIcon = { Icon(Icons.Filled.Search, null) },
                singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(16.dp),
            )

            when (val current = state) {
                is UiState.Loading -> Box(Modifier.fillMaxSize(), Alignment.Center) {
                    CircularProgressIndicator()
                }
                is UiState.Error -> ErrorState(current.error, onRetry = viewModel::load)
                is UiState.Empty -> EmptyState("Henüz anime eklenmemiş.", Icons.Filled.MovieFilter)
                is UiState.Success -> LazyColumn {
                    items(current.data, key = { it.id }) { work ->
                        ListItem(
                            headlineContent = { Text(work.displayTitle, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                            supportingContent = {
                                Text(
                                    listOfNotNull(
                                        if (work.published) stringResource(R.string.admin_published)
                                        else stringResource(R.string.admin_draft),
                                        work.year.takeIf { it > 0 }?.toString(),
                                        "${work.totalEpisodes} bölüm".takeIf { work.totalEpisodes > 0 },
                                    ).joinToString(" · ")
                                )
                            },
                            leadingContent = {
                                AsyncImage(
                                    model = work.posterUrl,
                                    contentDescription = null,
                                    contentScale = ContentScale.Crop,
                                    modifier = Modifier
                                        .width(44.dp)
                                        .height(62.dp)
                                        .clip(MaterialTheme.shapes.extraSmall)
                                        .background(SurfaceCard),
                                )
                            },
                            trailingContent = {
                                Row {
                                    IconButton(onClick = { onEpisodes(work.id) }) {
                                        Icon(Icons.Filled.PlaylistPlay, stringResource(R.string.admin_episodes))
                                    }
                                    IconButton(onClick = { onEdit(work.id) }) {
                                        Icon(Icons.Filled.Edit, null)
                                    }
                                }
                            },
                        )
                    }
                }
            }
        }
    }
}

@Composable
fun AdminWorkEditScreen(
    workId: Long,
    onBack: () -> Unit,
    viewModel: AdminWorkEditViewModel = hiltViewModel(),
) {
    val form by viewModel.form.collectAsStateWithLifecycle()
    val saving by viewModel.saving.collectAsStateWithLifecycle()
    val error by viewModel.error.collectAsStateWithLifecycle()
    val saved by viewModel.saved.collectAsStateWithLifecycle()
    val artwork by viewModel.artwork.collectAsStateWithLifecycle()
    val fetching by viewModel.fetching.collectAsStateWithLifecycle()

    LaunchedEffect(saved) { if (saved) onBack() }

    val snackbar = remember { SnackbarHostState() }

    LaunchedEffect(artwork) {
        artwork?.let {
            snackbar.showSnackbar(it)
            viewModel.dismissArtwork()
        }
    }

    val artworkDone = stringResource(R.string.admin_tmdb_done, 0)
    val artworkNone = stringResource(R.string.admin_tmdb_none)

    AdminScaffold(
        title = if (viewModel.isNew) stringResource(R.string.admin_new_anime) else "Anime düzenle",
        onBack = onBack,
        snackbarHost = snackbar,
    ) { padding ->
        Column(
            Modifier
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Field("Başlık", form.title.orEmpty()) { value -> viewModel.update { it.copy(title = value) } }
            Field("İngilizce başlık", form.titleEnglish.orEmpty()) { value -> viewModel.update { it.copy(titleEnglish = value) } }
            Field("Açıklama", form.synopsis.orEmpty(), lines = 5) { value -> viewModel.update { it.copy(synopsis = value) } }
            Field("Poster URL", form.posterUrl.orEmpty()) { value -> viewModel.update { it.copy(posterUrl = value) } }
            Field("Banner URL", form.bannerUrl.orEmpty()) { value -> viewModel.update { it.copy(bannerUrl = value) } }

            // Only on a work that exists: TMDB is matched by what is already
            // stored, and there is nothing to match a form that has not been
            // saved once.
            if (!viewModel.isNew) {
                OutlinedButton(
                    onClick = {
                        viewModel.fetchArtwork(
                            doneMessage = { count -> artworkDone.replace("0", count.toString()) },
                            emptyMessage = artworkNone,
                        )
                    },
                    enabled = !fetching,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    if (fetching) {
                        CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                    } else {
                        Icon(Icons.Filled.Image, null, Modifier.size(18.dp))
                        Spacer(Modifier.width(8.dp))
                        Text(stringResource(R.string.admin_tmdb_fetch))
                    }
                }
            }
            Field("Stüdyo", form.studio.orEmpty()) { value -> viewModel.update { it.copy(studio = value) } }
            Field("Yıl", form.year?.toString().orEmpty(), numeric = true) { value ->
                viewModel.update { it.copy(year = value.toIntOrNull()) }
            }
            Field("Toplam bölüm", form.totalEpisodes?.toString().orEmpty(), numeric = true) { value ->
                viewModel.update { it.copy(totalEpisodes = value.toIntOrNull()) }
            }

            Row(verticalAlignment = Alignment.CenterVertically) {
                Switch(
                    checked = form.published == true,
                    onCheckedChange = { value -> viewModel.update { it.copy(published = value) } },
                )
                Spacer(Modifier.width(12.dp))
                Text(stringResource(R.string.admin_published))
            }

            Row(verticalAlignment = Alignment.CenterVertically) {
                Switch(
                    checked = form.adult == true,
                    onCheckedChange = { value -> viewModel.update { it.copy(adult = value) } },
                )
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(stringResource(R.string.admin_adult))
                    Text(
                        stringResource(R.string.admin_adult_hint),
                        style = MaterialTheme.typography.labelSmall,
                        color = TextMuted,
                    )
                }
            }

            error?.let {
                Text(
                    it.technical ?: stringResource(it.messageRes),
                    color = MaterialTheme.colorScheme.error,
                    style = MaterialTheme.typography.bodySmall,
                )
            }

            Button(
                onClick = viewModel::save,
                enabled = !saving,
                modifier = Modifier.fillMaxWidth().height(50.dp),
            ) {
                if (saving) CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                else Text(stringResource(R.string.save))
            }
        }
    }
}

@Composable
fun AdminEpisodesScreen(
    workId: Long,
    onBack: () -> Unit,
    onEdit: (Long) -> Unit,
    viewModel: AdminEpisodesViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    AdminScaffold(
        title = stringResource(R.string.admin_episodes),
        onBack = onBack,
        actions = {
            IconButton(onClick = { onEdit(0L) }) {
                Icon(Icons.Filled.Add, stringResource(R.string.admin_new_episode))
            }
        },
    ) { padding ->
        Box(Modifier.padding(padding)) {
            when (val current = state) {
                is UiState.Loading -> Box(Modifier.fillMaxSize(), Alignment.Center) {
                    CircularProgressIndicator()
                }
                is UiState.Error -> ErrorState(current.error, onRetry = viewModel::load)
                is UiState.Empty -> EmptyState(stringResource(R.string.detail_no_episodes))
                is UiState.Success -> LazyColumn {
                    items(current.data, key = { it.id }) { episode ->
                        ListItem(
                            headlineContent = {
                                Text("${episode.seasonNumber}×${episode.number} · ${episode.label}")
                            },
                            supportingContent = {
                                // "No video attached" is the state an operator
                                // is scanning this list for.
                                Text(
                                    buildString {
                                        append(if (episode.published) "Yayında" else "Taslak")
                                        append(" · ")
                                        append("${episode.videoSourceCount} video")
                                        append(" · ")
                                        append("${episode.subtitleSourceCount} altyazı")
                                    },
                                    color = if (episode.videoSourceCount == 0) MaterialTheme.colorScheme.error
                                    else TextSecondary,
                                )
                            },
                            trailingContent = {
                                Row {
                                    IconButton(onClick = { viewModel.togglePublished(episode) }) {
                                        Icon(
                                            if (episode.published) Icons.Filled.Visibility
                                            else Icons.Filled.VisibilityOff,
                                            null,
                                        )
                                    }
                                    IconButton(onClick = { onEdit(episode.id) }) {
                                        Icon(Icons.Filled.Edit, null)
                                    }
                                }
                            },
                            modifier = Modifier.clickable { onEdit(episode.id) },
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun StatTile(value: String, label: String, modifier: Modifier = Modifier) {
    Surface(modifier = modifier, shape = MaterialTheme.shapes.medium, color = SurfaceCard) {
        Column(Modifier.padding(14.dp)) {
            Text(value, style = MaterialTheme.typography.headlineSmall)
            Text(label, style = MaterialTheme.typography.labelSmall, color = TextSecondary)
        }
    }
}

@Composable
internal fun AdminScaffold(
    title: String,
    onBack: () -> Unit,
    actions: @Composable RowScope.() -> Unit = {},
    snackbarHost: SnackbarHostState? = null,
    content: @Composable (PaddingValues) -> Unit,
) {
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(title) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back))
                    }
                },
                actions = actions,
            )
        },
        snackbarHost = { snackbarHost?.let { SnackbarHost(it) } },
        content = content,
    )
}

@Composable
internal fun Field(
    label: String,
    value: String,
    numeric: Boolean = false,
    lines: Int = 1,
    onChange: (String) -> Unit,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onChange,
        label = { Text(label) },
        singleLine = lines == 1,
        minLines = lines,
        keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(
            keyboardType = if (numeric) androidx.compose.ui.text.input.KeyboardType.Number
            else androidx.compose.ui.text.input.KeyboardType.Text,
        ),
        modifier = Modifier.fillMaxWidth(),
    )
}

private val SECTIONS = listOf(
    Triple(Routes.ADMIN_WORKS, R.string.admin_anime, Icons.Filled.MovieFilter),
    Triple(Routes.ADMIN_TENRAI, R.string.admin_tenrai, Icons.Filled.CloudDownload),
    Triple(Routes.ADMIN_TMDB, R.string.admin_tmdb_search, Icons.Filled.Image),
    Triple(Routes.ADMIN_FONTS, R.string.admin_fonts, Icons.Filled.FontDownload),
    Triple(Routes.ADMIN_TERMS, R.string.admin_terms, Icons.Filled.Translate),
    Triple(Routes.ADMIN_USERS, R.string.admin_users, Icons.Filled.People),
    Triple(Routes.ADMIN_REPORTS, R.string.admin_reports, Icons.Filled.Flag),
    Triple(Routes.ADMIN_MODERATORS, R.string.admin_moderators, Icons.Filled.AdminPanelSettings),
    Triple(Routes.ADMIN_ANNOUNCEMENTS, R.string.admin_announcements, Icons.Filled.Campaign),
    Triple(Routes.ADMIN_SERVER, R.string.admin_server, Icons.Filled.Settings),
    Triple(Routes.ADMIN_LOGS, R.string.admin_logs, Icons.Filled.Article),
)
