package com.animeh.app.player.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.animeh.app.R
import com.animeh.app.player.PlayerUiState
import com.animeh.app.player.QualityPolicy
import com.animeh.app.player.QualitySelection
import com.animeh.app.ui.theme.AccentPrimary
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

/**
 * Quality, speed, subtitles and the measurements, in one sheet.
 *
 * The stats block is not decoration: it is the same set of numbers the
 * WordPress test panel reports, so a viewer describing a problem and an
 * operator reproducing it are talking about the same figures.
 */
@Composable
fun PlayerSettingsSheet(
    state: PlayerUiState,
    onQuality: (QualitySelection) -> Unit,
    onSpeed: (Float) -> Unit,
    onSubtitle: (Long?) -> Unit,
    onDismiss: () -> Unit,
) {
    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(
            Modifier
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp)
                .padding(bottom = 32.dp),
        ) {
            SectionTitle(stringResource(R.string.player_quality))

            val heights = QualityPolicy.availableHeights(state.videoSources)

            OptionRow(
                label = stringResource(R.string.player_quality_auto),
                detail = if (state.activeHeight > 0) "${state.activeHeight}p" else null,
                selected = state.quality is QualitySelection.Auto,
                onClick = { onQuality(QualitySelection.Auto) },
            )

            heights.forEach { height ->
                OptionRow(
                    label = "${height}p",
                    selected = (state.quality as? QualitySelection.Fixed)?.height == height,
                    onClick = { onQuality(QualitySelection.Fixed(height)) },
                )
            }

            HorizontalDivider(Modifier.padding(vertical = 12.dp))
            SectionTitle(stringResource(R.string.player_speed))

            SPEEDS.forEach { speed ->
                OptionRow(
                    label = if (speed == 1f) "Normal" else "${speed}x",
                    selected = state.speed == speed,
                    onClick = { onSpeed(speed) },
                )
            }

            HorizontalDivider(Modifier.padding(vertical = 12.dp))
            SectionTitle(stringResource(R.string.player_subtitle))

            OptionRow(
                label = stringResource(R.string.player_subtitle_off),
                selected = state.selectedSubtitleId == null,
                onClick = { onSubtitle(null) },
            )

            state.subtitleSources.forEach { subtitle ->
                OptionRow(
                    label = subtitle.label.ifBlank { subtitle.language.uppercase() },
                    detail = subtitle.language.takeIf { it.isNotBlank() }?.uppercase(),
                    selected = state.selectedSubtitleId == subtitle.id,
                    onClick = { onSubtitle(subtitle.id) },
                )
            }

            if (state.missingFonts.isNotEmpty()) {
                Spacer(Modifier.height(8.dp))
                // Reported rather than substituted: a near-match font renders
                // at the wrong metrics and breaks the typesetting silently.
                Text(
                    text = stringResource(R.string.player_missing_fonts, state.missingFonts.joinToString(", ")),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.error,
                )
            }

            HorizontalDivider(Modifier.padding(vertical = 12.dp))
            SectionTitle(stringResource(R.string.player_stats))

            StatRow("Başlangıç süresi", "${state.stats.startupMs} ms")
            StatRow(
                "Yeniden tamponlama",
                "${state.stats.rebufferCount}× · ${"%.1f".format(state.stats.rebufferMs / 1000.0)}s",
            )
            StatRow("Ortalama hız", state.stats.bandwidthLabel)
            StatRow("Kalite değişimi", "${state.stats.switchCount}")
        }
    }
}

@Composable
private fun SectionTitle(text: String) {
    Text(
        text = text,
        style = MaterialTheme.typography.titleSmall,
        color = TextSecondary,
        modifier = Modifier.padding(vertical = 8.dp),
    )
}

@Composable
private fun OptionRow(
    label: String,
    selected: Boolean,
    onClick: () -> Unit,
    detail: String? = null,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .selectable(selected = selected, onClick = onClick)
            // 48dp: the minimum comfortable touch target, and these are being
            // tapped one-handed in the dark.
            .heightIn(min = 48.dp)
            .padding(vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            text = label,
            style = MaterialTheme.typography.bodyLarge,
            color = if (selected) AccentPrimary else MaterialTheme.colorScheme.onSurface,
            modifier = Modifier.weight(1f),
        )

        detail?.let {
            Text(it, style = MaterialTheme.typography.bodySmall, color = TextMuted)
            Spacer(Modifier.width(8.dp))
        }

        if (selected) {
            Icon(Icons.Filled.Check, null, tint = AccentPrimary, modifier = Modifier.size(20.dp))
        }
    }
}

@Composable
private fun StatRow(label: String, value: String) {
    Row(
        Modifier.fillMaxWidth().padding(vertical = 6.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
    ) {
        Text(label, style = MaterialTheme.typography.bodyMedium, color = TextSecondary)
        Text(value, style = MaterialTheme.typography.bodyMedium)
    }
}

private val SPEEDS = listOf(0.5f, 0.75f, 1f, 1.25f, 1.5f, 2f)
