package com.animeh.app.ui.screens.social

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.People
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.core.UiState
import com.animeh.app.data.remote.dto.UserDto
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.components.ErrorState
import com.animeh.app.ui.theme.SurfaceOverlay
import com.animeh.app.ui.theme.TextMuted

/**
 * Who you can invite to a room.
 *
 * Added by username or address rather than by browsing a directory of every
 * account on the site: a friend is somebody you already know, and a browsable
 * user list is a different feature with different consequences.
 */
@Composable
fun FriendsScreen(
    onBack: () -> Unit,
    onOpenProfile: (Long) -> Unit,
    viewModel: FriendsViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val handle by viewModel.handle.collectAsStateWithLifecycle()
    val busy by viewModel.busy.collectAsStateWithLifecycle()
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
                title = { Text(stringResource(R.string.friends_title)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back))
                    }
                },
            )
        },
    ) { padding ->
        Column(Modifier.padding(padding)) {
            Row(
                Modifier.fillMaxWidth().padding(16.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                OutlinedTextField(
                    value = handle,
                    onValueChange = viewModel::setHandle,
                    label = { Text(stringResource(R.string.friends_add_hint)) },
                    singleLine = true,
                    modifier = Modifier.weight(1f),
                )

                Spacer(Modifier.width(10.dp))

                Button(
                    onClick = { viewModel.add(sent) },
                    enabled = !busy && handle.isNotBlank(),
                    modifier = Modifier.height(52.dp),
                ) {
                    if (busy) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                    else Text(stringResource(R.string.friends_add))
                }
            }

            when (val current = state) {
                is UiState.Loading -> Box(Modifier.fillMaxSize(), Alignment.Center) {
                    CircularProgressIndicator()
                }

                is UiState.Error -> ErrorState(current.error, onRetry = viewModel::load)

                is UiState.Empty -> EmptyState(stringResource(R.string.friends_none), Icons.Filled.People)

                is UiState.Success -> {
                    val data = current.data

                    LazyColumn {
                        if (data.incoming.isNotEmpty()) {
                            item { SectionLabel(stringResource(R.string.friends_incoming)) }

                            items(data.incoming, key = { "in-${it.id}" }) { person ->
                                PersonRow(person, onClick = { onOpenProfile(person.id) }) {
                                    TextButton(onClick = { viewModel.accept(person.id) }) {
                                        Text(stringResource(R.string.friends_accept))
                                    }
                                    TextButton(onClick = { viewModel.remove(person.id) }) {
                                        Text(stringResource(R.string.friends_decline))
                                    }
                                }
                            }
                        }

                        if (data.outgoing.isNotEmpty()) {
                            item { SectionLabel(stringResource(R.string.friends_outgoing)) }

                            items(data.outgoing, key = { "out-${it.id}" }) { person ->
                                PersonRow(person, onClick = { onOpenProfile(person.id) }) {
                                    TextButton(onClick = { viewModel.remove(person.id) }) {
                                        Text(stringResource(R.string.cancel))
                                    }
                                }
                            }
                        }

                        if (data.friends.isEmpty()) {
                            item {
                                EmptyState(stringResource(R.string.friends_none), Icons.Filled.People)
                            }
                        } else {
                            item { SectionLabel(stringResource(R.string.friends_title)) }

                            items(data.friends, key = { it.id }) { person ->
                                PersonRow(person, onClick = { onOpenProfile(person.id) }) {
                                    TextButton(onClick = { viewModel.remove(person.id) }) {
                                        Text(stringResource(R.string.friends_remove))
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
private fun SectionLabel(text: String) {
    Text(
        text,
        style = MaterialTheme.typography.titleSmall,
        color = TextMuted,
        modifier = Modifier.padding(start = 16.dp, top = 16.dp, bottom = 4.dp),
    )
}

@Composable
private fun PersonRow(
    person: UserDto,
    onClick: () -> Unit,
    actions: @Composable RowScope.() -> Unit,
) {
    ListItem(
        headlineContent = { Text(person.displayName.ifBlank { person.username }) },
        supportingContent = { Text("@${person.username}") },
        leadingContent = {
            AsyncImage(
                model = person.avatar,
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.size(40.dp).clip(CircleShape).background(SurfaceOverlay),
            )
        },
        trailingContent = { Row(verticalAlignment = Alignment.CenterVertically, content = actions) },
        modifier = Modifier.clickable(onClick = onClick),
    )
}
