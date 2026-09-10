package com.animeh.app.ui.screens.admin

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
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
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.FolderZip
import androidx.compose.material.icons.filled.MenuBook
import androidx.compose.material.icons.filled.PhotoLibrary
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.theme.StatusError
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

/**
 * The chapters of one manga.
 *
 * Deliberately not the episode screen: a chapter has a number that may be
 * 10.5, no runtime, no intro markers and no video — and every field the
 * episode form carries for those is a field somebody has to skip past.
 */
@Composable
fun AdminChaptersScreen(
    onBack: () -> Unit,
    onPages: (chapterId: Long) -> Unit,
    viewModel: AdminChaptersViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val message by viewModel.message.collectAsStateWithLifecycle()

    var editing by remember { mutableStateOf<ChapterDraft?>(null) }
    var confirmDelete by remember { mutableStateOf<Long?>(null) }

    AdminScaffold(
        title = state.title.ifBlank { "Bölümler" },
        onBack = onBack,
        actions = {
            IconButton(onClick = { editing = ChapterDraft(number = state.nextNumber()) }) {
                Icon(Icons.Filled.Add, "Bölüm ekle")
            }
        },
    ) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {
            if (message != null) {
                Text(
                    message.orEmpty(),
                    style = MaterialTheme.typography.labelMedium,
                    color = if (state.lastError.isBlank()) TextSecondary else StatusError,
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp),
                )
            }

            if (state.loading) {
                Box(Modifier.fillMaxSize(), Alignment.Center) { CircularProgressIndicator() }
                return@Column
            }

            if (state.chapters.isEmpty()) {
                EmptyState("Bu mangada henüz bölüm yok.", Icons.Filled.MenuBook)
                return@Column
            }

            LazyColumn {
                items(state.chapters, key = { it.id }) { chapter ->
                    ListItem(
                        headlineContent = {
                            Text(
                                chapter.label,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                                fontWeight = FontWeight.Medium,
                            )
                        },
                        supportingContent = {
                            Text(
                                if (chapter.pageCount > 0) {
                                    "${chapter.pageCount} sayfa"
                                } else {
                                    "Sayfa yok"
                                },
                                color = if (chapter.pageCount > 0) TextSecondary else TextMuted,
                            )
                        },
                        trailingContent = {
                            Row {
                                IconButton(onClick = { onPages(chapter.id) }) {
                                    Icon(Icons.Filled.PhotoLibrary, "Sayfalar")
                                }
                                IconButton(onClick = { confirmDelete = chapter.id }) {
                                    Icon(Icons.Filled.Delete, "Sil", tint = StatusError)
                                }
                            }
                        },
                        modifier = Modifier.clickable {
                            editing = ChapterDraft(chapter.id, chapter.number, chapter.title)
                        },
                    )
                }
            }
        }
    }

    editing?.let { draft ->
        ChapterDialog(
            draft = draft,
            saving = state.saving,
            onDismiss = { editing = null },
            onSave = { number, title ->
                viewModel.save(draft.id, number, title)
                editing = null
            },
        )
    }

    confirmDelete?.let { id ->
        AlertDialog(
            onDismissRequest = { confirmDelete = null },
            title = { Text("Bölüm silinsin mi?") },
            text = { Text("Sayfa kayıtları da silinir. Kovadaki görseller yerinde kalır.") },
            confirmButton = {
                TextButton(onClick = {
                    viewModel.delete(id)
                    confirmDelete = null
                }) { Text("Sil", color = StatusError) }
            },
            dismissButton = {
                TextButton(onClick = { confirmDelete = null }) { Text("Vazgeç") }
            },
        )
    }
}

/** What the dialog is editing: a new chapter, or one that already exists. */
data class ChapterDraft(
    val id: Long = 0,
    val number: String = "1",
    val title: String = "",
)

@Composable
private fun ChapterDialog(
    draft: ChapterDraft,
    saving: Boolean,
    onDismiss: () -> Unit,
    onSave: (number: String, title: String) -> Unit,
) {
    var number by remember(draft) { mutableStateOf(draft.number) }
    var title by remember(draft) { mutableStateOf(draft.title) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(if (draft.id > 0) "Bölümü düzenle" else "Bölüm ekle") },
        text = {
            Column {
                OutlinedTextField(
                    value = number,
                    onValueChange = { number = it },
                    label = { Text("Bölüm numarası") },
                    // Not an integer field: 10.5 is a chapter of its own.
                    supportingText = { Text("Ondalık olabilir: 10.5") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(10.dp))
                OutlinedTextField(
                    value = title,
                    onValueChange = { title = it },
                    label = { Text("Başlık (isteğe bağlı)") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = { onSave(number, title) },
                enabled = !saving && number.isNotBlank(),
            ) { Text("Kaydet") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Vazgeç") } },
    )
}

/**
 * The pages of one chapter: what is there, and how to put more there.
 *
 * Two ways in, because both are how a chapter actually arrives — a folder of
 * images picked from the phone, or the zip it was downloaded as. Neither
 * decides the reading order: the server sorts by the file names, compared as
 * numbers, so `1.jpg … 24.jpg` reads in that order whatever the picker
 * handed over.
 */
@Composable
fun AdminChapterPagesScreen(
    onBack: () -> Unit,
    viewModel: AdminChapterPagesViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val message by viewModel.message.collectAsStateWithLifecycle()

    val images = rememberLauncherForActivityResult(
        ActivityResultContracts.OpenMultipleDocuments()
    ) { uris: List<Uri> -> if (uris.isNotEmpty()) viewModel.upload(uris) }

    val archive = rememberLauncherForActivityResult(
        ActivityResultContracts.OpenDocument()
    ) { uri: Uri? -> uri?.let { viewModel.upload(listOf(it)) } }

    var confirmClear by remember { mutableStateOf(false) }

    AdminScaffold(title = "Sayfalar", onBack = onBack) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {
            Card(Modifier.fillMaxWidth().padding(16.dp)) {
                Column(Modifier.padding(16.dp)) {
                    Text(
                        "${state.pages} sayfa",
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.SemiBold,
                    )

                    Spacer(Modifier.height(4.dp))

                    Text(
                        "Sıralama dosya adına göre: 1, 2, 10 — seçim sırasına göre değil.",
                        style = MaterialTheme.typography.labelSmall,
                        color = TextMuted,
                    )

                    if (state.uploading) {
                        Spacer(Modifier.height(12.dp))
                        LinearProgressIndicator(Modifier.fillMaxWidth())
                    }

                    Spacer(Modifier.height(12.dp))

                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Button(
                            onClick = { images.launch(arrayOf("image/*")) },
                            enabled = !state.uploading,
                            modifier = Modifier.weight(1f),
                        ) {
                            Icon(Icons.Filled.PhotoLibrary, null, modifier = Modifier.size(18.dp))
                            Spacer(Modifier.width(6.dp))
                            Text("Görseller")
                        }

                        OutlinedButton(
                            onClick = { archive.launch(arrayOf("application/zip", "application/x-zip-compressed", "*/*")) },
                            enabled = !state.uploading,
                            modifier = Modifier.weight(1f),
                        ) {
                            Icon(Icons.Filled.FolderZip, null, modifier = Modifier.size(18.dp))
                            Spacer(Modifier.width(6.dp))
                            Text("Zip")
                        }
                    }

                    if (state.pages > 0) {
                        Spacer(Modifier.height(8.dp))
                        TextButton(
                            onClick = { confirmClear = true },
                            enabled = !state.uploading,
                        ) { Text("Sayfaları temizle", color = StatusError) }
                    }
                }
            }

            if (message != null) {
                Text(
                    message.orEmpty(),
                    style = MaterialTheme.typography.labelMedium,
                    color = if (state.failed.isEmpty()) TextSecondary else StatusError,
                    modifier = Modifier.padding(horizontal = 16.dp),
                )
            }

            if (state.failed.isNotEmpty()) {
                LazyColumn(Modifier.padding(top = 8.dp)) {
                    items(state.failed, key = { it.name }) { failure ->
                        ListItem(
                            headlineContent = { Text(failure.name, maxLines = 1) },
                            supportingContent = { Text(failure.message, color = StatusError) },
                        )
                    }
                }
            }
        }
    }

    if (confirmClear) {
        AlertDialog(
            onDismissRequest = { confirmClear = false },
            title = { Text("Sayfalar silinsin mi?") },
            text = { Text("Bu bölümün sayfa kayıtları silinir. Kovadaki görseller yerinde kalır.") },
            confirmButton = {
                TextButton(onClick = {
                    viewModel.clear()
                    confirmClear = false
                }) { Text("Sil", color = StatusError) }
            },
            dismissButton = { TextButton(onClick = { confirmClear = false }) { Text("Vazgeç") } },
        )
    }
}
