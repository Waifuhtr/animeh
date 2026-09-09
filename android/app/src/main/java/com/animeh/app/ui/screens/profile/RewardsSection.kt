package com.animeh.app.ui.screens.profile

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.EmojiEvents
import androidx.compose.material.icons.filled.Palette
import androidx.compose.material.icons.filled.Stars
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.animeh.app.data.remote.dto.WalletDto
import com.animeh.app.ui.theme.AccentBright
import com.animeh.app.ui.theme.ProfileTheme
import com.animeh.app.ui.theme.ProfileThemes
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

/**
 * The wallet, the standings and the palette, as they appear on your own
 * profile.
 *
 * Kept apart from [ProfileScreen] because these three grew into most of the
 * screen and none of them has anything to do with signing out.
 */

/**
 * What you have, and what it is for.
 *
 * The rate is on the card next to the balance rather than buried in a help
 * page: a number nobody knows how to increase is a number nobody looks at
 * twice.
 */
@Composable
fun PointsCard(
    wallet: WalletDto,
    theme: ProfileTheme,
    onShop: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(18.dp))
            .background(theme.wash)
            .border(1.dp, theme.accent.copy(alpha = 0.35f), RoundedCornerShape(18.dp))
            .padding(16.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Filled.Stars, null, tint = theme.accent, modifier = Modifier.size(26.dp))
            Spacer(Modifier.width(10.dp))

            Column(Modifier.weight(1f)) {
                Text(
                    formatPoints(wallet.balance),
                    style = MaterialTheme.typography.headlineSmall,
                    fontWeight = FontWeight.Bold,
                )
                Text(
                    "Animeh Puanı",
                    style = MaterialTheme.typography.labelMedium,
                    color = TextSecondary,
                )
            }

            Column(horizontalAlignment = Alignment.End) {
                Text(
                    "+${wallet.perEpisode}",
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                    color = theme.accent,
                )
                Text(
                    "her bölüm",
                    style = MaterialTheme.typography.labelSmall,
                    color = TextMuted,
                )
            }
        }

        if (wallet.earned > wallet.balance) {
            Spacer(Modifier.height(6.dp))
            Text(
                "Bugüne kadar ${formatPoints(wallet.earned)} kazandın",
                style = MaterialTheme.typography.labelSmall,
                color = TextMuted,
            )
        }

        Spacer(Modifier.height(14.dp))

        Button(
            onClick = onShop,
            modifier = Modifier.fillMaxWidth().height(44.dp),
            shape = RoundedCornerShape(12.dp),
            colors = ButtonDefaults.buttonColors(
                containerColor = theme.accent.copy(alpha = 0.9f),
                contentColor = Color.White,
            ),
        ) {
            Text("Çerçeve Mağazası", fontWeight = FontWeight.SemiBold)
            Spacer(Modifier.width(4.dp))
            Icon(Icons.Filled.ChevronRight, null, modifier = Modifier.size(18.dp))
        }
    }
}

/**
 * Three ranks, side by side, and the way in to the full boards.
 *
 * A rank of zero means "nothing watched yet on this board" rather than "last".
 * It is drawn as a dash, because a zero next to two real positions reads as a
 * score and this is a position.
 */
@Composable
fun RankCard(
    wallet: WalletDto,
    theme: ProfileTheme,
    onLeaderboard: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(18.dp))
            .background(SurfaceCard.copy(alpha = 0.6f))
            .clickable(onClick = onLeaderboard)
            .padding(16.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Filled.EmojiEvents, null, tint = theme.accent, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
            Text(
                "Sıralamam",
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
                modifier = Modifier.weight(1f),
            )
            Icon(Icons.Filled.ChevronRight, null, tint = TextMuted, modifier = Modifier.size(20.dp))
        }

        Spacer(Modifier.height(12.dp))

        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            RankChip("Seri", wallet.ranks.works, theme, Modifier.weight(1f))
            RankChip("Süre", wallet.ranks.seconds, theme, Modifier.weight(1f))
            RankChip("Bölüm", wallet.ranks.episodes, theme, Modifier.weight(1f))
        }
    }
}

@Composable
private fun RankChip(label: String, rank: Int, theme: ProfileTheme, modifier: Modifier = Modifier) {
    Column(
        modifier
            .clip(RoundedCornerShape(12.dp))
            .background(theme.accent.copy(alpha = 0.10f))
            .padding(vertical = 10.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(
            if (rank > 0) "#$rank" else "—",
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
            color = if (rank in 1..3) theme.accent else AccentBright,
        )
        Text(label, style = MaterialTheme.typography.labelSmall, color = TextMuted)
    }
}

/**
 * The free palette.
 *
 * Twelve swatches, three rows of four, laid out by hand rather than with a
 * grid: a `LazyVerticalGrid` inside a vertically scrolling column has to be
 * given a height, and twelve fixed items do not need one.
 */
@Composable
fun ThemePalette(
    selected: String,
    onChoose: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(modifier.fillMaxWidth()) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Filled.Palette, null, tint = TextMuted, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            Text(
                "Profil Rengi",
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
                modifier = Modifier.weight(1f),
            )
            Text("Ücretsiz", style = MaterialTheme.typography.labelSmall, color = TextMuted)
        }

        Spacer(Modifier.height(12.dp))

        ProfileThemes.chunked(4).forEach { row ->
            Row(
                Modifier.fillMaxWidth().padding(bottom = 10.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                row.forEach { option ->
                    Swatch(
                        option = option,
                        selected = option.slug == selected,
                        onClick = { onChoose(option.slug) },
                        modifier = Modifier.weight(1f),
                    )
                }
                // A short last row keeps the swatches the same size as the
                // full rows above it, rather than stretching to fill.
                repeat(4 - row.size) { Spacer(Modifier.weight(1f)) }
            }
        }
    }
}

@Composable
private fun Swatch(
    option: ProfileTheme,
    selected: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(modifier, horizontalAlignment = Alignment.CenterHorizontally) {
        Box(
            Modifier
                .size(46.dp)
                .clip(CircleShape)
                .background(option.accent)
                .border(
                    width = if (selected) 3.dp else 0.dp,
                    color = if (selected) Color.White else Color.Transparent,
                    shape = CircleShape,
                )
                .clickable(onClick = onClick),
            contentAlignment = Alignment.Center,
        ) {
            if (selected) {
                Icon(Icons.Filled.Check, null, tint = Color.White, modifier = Modifier.size(22.dp))
            }
        }

        Spacer(Modifier.height(5.dp))

        Text(
            option.label,
            style = MaterialTheme.typography.labelSmall,
            color = if (selected) AccentBright else TextMuted,
            maxLines = 1,
        )
    }
}

/**
 * A points total with thousands separated.
 *
 * Turkish uses a full stop for that, which is why this is not `%,d` with
 * whatever locale the phone happens to be in.
 */
fun formatPoints(value: Int): String {
    val digits = value.toString()
    if (digits.length <= 3) return digits

    return digits.reversed().chunked(3).joinToString(".").reversed()
}
