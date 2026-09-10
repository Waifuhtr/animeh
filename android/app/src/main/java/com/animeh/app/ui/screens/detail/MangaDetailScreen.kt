package com.animeh.app.ui.screens.detail

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.Bookmark
import androidx.compose.material.icons.filled.BookmarkBorder
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material.icons.filled.KeyboardArrowUp
import androidx.compose.material.icons.filled.MenuBook
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Send
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.SwapVert
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.core.UiState
import com.animeh.app.domain.Episode
import com.animeh.app.domain.Work
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.components.ErrorState
import com.animeh.app.ui.theme.AccentBright
import com.animeh.app.ui.theme.AccentPrimary
import com.animeh.app.ui.theme.PosterShape
import com.animeh.app.ui.theme.StatusWarning
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextPrimary
import com.animeh.app.ui.theme.TextSecondary

/**
 * A manga's page.
 *
 * The same view model as an anime's, because everything behind the screen is
 * the same — favourites, history, reviews, the adult gate. What differs is
 * what belongs on it: no seasons, no runtime, no trailer, no studio; a chapter
 * count where the episode count was, the person who drew it where the studio
 * was, and a chapter list whose rows say "Oku" and open the reader.
 *
 * Kept apart from [DetailScreen] rather than branched inside it: the two
 * layouts share almost no rows, and the version that tried to be both was a
 * page with half its fields hidden.
 */
@Composable
internal fun MangaDetailScreen(
    state: DetailUiState,
    signedIn: Boolean,
    onBack: () -> Unit,
    onReadChapter: (Long) -> Unit,
    onSignIn: () -> Unit,
    onRecommend: (Long) -> Unit,
    viewModel: DetailViewModel,
) {
    // Newest first is the wrong default for a manga: a series is read from
    // chapter one, and a shelf that opens at the latest chapter is one you
    // have to scroll to the bottom of before you can start.
    var oldestFirst by remember { mutableStateOf(true) }

    Scaffold(
        bottomBar = {
            val chapters = (state.episodes as? UiState.Success)?.data.orEmpty()
            val first = if (oldestFirst) chapters.firstOrNull() else chapters.lastOrNull()

            if (first != null) {
                Surface(color = Color.Transparent) {
                    Button(
                        onClick = { onReadChapter(first.id) },
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(16.dp)
                            .navigationBarsPadding()
                            .height(56.dp),
                        shape = RoundedCornerShape(28.dp),
                    ) {
                        Icon(Icons.Filled.MenuBook, null, Modifier.size(20.dp))
                        Spacer(Modifier.width(8.dp))
                        Text("Oku", style = MaterialTheme.typography.titleMedium)
                    }
                }
            }
        },
    ) { padding ->
        when (val work = state.work) {
            is UiState.Loading -> Box(Modifier.fillMaxSize(), Alignment.Center) {
                CircularProgressIndicator()
            }

            is UiState.Error -> ErrorState(work.error, onRetry = viewModel::load)

            is UiState.Empty -> EmptyState("Bu manga bulunamadı.", Icons.Filled.MenuBook)

            is UiState.Success -> LazyColumn(
                // Only the bottom inset: the hero runs under the status bar
                // on purpose, which is where the artwork wants to be.
                contentPadding = PaddingValues(bottom = padding.calculateBottomPadding()),
            ) {
                item(contentType = "hero") {
                    MangaHero(
                        work = work.data,
                        bookmarked = state.inWatchlist,
                        onBack = onBack,
                        onBookmark = { viewModel.toggleWatchlist() },
                        onRecommend = { onRecommend(work.data.id) },
                    )
                }

                item(contentType = "about") {
                    AboutCard(work.data.synopsis)
                }

                item(contentType = "chapters") {
                    ChaptersHeader(
                        count = (state.episodes as? UiState.Success)?.data?.size ?: 0,
                        oldestFirst = oldestFirst,
                        onToggleOrder = { oldestFirst = !oldestFirst },
                    )
                }

                when (val episodes = state.episodes) {
                    is UiState.Success -> {
                        val ordered = if (oldestFirst) episodes.data else episodes.data.reversed()

                        items(ordered, key = { it.id }, contentType = { "chapter" }) { chapter ->
                            ChapterRow(
                                chapter = chapter,
                                cover = work.data.posterUrl,
                                onRead = { onReadChapter(chapter.id) },
                            )
                        }
                    }

                    is UiState.Loading -> item(contentType = "loading") {
                        Box(Modifier.fillMaxWidth().padding(24.dp), Alignment.Center) {
                            CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 2.dp)
                        }
                    }

                    else -> item(contentType = "no-chapters") {
                        Text(
                            "Henüz bölüm yok.",
                            style = MaterialTheme.typography.bodyMedium,
                            color = TextMuted,
                            modifier = Modifier.padding(24.dp),
                        )
                    }
                }

                item(contentType = "reviews") {
                    Spacer(Modifier.height(16.dp))
                    ReviewSection(
                        reviews = state.reviews,
                        mine = state.myReview,
                        average = state.rating,
                        ratingCount = state.ratingCount,
                        signedIn = signedIn,
                        onSubmit = viewModel::submitReview,
                        onDelete = viewModel::deleteReview,
                        onVote = viewModel::vote,
                        onReport = viewModel::report,
                        onSignIn = onSignIn,
                    )
                    Spacer(Modifier.height(24.dp))
                }
            }
        }
    }
}

/**
 * Artwork, then the cover overlapping it, then the facts.
 *
 * The row of facts is the manga's own: a score, a chapter count and whoever
 * drew it. An anime's row carries a season and a runtime, and neither means
 * anything here.
 */
@Composable
private fun MangaHero(
    work: Work,
    bookmarked: Boolean,
    onBack: () -> Unit,
    onBookmark: () -> Unit,
    onRecommend: () -> Unit,
) {
    Column {
        Box(Modifier.fillMaxWidth().height(260.dp)) {
            AsyncImage(
                model = work.bannerUrl.ifBlank { work.posterUrl },
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize(),
            )

            // The title sits on this, so the artwork has to give way to it
            // rather than the other way round.
            Box(
                Modifier
                    .fillMaxSize()
                    .background(
                        Brush.verticalGradient(
                            0f to Color.Transparent,
                            0.55f to Color.Black.copy(alpha = 0.45f),
                            1f to Color.Black.copy(alpha = 0.92f),
                        )
                    )
            )

            Row(
                Modifier
                    .fillMaxWidth()
                    .statusBarsPadding()
                    .padding(horizontal = 4.dp, vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                IconButton(onClick = onBack) {
                    Icon(Icons.Filled.ArrowBack, stringResource(R.string.back), tint = Color.White)
                }

                Spacer(Modifier.weight(1f))

                IconButton(onClick = onBookmark) {
                    Icon(
                        if (bookmarked) Icons.Filled.Bookmark else Icons.Filled.BookmarkBorder,
                        stringResource(R.string.add_to_list),
                        tint = Color.White,
                    )
                }

                IconButton(onClick = onRecommend) {
                    Icon(Icons.Filled.Send, "Arkadaşına öner", tint = Color.White)
                }
            }
        }

        Row(
            // Pulled up over the artwork, the way a cover sits on a shelf.
            Modifier.fillMaxWidth().padding(horizontal = 16.dp).offset(y = (-44).dp),
            verticalAlignment = Alignment.Bottom,
        ) {
            Box {
                AsyncImage(
                    model = work.posterUrl,
                    contentDescription = null,
                    contentScale = ContentScale.Crop,
                    modifier = Modifier
                        .width(118.dp)
                        .aspectRatio(2f / 3f)
                        .clip(PosterShape)
                        .background(SurfaceCard),
                )

                Surface(
                    color = AccentPrimary,
                    shape = RoundedCornerShape(6.dp),
                    modifier = Modifier.padding(6.dp),
                ) {
                    Text(
                        "Manga",
                        style = MaterialTheme.typography.labelSmall,
                        fontWeight = FontWeight.SemiBold,
                        color = Color.White,
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 3.dp),
                    )
                }
            }

            Spacer(Modifier.width(14.dp))

            Column(Modifier.weight(1f).padding(bottom = 6.dp)) {
                Text(
                    work.title,
                    style = MaterialTheme.typography.headlineSmall,
                    fontWeight = FontWeight.Bold,
                    color = TextPrimary,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )

                if (work.titleEnglish.isNotBlank() && work.titleEnglish != work.title) {
                    Text(
                        work.titleEnglish,
                        style = MaterialTheme.typography.titleMedium,
                        color = AccentBright,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
            }
        }

        Spacer(Modifier.height(12.dp))

        Row(
            Modifier.fillMaxWidth().padding(horizontal = 16.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            if (work.score > 0) {
                Fact(Icons.Filled.Star, String.format("%.1f", work.score), StatusWarning)
            }

            if (work.totalEpisodes > 0) {
                Fact(Icons.Filled.MenuBook, "${work.totalEpisodes} Bölüm", AccentPrimary)
            }

            work.author.takeIf { it.isNotBlank() }?.let {
                Fact(Icons.Filled.Person, it, TextSecondary)
            }
        }

        if (work.genres.isNotEmpty()) {
            Spacer(Modifier.height(12.dp))
            GenreChips(work.genres)
        }

        Spacer(Modifier.height(16.dp))
    }
}

/** One fact in the row under the title: an icon and its value. */
@Composable
private fun Fact(
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    value: String,
    tint: Color,
) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(6.dp))
        Text(
            value,
            style = MaterialTheme.typography.labelLarge,
            color = TextSecondary,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

@Composable
private fun GenreChips(genres: List<String>) {
    LazyRow(
        contentPadding = PaddingValues(horizontal = 16.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        items(genres, key = { it }) { genre ->
            Surface(
                color = AccentPrimary.copy(alpha = 0.16f),
                shape = RoundedCornerShape(20.dp),
            ) {
                Text(
                    genre,
                    style = MaterialTheme.typography.labelMedium,
                    color = AccentBright,
                    modifier = Modifier.padding(horizontal = 14.dp, vertical = 7.dp),
                )
            }
        }
    }
}

@Composable
private fun AboutCard(synopsis: String) {
    if (synopsis.isBlank()) return

    var expanded by remember { mutableStateOf(false) }

    Card(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp),
        colors = CardDefaults.cardColors(containerColor = SurfaceCard),
    ) {
        Column(Modifier.padding(16.dp)) {
            Text(
                "Hakkında",
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
            )

            Spacer(Modifier.height(8.dp))

            Text(
                synopsis,
                style = MaterialTheme.typography.bodyMedium,
                color = TextSecondary,
                maxLines = if (expanded) Int.MAX_VALUE else 4,
                overflow = TextOverflow.Ellipsis,
            )

            AnimatedVisibility(synopsis.length > 180) {
                TextButton(
                    onClick = { expanded = !expanded },
                    modifier = Modifier.align(Alignment.End),
                ) {
                    Text(if (expanded) "Daha Az" else "Daha Fazla")
                    Icon(
                        if (expanded) Icons.Filled.KeyboardArrowUp else Icons.Filled.KeyboardArrowDown,
                        null,
                        Modifier.size(18.dp),
                    )
                }
            }
        }
    }
}

@Composable
private fun ChaptersHeader(count: Int, oldestFirst: Boolean, onToggleOrder: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp, top = 20.dp, bottom = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Filled.MenuBook, null, tint = AccentPrimary, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(8.dp))
        Text(
            if (count > 0) "Bölümler ($count)" else "Bölümler",
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.SemiBold,
            modifier = Modifier.weight(1f),
        )

        TextButton(onClick = onToggleOrder) {
            Icon(Icons.Filled.SwapVert, null, Modifier.size(18.dp))
            Spacer(Modifier.width(4.dp))
            Text(if (oldestFirst) "İlk önce" else "Son önce")
        }
    }
}

/**
 * One chapter.
 *
 * Its thumbnail is the manga's cover — a chapter's own first page is a title
 * card or a blank as often as it is a picture, and a list of those reads as
 * broken next to a list of covers.
 */
@Composable
private fun ChapterRow(chapter: Episode, cover: String, onRead: () -> Unit) {
    Card(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 5.dp)
            .clickable(onClick = onRead),
        colors = CardDefaults.cardColors(containerColor = SurfaceCard),
    ) {
        Row(
            Modifier.padding(10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            AsyncImage(
                model = chapter.thumbnailUrl.ifBlank { cover },
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier
                    .width(76.dp)
                    .height(52.dp)
                    .clip(RoundedCornerShape(8.dp))
                    .background(Color.Black.copy(alpha = 0.35f)),
            )

            Spacer(Modifier.width(12.dp))

            Surface(
                color = AccentPrimary.copy(alpha = 0.22f),
                shape = RoundedCornerShape(6.dp),
            ) {
                Text(
                    chapter.displayNumber,
                    style = MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.SemiBold,
                    color = AccentBright,
                    modifier = Modifier.padding(horizontal = 8.dp, vertical = 3.dp),
                )
            }

            Spacer(Modifier.width(10.dp))

            Column(Modifier.weight(1f)) {
                Text(
                    chapter.title.ifBlank { "Bölüm ${chapter.displayNumber}" },
                    style = MaterialTheme.typography.bodyLarge,
                    fontWeight = FontWeight.Medium,
                    color = TextPrimary,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )

                Text(
                    if (chapter.pageCount > 0) "${chapter.pageCount} sayfa" else "Sayfa yok",
                    style = MaterialTheme.typography.labelSmall,
                    color = if (chapter.pageCount > 0) TextMuted else StatusWarning,
                )
            }

            Spacer(Modifier.width(8.dp))

            Button(
                onClick = onRead,
                shape = RoundedCornerShape(20.dp),
                contentPadding = PaddingValues(horizontal = 16.dp, vertical = 8.dp),
            ) {
                Icon(Icons.Filled.PlayArrow, null, Modifier.size(16.dp))
                Spacer(Modifier.width(4.dp))
                Text("Oku", style = MaterialTheme.typography.labelLarge)
            }
        }
    }
}
