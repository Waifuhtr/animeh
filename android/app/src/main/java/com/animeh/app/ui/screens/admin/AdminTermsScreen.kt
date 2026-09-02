package com.animeh.app.ui.screens.admin

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Translate
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import com.animeh.app.R
import com.animeh.app.core.AppResult
import com.animeh.app.core.UiState
import com.animeh.app.data.remote.dto.AdminTermDto
import com.animeh.app.data.repository.AdminRepository
import com.animeh.app.data.repository.CommunityRepository
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.components.ErrorState
import com.animeh.app.ui.theme.TextMuted
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

/** The vocabularies that can be relabelled, in the order they matter. */
private val TERM_KINDS = listOf(
    "genre" to R.string.admin_terms_kind_genre,
    "status" to R.string.admin_terms_kind_status,
    "format" to R.string.admin_terms_kind_format,
    "season" to R.string.admin_terms_kind_season,
)

@HiltViewModel
class AdminTermsViewModel @Inject constructor(
    private val repository: AdminRepository,
    private val community: CommunityRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<List<AdminTermDto>>>(UiState.Loading)
    val state: StateFlow<UiState<List<AdminTermDto>>> = _state.asStateFlow()

    private val _kind = MutableStateFlow("genre")
    val kind: StateFlow<String> = _kind.asStateFlow()

    init {
        load()
    }

    fun setKind(value: String) {
        _kind.value = value
    }

    fun load() {
        _state.value = UiState.Loading

        viewModelScope.launch {
            _state.value = when (val result = repository.terms()) {
                is AppResult.Success ->
                    if (result.data.isEmpty()) UiState.Empty else UiState.Success(result.data)
                is AppResult.Failure -> UiState.Error(result.error)
            }
        }
    }

    /**
     * Save one label.
     *
     * The row is updated in place rather than reloading the list: reloading
     * would move the field out from under whoever is still typing in the next
     * one. The shared label map is refreshed too, so the rest of the app shows
     * the new wording without a restart.
     */
    fun save(term: AdminTermDto, display: String) {
        viewModelScope.launch {
            if (repository.saveTerm(term.kind, term.source, display) !is AppResult.Success) return@launch

            _state.value = (_state.value as? UiState.Success)?.let { current ->
                UiState.Success(
                    current.data.map {
                        if (it.kind == term.kind && it.source == term.source) {
                            it.copy(display = display.trim())
                        } else {
                            it
                        }
                    }
                )
            } ?: _state.value

            community.refreshTerms()
        }
    }
}

/**
 * Rename what the catalog's own vocabulary is called.
 *
 * The imported value stays on the left and is never editable: it is the key
 * every filter and stored row matches on. Only the label beside it changes.
 */
@Composable
fun AdminTermsScreen(onBack: () -> Unit, viewModel: AdminTermsViewModel = hiltViewModel()) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val kind by viewModel.kind.collectAsStateWithLifecycle()

    AdminScaffold(stringResource(R.string.admin_terms), onBack) { padding ->
        Column(Modifier.padding(padding)) {
            LazyRow(
                contentPadding = PaddingValues(horizontal = 16.dp, vertical = 8.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                items(TERM_KINDS) { (value, label) ->
                    FilterChip(
                        selected = kind == value,
                        onClick = { viewModel.setKind(value) },
                        label = { Text(stringResource(label)) },
                    )
                }
            }

            Text(
                stringResource(R.string.admin_terms_hint),
                style = MaterialTheme.typography.bodySmall,
                color = TextMuted,
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 4.dp),
            )

            when (val current = state) {
                is UiState.Loading -> Box(Modifier.fillMaxSize(), Alignment.Center) {
                    CircularProgressIndicator()
                }

                is UiState.Error -> ErrorState(current.error, onRetry = viewModel::load)

                is UiState.Empty -> EmptyState("Katalogda henüz terim yok.", Icons.Filled.Translate)

                is UiState.Success -> {
                    val rows = current.data.filter { it.kind == kind }

                    if (rows.isEmpty()) {
                        EmptyState("Bu türde değer yok.", Icons.Filled.Translate)
                    } else {
                        LazyColumn(contentPadding = PaddingValues(bottom = 24.dp)) {
                            items(rows, key = { "${it.kind}:${it.source}" }) { term ->
                                TermRow(term = term, onSave = { viewModel.save(term, it) })
                                HorizontalDivider()
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun TermRow(term: AdminTermDto, onSave: (String) -> Unit) {
    // Keyed on the row so recycling onto a different term does not carry the
    // previous one's half-typed label across.
    var display by remember(term.kind, term.source) { mutableStateOf(term.display) }

    Row(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            term.source,
            style = MaterialTheme.typography.bodyMedium,
            fontFamily = FontFamily.Monospace,
            color = TextMuted,
            modifier = Modifier.weight(0.9f),
        )

        Spacer(Modifier.width(10.dp))

        OutlinedTextField(
            value = display,
            onValueChange = { display = it },
            placeholder = { Text(term.source) },
            singleLine = true,
            modifier = Modifier.weight(1.3f),
        )

        IconButton(
            onClick = { onSave(display) },
            enabled = display.trim() != term.display,
        ) {
            Icon(Icons.Filled.Check, stringResource(R.string.save))
        }
    }
}
