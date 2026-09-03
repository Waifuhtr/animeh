package com.animeh.app.ui.screens.profile

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.data.prefs.AuthState
import com.animeh.app.data.prefs.user
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

@Composable
fun ProfileScreen(
    authState: AuthState,
    onSignIn: () -> Unit,
    onSettings: () -> Unit,
    onChangePassword: () -> Unit,
    onFriends: () -> Unit = {},
    onPublicProfile: (Long) -> Unit = {},
    viewModel: ProfileViewModel = hiltViewModel(),
) {
    val user = authState.user

    if (user == null) {
        EmptyState(
            message = stringResource(R.string.auth_login_required),
            icon = Icons.Filled.Person,
            actionLabel = stringResource(R.string.auth_login),
            onAction = onSignIn,
            modifier = Modifier.fillMaxSize(),
        )
        return
    }

    val stats by viewModel.stats.collectAsStateWithLifecycle()
    val uploading by viewModel.uploadingAvatar.collectAsStateWithLifecycle()

    val pickImage = rememberLauncherForActivityResult(
        ActivityResultContracts.GetContent()
    ) { uri -> uri?.let(viewModel::uploadAvatar) }

    Column(
        Modifier
            .fillMaxSize()
            .statusBarsPadding()
            .verticalScroll(rememberScrollState()),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(20.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box {
                AsyncImage(
                    model = user.avatar,
                    contentDescription = stringResource(R.string.profile_change_photo),
                    contentScale = ContentScale.Crop,
                    modifier = Modifier
                        .size(72.dp)
                        .clip(CircleShape)
                        .background(SurfaceCard)
                        .clickable(enabled = !uploading) {
                            pickImage.launch("image/*")
                        },
                )

                // Over the picture rather than beside it: the picture is the
                // button, and a separate control would need explaining.
                if (uploading) {
                    CircularProgressIndicator(
                        Modifier.size(72.dp).padding(8.dp),
                        strokeWidth = 2.dp,
                    )
                } else {
                    Surface(
                        color = MaterialTheme.colorScheme.primary,
                        shape = CircleShape,
                        modifier = Modifier.align(Alignment.BottomEnd),
                    ) {
                        Icon(
                            Icons.Filled.PhotoCamera,
                            contentDescription = null,
                            tint = MaterialTheme.colorScheme.onPrimary,
                            modifier = Modifier.size(22.dp).padding(4.dp),
                        )
                    }
                }
            }

            Spacer(Modifier.width(16.dp))

            Column(Modifier.weight(1f)) {
                Text(
                    user.displayName.ifBlank { user.username },
                    style = MaterialTheme.typography.headlineSmall,
                )
                Text(user.email, style = MaterialTheme.typography.bodySmall, color = TextMuted)
                if (user.isAdmin) {
                    Spacer(Modifier.height(4.dp))
                    AssistChip(onClick = {}, label = { Text(stringResource(R.string.nav_admin)) })
                }
            }
        }

        stats?.let { current ->
            Row(
                Modifier.fillMaxWidth().padding(horizontal = 16.dp),
                horizontalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                StatCard(
                    value = current.episodesCompleted.toString(),
                    label = stringResource(R.string.profile_episodes_watched),
                    modifier = Modifier.weight(1f),
                )
                StatCard(
                    value = formatHours(current.secondsWatched),
                    label = stringResource(R.string.profile_time_watched),
                    modifier = Modifier.weight(1f),
                )
                StatCard(
                    value = current.worksCompleted.toString(),
                    label = stringResource(R.string.profile_series_completed),
                    modifier = Modifier.weight(1f),
                )
            }
        }

        Spacer(Modifier.height(20.dp))

        // The public half of the profile is a separate screen because it is
        // the one other people see: editing what is on it and reading it are
        // the same view, so what you set is exactly what they get.
        ListItem(
            headlineContent = { Text(stringResource(R.string.profile_public_view)) },
            leadingContent = { Icon(Icons.Filled.Person, null) },
            modifier = Modifier.clickable { onPublicProfile(user.id) },
        )

        ListItem(
            headlineContent = { Text(stringResource(R.string.friends_title)) },
            leadingContent = { Icon(Icons.Filled.People, null) },
            modifier = Modifier.clickable(onClick = onFriends),
        )

        ListItem(
            headlineContent = { Text(stringResource(R.string.profile_settings)) },
            leadingContent = { Icon(Icons.Filled.Settings, null) },
            modifier = Modifier.clickable(onClick = onSettings),
        )

        ListItem(
            headlineContent = { Text(stringResource(R.string.profile_change_password)) },
            leadingContent = { Icon(Icons.Filled.Lock, null) },
            modifier = Modifier.clickable(onClick = onChangePassword),
        )

        ListItem(
            headlineContent = {
                Text(stringResource(R.string.auth_logout), color = MaterialTheme.colorScheme.error)
            },
            leadingContent = {
                Icon(Icons.AutoMirrored.Filled.Logout, null, tint = MaterialTheme.colorScheme.error)
            },
            modifier = Modifier.clickable(onClick = viewModel::logout),
        )

        Spacer(Modifier.height(32.dp))
    }
}

@Composable
private fun StatCard(value: String, label: String, modifier: Modifier = Modifier) {
    Surface(
        modifier = modifier,
        shape = MaterialTheme.shapes.medium,
        color = SurfaceCard,
    ) {
        Column(
            Modifier.padding(vertical = 16.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(value, style = MaterialTheme.typography.headlineSmall)
            Spacer(Modifier.height(2.dp))
            Text(
                label,
                style = MaterialTheme.typography.labelSmall,
                color = TextSecondary,
                textAlign = TextAlign.Center,
            )
        }
    }
}

/**
 * A watch total someone can read at a glance.
 *
 * Hours and minutes together once past an hour: "37s" hides whether that is
 * nearly 38 hours or barely 37, and this number is the one people compare.
 */
private fun formatHours(seconds: Long): String {
    val hours = seconds / 3600
    val minutes = (seconds % 3600) / 60

    return when {
        hours > 0 && minutes > 0 -> "${hours}s ${minutes}dk"
        hours > 0 -> "${hours}s"
        else -> "${minutes}dk"
    }
}
