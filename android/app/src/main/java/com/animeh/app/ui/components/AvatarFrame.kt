package com.animeh.app.ui.components

import android.content.Context
import android.os.Build
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import coil.ImageLoader
import coil.compose.AsyncImage
import coil.decode.GifDecoder
import coil.decode.ImageDecoderDecoder
import coil.imageLoader
import com.animeh.app.data.remote.dto.FrameDto
import com.animeh.app.ui.theme.SurfaceCard

/**
 * A profile picture, wearing whatever frame its owner bought.
 *
 * ### Why [animate] is a parameter and not just `frame.animated`
 *
 * A frame is a few hundred milliseconds of animation at 288×288. One of them
 * costs nothing. A friends list with twenty of them is twenty independent
 * decode loops running at once, and that is where the frame rate goes — which
 * is exactly the case this app already learned about the hard way on the home
 * screen.
 *
 * So animation is opt-in, per call site, and the rule is: **animate a frame
 * only where there is one of it, large**. The profile header, a public
 * profile, the one card the shop has focused, the three on the podium. Never
 * a list.
 *
 * The mechanism is two image loaders rather than a flag on the request. The
 * app's default loader has no animated decoder at all, so it decodes an
 * animated WebP or GIF to its first frame — a still, at the cost of a still.
 * The second loader, built here, adds the decoder. Nothing else differs: it is
 * made with `newBuilder()` from the default one, so the HTTP client and the
 * disk cache are shared and a frame is downloaded once however it is shown.
 */
@Composable
fun AvatarWithFrame(
    avatarUrl: Any?,
    frame: FrameDto?,
    size: Dp,
    modifier: Modifier = Modifier,
    animate: Boolean = false,
    contentDescription: String? = null,
) {
    val context = LocalContext.current
    val wantsAnimation = animate && frame?.animated == true
    val loader = if (wantsAnimation) animatedImageLoader(context) else context.imageLoader

    Box(modifier.size(size), contentAlignment = Alignment.Center) {
        AsyncImage(
            model = avatarUrl,
            contentDescription = contentDescription,
            contentScale = ContentScale.Crop,
            modifier = Modifier
                // The frame is a ring drawn around the picture, so the picture
                // has to sit inside it. How far inside is a property of the
                // artwork rather than something that can be measured: a 288px
                // frame leaves a hole, and this is how big that hole is as a
                // fraction of the whole. One number, in one place, if a set of
                // frames is drawn to different proportions.
                .size(size * AVATAR_INSET)
                .clip(CircleShape)
                .background(SurfaceCard),
        )

        if (frame != null && frame.url.isNotBlank()) {
            AsyncImage(
                model = frame.url,
                contentDescription = null,
                imageLoader = loader,
                contentScale = ContentScale.Fit,
                modifier = Modifier.fillMaxSize(),
            )
        }
    }
}

/**
 * How much of the frame's square the picture fills.
 *
 * Three quarters, near enough: the ring, its glow and whatever it hangs off
 * live in the outer eighth on each side.
 */
private const val AVATAR_INSET = 0.74f

/**
 * The loader that can play a frame.
 *
 * Built once for the process and remembered, not per composition: an
 * `ImageLoader` owns a memory cache, and one per avatar would be one cache per
 * avatar.
 */
@Composable
fun animatedImageLoader(context: Context): ImageLoader = remember(context) {
    animatedLoaderFor(context)
}

@Volatile
private var animatedLoader: ImageLoader? = null

private fun animatedLoaderFor(context: Context): ImageLoader {
    animatedLoader?.let { return it }

    return synchronized(FrameLoaderLock) {
        animatedLoader ?: context.applicationContext.imageLoader
            .newBuilder()
            .components {
                // `ImageDecoderDecoder` is the platform's own, and it plays
                // animated WebP, APNG and GIF — but it arrived in API 28.
                // Below that only GIF can be played at all, by Coil's own
                // decoder; an animated WebP there falls back to its first
                // frame, which is a still ring rather than a broken one.
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                    add(ImageDecoderDecoder.Factory())
                } else {
                    add(GifDecoder.Factory())
                }
            }
            .build()
            .also { animatedLoader = it }
    }
}

private object FrameLoaderLock

/** The size an avatar takes in a list, where frames never animate. */
val AvatarListSize: Dp = 44.dp
