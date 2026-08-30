package com.animeh.app.ui.screens.profile

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
            AsyncImage(
                model = user.avatar,
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier
                    .size(72.dp)
                    .clip(CircleShape)
                    .background(SurfaceCard),
            )

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
                    value = current.worksStarted.toString(),
                    label = stringResource(R.string.profile_series_started),
                    modifier = Modifier.weight(1f),
                )
            }
        }

        Spacer(Modifier.height(20.dp))

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

private fun formatHours(seconds: Long): String {
    val hours = seconds / 3600
    return if (hours > 0) "${hours}s" else "${seconds / 60}dk"
}
