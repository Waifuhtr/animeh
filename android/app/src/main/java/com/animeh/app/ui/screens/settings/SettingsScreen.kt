package com.animeh.app.ui.screens.settings

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.BuildConfig
import com.animeh.app.R
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

@Composable
fun SettingsScreen(
    onBack: () -> Unit,
    viewModel: SettingsViewModel = hiltViewModel(),
) {
    val settings by viewModel.settings.collectAsStateWithLifecycle()
    val apiBase by viewModel.apiBase.collectAsStateWithLifecycle()
    val canSeeServer by viewModel.canSeeServer.collectAsStateWithLifecycle()
    val message by viewModel.message.collectAsStateWithLifecycle()

    val snackbarHost = remember { SnackbarHostState() }

    LaunchedEffect(message) {
        message?.let {
            snackbarHost.showSnackbar(it)
            viewModel.dismissMessage()
        }
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbarHost) },
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.profile_settings)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back))
                    }
                },
            )
        },
    ) { padding ->
        Column(
            Modifier
                .padding(padding)
                .verticalScroll(rememberScrollState()),
        ) {
            SectionLabel(stringResource(R.string.settings_playback))

            ChoiceRow(
                title = stringResource(R.string.settings_default_quality),
                options = QUALITIES,
                selected = settings.defaultQuality,
                onSelect = viewModel::setQuality,
            )

            SwitchRow(
                title = stringResource(R.string.settings_autoplay),
                checked = settings.autoplayNext,
                onCheckedChange = viewModel::setAutoplayNext,
            )

            SwitchRow(
                title = stringResource(R.string.settings_skip_intro),
                checked = settings.skipIntro,
                onCheckedChange = viewModel::setSkipIntro,
            )

            HorizontalDivider()
            SectionLabel(stringResource(R.string.settings_subtitles))

            SwitchRow(
                title = stringResource(R.string.settings_subtitles_on),
                checked = settings.subtitlesEnabled,
                onCheckedChange = viewModel::setSubtitlesEnabled,
            )

            ChoiceRow(
                title = stringResource(R.string.settings_subtitle_language),
                options = LANGUAGES,
                selected = settings.subtitleLanguage,
                onSelect = viewModel::setSubtitleLanguage,
            )

            HorizontalDivider()
            SectionLabel(stringResource(R.string.settings_data))

            SwitchRow(
                title = stringResource(R.string.settings_data_saver),
                checked = settings.dataSaver,
                onCheckedChange = viewModel::setDataSaver,
            )

            SwitchRow(
                title = stringResource(R.string.settings_wifi_download),
                checked = settings.wifiOnlyDownload,
                onCheckedChange = viewModel::setWifiOnlyDownload,
            )

            SwitchRow(
                title = stringResource(R.string.settings_notifications),
                checked = settings.notifications,
                onCheckedChange = viewModel::setNotifications,
            )

            if (canSeeServer) {
                HorizontalDivider()
                SectionLabel(stringResource(R.string.settings_server))

                ApiBaseField(
                    value = apiBase,
                    onSave = viewModel::setApiBase,
                )
            }

            HorizontalDivider()
            SectionLabel(stringResource(R.string.settings_cache))

            val cacheCleared = stringResource(R.string.settings_cache_cleared)
            ListItem(
                headlineContent = { Text(stringResource(R.string.settings_clear_cache)) },
                modifier = Modifier.clickable { viewModel.clearCache(cacheCleared) },
            )

            HorizontalDivider()
            SectionLabel(stringResource(R.string.settings_about))

            ListItem(
                headlineContent = { Text(stringResource(R.string.settings_version)) },
                trailingContent = { Text(BuildConfig.VERSION_NAME, color = TextMuted) },
            )

            Spacer(Modifier.height(32.dp))
        }
    }
}

@Composable
private fun ApiBaseField(value: String, onSave: (String, String) -> Unit) {
    var draft by remember(value) { mutableStateOf(value) }
    val savedMessage = stringResource(R.string.settings_api_saved)

    Column(Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) {
        OutlinedTextField(
            value = draft,
            onValueChange = { draft = it },
            label = { Text(stringResource(R.string.settings_api_base)) },
            placeholder = { Text(stringResource(R.string.settings_api_base_hint)) },
            supportingText = { Text(stringResource(R.string.settings_api_base_help)) },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )

        Spacer(Modifier.height(8.dp))

        Button(
            onClick = { onSave(draft, savedMessage) },
            enabled = draft.isNotBlank() && draft != value,
        ) {
            Text(stringResource(R.string.save))
        }
    }
}

@Composable
private fun SectionLabel(text: String) {
    Text(
        text = text,
        style = MaterialTheme.typography.titleSmall,
        color = MaterialTheme.colorScheme.primary,
        modifier = Modifier.padding(start = 16.dp, top = 16.dp, bottom = 4.dp),
    )
}

@Composable
private fun SwitchRow(title: String, checked: Boolean, onCheckedChange: (Boolean) -> Unit) {
    ListItem(
        headlineContent = { Text(title) },
        trailingContent = { Switch(checked = checked, onCheckedChange = onCheckedChange) },
        modifier = Modifier.clickable { onCheckedChange(!checked) },
    )
}

@Composable
private fun ChoiceRow(
    title: String,
    options: List<Pair<String, String>>,
    selected: String,
    onSelect: (String) -> Unit,
) {
    var expanded by remember { mutableStateOf(false) }
    val label = options.firstOrNull { it.first == selected }?.second ?: selected

    Box {
        ListItem(
            headlineContent = { Text(title) },
            trailingContent = { Text(label, color = TextSecondary) },
            modifier = Modifier.clickable { expanded = true },
        )

        DropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            options.forEach { (value, text) ->
                DropdownMenuItem(
                    text = { Text(text) },
                    onClick = {
                        onSelect(value)
                        expanded = false
                    },
                )
            }
        }
    }
}

private val QUALITIES = listOf(
    "auto" to "Otomatik",
    "1080" to "1080p",
    "720" to "720p",
    "480" to "480p",
    "360" to "360p",
)

private val LANGUAGES = listOf(
    "tr" to "Türkçe",
    "en" to "English",
    "ja" to "日本語",
)
