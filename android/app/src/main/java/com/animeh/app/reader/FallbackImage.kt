package com.animeh.app.reader

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage
import coil.compose.AsyncImagePainter
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.TextMuted

/**
 * One page, with somewhere else to look when the first address fails.
 *
 * Backblaze serves the same object at two addresses — the friendly
 * `f00N.backblazeb2.com/file/...` and the S3-compatible
 * `bucket.s3.region...` — and the friendly one goes down by itself, for
 * minutes at a time, while the other keeps answering. A reader that treats
 * the first failure as final shows a grey hole in the middle of a chapter
 * that is otherwise perfectly readable.
 *
 * So a page arrives as a list of addresses, best first, and this walks it.
 * The server builds that list: our own bucket, then the same object's other
 * address, then the manga site's copy, then that one's other address. Each is
 * tried once; when they are all gone the page offers a retry, which starts
 * again from the top — because the usual reason all four failed is that the
 * phone was on a train.
 */
@Composable
fun FallbackImage(
    candidates: List<String>,
    contentDescription: String?,
    modifier: Modifier = Modifier,
    /** Whether this page is in our own bucket, which changes what a failure means. */
    mirrored: Boolean = true,
) {
    if (candidates.isEmpty()) {
        PagePlaceholder(modifier) { Text("Sayfa yok", color = TextMuted) }
        return
    }

    // Which address is being tried, and how many times the whole list has
    // been walked. `attempt` is part of the key so that pressing retry after
    // exhausting the list actually re-issues the requests rather than being
    // served the failures Coil has already cached.
    var index by remember(candidates) { mutableIntStateOf(0) }
    var attempt by remember(candidates) { mutableIntStateOf(0) }

    val exhausted = index >= candidates.size

    if (exhausted) {
        PagePlaceholder(
            modifier.clickable {
                index = 0
                attempt += 1
            }
        ) {
            Icon(Icons.Filled.Refresh, null, tint = TextMuted, modifier = Modifier.size(28.dp))
            Spacer(Modifier.height(8.dp))
            Text(
                if (mirrored) {
                    "Sayfa yüklenemedi — dokunup tekrar dene"
                } else {
                    // The page is still on whoever we imported it from, and
                    // those hosts refuse an app asking directly. Saying so
                    // beats a spinner that never stops: the fix is a copy
                    // run, and the person reading this is usually the admin.
                    "Sayfa hâlâ kaynak sitede ve oradan açılmıyor.\n" +
                        "Yönetim → Manga → \u201cGörselleri kovamıza kopyala\u201d\n" +
                        "Dokunup tekrar deneyebilirsin."
                },
                style = MaterialTheme.typography.labelMedium,
                color = TextMuted,
                textAlign = TextAlign.Center,
            )
        }
        return
    }

    // Reset when the address changes, or the spinner from the first attempt
    // sits over the second one and a page that is loading looks identical to
    // a page that is stuck.
    var loading by remember(candidates, index, attempt) { mutableIntStateOf(1) }

    Box(modifier) {
        AsyncImage(
            // The attempt counter rides along in the model so a retry is a
            // different request as far as the cache is concerned.
            model = if (attempt == 0) candidates[index] else "${candidates[index]}#retry$attempt",
            contentDescription = contentDescription,
            contentScale = ContentScale.FillWidth,
            modifier = Modifier.fillMaxWidth(),
            onState = { state ->
                when (state) {
                    is AsyncImagePainter.State.Error -> {
                        // Straight to the next address. No delay: the point of
                        // a second address is that it is a different server,
                        // and waiting to ask it only holds up the page.
                        index += 1
                    }

                    is AsyncImagePainter.State.Success -> loading = 0
                    else -> Unit
                }
            },
        )

        if (loading == 1) {
            PagePlaceholder(Modifier.fillMaxWidth()) {
                CircularProgressIndicator(strokeWidth = 2.dp, modifier = Modifier.size(26.dp))
            }
        }
    }
}

/**
 * The space a page occupies before it has one.
 *
 * A fixed ratio rather than nothing at all: a list whose items have no height
 * until their images arrive jumps under the reader's thumb on every load, and
 * a manga page is close enough to 2:3 that reserving it is almost always
 * right.
 */
@Composable
private fun PagePlaceholder(
    modifier: Modifier = Modifier,
    content: @Composable () -> Unit,
) {
    Box(
        modifier
            .fillMaxWidth()
            .aspectRatio(PAGE_RATIO)
            .background(SurfaceCard.copy(alpha = 0.35f))
            .padding(24.dp),
        contentAlignment = Alignment.Center,
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) { content() }
    }
}

/** Width over height, for the space a page is given before it loads. */
private const val PAGE_RATIO = 2f / 3f
