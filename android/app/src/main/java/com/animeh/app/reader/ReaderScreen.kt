package com.animeh.app.reader

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Favorite
import androidx.compose.material.icons.filled.FavoriteBorder
import androidx.compose.material.icons.filled.SkipNext
import androidx.compose.material.icons.filled.SkipPrevious
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.core.AppError
import com.animeh.app.core.UiState
import com.animeh.app.ui.components.ErrorState
import com.animeh.app.ui.theme.AccentBright
import com.animeh.app.ui.theme.AccentPrimary
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

/**
 * The reader.
 *
 * One long strip of pages, which is how everybody reads translated manga on a
 * phone and how the site this library came from already presents it. Not a
 * pager: a page turn per tap is a desktop idea, and a scan whose panels run
 * past the bottom of the screen has to be scrolled anyway.
 *
 * The chrome hides on a tap and stays hidden while reading, because the pages
 * are the point and the bars cover the top and bottom of every one of them.
 *
 * Progress is reported from the furthest page reached rather than the current
 * one, and lands in the same history an episode does — so a chapter finished
 * shows up under "devam et", counts on the profile, and earns the same twenty
 * points. See [ReaderViewModel] for why a page is measured as a second.
 */
@Composable
fun ReaderScreen(
    chapterId: Long,
    onBack: () -> Unit,
    onChapter: (Long) -> Unit,
    viewModel: ReaderViewModel = hiltViewModel(),
) {
    LaunchedEffect(chapterId) { viewModel.open(chapterId) }

    val state by viewModel.state.collectAsStateWithLifecycle()
    val current by viewModel.page.collectAsStateWithLifecycle()

    var chromeVisible by rememberSaveable { mutableStateOf(true) }

    // Whatever has not been sent yet goes on the way out, so closing the
    // reader on the last page still counts as having read it.
    DisposableEffect(chapterId) {
        onDispose { viewModel.flush() }
    }

    BackHandler {
        viewModel.flush()
        onBack()
    }

    Box(Modifier.fillMaxSize().background(Color.Black)) {
        when (val loaded = state) {
            is UiState.Loading ->
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator()
                }

            is UiState.Error -> ErrorState(loaded.error, onRetry = { viewModel.open(chapterId) })

            is UiState.Empty ->
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text("Bu bölümde sayfa yok.", color = TextMuted)
                }

            is UiState.Success -> {
                val data = loaded.data

                Pages(
                    state = data,
                    resumeAt = data.resumeAt,
                    onPage = viewModel::onPage,
                    onToggleChrome = { chromeVisible = !chromeVisible },
                )

                AnimatedVisibility(
                    visible = chromeVisible,
                    enter = fadeIn(),
                    exit = fadeOut(),
                    modifier = Modifier.align(Alignment.TopCenter),
                ) {
                    ReaderTopBar(
                        title = data.work?.displayTitle.orEmpty(),
                        subtitle = data.chapter?.label.orEmpty(),
                        favourite = data.work?.isFavorite == true,
                        onBack = {
                            viewModel.flush()
                            onBack()
                        },
                        onFavourite = viewModel::toggleFavourite,
                    )
                }

                AnimatedVisibility(
                    visible = chromeVisible,
                    enter = fadeIn(),
                    exit = fadeOut(),
                    modifier = Modifier.align(Alignment.BottomCenter),
                ) {
                    ReaderBottomBar(
                        page = current,
                        total = data.pages.size,
                        hasPrevious = data.previous != null,
                        hasNext = data.next != null,
                        onPrevious = { data.previous?.let { onChapter(it.id) } },
                        onNext = { data.next?.let { onChapter(it.id) } },
                    )
                }
            }
        }
    }
}

@Composable
private fun Pages(
    state: ReaderState,
    resumeAt: Int,
    onPage: (Int) -> Unit,
    onToggleChrome: () -> Unit,
) {
    val listState = rememberLazyListState()

    // Land where they left off. Keyed on the chapter so re-entering the same
    // one does not jump on every recomposition.
    LaunchedEffect(state.chapter?.id) {
        if (resumeAt > 1) listState.scrollToItem((resumeAt - 1).coerceAtMost(state.pages.lastIndex.coerceAtLeast(0)))
    }

    // The first visible item is the page being read. Read from the list's own
    // state in a snapshot flow rather than during composition, so scrolling
    // does not recompose the whole strip on every frame.
    LaunchedEffect(listState, state.pages.size) {
        snapshotFlow { listState.firstVisibleItemIndex }
            .collect { index -> onPage(index + 1) }
    }

    LazyColumn(
        state = listState,
        modifier = Modifier
            .fillMaxSize()
            .pointerInput(Unit) {
                detectTapGestures(onTap = { onToggleChrome() })
            },
    ) {
        items(state.pages, key = { it.position }, contentType = { "page" }) { page ->
            FallbackImage(
                candidates = page.candidates,
                contentDescription = null,
                modifier = Modifier.fillMaxWidth(),
            )
        }

        item(contentType = "end") {
            ChapterEnd(
                hasNext = state.next != null,
                nextLabel = state.next?.label.orEmpty(),
            )
        }
    }
}

@Composable
private fun ChapterEnd(hasNext: Boolean, nextLabel: String) {
    Column(
        Modifier
            .fillMaxWidth()
            .padding(vertical = 40.dp, horizontal = 24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(
            if (hasNext) "Bölüm bitti" else "Son bölüm",
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.SemiBold,
            color = Color.White,
        )

        if (hasNext) {
            Spacer(Modifier.height(6.dp))
            Text(
                "Sıradaki: $nextLabel",
                style = MaterialTheme.typography.bodySmall,
                color = TextSecondary,
                textAlign = TextAlign.Center,
            )
        }

        Spacer(Modifier.height(80.dp))
    }
}

@Composable
private fun ReaderTopBar(
    title: String,
    subtitle: String,
    favourite: Boolean,
    onBack: () -> Unit,
    onFavourite: () -> Unit,
) {
    Row(
        Modifier
            .fillMaxWidth()
            .background(Color.Black.copy(alpha = 0.82f))
            .statusBarsPadding()
            .padding(horizontal = 6.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconButton(onClick = onBack) {
            Icon(Icons.AutoMirrored.Filled.ArrowBack, "Geri", tint = Color.White)
        }

        Column(Modifier.weight(1f)) {
            Text(
                title,
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
                color = Color.White,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                subtitle,
                style = MaterialTheme.typography.labelSmall,
                color = TextSecondary,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }

        IconButton(onClick = onFavourite) {
            Icon(
                if (favourite) Icons.Filled.Favorite else Icons.Filled.FavoriteBorder,
                "Favori",
                tint = if (favourite) AccentBright else Color.White,
            )
        }
    }
}

@Composable
private fun ReaderBottomBar(
    page: Int,
    total: Int,
    hasPrevious: Boolean,
    hasNext: Boolean,
    onPrevious: () -> Unit,
    onNext: () -> Unit,
) {
    Column(
        Modifier
            .fillMaxWidth()
            .background(Color.Black.copy(alpha = 0.82f))
            .navigationBarsPadding()
            .padding(horizontal = 12.dp, vertical = 10.dp),
    ) {
        // A bar rather than a slider: it says where you are, and a manga page
        // is not a thing anybody wants to scrub to by dragging.
        LinearProgressIndicator(
            progress = { if (total > 0) page.toFloat() / total else 0f },
            modifier = Modifier
                .fillMaxWidth()
                .height(3.dp)
                .clip(RoundedCornerShape(2.dp)),
            color = AccentPrimary,
            trackColor = Color.White.copy(alpha = 0.15f),
        )

        Spacer(Modifier.height(8.dp))

        Row(verticalAlignment = Alignment.CenterVertically) {
            ChapterButton(
                label = "Önceki",
                enabled = hasPrevious,
                onClick = onPrevious,
                leading = true,
            )

            Text(
                "$page / $total",
                style = MaterialTheme.typography.labelMedium,
                color = Color.White,
                textAlign = TextAlign.Center,
                modifier = Modifier.weight(1f),
            )

            ChapterButton(
                label = "Sonraki",
                enabled = hasNext,
                onClick = onNext,
                leading = false,
            )
        }
    }
}

@Composable
private fun ChapterButton(
    label: String,
    enabled: Boolean,
    onClick: () -> Unit,
    leading: Boolean,
) {
    val tint = if (enabled) Color.White else TextMuted.copy(alpha = 0.4f)

    Row(
        Modifier
            .clip(RoundedCornerShape(10.dp))
            .clickable(enabled = enabled, onClick = onClick)
            .padding(horizontal = 10.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (leading) {
            Icon(Icons.Filled.SkipPrevious, null, tint = tint, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(4.dp))
        }

        Text(label, style = MaterialTheme.typography.labelMedium, color = tint)

        if (!leading) {
            Spacer(Modifier.width(4.dp))
            Icon(Icons.Filled.SkipNext, null, tint = tint, modifier = Modifier.size(18.dp))
        }
    }
}
