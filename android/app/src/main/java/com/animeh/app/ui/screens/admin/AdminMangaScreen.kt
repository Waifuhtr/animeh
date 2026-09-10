package com.animeh.app.ui.screens.admin

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CloudSync
import androidx.compose.material.icons.filled.Download
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.data.remote.dto.MangaSearchItemDto
import com.animeh.app.ui.theme.AccentBright
import com.animeh.app.ui.theme.AccentPrimary
import com.animeh.app.ui.theme.PosterShape
import com.animeh.app.ui.theme.StatusError
import com.animeh.app.ui.theme.StatusWarning
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

/**
 * Manga, from the panel's side.
 *
 * Three things stacked, in the order somebody does them: connect to the manga
 * site, pull its library across, and then copy every page image into our own
 * bucket so the library stops depending on that site being up. A fourth
 * section adds one manga at a time from a metadata source, for things that
 * were never on the other site at all.
 */
@Composable
fun AdminMangaScreen(
    onBack: () -> Unit,
    viewModel: AdminMangaViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val message by viewModel.message.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }

    LaunchedEffect(message) {
        message?.let {
            snackbar.showSnackbar(it)
            viewModel.messageShown()
        }
    }

    AdminScaffold(stringResource(R.string.admin_manga), onBack, snackbarHost = snackbar) { padding ->
        LazyColumn(
            Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            item(contentType = "bridge") {
                BridgeCard(
                    url = state.bridgeUrl,
                    key = state.bridgeKey,
                    hasKey = state.hasKey,
                    site = state.site,
                    connecting = state.connecting,
                    onUrl = viewModel::setBridgeUrl,
                    onKey = viewModel::setBridgeKey,
                    onConnect = viewModel::connect,
                )
            }

            if (state.hasKey) {
                item(contentType = "sync") {
                    SyncCard(
                        works = state.works,
                        chapters = state.chapters,
                        pages = state.pages,
                        syncing = state.syncing,
                        progressText = state.syncProgress,
                        errorText = state.lastError,
                        onSync = { viewModel.sync(reset = false) },
                        onRestart = { viewModel.sync(reset = true) },
                    )
                }

                item(contentType = "mirror") {
                    MirrorCard(
                        total = state.mirrorTotal,
                        mirrored = state.mirrored,
                        running = state.mirroring,
                        onRun = viewModel::mirror,
                        onStop = viewModel::stopMirror,
                    )
                }
            }

            item(contentType = "search") {
                SearchCard(
                    query = state.query,
                    source = state.source,
                    galleryEnabled = state.galleryEnabled,
                    searching = state.searching,
                    onQuery = viewModel::setQuery,
                    onSource = viewModel::setSource,
                    onSearch = viewModel::search,
                    onGalleryEnabled = viewModel::setGalleryEnabled,
                )
            }

            items(state.results, key = { "${it.source}-${it.id}" }, contentType = { "result" }) { item ->
                SearchResult(
                    item = item,
                    importing = state.importingId == item.id,
                    onImport = { viewModel.import(item) },
                )
            }
        }
    }
}

@Composable
private fun BridgeCard(
    url: String,
    key: String,
    hasKey: Boolean,
    site: String,
    connecting: Boolean,
    onUrl: (String) -> Unit,
    onKey: (String) -> Unit,
    onConnect: () -> Unit,
) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp)) {
            Text(
                stringResource(R.string.admin_manga_bridge),
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
            )

            Spacer(Modifier.height(4.dp))

            Text(
                stringResource(R.string.admin_manga_bridge_hint),
                style = MaterialTheme.typography.labelSmall,
                color = TextMuted,
            )

            Spacer(Modifier.height(12.dp))

            OutlinedTextField(
                value = url,
                onValueChange = onUrl,
                label = { Text(stringResource(R.string.admin_manga_bridge_url)) },
                placeholder = { Text("https://manga-siten.com") },
                // The site's own address is the one people have to hand, and
                // the server completes it, so saying so avoids the failure
                // where WordPress answers a normal page and this end can only
                // report that the response was unreadable.
                supportingText = {
                    Text(stringResource(R.string.admin_manga_bridge_url_hint))
                },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )

            Spacer(Modifier.height(10.dp))

            OutlinedTextField(
                value = key,
                onValueChange = onKey,
                label = { Text(stringResource(R.string.admin_manga_bridge_key)) },
                // Never sent back by the server, so an empty box means "keep
                // the stored one" rather than "there isn't one".
                placeholder = { Text(if (hasKey) "•••••• (kayıtlı)" else "") },
                singleLine = true,
                visualTransformation = PasswordVisualTransformation(),
                modifier = Modifier.fillMaxWidth(),
            )

            if (site.isNotBlank()) {
                Spacer(Modifier.height(8.dp))
                Text(
                    "Bağlı: $site",
                    style = MaterialTheme.typography.labelMedium,
                    color = AccentBright,
                )
            }

            Spacer(Modifier.height(12.dp))

            Button(
                onClick = onConnect,
                enabled = !connecting && url.isNotBlank(),
                modifier = Modifier.fillMaxWidth(),
            ) {
                if (connecting) {
                    CircularProgressIndicator(
                        strokeWidth = 2.dp,
                        modifier = Modifier.size(18.dp),
                        color = Color.White,
                    )
                } else {
                    Text(stringResource(R.string.admin_manga_connect))
                }
            }
        }
    }
}

@Composable
private fun SyncCard(
    works: Int,
    chapters: Int,
    pages: Int,
    syncing: Boolean,
    progressText: String,
    errorText: String,
    onSync: () -> Unit,
    onRestart: () -> Unit,
) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.Download, null, tint = AccentPrimary, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text(
                    stringResource(R.string.admin_manga_sync),
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                )
            }

            Spacer(Modifier.height(10.dp))

            Text(
                "$works manga · $chapters bölüm · $pages sayfa",
                style = MaterialTheme.typography.bodyMedium,
                color = TextSecondary,
            )

            if (progressText.isNotBlank()) {
                Spacer(Modifier.height(4.dp))
                Text(progressText, style = MaterialTheme.typography.labelSmall, color = TextMuted)
            }

            // The reason the last run stopped, kept where it can be read while
            // the address and key fields above are being corrected.
            if (errorText.isNotBlank()) {
                Spacer(Modifier.height(6.dp))
                Text(
                    errorText,
                    style = MaterialTheme.typography.labelSmall,
                    color = StatusError,
                )
            }

            Spacer(Modifier.height(12.dp))

            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = onSync, enabled = !syncing, modifier = Modifier.weight(1f)) {
                    if (syncing) {
                        CircularProgressIndicator(
                            strokeWidth = 2.dp,
                            modifier = Modifier.size(18.dp),
                            color = Color.White,
                        )
                    } else {
                        Text(stringResource(R.string.admin_manga_sync))
                    }
                }

                OutlinedButton(onClick = onRestart, enabled = !syncing) {
                    Text(stringResource(R.string.admin_manga_sync_restart))
                }
            }
        }
    }
}

@Composable
private fun MirrorCard(
    total: Int,
    mirrored: Int,
    running: Boolean,
    onRun: () -> Unit,
    onStop: () -> Unit,
) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.CloudSync, null, tint = AccentPrimary, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text(
                    stringResource(R.string.admin_manga_mirror),
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.weight(1f),
                )
                Text(
                    "$mirrored / $total",
                    style = MaterialTheme.typography.labelMedium,
                    color = if (total > 0 && mirrored >= total) AccentBright else TextMuted,
                )
            }

            Spacer(Modifier.height(10.dp))

            LinearProgressIndicator(
                progress = { if (total > 0) mirrored.toFloat() / total else 0f },
                modifier = Modifier.fillMaxWidth().height(5.dp).clip(RoundedCornerShape(3.dp)),
                color = AccentPrimary,
                trackColor = Color.White.copy(alpha = 0.12f),
            )

            Spacer(Modifier.height(8.dp))

            Text(
                stringResource(R.string.admin_manga_mirror_hint),
                style = MaterialTheme.typography.labelSmall,
                color = TextMuted,
            )

            Spacer(Modifier.height(12.dp))

            if (running) {
                OutlinedButton(onClick = onStop, modifier = Modifier.fillMaxWidth()) {
                    Text("Durdur")
                }
            } else {
                Button(
                    onClick = onRun,
                    enabled = total > mirrored,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(if (total > mirrored) "Kopyalamayı başlat" else "Hepsi kopyalandı")
                }
            }
        }
    }
}

@Composable
private fun SearchCard(
    query: String,
    source: String,
    galleryEnabled: Boolean,
    searching: Boolean,
    onQuery: (String) -> Unit,
    onSource: (String) -> Unit,
    onSearch: () -> Unit,
    onGalleryEnabled: (Boolean) -> Unit,
) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp)) {
            Text(
                stringResource(R.string.admin_manga_search),
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
            )

            Spacer(Modifier.height(12.dp))

            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                SourceChip(
                    label = stringResource(R.string.admin_manga_source_tenrai),
                    active = source == "tenrai",
                    onClick = { onSource("tenrai") },
                    modifier = Modifier.weight(1f),
                )
                SourceChip(
                    label = stringResource(R.string.admin_manga_source_gallery),
                    active = source == "gallery",
                    onClick = { onSource("gallery") },
                    modifier = Modifier.weight(1f),
                )
            }

            if (source == "gallery") {
                Spacer(Modifier.height(10.dp))

                Row(verticalAlignment = Alignment.CenterVertically) {
                    Switch(checked = galleryEnabled, onCheckedChange = onGalleryEnabled)
                    Spacer(Modifier.width(8.dp))
                    Column {
                        Text(
                            stringResource(R.string.admin_manga_gallery_enable),
                            style = MaterialTheme.typography.bodyMedium,
                        )
                        Text(
                            stringResource(R.string.admin_manga_gallery_note),
                            style = MaterialTheme.typography.labelSmall,
                            color = StatusWarning,
                        )
                    }
                }
            }

            Spacer(Modifier.height(10.dp))

            // The two sources are asked different questions. Tenrai is
            // searched by name; the gallery source publishes no search at all
            // and is addressed by number, so the field says which one it wants
            // rather than letting a title be typed into something that can
            // only answer "bulunamadı".
            val galleryLookup = source == "gallery"

            OutlinedTextField(
                value = query,
                onValueChange = onQuery,
                placeholder = {
                    Text(
                        stringResource(
                            if (galleryLookup) R.string.admin_manga_gallery_hint else R.string.search
                        )
                    )
                },
                supportingText = if (galleryLookup) {
                    { Text(stringResource(R.string.admin_manga_gallery_id_note)) }
                } else {
                    null
                },
                leadingIcon = { Icon(Icons.Filled.Search, null) },
                trailingIcon = {
                    if (searching) {
                        CircularProgressIndicator(strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
                    } else {
                        TextButton(onClick = onSearch, enabled = query.isNotBlank()) { Text("Ara") }
                    }
                },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
        }
    }
}

@Composable
private fun SourceChip(
    label: String,
    active: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Box(
        modifier
            .clip(RoundedCornerShape(10.dp))
            .background(if (active) AccentPrimary.copy(alpha = 0.2f) else Color.Transparent)
            .border(
                1.dp,
                if (active) AccentPrimary else Color.White.copy(alpha = 0.12f),
                RoundedCornerShape(10.dp),
            )
            .clickable(onClick = onClick)
            .padding(vertical = 10.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            label,
            style = MaterialTheme.typography.labelMedium,
            color = if (active) AccentBright else TextMuted,
        )
    }
}

@Composable
private fun SearchResult(
    item: MangaSearchItemDto,
    importing: Boolean,
    onImport: () -> Unit,
) {
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(14.dp))
            .background(SurfaceCard.copy(alpha = 0.5f))
            .padding(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        AsyncImage(
            model = item.posterUrl,
            contentDescription = null,
            contentScale = ContentScale.Crop,
            modifier = Modifier.width(52.dp).height(74.dp).clip(PosterShape).background(SurfaceCard),
        )

        Spacer(Modifier.width(12.dp))

        Column(Modifier.weight(1f)) {
            Text(
                item.title,
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.SemiBold,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                listOfNotNull(
                    item.format.takeIf { it.isNotBlank() },
                    item.year.takeIf { it > 0 }?.toString(),
                    item.chapters.takeIf { it > 0 }?.let { "$it bölüm" },
                    item.score.takeIf { it > 0 }?.let { "★ %.1f".format(it) },
                ).joinToString(" · "),
                style = MaterialTheme.typography.labelSmall,
                color = TextMuted,
            )
            if (item.adult) {
                Text(
                    "+18",
                    style = MaterialTheme.typography.labelSmall,
                    color = StatusWarning,
                )
            }
        }

        TextButton(onClick = onImport, enabled = !importing) {
            if (importing) {
                CircularProgressIndicator(strokeWidth = 2.dp, modifier = Modifier.size(16.dp))
            } else {
                Text("Ekle")
            }
        }
    }
}
