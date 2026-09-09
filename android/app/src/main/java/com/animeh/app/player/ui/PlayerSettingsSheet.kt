package com.animeh.app.player.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
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
    subtitleScale: Float,
    onQuality: (QualitySelection) -> Unit,
    onSpeed: (Float) -> Unit,
    onSubtitle: (Long?) -> Unit,
    onSubtitleScale: (Float) -> Unit,
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

            // Here as well as in Settings, and for a plain reason: this is
            // where the problem is noticed. A line that fills half the picture
            // is something you want smaller now, in this episode, not after
            // backing out to a settings screen and finding your way in again.
            //
            // Not hidden behind "subtitles are on" either — switching them off
            // to escape a size that is unreadable is exactly the move this is
            // meant to make unnecessary.
            Spacer(Modifier.height(8.dp))

            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    stringResource(R.string.settings_subtitle_size),
                    style = MaterialTheme.typography.bodyLarge,
                )
                Text(
                    "%d%%".format((subtitleScale * 100).toInt()),
                    style = MaterialTheme.typography.bodyMedium,
                    color = TextMuted,
                )
            }

            Slider(
                value = subtitleScale.coerceIn(SUBTITLE_SCALE_RANGE),
                valueRange = SUBTITLE_SCALE_RANGE,
                // Twenty-one stops of five percent. Continuous would write the
                // preference on every frame of the drag for a difference
                // nobody can see between one stop and the next.
                steps = 19,
                onValueChange = onSubtitleScale,
            )

            // A line to judge it by. The sheet covers the bottom of the
            // picture, which is where subtitles live, so without this the
            // slider changes something the person moving it cannot see. It
            // tracks the setting rather than predicting the exact result —
            // the real size also depends on what the script asked for — so it
            // is labelled as a sample and not as a preview of this episode.
            Box(
                Modifier
                    .fillMaxWidth()
                    .padding(top = 4.dp)
                    .clip(RoundedCornerShape(10.dp))
                    .background(Color.Black.copy(alpha = 0.55f))
                    .padding(horizontal = 12.dp, vertical = 10.dp),
                contentAlignment = Alignment.Center,
            ) {
                Text(
                    stringResource(R.string.player_subtitle_sample),
                    style = MaterialTheme.typography.bodyLarge,
                    fontSize = SAMPLE_TEXT_SP * subtitleScale.coerceIn(SUBTITLE_SCALE_RANGE),
                    lineHeight = SAMPLE_TEXT_SP * subtitleScale.coerceIn(SUBTITLE_SCALE_RANGE) * 1.25f,
                    color = Color.White,
                    textAlign = TextAlign.Center,
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

/** Same range as the one in Settings: the two write the same preference. */
private val SUBTITLE_SCALE_RANGE = 0.5f..1.5f

/** The sample line at 100%. Roughly what a 1080p script asks for on a phone. */
private val SAMPLE_TEXT_SP = 17.sp
