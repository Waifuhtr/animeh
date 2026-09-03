package com.animeh.app.ui.screens.social

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Groups
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.data.remote.dto.RoomDto
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.components.ErrorState
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.SurfaceOverlay
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

/**
 * The rooms that are open, and a box to type a code into.
 *
 * The third way into a watch party, and the only one that does not depend on
 * something arriving: an invite link can be swallowed by the chat app it was
 * pasted into, and a notification can be refused, delayed or dropped by a
 * phone that was asleep. A list of what your friends have open is simply
 * there whenever it is looked at.
 *
 * Scoped to friends by the server, not filtered here. A directory of every
 * room on the site would be a list of strangers to walk in on.
 */
@Composable
fun RoomsScreen(
    signedIn: Boolean,
    onSignIn: () -> Unit,
    onOpenRoom: () -> Unit,
    viewModel: RoomsViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val code by viewModel.code.collectAsStateWithLifecycle()
    val available by viewModel.available.collectAsStateWithLifecycle()

    val snackbar = remember { SnackbarHostState() }
    val gone = stringResource(R.string.room_gone)

    LaunchedEffect(state.message) {
        state.message?.let {
            snackbar.showSnackbar(it)
            viewModel.dismissMessage()
        }
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.rooms_title)) },
                actions = {
                    if (signedIn) {
                        IconButton(onClick = viewModel::load) {
                            Icon(Icons.Filled.Refresh, stringResource(R.string.rooms_refresh))
                        }
                    }
                },
            )
        },
    ) { padding ->
        if (!signedIn) {
            EmptyState(
                stringResource(R.string.rooms_signed_out),
                icon = Icons.Filled.Groups,
                actionLabel = stringResource(R.string.auth_login),
                onAction = onSignIn,
                modifier = Modifier.padding(padding).fillMaxSize(),
            )
            return@Scaffold
        }

        Column(Modifier.padding(padding).fillMaxSize()) {
            // An install with no Firebase can list rooms but cannot live in
            // one, so it is said before somebody taps a card and finds a
            // screen that never fills in.
            if (!available) {
                Surface(color = SurfaceOverlay, modifier = Modifier.fillMaxWidth()) {
                    Text(
                        stringResource(R.string.room_unavailable),
                        style = MaterialTheme.typography.bodySmall,
                        color = TextSecondary,
                        modifier = Modifier.padding(horizontal = 16.dp, vertical = 10.dp),
                    )
                }
            }

            JoinByCode(
                code = code,
                busy = state.joining,
                onCode = viewModel::setCode,
                onJoin = { viewModel.join(code, gone, onOpenRoom) },
            )

            HorizontalDivider(color = SurfaceOverlay)

            when {
                state.loading && state.rooms.isEmpty() -> {
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator()
                    }
                }

                state.error != null && state.rooms.isEmpty() -> {
                    ErrorState(
                        error = requireNotNull(state.error),
                        onRetry = viewModel::load,
                        modifier = Modifier.fillMaxSize(),
                    )
                }

                state.rooms.isEmpty() -> {
                    EmptyState(
                        stringResource(R.string.rooms_none),
                        icon = Icons.Filled.Groups,
                        modifier = Modifier.fillMaxSize(),
                    )
                }

                else -> {
                    LazyColumn(
                        contentPadding = PaddingValues(16.dp),
                        verticalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        item {
                            Text(
                                stringResource(R.string.rooms_open),
                                style = MaterialTheme.typography.titleSmall,
                                color = TextSecondary,
                            )
                        }

                        items(state.rooms, key = { it.code }) { room ->
                            RoomCard(
                                room = room,
                                onClick = { viewModel.join(room.code, gone, onOpenRoom) },
                            )
                        }
                    }
                }
            }
        }
    }
}

/**
 * The code box.
 *
 * Above the list rather than behind a button: it is what somebody arrives on
 * this screen holding, having been read a code over the phone or sent a link
 * that would not open.
 */
@Composable
private fun JoinByCode(
    code: String,
    busy: Boolean,
    onCode: (String) -> Unit,
    onJoin: () -> Unit,
) {
    Column(Modifier.fillMaxWidth().padding(16.dp)) {
        Text(
            stringResource(R.string.rooms_join),
            style = MaterialTheme.typography.titleSmall,
        )

        Spacer(Modifier.height(4.dp))

        Text(
            stringResource(R.string.rooms_join_explainer),
            style = MaterialTheme.typography.bodySmall,
            color = TextMuted,
        )

        Spacer(Modifier.height(10.dp))

        Row(verticalAlignment = Alignment.CenterVertically) {
            OutlinedTextField(
                value = code,
                onValueChange = onCode,
                singleLine = true,
                placeholder = { Text(stringResource(R.string.rooms_join_hint)) },
                keyboardOptions = KeyboardOptions(
                    // No autocapitalisation: the alphabet a code is drawn from
                    // is lower case, and a capital first letter is the kind of
                    // thing a keyboard adds while nobody is looking.
                    capitalization = KeyboardCapitalization.None,
                    imeAction = ImeAction.Go,
                ),
                modifier = Modifier.weight(1f),
            )

            Spacer(Modifier.width(10.dp))

            Button(
                onClick = onJoin,
                enabled = code.isNotBlank() && !busy,
            ) {
                if (busy) {
                    CircularProgressIndicator(
                        strokeWidth = 2.dp,
                        modifier = Modifier.size(18.dp),
                        color = MaterialTheme.colorScheme.onPrimary,
                    )
                } else {
                    Text(stringResource(R.string.rooms_join))
                }
            }
        }
    }
}

/** One open room. */
@Composable
private fun RoomCard(room: RoomDto, onClick: () -> Unit) {
    Surface(
        color = SurfaceCard,
        shape = RoundedCornerShape(14.dp),
        modifier = Modifier.fillMaxWidth().clickable(onClick = onClick),
    ) {
        Row(
            Modifier.padding(10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            AsyncImage(
                model = room.work?.posterUrl,
                contentDescription = room.work?.title,
                contentScale = ContentScale.Crop,
                modifier = Modifier
                    .size(width = 48.dp, height = 68.dp)
                    .clip(RoundedCornerShape(8.dp))
                    .background(SurfaceOverlay),
            )

            Spacer(Modifier.width(12.dp))

            Column(Modifier.weight(1f)) {
                Text(
                    room.work?.title.orEmpty().ifBlank { stringResource(R.string.room_title) },
                    style = MaterialTheme.typography.titleSmall,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )

                Spacer(Modifier.height(2.dp))

                Text(
                    if (room.mine) {
                        stringResource(R.string.rooms_mine)
                    } else {
                        stringResource(
                            R.string.rooms_host,
                            room.host.displayName.ifBlank { room.host.username },
                        )
                    },
                    style = MaterialTheme.typography.bodySmall,
                    color = TextSecondary,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )

                Spacer(Modifier.height(2.dp))

                Text(
                    stringResource(R.string.rooms_people, room.members),
                    style = MaterialTheme.typography.labelSmall,
                    color = TextMuted,
                )
            }
        }
    }
}
