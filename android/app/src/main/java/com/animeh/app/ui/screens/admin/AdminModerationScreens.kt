package com.animeh.app.ui.screens.admin

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Flag
import androidx.compose.material.icons.filled.Image
import androidx.compose.material.icons.filled.PersonOff
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.outlined.Delete
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
import com.animeh.app.data.remote.dto.ReportDto
import com.animeh.app.data.remote.dto.UserDto
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.components.ErrorState
import com.animeh.app.ui.theme.StatusError
import com.animeh.app.ui.theme.StatusWarning
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

/**
 * What people reported, with the review itself in front of you.
 *
 * The reported text is shown in full, spoiler flag and all: a moderator
 * deciding whether a review needed one has to be able to read it, and a
 * queue of "someone said this was bad" without the words is not a queue
 * anyone can work.
 */
@Composable
fun AdminReportsScreen(
    onBack: () -> Unit,
    viewModel: AdminReportsViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    AdminScaffold(title = stringResource(R.string.admin_reports), onBack = onBack) { padding ->
        when (val current = state) {
            is UiState.Loading -> Box(Modifier.fillMaxSize().padding(padding), Alignment.Center) {
                CircularProgressIndicator()
            }

            is UiState.Error -> ErrorState(
                current.error,
                onRetry = viewModel::load,
                modifier = Modifier.padding(padding),
            )

            is UiState.Empty -> EmptyState(
                stringResource(R.string.admin_reports_empty),
                Icons.Filled.Flag,
                modifier = Modifier.padding(padding),
            )

            is UiState.Success -> LazyColumn(
                Modifier.padding(padding),
                contentPadding = PaddingValues(16.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                items(current.data, key = { it.id }) { report ->
                    ReportCard(
                        report = report,
                        onDelete = { viewModel.handle(report.id, "delete") },
                        onDismiss = { viewModel.handle(report.id, "dismiss") },
                    )
                }
            }
        }
    }
}

@Composable
private fun ReportCard(
    report: ReportDto,
    onDelete: () -> Unit,
    onDismiss: () -> Unit,
) {
    Surface(color = SurfaceCard, shape = RoundedCornerShape(14.dp)) {
        Column(Modifier.fillMaxWidth().padding(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Surface(
                    color = StatusWarning.copy(alpha = 0.18f),
                    shape = RoundedCornerShape(8.dp),
                ) {
                    Text(
                        stringResource(reportReasonLabel(report.reason)),
                        style = MaterialTheme.typography.labelSmall,
                        color = StatusWarning,
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
                    )
                }

                if (report.reviewSpoiler) {
                    Spacer(Modifier.width(6.dp))
                    Text(
                        stringResource(R.string.admin_report_spoiler),
                        style = MaterialTheme.typography.labelSmall,
                        color = TextMuted,
                    )
                }

                Spacer(Modifier.weight(1f))

                Text(
                    "${report.reviewScore}/10",
                    style = MaterialTheme.typography.labelMedium,
                    color = TextMuted,
                )
            }

            Spacer(Modifier.height(8.dp))

            Text(
                report.workTitle,
                style = MaterialTheme.typography.titleSmall,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )

            if (report.reviewBody.isNotBlank()) {
                Spacer(Modifier.height(6.dp))
                Text(
                    report.reviewBody,
                    style = MaterialTheme.typography.bodyMedium,
                    maxLines = 6,
                    overflow = TextOverflow.Ellipsis,
                )
            }

            // The reporter's own words, when they picked "other". This is the
            // only part of a report that is not a category.
            if (report.note.isNotBlank()) {
                Spacer(Modifier.height(8.dp))
                Text(
                    "\"${report.note}\"",
                    style = MaterialTheme.typography.bodySmall,
                    color = TextSecondary,
                )
            }

            Spacer(Modifier.height(8.dp))

            Text(
                stringResource(
                    R.string.admin_report_reported_by,
                    report.reporter.displayName.ifBlank { report.reporter.username },
                ),
                style = MaterialTheme.typography.labelSmall,
                color = TextMuted,
            )

            Spacer(Modifier.height(10.dp))

            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                TextButton(onClick = onDelete) {
                    Icon(Icons.Outlined.Delete, null, Modifier.size(16.dp), tint = StatusError)
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(R.string.admin_report_delete), color = StatusError)
                }

                TextButton(onClick = onDismiss) {
                    Text(stringResource(R.string.admin_report_dismiss))
                }
            }
        }
    }
}

private fun reportReasonLabel(value: String): Int = when (value) {
    "spam" -> R.string.report_reason_spam
    "spoiler" -> R.string.report_reason_spoiler
    "abuse" -> R.string.report_reason_abuse
    "offtopic" -> R.string.report_reason_offtopic
    else -> R.string.report_reason_other
}

/**
 * Who may moderate, added by the address they gave you.
 *
 * By email rather than by picking from the user list: the address is the
 * thing a person tells you, and scanning a list of a thousand accounts for
 * the right "ayse" is exactly where the wrong one gets promoted.
 */
@Composable
fun AdminModeratorsScreen(
    onBack: () -> Unit,
    viewModel: AdminModeratorsViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val email by viewModel.email.collectAsStateWithLifecycle()
    val busy by viewModel.busy.collectAsStateWithLifecycle()
    val message by viewModel.message.collectAsStateWithLifecycle()

    val snackbar = remember { SnackbarHostState() }

    LaunchedEffect(message) {
        message?.let {
            snackbar.showSnackbar(it)
            viewModel.dismissMessage()
        }
    }

    AdminScaffold(
        title = stringResource(R.string.admin_moderators),
        onBack = onBack,
        snackbarHost = snackbar,
    ) { padding ->
        Column(Modifier.padding(padding)) {
            Text(
                stringResource(R.string.admin_moderators_hint),
                style = MaterialTheme.typography.bodySmall,
                color = TextMuted,
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 12.dp),
            )

            Row(
                Modifier.fillMaxWidth().padding(horizontal = 16.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                OutlinedTextField(
                    value = email,
                    onValueChange = viewModel::setEmail,
                    label = { Text(stringResource(R.string.admin_moderators_email)) },
                    singleLine = true,
                    modifier = Modifier.weight(1f),
                )

                Spacer(Modifier.width(10.dp))

                Button(
                    onClick = viewModel::add,
                    enabled = !busy && email.isNotBlank(),
                    modifier = Modifier.height(52.dp),
                ) {
                    if (busy) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                    else Text(stringResource(R.string.admin_moderators_add))
                }
            }

            Spacer(Modifier.height(12.dp))
            HorizontalDivider()

            when (val current = state) {
                is UiState.Loading -> Box(Modifier.fillMaxWidth().padding(32.dp), Alignment.Center) {
                    CircularProgressIndicator()
                }

                is UiState.Error -> ErrorState(current.error, onRetry = viewModel::load)

                is UiState.Empty -> EmptyState(
                    stringResource(R.string.admin_moderators_empty),
                    Icons.Filled.PersonOff,
                )

                is UiState.Success -> LazyColumn {
                    items(current.data, key = { it.id }) { user ->
                        ListItem(
                            headlineContent = {
                                Text(user.displayName.ifBlank { user.username })
                            },
                            supportingContent = { Text(user.email) },
                            trailingContent = {
                                TextButton(onClick = { viewModel.remove(user.id) }) {
                                    Text(stringResource(R.string.admin_moderators_remove))
                                }
                            },
                        )
                    }
                }
            }
        }
    }
}

/**
 * Where every client is told to connect.
 *
 * The one setting on this screen that moves other people's phones, which is
 * why the warning is on the screen rather than in a changelog: the address has
 * to be published while the old server still answers, or nothing hears it.
 */
@Composable
fun AdminServerScreen(
    onBack: () -> Unit,
    viewModel: AdminServerViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val message by viewModel.message.collectAsStateWithLifecycle()

    val snackbar = remember { SnackbarHostState() }
    val savedMessage = stringResource(R.string.admin_server_saved)

    LaunchedEffect(message) {
        message?.let {
            snackbar.showSnackbar(it)
            viewModel.dismissMessage()
        }
    }

    AdminScaffold(
        title = stringResource(R.string.admin_server),
        onBack = onBack,
        snackbarHost = snackbar,
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
                val config = current.data

                // Keyed on the loaded value so a reload lands in the field,
                // but typing is not overwritten by a recomposition.
                var base by remember(config.apiBaseOverride) {
                    mutableStateOf(config.apiBaseOverride)
                }
                var registration by remember(config.registrationOpen) {
                    mutableStateOf(config.registrationOpen)
                }

                Column(
                    Modifier
                        .padding(padding)
                        .verticalScroll(rememberScrollState())
                        .padding(16.dp),
                    verticalArrangement = Arrangement.spacedBy(14.dp),
                ) {
                    Text(
                        stringResource(R.string.admin_server_hint),
                        style = MaterialTheme.typography.bodySmall,
                        color = TextMuted,
                    )

                    OutlinedTextField(
                        value = base,
                        onValueChange = { base = it },
                        label = { Text(stringResource(R.string.admin_server)) },
                        placeholder = { Text(config.defaultBase) },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )

                    Text(
                        stringResource(R.string.admin_server_current, config.apiBase),
                        style = MaterialTheme.typography.labelSmall,
                        color = TextMuted,
                    )

                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Switch(checked = registration, onCheckedChange = { registration = it })
                        Spacer(Modifier.width(12.dp))
                        Text(stringResource(R.string.admin_server_registration))
                    }

                    Button(
                        onClick = { viewModel.save(base, registration, savedMessage) },
                        modifier = Modifier.fillMaxWidth().height(50.dp),
                    ) {
                        Text(stringResource(R.string.admin_server_save))
                    }
                }
            }
        }
    }
}

/**
 * Suspend or ban one user.
 *
 * The duration is a small set of choices rather than a free field: "how many
 * days" is a decision, and a text box invites typing 10000 where 30 was meant.
 */
@Composable
internal fun BanDialog(
    user: UserDto,
    onDismiss: () -> Unit,
    onConfirm: (reason: String, days: Int) -> Unit,
) {
    var reason by remember { mutableStateOf("") }
    var days by remember { mutableStateOf(BAN_DURATIONS.first()) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = {
            Text(
                stringResource(
                    R.string.admin_ban_title,
                    user.displayName.ifBlank { user.username },
                )
            )
        },
        text = {
            Column {
                OutlinedTextField(
                    value = reason,
                    onValueChange = { reason = it.take(200) },
                    label = { Text(stringResource(R.string.admin_ban_reason)) },
                    minLines = 2,
                    maxLines = 3,
                    modifier = Modifier.fillMaxWidth(),
                )

                Spacer(Modifier.height(12.dp))

                Text(
                    stringResource(R.string.admin_ban_days),
                    style = MaterialTheme.typography.labelMedium,
                    color = TextMuted,
                )

                Spacer(Modifier.height(6.dp))

                Column {
                    BAN_DURATIONS.forEach { value ->
                        Row(
                            Modifier
                                .fillMaxWidth()
                                .clip(RoundedCornerShape(8.dp))
                                .padding(vertical = 2.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            RadioButton(selected = days == value, onClick = { days = value })
                            Spacer(Modifier.width(6.dp))
                            Text(
                                if (value == 0) stringResource(R.string.admin_ban_permanent)
                                else stringResource(R.string.admin_ban_days_value, value),
                                style = MaterialTheme.typography.bodyMedium,
                            )
                        }
                    }
                }
            }
        },
        confirmButton = {
            TextButton(onClick = { onConfirm(reason, days) }) {
                Text(stringResource(R.string.admin_ban))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) }
        },
    )
}

/** Zero is permanent; the rest are days. Kept short on purpose. */
private val BAN_DURATIONS = listOf(1, 3, 7, 30, 0)

/**
 * Searching TMDB and bringing a show over, episodes and all.
 *
 * Deliberately the same screen as the Tenrai one rather than a variation on
 * it: which source a title comes from is a judgement about that title — Tenrai
 * knows anime numbering, seasons and filler; TMDB has the artwork and the
 * Turkish text — and an operator switching between them should not have to
 * learn a second set of controls to do it.
 */
@Composable
fun AdminTmdbScreen(
    onBack: () -> Unit,
    viewModel: AdminTmdbViewModel = hiltViewModel(),
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

    AdminScaffold(
        title = stringResource(R.string.admin_tmdb_search),
        onBack = onBack,
        snackbarHost = snackbar,
    ) { padding ->
        Column(Modifier.padding(padding)) {
            OutlinedTextField(
                value = query,
                onValueChange = viewModel::setQuery,
                placeholder = { Text(stringResource(R.string.admin_tmdb_search)) },
                leadingIcon = { Icon(Icons.Filled.Search, null) },
                singleLine = true,
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(start = 16.dp, end = 16.dp, top = 16.dp),
            )

            // Searching by title does not always reach the show you meant, and
            // the id is in the address bar of the page you are looking at.
            Text(
                stringResource(R.string.admin_tmdb_hint),
                style = MaterialTheme.typography.labelSmall,
                color = TextMuted,
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp),
            )

            when (val current = results) {
                is UiState.Loading -> Box(Modifier.fillMaxSize(), Alignment.Center) {
                    CircularProgressIndicator()
                }

                is UiState.Error -> ErrorState(current.error)

                is UiState.Empty -> EmptyState(
                    if (query.length < 3) stringResource(R.string.admin_search_min)
                    else stringResource(R.string.discover_no_results),
                    Icons.Filled.Image,
                )

                is UiState.Success -> LazyColumn {
                    items(current.data, key = { it.tmdbId }) { result ->
                        ListItem(
                            headlineContent = {
                                Text(result.title, maxLines = 2, overflow = TextOverflow.Ellipsis)
                            },
                            supportingContent = {
                                Text(
                                    listOfNotNull(
                                        result.year.takeIf { it > 0 }?.toString(),
                                        result.original.takeIf {
                                            it.isNotBlank() && it != result.title
                                        },
                                        "★ %.1f".format(result.score).takeIf { result.score > 0 },
                                    ).joinToString(" · "),
                                    maxLines = 2,
                                    overflow = TextOverflow.Ellipsis,
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
                                if (importing == result.tmdbId) {
                                    CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp)
                                } else {
                                    TextButton(onClick = { viewModel.import(result.tmdbId) }) {
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
