package com.animeh.app.ui.screens.social

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.PersonAdd
import androidx.compose.material.icons.filled.Send
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.ui.components.AvatarWithFrame
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.screens.admin.AdminScaffold
import com.animeh.app.ui.theme.StatusError
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

/**
 * Hand a manga or an anime to a friend.
 *
 * It arrives as a notification that opens the work, rather than a message in
 * a list nobody opens — which is the only version of this that gets read.
 *
 * Friends only, and the server enforces it rather than trusting this screen:
 * anyone able to push a notification onto somebody's phone is a spam problem.
 */
@Composable
fun RecommendScreen(
    onBack: () -> Unit,
    viewModel: RecommendViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    // Closes itself once it has done its one job.
    LaunchedEffect(state.sent) {
        if (state.sent) onBack()
    }

    AdminScaffold(title = "Arkadaşına öner", onBack = onBack) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {
            if (state.loading) {
                Box(Modifier.fillMaxSize(), Alignment.Center) { CircularProgressIndicator() }
                return@Column
            }

            if (state.friends.isEmpty()) {
                EmptyState(
                    "Önerecek arkadaşın yok. Önce birini ekle.",
                    Icons.Filled.PersonAdd,
                )
                return@Column
            }

            OutlinedTextField(
                value = state.note,
                onValueChange = viewModel::setNote,
                label = { Text("Not (isteğe bağlı)") },
                placeholder = { Text("Bunu beğenirsin") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(16.dp),
            )

            state.error?.let {
                Text(
                    it,
                    style = MaterialTheme.typography.labelMedium,
                    color = StatusError,
                    modifier = Modifier.padding(horizontal = 16.dp),
                )
            }

            LazyColumn(Modifier.weight(1f)) {
                items(state.friends, key = { it.id }) { friend ->
                    val picked = friend.id in state.picked

                    ListItem(
                        headlineContent = { Text(friend.displayName.ifBlank { friend.username }) },
                        supportingContent = {
                            Text("@${friend.username}", color = TextMuted)
                        },
                        leadingContent = {
                            AvatarWithFrame(
                                avatarUrl = friend.avatarUrl,
                                frame = null,
                                size = 40.dp,
                            )
                        },
                        trailingContent = {
                            Checkbox(
                                checked = picked,
                                onCheckedChange = { viewModel.toggle(friend.id) },
                            )
                        },
                        modifier = Modifier.clickable { viewModel.toggle(friend.id) },
                    )
                }
            }

            Button(
                onClick = viewModel::send,
                enabled = state.picked.isNotEmpty() && !state.sending,
                modifier = Modifier.fillMaxWidth().padding(16.dp).height(52.dp),
            ) {
                if (state.sending) {
                    CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                } else {
                    Icon(Icons.Filled.Send, null, Modifier.size(18.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(
                        if (state.picked.size == 1) "Gönder" else "${state.picked.size} kişiye gönder",
                    )
                }
            }
        }
    }
}
