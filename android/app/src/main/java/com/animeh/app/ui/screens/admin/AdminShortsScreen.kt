package com.animeh.app.ui.screens.admin

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.DeleteOutline
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.core.AppResult
import com.animeh.app.core.UiState
import com.animeh.app.core.dataOrNull
import com.animeh.app.core.explain
import com.animeh.app.data.remote.dto.ShortDto
import com.animeh.app.data.repository.AdminRepository
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.components.ErrorState
import com.animeh.app.ui.theme.StatusError
import com.animeh.app.ui.theme.SurfaceOverlay
import com.animeh.app.ui.theme.TextSecondary
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

/**
 * AnimehTok, from the moderation side.
 *
 * Every video including the unpublished ones, newest first, each with the one
 * action a moderator needs. Deleting here removes the row, the video and its
 * cover from the bucket together: a takedown that leaves the file reachable is
 * not a takedown.
 */
@HiltViewModel
class AdminShortsViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<List<ShortDto>>>(UiState.Loading)
    val state: StateFlow<UiState<List<ShortDto>>> = _state.asStateFlow()

    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    init {
        load()
    }

    fun load() {
        _state.value = UiState.Loading

        viewModelScope.launch {
            when (val result = repository.shorts()) {
                is AppResult.Success -> _state.value =
                    if (result.data.items.isEmpty()) UiState.Empty
                    else UiState.Success(result.data.items)

                is AppResult.Failure -> _state.value = UiState.Error(result.error)
            }
        }
    }

    fun delete(short: ShortDto) {
        viewModelScope.launch {
            when (val result = repository.deleteShort(short.id)) {
                is AppResult.Success -> {
                    val left = _state.value.dataOrNull.orEmpty()
                        .filterNot { it.id == short.id }

                    _state.value = if (left.isEmpty()) UiState.Empty else UiState.Success(left)
                }

                is AppResult.Failure -> _message.value = result.error.explain()
            }
        }
    }

    fun messageShown() {
        _message.value = null
    }
}

@Composable
fun AdminShortsScreen(
    onBack: () -> Unit,
    viewModel: AdminShortsViewModel = hiltViewModel(),
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

    var confirmDelete by remember { mutableStateOf<ShortDto?>(null) }

    AdminScaffold(
        title = stringResource(R.string.admin_shorts),
        onBack = onBack,
        snackbarHost = snackbar,
    ) { padding ->
        Box(Modifier.padding(padding)) {
            when (val current = state) {
                is UiState.Loading -> Box(Modifier.fillMaxSize(), Alignment.Center) {
                    CircularProgressIndicator()
                }

                is UiState.Error -> ErrorState(
                    error = current.error,
                    onRetry = viewModel::load,
                    modifier = Modifier.fillMaxSize(),
                )

                is UiState.Empty -> EmptyState(
                    message = stringResource(R.string.admin_shorts_empty),
                    modifier = Modifier.fillMaxSize(),
                )

                is UiState.Success -> LazyColumn {
                    items(current.data, key = { it.id }) { short ->
                        ShortRow(short = short, onDelete = { confirmDelete = short })
                        HorizontalDivider()
                    }
                }
            }
        }
    }

    confirmDelete?.let { short ->
        AlertDialog(
            onDismissRequest = { confirmDelete = null },
            title = { Text(stringResource(R.string.tok_delete)) },
            text = { Text(stringResource(R.string.tok_delete_confirm)) },
            confirmButton = {
                TextButton(
                    onClick = {
                        confirmDelete = null
                        viewModel.delete(short)
                    }
                ) { Text(stringResource(R.string.delete), color = StatusError) }
            },
            dismissButton = {
                TextButton(onClick = { confirmDelete = null }) { Text(stringResource(R.string.cancel)) }
            },
        )
    }
}

@Composable
private fun ShortRow(short: ShortDto, onDelete: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier.size(width = 44.dp, height = 68.dp)
                .clip(RoundedCornerShape(8.dp))
                .background(SurfaceOverlay),
            Alignment.Center,
        ) {
            AsyncImage(
                model = short.coverUrl,
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize(),
            )

            if (!short.published) {
                Icon(
                    Icons.Filled.VisibilityOff,
                    contentDescription = null,
                    tint = StatusError,
                    modifier = Modifier.size(18.dp),
                )
            }
        }

        Spacer(Modifier.width(12.dp))

        Column(Modifier.weight(1f)) {
            Text(
                short.description.take(70).ifBlank { short.slug },
                style = MaterialTheme.typography.bodyMedium,
                maxLines = 2,
            )
            Text(
                "@${short.creator.username} · ${short.viewCount} · ${short.likeCount} · ${short.createdAt.take(10)}",
                style = MaterialTheme.typography.labelSmall,
                color = TextSecondary,
            )
        }

        IconButton(onClick = onDelete) {
            Icon(Icons.Filled.DeleteOutline, stringResource(R.string.delete), tint = StatusError)
        }
    }
}
