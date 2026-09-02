package com.animeh.app.ui.screens.admin

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.selection.SelectionContainer
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
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.core.ClientLog
import com.animeh.app.core.UiState
import com.animeh.app.data.remote.dto.UserDto
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.components.ErrorState
import com.animeh.app.ui.theme.*

/**
 * The episode editor, with the upload.
 *
 * The upload is the reason this screen exists on the phone at all: an operator
 * with a finished encode on their device can attach it to an episode without a
 * desktop, because the file goes straight to storage in presigned parts rather
 * than through WordPress.
 */
@Composable
fun AdminEpisodeEditScreen(
    workId: Long,
    episodeId: Long,
    onBack: () -> Unit,
    viewModel: AdminEpisodeEditViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    LaunchedEffect(state.saved) { if (state.saved) onBack() }

    var pendingKind by remember { mutableStateOf("video") }
    var pendingHeight by remember { mutableStateOf(1080) }

    val picker = rememberLauncherForActivityResult(
        ActivityResultContracts.OpenDocument()
    ) { uri ->
        uri ?: return@rememberLauncherForActivityResult

        val name = uri.lastPathSegment?.substringAfterLast('/') ?: "upload"
        val contentType = when (pendingKind) {
            "subtitle" -> "text/x-ssa"
            "font" -> "font/ttf"
            else -> "video/mp4"
        }

        viewModel.upload(
            uri = uri,
            filename = name,
            kind = pendingKind,
            contentType = contentType,
            height = if (pendingKind == "video") pendingHeight else 0,
            language = if (pendingKind == "subtitle") "tr" else "",
        )
    }

    AdminScaffold(
        title = if (viewModel.isNew) stringResource(R.string.admin_new_episode) else "Bölüm düzenle",
        onBack = onBack,
    ) { padding ->
        Column(
            Modifier
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Box(Modifier.weight(1f)) {
                    Field("Sezon", state.form.seasonNumber.toString(), numeric = true) { value ->
                        viewModel.update { it.copy(seasonNumber = value.toIntOrNull() ?: 1) }
                    }
                }
                Box(Modifier.weight(1f)) {
                    Field("Bölüm", state.form.number.toString(), numeric = true) { value ->
                        viewModel.update { it.copy(number = value.toIntOrNull() ?: 1) }
                    }
                }
            }

            Field("Başlık", state.form.title.orEmpty()) { value ->
                viewModel.update { it.copy(title = value) }
            }
            Field("Açıklama", state.form.synopsis.orEmpty(), lines = 3) { value ->
                viewModel.update { it.copy(synopsis = value) }
            }
            Field("Kapak URL", state.form.thumbnailUrl.orEmpty()) { value ->
                viewModel.update { it.copy(thumbnailUrl = value) }
            }
            Field("Süre (saniye)", state.form.durationSeconds?.toString().orEmpty(), numeric = true) { value ->
                viewModel.update { it.copy(durationSeconds = value.toIntOrNull()) }
            }

            Text("Atlama işaretleri", style = MaterialTheme.typography.titleSmall, color = TextSecondary)
            Text(
                "Boş bırakılırsa jenerik atlama düğmesi görünmez.",
                style = MaterialTheme.typography.bodySmall,
                color = TextMuted,
            )

            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Box(Modifier.weight(1f)) {
                    Field("Jenerik başı", state.form.introStart?.takeIf { it >= 0 }?.toString().orEmpty(), numeric = true) { value ->
                        viewModel.update { it.copy(introStart = value.toIntOrNull() ?: -1) }
                    }
                }
                Box(Modifier.weight(1f)) {
                    Field("Jenerik sonu", state.form.introEnd?.takeIf { it >= 0 }?.toString().orEmpty(), numeric = true) { value ->
                        viewModel.update { it.copy(introEnd = value.toIntOrNull() ?: -1) }
                    }
                }
            }

            Row(verticalAlignment = Alignment.CenterVertically) {
                Switch(
                    checked = state.form.published == true,
                    onCheckedChange = { value -> viewModel.update { it.copy(published = value) } },
                )
                Spacer(Modifier.width(12.dp))
                Text(stringResource(R.string.admin_published))
            }

            state.error?.let {
                Text(
                    it.technical ?: stringResource(it.messageRes),
                    color = MaterialTheme.colorScheme.error,
                    style = MaterialTheme.typography.bodySmall,
                )
            }

            Button(
                onClick = viewModel::save,
                enabled = !state.saving,
                modifier = Modifier.fillMaxWidth().height(50.dp),
            ) {
                if (state.saving) CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                else Text(stringResource(R.string.save))
            }

            if (!viewModel.isNew) {
                HorizontalDivider(Modifier.padding(vertical = 8.dp))
                Text(stringResource(R.string.admin_sources), style = MaterialTheme.typography.titleMedium)

                state.sources.forEach { source ->
                    ListItem(
                        headlineContent = {
                            Text(source.label.ifBlank { source.kind }, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        },
                        supportingContent = {
                            Text(
                                listOfNotNull(
                                    source.kind,
                                    source.language.takeIf { it.isNotBlank() },
                                    "varsayılan".takeIf { source.isDefault },
                                    source.storageKey.takeIf { it.isNotBlank() },
                                ).joinToString(" · "),
                                maxLines = 2,
                                overflow = TextOverflow.Ellipsis,
                                style = MaterialTheme.typography.bodySmall,
                            )
                        },
                        trailingContent = {
                            IconButton(onClick = { viewModel.deleteSource(source.id) }) {
                                Icon(Icons.Filled.Delete, null, tint = MaterialTheme.colorScheme.error)
                            }
                        },
                    )
                }

                state.uploadProgress?.let { progress ->
                    Column {
                        Text(
                            stringResource(R.string.admin_uploading, (progress * 100).toInt()),
                            style = MaterialTheme.typography.bodySmall,
                        )
                        LinearProgressIndicator(
                            progress = { progress },
                            modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp),
                        )
                    }
                }

                if (state.uploadProgress == null) {
                    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        items(VIDEO_HEIGHTS) { height ->
                            FilterChip(
                                selected = pendingHeight == height,
                                onClick = { pendingHeight = height },
                                label = { Text("${height}p") },
                            )
                        }
                    }

                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        OutlinedButton(
                            onClick = {
                                pendingKind = "video"
                                picker.launch(arrayOf("video/*"))
                            },
                            modifier = Modifier.weight(1f),
                        ) {
                            Icon(Icons.Filled.UploadFile, null, Modifier.size(18.dp))
                            Spacer(Modifier.width(6.dp))
                            Text(stringResource(R.string.admin_upload_video))
                        }

                        OutlinedButton(
                            onClick = {
                                pendingKind = "subtitle"
                                // ASS has no registered MIME type on most
                                // devices, so the picker is opened wide and the
                                // server validates what actually arrives.
                                picker.launch(arrayOf("*/*"))
                            },
                            modifier = Modifier.weight(1f),
                        ) {
                            Icon(Icons.Filled.Subtitles, null, Modifier.size(18.dp))
                            Spacer(Modifier.width(6.dp))
                            Text(stringResource(R.string.admin_upload_subtitle))
                        }
                    }
                }
            }

            Spacer(Modifier.height(32.dp))
        }
    }
}

@Composable
fun AdminTenraiScreen(
    onBack: () -> Unit,
    viewModel: AdminTenraiViewModel = hiltViewModel(),
) {
    val query by viewModel.query.collectAsStateWithLifecycle()
    val results by viewModel.results.collectAsStateWithLifecycle()
    val importing by viewModel.importing.collectAsStateWithLifecycle()
    val message by viewModel.message.collectAsStateWithLifecycle()

    val snackbar = remember { SnackbarHostState() }

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
                title = { Text(stringResource(R.string.admin_tenrai_search)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back))
                    }
                },
            )
        },
    ) { padding ->
        Column(Modifier.padding(padding)) {
            OutlinedTextField(
                value = query,
                onValueChange = viewModel::setQuery,
                placeholder = { Text(stringResource(R.string.admin_tenrai_search)) },
                leadingIcon = { Icon(Icons.Filled.Search, null) },
                singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(16.dp),
            )

            when (val current = results) {
                is UiState.Loading -> Box(Modifier.fillMaxSize(), Alignment.Center) {
                    CircularProgressIndicator()
                }
                is UiState.Error -> ErrorState(current.error)
                is UiState.Empty -> EmptyState(
                    if (query.length < 3) "En az 3 harf yaz." else stringResource(R.string.discover_no_results),
                    Icons.Filled.CloudDownload,
                )
                is UiState.Success -> LazyColumn {
                    items(current.data, key = { it.tenraiId }) { result ->
                        ListItem(
                            headlineContent = {
                                Text(result.title, maxLines = 2, overflow = TextOverflow.Ellipsis)
                            },
                            supportingContent = {
                                Text(
                                    listOfNotNull(
                                        result.year.takeIf { it > 0 }?.toString(),
                                        result.format.takeIf { it.isNotBlank() },
                                        result.totalEpisodes.takeIf { it > 0 }?.let { "$it bölüm" },
                                        "★ %.1f".format(result.score).takeIf { result.score > 0 },
                                    ).joinToString(" · ")
                                )
                            },
                            leadingContent = {
                                AsyncImage(
                                    model = result.posterUrl,
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
                                if (importing == result.tenraiId) {
                                    CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp)
                                } else {
                                    TextButton(onClick = { viewModel.import(result.tenraiId) }) {
                                        Text(
                                            stringResource(
                                                if (result.importedId > 0) R.string.admin_tenrai_update
                                                else R.string.admin_tenrai_import
                                            )
                                        )
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
fun AdminUsersScreen(onBack: () -> Unit, viewModel: AdminUsersViewModel = hiltViewModel()) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val query by viewModel.query.collectAsStateWithLifecycle()

    // Which user the ban dialog is open for, or null when it is closed.
    var banning by remember { mutableStateOf<UserDto?>(null) }

    banning?.let { user ->
        BanDialog(
            user = user,
            onDismiss = { banning = null },
            onConfirm = { reason, days ->
                banning = null
                viewModel.ban(user.id, reason, days)
            },
        )
    }

    AdminScaffold(stringResource(R.string.admin_users), onBack) { padding ->
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
                is UiState.Loading -> Box(Modifier.fillMaxSize(), Alignment.Center) { CircularProgressIndicator() }
                is UiState.Error -> ErrorState(current.error, onRetry = viewModel::load)
                is UiState.Empty -> EmptyState("Kullanıcı bulunamadı.", Icons.Filled.People)
                is UiState.Success -> LazyColumn {
                    items(current.data, key = { it.id }) { user ->
                        ListItem(
                            headlineContent = { Text(user.displayName.ifBlank { user.username }) },
                            supportingContent = {
                                val ban = user.ban

                                Text(
                                    listOfNotNull(
                                        user.email,
                                        user.roles.joinToString(", ").takeIf { it.isNotBlank() },
                                        user.stats?.let { "${it.episodesCompleted} bölüm" },
                                        // The sanction reads first when there
                                        // is one: it is why the row is here.
                                        ban?.let {
                                            if (it.permanent) stringResource(R.string.admin_ban_banned)
                                            else stringResource(R.string.admin_ban_until, it.expiresAt)
                                        },
                                    ).joinToString(" · "),
                                    style = MaterialTheme.typography.bodySmall,
                                    color = if (ban != null) StatusError else Color.Unspecified,
                                )
                            },
                            trailingContent = {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    if (user.isAdmin) {
                                        Icon(
                                            Icons.Filled.AdminPanelSettings,
                                            null,
                                            tint = AccentPrimary,
                                        )
                                    }

                                    // Never offered against an administrator:
                                    // the server refuses it too, and an action
                                    // that always fails is worse than none.
                                    if (!user.isAdmin) {
                                        if (user.ban != null) {
                                            TextButton(onClick = { viewModel.liftBan(user.id) }) {
                                                Text(stringResource(R.string.admin_ban_lift))
                                            }
                                        } else {
                                            TextButton(onClick = { banning = user }) {
                                                Text(stringResource(R.string.admin_ban))
                                            }
                                        }
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
fun AdminAnnouncementsScreen(
    onBack: () -> Unit,
    viewModel: AdminAnnouncementsViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    var composing by remember { mutableStateOf(false) }
    var title by remember { mutableStateOf("") }
    var body by remember { mutableStateOf("") }

    AdminScaffold(
        stringResource(R.string.admin_announcements),
        onBack,
        actions = {
            IconButton(onClick = { composing = true }) { Icon(Icons.Filled.Add, null) }
        },
    ) { padding ->
        Box(Modifier.padding(padding)) {
            when (val current = state) {
                is UiState.Loading -> Box(Modifier.fillMaxSize(), Alignment.Center) { CircularProgressIndicator() }
                is UiState.Error -> ErrorState(current.error, onRetry = viewModel::load)
                is UiState.Empty -> EmptyState("Henüz duyuru yok.", Icons.Filled.Campaign)
                is UiState.Success -> LazyColumn {
                    items(current.data, key = { it.id }) { announcement ->
                        ListItem(
                            headlineContent = { Text(announcement.title) },
                            supportingContent = { Text(announcement.body, maxLines = 3, overflow = TextOverflow.Ellipsis) },
                            trailingContent = {
                                IconButton(onClick = { viewModel.delete(announcement.id) }) {
                                    Icon(Icons.Filled.Delete, null, tint = MaterialTheme.colorScheme.error)
                                }
                            },
                        )
                    }
                }
            }
        }
    }

    if (composing) {
        AlertDialog(
            onDismissRequest = { composing = false },
            title = { Text(stringResource(R.string.admin_announcements)) },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Field("Başlık", title) { title = it }
                    Field("Metin", body, lines = 4) { body = it }
                }
            },
            confirmButton = {
                TextButton(
                    onClick = {
                        viewModel.save(title, body)
                        title = ""
                        body = ""
                        composing = false
                    },
                    enabled = title.isNotBlank(),
                ) { Text(stringResource(R.string.save)) }
            },
            dismissButton = {
                TextButton(onClick = { composing = false }) { Text(stringResource(R.string.cancel)) }
            },
        )
    }
}

@Composable
fun AdminLogsScreen(onBack: () -> Unit, viewModel: AdminLogsViewModel = hiltViewModel()) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val level by viewModel.level.collectAsStateWithLifecycle()

    // The server's table only knows what reached the server; a response this
    // app could not parse is only ever visible here.
    val deviceEntries by ClientLog.entries.collectAsStateWithLifecycle()
    var showingDevice by remember { mutableStateOf(false) }
    val clipboard = LocalClipboardManager.current

    AdminScaffold(
        stringResource(R.string.admin_logs),
        onBack,
        actions = {
            if (showingDevice) {
                IconButton(
                    onClick = { clipboard.setText(AnnotatedString(ClientLog.asText())) },
                    enabled = deviceEntries.isNotEmpty(),
                ) { Icon(Icons.Filled.ContentCopy, "Tümünü kopyala") }

                IconButton(onClick = ClientLog::clear) { Icon(Icons.Filled.DeleteSweep, null) }
            } else {
                IconButton(onClick = viewModel::clear) { Icon(Icons.Filled.DeleteSweep, null) }
            }
        },
    ) { padding ->
        Column(Modifier.padding(padding)) {
            TabRow(selectedTabIndex = if (showingDevice) 1 else 0) {
                Tab(
                    selected = !showingDevice,
                    onClick = { showingDevice = false },
                    text = { Text("Sunucu") },
                )
                Tab(
                    selected = showingDevice,
                    onClick = { showingDevice = true },
                    text = { Text("Bu cihaz (${deviceEntries.size})") },
                )
            }

            if (showingDevice) {
                DeviceLogList(deviceEntries, clipboard)
                return@Column
            }

            LazyRow(
                contentPadding = PaddingValues(horizontal = 16.dp, vertical = 8.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                items(LOG_LEVELS) { (value, label) ->
                    FilterChip(
                        selected = level == value,
                        onClick = { viewModel.setLevel(value) },
                        label = { Text(label) },
                    )
                }
            }

            when (val current = state) {
                is UiState.Loading -> Box(Modifier.fillMaxSize(), Alignment.Center) { CircularProgressIndicator() }
                is UiState.Error -> ErrorState(current.error, onRetry = viewModel::load)
                is UiState.Empty -> EmptyState("Log kaydı yok.", Icons.Filled.Article)
                is UiState.Success -> LazyColumn {
                    items(current.data, key = { it.id }) { entry ->
                        ListItem(
                            overlineContent = {
                                Text(
                                    "${entry.level.uppercase()} · ${entry.code}",
                                    color = when (entry.level) {
                                        "error" -> MaterialTheme.colorScheme.error
                                        "warning" -> StatusWarning
                                        else -> TextMuted
                                    },
                                )
                            },
                            headlineContent = { Text(entry.message) },
                            supportingContent = {
                                Text(
                                    entry.createdAt,
                                    style = MaterialTheme.typography.labelSmall,
                                    fontFamily = FontFamily.Monospace,
                                    color = TextMuted,
                                )
                            },
                        )
                    }
                }
            }
        }
    }
}

/**
 * The failures this device saw, in full.
 *
 * Selectable and individually copyable: an error worth reporting is one worth
 * pasting somewhere, and reading a parser's offset off a screenshot is how a
 * useful message gets lost.
 */
@Composable
private fun DeviceLogList(
    entries: List<ClientLog.Entry>,
    clipboard: androidx.compose.ui.platform.ClipboardManager,
) {
    if (entries.isEmpty()) {
        EmptyState("Bu cihazda kayıtlı hata yok.", Icons.Filled.Article)
        return
    }

    LazyColumn(contentPadding = PaddingValues(bottom = 24.dp)) {
        items(entries, key = { it.at.toString() + it.label }) { entry ->
            ListItem(
                overlineContent = {
                    Text(
                        "${entry.time} · ${entry.label}",
                        color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.labelMedium,
                    )
                },
                headlineContent = {
                    SelectionContainer {
                        Text(
                            entry.detail,
                            style = MaterialTheme.typography.bodySmall,
                            fontFamily = FontFamily.Monospace,
                        )
                    }
                },
                trailingContent = {
                    IconButton(
                        onClick = {
                            clipboard.setText(
                                AnnotatedString("[${entry.time}] ${entry.label}\n${entry.detail}")
                            )
                        }
                    ) { Icon(Icons.Filled.ContentCopy, "Kopyala") }
                },
            )
            HorizontalDivider()
        }
    }
}

@Composable
fun AdminFontsScreen(onBack: () -> Unit, viewModel: AdminFontsViewModel = hiltViewModel()) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    AdminScaffold(stringResource(R.string.admin_fonts), onBack) { padding ->
        Box(Modifier.padding(padding)) {
            when (val current = state) {
                is UiState.Loading -> Box(Modifier.fillMaxSize(), Alignment.Center) { CircularProgressIndicator() }
                is UiState.Error -> ErrorState(current.error, onRetry = viewModel::load)
                is UiState.Empty -> EmptyState(
                    "Kayıtlı font yok. Fontları WordPress panelinden yükleyebilirsin.",
                    Icons.Filled.FontDownload,
                )
                is UiState.Success -> LazyColumn {
                    items(current.data, key = { it.id }) { font ->
                        ListItem(
                            headlineContent = { Text(font.family) },
                            supportingContent = {
                                Text("${font.filename} · ${font.sizeBytes / 1024} KB · ${font.format}")
                            },
                            trailingContent = {
                                IconButton(onClick = { viewModel.delete(font.id) }) {
                                    Icon(Icons.Filled.Delete, null, tint = MaterialTheme.colorScheme.error)
                                }
                            },
                        )
                    }
                }
            }
        }
    }
}

private val VIDEO_HEIGHTS = listOf(1080, 720, 480, 360)

private val LOG_LEVELS = listOf(
    "error" to "Hata",
    "warning" to "Uyarı",
    "info" to "Bilgi",
)
