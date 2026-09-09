package com.animeh.app.ui.screens.profile

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Stars
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.data.remote.dto.FrameDto
import com.animeh.app.ui.components.AvatarWithFrame
import com.animeh.app.ui.theme.AccentBright
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary
import com.animeh.app.ui.theme.profileTheme
import com.animeh.app.ui.theme.rarityColor
import com.animeh.app.ui.theme.rarityLabel

/**
 * Where points are spent.
 *
 * ### The one rule this screen is built around
 *
 * **Only the selected frame moves.** Every other card shows its frame's first
 * frame, decoded as a still by the app's default image loader, which has no
 * animated decoder in it at all. Thirty animated rings at 288×288 all playing
 * at once is thirty decode loops, and that is a slideshow on any phone.
 *
 * So the screen is a big preview at the top — one frame, animated, around the
 * viewer's own picture, which is what they are actually buying — and a grid of
 * stills underneath. Tapping a card moves the preview. It also happens to be
 * the better way to shop: a ring is judged around a face, not in a thumbnail.
 */
@Composable
fun FrameShopScreen(
    avatarUrl: String,
    onBack: () -> Unit,
    onFrameEquipped: (FrameDto?) -> Unit = {},
    onBalanceChanged: (Int) -> Unit = {},
    viewModel: FrameShopViewModel = hiltViewModel(),
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

    // Reported upwards so the profile behind this screen is already right when
    // it comes back, rather than correct only after its next refresh.
    LaunchedEffect(state.equipped) { onFrameEquipped(state.equippedFrame) }
    LaunchedEffect(state.balance) { onBalanceChanged(state.balance) }

    val selected = state.frames.firstOrNull { it.id == state.selectedId }
        ?: state.frames.firstOrNull { it.equipped }
        ?: state.frames.firstOrNull()

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        topBar = {
            TopAppBar(
                title = { Text("Çerçeve Mağazası") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, "Geri")
                    }
                },
                actions = {
                    Row(
                        Modifier.padding(end = 14.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(
                            Icons.Filled.Stars,
                            null,
                            tint = AccentBright,
                            modifier = Modifier.size(18.dp),
                        )
                        Spacer(Modifier.width(5.dp))
                        Text(
                            formatPoints(state.balance),
                            style = MaterialTheme.typography.titleSmall,
                            fontWeight = FontWeight.Bold,
                        )
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Color.Transparent),
            )
        },
    ) { padding ->
        if (state.loading && state.frames.isEmpty()) {
            Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                CircularProgressIndicator()
            }
            return@Scaffold
        }

        if (state.frames.isEmpty()) {
            Box(Modifier.fillMaxSize().padding(padding).padding(32.dp), contentAlignment = Alignment.Center) {
                Text(
                    "Henüz çerçeve eklenmemiş.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = TextMuted,
                    textAlign = TextAlign.Center,
                )
            }
            return@Scaffold
        }

        LazyVerticalGrid(
            columns = GridCells.Fixed(3),
            contentPadding = PaddingValues(16.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
            modifier = Modifier.fillMaxSize().padding(padding),
        ) {
            item(span = { GridItemSpan(maxLineSpan) }, contentType = "preview") {
                FramePreview(
                    avatarUrl = avatarUrl,
                    frame = selected,
                    balance = state.balance,
                    busy = state.busyId == selected?.id,
                    onBuy = { selected?.let(viewModel::buy) },
                    onEquip = { selected?.let { viewModel.equip(it) } },
                    onRemove = viewModel::removeFrame,
                    equippedAny = state.equipped > 0,
                )
            }

            items(state.frames, key = { it.id }, contentType = { "frame" }) { frame ->
                FrameCard(
                    frame = frame,
                    selected = frame.id == selected?.id,
                    affordable = state.balance >= frame.price,
                    onClick = { viewModel.select(frame.id) },
                )
            }
        }
    }
}

/**
 * The one frame that moves.
 *
 * Around the viewer's own picture, at the size a profile draws it, because
 * that is the only honest preview of a thing whose whole job is to sit around
 * that picture.
 */
@Composable
private fun FramePreview(
    avatarUrl: String,
    frame: FrameDto?,
    balance: Int,
    busy: Boolean,
    equippedAny: Boolean,
    onBuy: () -> Unit,
    onEquip: () -> Unit,
    onRemove: () -> Unit,
) {
    Column(
        Modifier
            .fillMaxWidth()
            .padding(bottom = 6.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        AvatarWithFrame(
            avatarUrl = avatarUrl,
            frame = frame,
            size = 168.dp,
            // The only place on this screen where this is true.
            animate = true,
            contentDescription = frame?.name,
        )

        Spacer(Modifier.height(12.dp))

        Text(
            frame?.name.orEmpty(),
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
        )

        if (frame != null) {
            Spacer(Modifier.height(4.dp))
            Text(
                buildString {
                    append(rarityLabel(frame.rarity))
                    if (!frame.animated) append(" · sabit")
                    if (frame.retired) append(" · koleksiyon")
                },
                style = MaterialTheme.typography.labelMedium,
                color = rarityColor(frame.rarity),
            )
        }

        Spacer(Modifier.height(14.dp))

        when {
            frame == null -> Unit

            frame.equipped -> OutlinedButton(
                onClick = onRemove,
                modifier = Modifier.fillMaxWidth(0.7f).height(46.dp),
                shape = RoundedCornerShape(14.dp),
            ) {
                Text("Çerçeveyi Kaldır")
            }

            frame.owned -> Button(
                onClick = onEquip,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(0.7f).height(46.dp),
                shape = RoundedCornerShape(14.dp),
            ) {
                Text(if (equippedAny) "Bunu Tak" else "Tak", fontWeight = FontWeight.SemiBold)
            }

            else -> Button(
                onClick = onBuy,
                enabled = !busy && balance >= frame.price,
                modifier = Modifier.fillMaxWidth(0.7f).height(46.dp),
                shape = RoundedCornerShape(14.dp),
            ) {
                if (busy) {
                    CircularProgressIndicator(
                        strokeWidth = 2.dp,
                        modifier = Modifier.size(18.dp),
                        color = Color.White,
                    )
                } else {
                    Icon(Icons.Filled.Stars, null, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(
                        // The price, not the word "buy": what it costs is the
                        // thing somebody needs to decide, and it is decided
                        // before the tap rather than in a dialog after it.
                        "${formatPoints(frame.price)} puana al",
                        fontWeight = FontWeight.SemiBold,
                    )
                }
            }
        }

        if (frame != null && !frame.owned && balance < frame.price) {
            Spacer(Modifier.height(6.dp))
            Text(
                "${formatPoints(frame.price - balance)} puan daha gerekiyor " +
                    "(${(frame.price - balance + 19) / 20} bölüm)",
                style = MaterialTheme.typography.labelSmall,
                color = TextMuted,
            )
        }

        Spacer(Modifier.height(18.dp))
        HorizontalDivider(color = Color.White.copy(alpha = 0.06f))
    }
}

/**
 * One card in the grid: a still, a price and whether it is already yours.
 *
 * Drawn with the default image loader, deliberately — that loader has no
 * decoder that can play an animation, so an animated file arrives here as its
 * first frame at the cost of a single bitmap.
 */
@Composable
private fun FrameCard(
    frame: FrameDto,
    selected: Boolean,
    affordable: Boolean,
    onClick: () -> Unit,
) {
    val edge = rarityColor(frame.rarity)

    Column(
        Modifier
            .clip(RoundedCornerShape(14.dp))
            .background(if (selected) edge.copy(alpha = 0.16f) else SurfaceCard.copy(alpha = 0.5f))
            .border(
                width = if (selected) 2.dp else 1.dp,
                color = if (selected) edge else Color.White.copy(alpha = 0.07f),
                shape = RoundedCornerShape(14.dp),
            )
            .clickable(onClick = onClick)
            .padding(8.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(contentAlignment = Alignment.Center) {
            AvatarWithFrame(
                avatarUrl = null,
                frame = frame,
                size = 74.dp,
                animate = false,
            )

            if (frame.equipped) {
                Icon(
                    Icons.Filled.CheckCircle,
                    null,
                    tint = AccentBright,
                    modifier = Modifier.align(Alignment.TopEnd).size(18.dp),
                )
            }
        }

        Spacer(Modifier.height(6.dp))

        Text(
            frame.name,
            style = MaterialTheme.typography.labelMedium,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            textAlign = TextAlign.Center,
        )

        Spacer(Modifier.height(2.dp))

        when {
            frame.owned -> Text(
                if (frame.equipped) "Takılı" else "Sende",
                style = MaterialTheme.typography.labelSmall,
                color = AccentBright,
            )

            else -> Row(verticalAlignment = Alignment.CenterVertically) {
                if (!affordable) {
                    Icon(
                        Icons.Filled.Lock,
                        null,
                        tint = TextMuted,
                        modifier = Modifier.size(11.dp),
                    )
                    Spacer(Modifier.width(3.dp))
                }
                Text(
                    formatPoints(frame.price),
                    style = MaterialTheme.typography.labelSmall,
                    color = if (affordable) TextSecondary else TextMuted,
                )
            }
        }
    }
}
