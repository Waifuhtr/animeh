package com.animeh.app.ui.screens.social

import android.content.Intent
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.ExitToApp
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.PersonAdd
import androidx.compose.material.icons.filled.Share
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.SurfaceOverlay
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

/**
 * The sociable half of a watch party: who is here, and what they are saying.
 *
 * The playhead is not on this screen. It belongs to the player, which is where
 * the video is, and where a pause has to happen the moment it is tapped. This
 * is what you open beside it — or over it, when somebody wants to type.
 */
@Composable
fun RoomScreen(
    onBack: () -> Unit,
    onPlay: (Long) -> Unit,
    viewModel: RoomViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val draft by viewModel.draft.collectAsStateWithLifecycle()

    val context = LocalContext.current
    val snackbar = remember { SnackbarHostState() }
    var inviting by remember { mutableStateOf(false) }

    LaunchedEffect(state.message) {
        state.message?.let {
            snackbar.showSnackbar(it)
            viewModel.dismissMessage()
        }
    }

    val room = state.room

    if (room == null) {
        Scaffold(topBar = { RoomBar(title = "", onBack = onBack) }) { padding ->
            EmptyState(
                stringResource(R.string.room_gone),
                modifier = Modifier.padding(padding).fillMaxSize(),
            )
        }
        return
    }

    if (inviting) {
        InviteSheet(
            friends = state.friends,
            onDismiss = { inviting = false },
            onInvite = { ids ->
                inviting = false
                viewModel.invite(ids) { invited, notified ->
                    when {
                        // Everyone reached: the ordinary case, said plainly.
                        notified >= invited -> context.getString(R.string.room_invited, invited)

                        // Nobody reached. Almost always a server with no
                        // Firebase service account, which is worth naming —
                        // "davet gönderildi" while nothing arrives is the
                        // worst of the three things this could say.
                        notified == 0 -> context.getString(R.string.room_invited_silent, invited)

                        else -> context.getString(R.string.room_invited_partial, invited, notified)
                    }
                }
            },
        )
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        topBar = {
            RoomBar(
                title = room.work?.title.orEmpty(),
                onBack = onBack,
                actions = {
                    IconButton(onClick = { inviting = true }) {
                        Icon(Icons.Filled.PersonAdd, stringResource(R.string.room_invite_friends))
                    }

                    IconButton(
                        onClick = {
                            // The system sheet rather than a copy button: the
                            // link is going to a chat app, and this is the way
                            // to put it there in one tap.
                            val share = Intent(Intent.ACTION_SEND).apply {
                                type = "text/plain"
                                putExtra(Intent.EXTRA_TEXT, room.link)
                            }
                            context.startActivity(Intent.createChooser(share, null))
                        }
                    ) {
                        Icon(Icons.Filled.Share, stringResource(R.string.room_invite_link))
                    }

                    // Leaving is now the only way out of a room: backing out
                    // of this screen keeps you in it, because a watch party
                    // outlives the screen you opened it from.
                    IconButton(
                        onClick = {
                            viewModel.leave()
                            onBack()
                        }
                    ) {
                        Icon(
                            Icons.AutoMirrored.Filled.ExitToApp,
                            stringResource(R.string.room_leave),
                        )
                    }
                },
            )
        },
    ) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {
            // Who is here, and a way back into the video.
            Surface(color = SurfaceCard) {
                Column(Modifier.fillMaxWidth().padding(12.dp)) {
                    // The code as well as the count: it is what somebody
                    // types into "Odaya katıl" when a link did not survive
                    // being pasted into a chat app.
                    Text(
                        stringResource(R.string.room_members, state.members.size) +
                            "  ·  " + stringResource(R.string.rooms_code, room.code),
                        style = MaterialTheme.typography.labelMedium,
                        color = TextMuted,
                    )

                    Spacer(Modifier.height(8.dp))

                    LazyRow(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        items(state.members, key = { it.uid }) { member ->
                            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                                AsyncImage(
                                    model = member.avatar,
                                    contentDescription = member.name,
                                    contentScale = ContentScale.Crop,
                                    modifier = Modifier
                                        .size(40.dp)
                                        .clip(CircleShape)
                                        .background(SurfaceOverlay),
                                )
                                Spacer(Modifier.height(4.dp))
                                Text(
                                    member.name,
                                    style = MaterialTheme.typography.labelSmall,
                                    maxLines = 1,
                                    overflow = TextOverflow.Ellipsis,
                                    modifier = Modifier.width(52.dp),
                                )
                            }
                        }
                    }

                    room.episode?.let { episode ->
                        Spacer(Modifier.height(10.dp))
                        Button(
                            onClick = { onPlay(episode.id) },
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            // Which episode, not just "İzle". A room is opened
                            // on one episode and lived in for an evening, and
                            // the one thing everybody in it needs to agree on
                            // is which episode that is. The series is already
                            // in the bar above.
                            Text(stringResource(R.string.room_play_episode, episode.number))
                        }
                    }
                }
            }

            val listState = rememberLazyListState()

            // Follow the conversation: a chat that does not scroll itself is
            // a chat you have to drag every time somebody speaks.
            LaunchedEffect(state.chat.size) {
                if (state.chat.isNotEmpty()) {
                    listState.animateScrollToItem(state.chat.lastIndex)
                }
            }

            LazyColumn(
                state = listState,
                modifier = Modifier.weight(1f).fillMaxWidth(),
                contentPadding = PaddingValues(12.dp),
                verticalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                items(state.chat, key = { it.id }) { message ->
                    Row(
                        Modifier.fillMaxWidth(),
                        horizontalArrangement = if (message.mine) Arrangement.End else Arrangement.Start,
                    ) {
                        Surface(
                            color = if (message.mine) {
                                MaterialTheme.colorScheme.primary
                            } else {
                                SurfaceCard
                            },
                            shape = RoundedCornerShape(14.dp),
                            modifier = Modifier.widthIn(max = 280.dp),
                        ) {
                            Column(Modifier.padding(horizontal = 12.dp, vertical = 8.dp)) {
                                if (!message.mine) {
                                    Text(
                                        message.name,
                                        style = MaterialTheme.typography.labelSmall,
                                        color = TextSecondary,
                                    )
                                    Spacer(Modifier.height(2.dp))
                                }

                                Text(
                                    message.text,
                                    style = MaterialTheme.typography.bodyMedium,
                                    color = if (message.mine) {
                                        MaterialTheme.colorScheme.onPrimary
                                    } else {
                                        MaterialTheme.colorScheme.onSurface
                                    },
                                )
                            }
                        }
                    }
                }
            }

            Row(
                Modifier
                    .fillMaxWidth()
                    .navigationBarsPadding()
                    .imePadding()
                    .padding(horizontal = 12.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                OutlinedTextField(
                    value = draft,
                    onValueChange = viewModel::setDraft,
                    placeholder = { Text(stringResource(R.string.room_chat_hint)) },
                    maxLines = 3,
                    modifier = Modifier.weight(1f),
                )

                Spacer(Modifier.width(8.dp))

                FilledIconButton(
                    onClick = viewModel::send,
                    enabled = draft.isNotBlank(),
                ) {
                    Icon(Icons.AutoMirrored.Filled.Send, stringResource(R.string.room_send))
                }
            }
        }
    }
}

@Composable
private fun RoomBar(
    title: String,
    onBack: () -> Unit,
    actions: @Composable RowScope.() -> Unit = {},
) {
    TopAppBar(
        title = {
            Text(
                title.ifBlank { stringResource(R.string.room_title) },
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        },
        navigationIcon = {
            IconButton(onClick = onBack) {
                Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back))
            }
        },
        actions = actions,
    )
}

/**
 * Pick friends to invite.
 *
 * Only friends: the server refuses anyone else, so offering a wider list would
 * be offering an action that fails.
 */
@Composable
private fun InviteSheet(
    friends: List<com.animeh.app.data.remote.dto.UserDto>,
    onDismiss: () -> Unit,
    onInvite: (List<Long>) -> Unit,
) {
    val selected = remember { mutableStateListOf<Long>() }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.room_invite_friends)) },
        text = {
            if (friends.isEmpty()) {
                Text(stringResource(R.string.friends_none))
            } else {
                LazyColumn(Modifier.heightIn(max = 320.dp)) {
                    items(friends, key = { it.id }) { friend ->
                        Row(
                            Modifier.fillMaxWidth().padding(vertical = 4.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Checkbox(
                                checked = friend.id in selected,
                                onCheckedChange = { checked ->
                                    if (checked) selected.add(friend.id) else selected.remove(friend.id)
                                },
                            )
                            Spacer(Modifier.width(6.dp))
                            Text(friend.displayName.ifBlank { friend.username })
                        }
                    }
                }
            }
        },
        confirmButton = {
            TextButton(
                onClick = { onInvite(selected.toList()) },
                enabled = selected.isNotEmpty(),
            ) {
                Text(stringResource(R.string.room_invite_friends))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) }
        },
    )
}
