package com.animeh.app.ui.screens.admin

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Stars
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.R
import com.animeh.app.core.UiState
import com.animeh.app.data.remote.dto.FrameDto
import com.animeh.app.data.remote.dto.UserDto
import com.animeh.app.ui.components.AvatarWithFrame
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.components.ErrorState
import com.animeh.app.ui.theme.AccentBright
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary
import com.animeh.app.ui.theme.rarityColor
import com.animeh.app.ui.theme.rarityLabel

/**
 * The shop's stock room.
 *
 * Uploading a frame here is the whole of adding one: the file goes into the
 * plugin's own uploads folder, the row goes into the catalogue, and every
 * phone sees it on its next look at the shop. No build, no release, nothing
 * for anybody to install.
 */
@Composable
fun AdminFramesScreen(
    onBack: () -> Unit,
    viewModel: AdminFramesViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val busy by viewModel.busy.collectAsStateWithLifecycle()
    val message by viewModel.message.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }

    var editing by remember { mutableStateOf<FrameDto?>(null) }
    var pendingUpload by remember { mutableStateOf<List<android.net.Uri>>(emptyList()) }

    LaunchedEffect(message) {
        message?.let {
            snackbar.showSnackbar(it)
            viewModel.messageShown()
        }
    }

    // Several at once. Adding forty frames one dialog at a time was the
    // slowest part of setting the shop up, and the picker has supported a
    // multiple selection all along.
    val pick = rememberLauncherForActivityResult(
        ActivityResultContracts.GetMultipleContents()
    ) { uris ->
        pendingUpload = uris
    }

    if (pendingUpload.isNotEmpty()) {
        FrameUploadDialog(
            count = pendingUpload.size,
            onDismiss = { pendingUpload = emptyList() },
            onConfirm = { name, price, rarity ->
                val chosen = pendingUpload
                pendingUpload = emptyList()
                viewModel.upload(chosen, name, price, rarity)
            },
        )
    }

    editing?.let { frame ->
        FrameEditDialog(
            frame = frame,
            onDismiss = { editing = null },
            onSave = { name, price, rarity, published ->
                editing = null
                viewModel.update(frame.id, name, price, rarity, published)
            },
            onDelete = {
                editing = null
                viewModel.delete(frame.id)
            },
        )
    }

    AdminScaffold(
        stringResource(R.string.admin_frames),
        onBack,
        snackbarHost = snackbar,
        actions = {
            IconButton(onClick = { pick.launch("image/*") }, enabled = !busy) {
                if (busy) {
                    CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp)
                } else {
                    Icon(Icons.Filled.Add, stringResource(R.string.admin_frames_add))
                }
            }
        },
    ) { padding ->
        Box(Modifier.padding(padding)) {
            when (val current = state) {
                is UiState.Loading ->
                    Box(Modifier.fillMaxSize(), Alignment.Center) { CircularProgressIndicator() }

                is UiState.Error -> ErrorState(current.error, onRetry = viewModel::load)

                is UiState.Empty -> EmptyState(
                    stringResource(R.string.admin_frames_empty),
                    Icons.Filled.Stars,
                )

                is UiState.Success -> LazyColumn(contentPadding = PaddingValues(bottom = 24.dp)) {
                    items(current.data, key = { it.id }) { frame ->
                        AdminFrameRow(frame) { editing = frame }
                    }
                }
            }
        }
    }
}

@Composable
private fun AdminFrameRow(frame: FrameDto, onClick: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 5.dp)
            .clip(RoundedCornerShape(14.dp))
            .background(SurfaceCard.copy(alpha = 0.5f))
            .clickable(onClick = onClick)
            .padding(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        // Still, like every other list in the app.
        AvatarWithFrame(avatarUrl = null, frame = frame, size = 58.dp, animate = false)

        Spacer(Modifier.width(12.dp))

        Column(Modifier.weight(1f)) {
            Text(
                frame.name,
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                listOfNotNull(
                    "${frame.price} puan",
                    rarityLabel(frame.rarity),
                    frame.format.takeIf { it.isNotBlank() },
                    "${frame.width}px",
                    if (frame.animated) "hareketli" else "sabit",
                    if (!frame.published) "gizli" else null,
                ).joinToString(" · "),
                style = MaterialTheme.typography.labelSmall,
                color = if (frame.published) TextMuted else TextSecondary,
            )
        }

        Box(
            Modifier
                .size(10.dp)
                .clip(RoundedCornerShape(5.dp))
                .background(rarityColor(frame.rarity))
        )
    }
}

@Composable
private fun FrameUploadDialog(
    count: Int,
    onDismiss: () -> Unit,
    onConfirm: (name: String, price: Int, rarity: String) -> Unit,
) {
    var name by remember { mutableStateOf("") }
    var price by remember { mutableStateOf("200") }
    var rarity by remember { mutableStateOf("common") }

    val single = count == 1

    AlertDialog(
        onDismissRequest = onDismiss,
        title = {
            Text(
                if (single) {
                    stringResource(R.string.admin_frames_add)
                } else {
                    stringResource(R.string.admin_frames_add_many, count)
                }
            )
        },
        text = {
            Column {
                // Offered only for a single file: one name across forty
                // frames would be forty frames nobody can tell apart. In a
                // batch each is named from its own filename.
                if (single) {
                    OutlinedTextField(
                        value = name,
                        onValueChange = { name = it.take(60) },
                        label = { Text(stringResource(R.string.admin_frames_name)) },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Spacer(Modifier.height(10.dp))
                }
                PriceField(price) { price = it }
                Spacer(Modifier.height(10.dp))
                RarityPicker(rarity) { rarity = it }
                Spacer(Modifier.height(8.dp))
                Text(
                    stringResource(R.string.admin_frames_hint),
                    style = MaterialTheme.typography.labelSmall,
                    color = TextMuted,
                )
            }
        },
        confirmButton = {
            TextButton(onClick = { onConfirm(name.trim(), price.toIntOrNull() ?: 0, rarity) }) {
                Text(stringResource(R.string.upload))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) }
        },
    )
}

@Composable
private fun FrameEditDialog(
    frame: FrameDto,
    onDismiss: () -> Unit,
    onSave: (name: String, price: Int, rarity: String, published: Boolean) -> Unit,
    onDelete: () -> Unit,
) {
    var name by remember { mutableStateOf(frame.name) }
    var price by remember { mutableStateOf(frame.price.toString()) }
    var rarity by remember { mutableStateOf(frame.rarity) }
    var published by remember { mutableStateOf(frame.published) }
    var confirmDelete by remember { mutableStateOf(false) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(frame.name) },
        text = {
            Column {
                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it.take(60) },
                    label = { Text(stringResource(R.string.admin_frames_name)) },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(10.dp))
                PriceField(price) { price = it }
                Spacer(Modifier.height(10.dp))
                RarityPicker(rarity) { rarity = it }
                Spacer(Modifier.height(6.dp))

                Row(verticalAlignment = Alignment.CenterVertically) {
                    Switch(checked = published, onCheckedChange = { published = it })
                    Spacer(Modifier.width(8.dp))
                    Text(
                        stringResource(R.string.admin_frames_published),
                        style = MaterialTheme.typography.bodyMedium,
                    )
                }

                Spacer(Modifier.height(4.dp))

                TextButton(onClick = { confirmDelete = !confirmDelete }) {
                    Icon(
                        Icons.Filled.Delete,
                        null,
                        tint = MaterialTheme.colorScheme.error,
                        modifier = Modifier.size(18.dp),
                    )
                    Spacer(Modifier.width(6.dp))
                    Text(
                        stringResource(R.string.delete),
                        color = MaterialTheme.colorScheme.error,
                    )
                }

                if (confirmDelete) {
                    // Spelled out rather than a second "are you sure": people
                    // who already own it lose it, and that is worth reading.
                    Text(
                        stringResource(R.string.admin_frames_delete_warning),
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.error,
                    )
                    Spacer(Modifier.height(4.dp))
                    Button(
                        onClick = onDelete,
                        colors = ButtonDefaults.buttonColors(
                            containerColor = MaterialTheme.colorScheme.error,
                        ),
                    ) {
                        Text(stringResource(R.string.admin_frames_delete_confirm))
                    }
                }
            }
        },
        confirmButton = {
            TextButton(
                onClick = { onSave(name.trim(), price.toIntOrNull() ?: 0, rarity, published) }
            ) {
                Text(stringResource(R.string.save))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) }
        },
    )
}

@Composable
private fun PriceField(value: String, onChange: (String) -> Unit) {
    OutlinedTextField(
        value = value,
        onValueChange = { text -> onChange(text.filter { it.isDigit() }.take(6)) },
        label = { Text(stringResource(R.string.admin_frames_price)) },
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
        singleLine = true,
        supportingText = {
            val points = value.toIntOrNull() ?: 0
            Text(
                stringResource(R.string.admin_frames_price_hint, (points + 19) / 20),
                style = MaterialTheme.typography.labelSmall,
            )
        },
        modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
private fun RarityPicker(selected: String, onSelect: (String) -> Unit) {
    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        listOf("common", "rare", "epic", "legendary").forEach { option ->
            val active = option == selected
            Box(
                Modifier
                    .weight(1f)
                    .clip(RoundedCornerShape(10.dp))
                    .background(
                        if (active) rarityColor(option).copy(alpha = 0.22f) else Color.Transparent
                    )
                    .border(
                        1.dp,
                        if (active) rarityColor(option) else Color.White.copy(alpha = 0.12f),
                        RoundedCornerShape(10.dp),
                    )
                    .clickable { onSelect(option) }
                    .padding(vertical = 8.dp),
                contentAlignment = Alignment.Center,
            ) {
                Text(
                    rarityLabel(option),
                    style = MaterialTheme.typography.labelSmall,
                    color = if (active) rarityColor(option) else TextMuted,
                )
            }
        }
    }
}

/**
 * Send points to somebody.
 *
 * A negative amount takes them back — the only way to undo a mistyped gift
 * without editing the database, and the ledger keeps both rows because a
 * correction is history too.
 */
@Composable
internal fun GrantPointsDialog(
    user: UserDto,
    onDismiss: () -> Unit,
    onConfirm: (amount: Int, note: String) -> Unit,
) {
    var amount by remember { mutableStateOf("100") }
    var note by remember { mutableStateOf("") }
    var negative by remember { mutableStateOf(false) }

    val value = (amount.toIntOrNull() ?: 0).let { if (negative) -it else it }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = {
            Text(
                stringResource(
                    R.string.admin_points_title,
                    user.displayName.ifBlank { user.username },
                )
            )
        },
        text = {
            Column {
                OutlinedTextField(
                    value = amount,
                    onValueChange = { text -> amount = text.filter { it.isDigit() }.take(6) },
                    label = { Text(stringResource(R.string.admin_points_amount)) },
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    singleLine = true,
                    leadingIcon = {
                        Icon(Icons.Filled.Stars, null, tint = AccentBright)
                    },
                    modifier = Modifier.fillMaxWidth(),
                )

                Spacer(Modifier.height(8.dp))

                Row(verticalAlignment = Alignment.CenterVertically) {
                    Switch(checked = negative, onCheckedChange = { negative = it })
                    Spacer(Modifier.width(8.dp))
                    Text(
                        stringResource(R.string.admin_points_take),
                        style = MaterialTheme.typography.bodyMedium,
                        color = if (negative) MaterialTheme.colorScheme.error else TextSecondary,
                    )
                }

                Spacer(Modifier.height(8.dp))

                OutlinedTextField(
                    value = note,
                    onValueChange = { note = it.take(120) },
                    label = { Text(stringResource(R.string.admin_points_note)) },
                    singleLine = true,
                    // Shown to the person receiving it, in their own history.
                    supportingText = {
                        Text(
                            stringResource(R.string.admin_points_note_hint),
                            style = MaterialTheme.typography.labelSmall,
                        )
                    },
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = { onConfirm(value, note.trim()) },
                enabled = value != 0,
            ) {
                Text(stringResource(R.string.admin_points_send))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) }
        },
    )
}
