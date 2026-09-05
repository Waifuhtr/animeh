package com.animeh.app.ui.screens

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.EnterTransition
import androidx.compose.animation.core.*
import androidx.compose.animation.fadeOut
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.scale
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.animeh.app.ui.theme.AccentBright
import com.animeh.app.ui.theme.AccentDeep
import com.animeh.app.ui.theme.AccentPrimary
import com.animeh.app.ui.theme.SurfaceBase
import kotlinx.coroutines.delay

/**
 * What the app shows while it has nothing to show.
 *
 * Drawn over the app rather than instead of it, so everything underneath is
 * composing and loading the whole time this is up — the point is to hide the
 * skeleton, not to delay reaching it.
 *
 * Three timings, and each is there for a different failure:
 *
 * - a **minimum**, because a launch that resolves in 200ms would otherwise
 *   flash this on and off, which looks like a glitch rather than a splash;
 * - the **gate**, which is the actual answer to "is there anything to show";
 * - a **maximum**, because a phone with no connection never gets an answer,
 *   and an animation that runs forever is a broken app.
 */
private const val MINIMUM_MS = 900L
private const val MAXIMUM_MS = 6_000L

@Composable
fun SplashOverlay(ready: Boolean, modifier: Modifier = Modifier) {
    var visible by remember { mutableStateOf(true) }
    var minimumPassed by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        delay(MINIMUM_MS)
        minimumPassed = true

        // The backstop runs regardless of the gate: this is the case where
        // nothing is coming.
        delay(MAXIMUM_MS - MINIMUM_MS)
        visible = false
    }

    LaunchedEffect(ready, minimumPassed) {
        if (ready && minimumPassed) visible = false
    }

    AnimatedVisibility(
        visible = visible,
        enter = EnterTransition.None,
        exit = fadeOut(tween(durationMillis = 420)),
        modifier = modifier,
    ) {
        SplashContent()
    }
}

@Composable
private fun SplashContent() {
    val transition = rememberInfiniteTransition(label = "splash")

    // The glow breathing behind the mark. Slow enough to read as light rather
    // than as something flashing for attention.
    val glow by transition.animateFloat(
        initialValue = 0.55f,
        targetValue = 1f,
        animationSpec = infiniteRepeatable(
            animation = tween(1_800, easing = FastOutSlowInEasing),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "glow",
    )

    val spin by transition.animateFloat(
        initialValue = 0f,
        targetValue = 360f,
        animationSpec = infiniteRepeatable(animation = tween(1_400, easing = LinearEasing)),
        label = "spin",
    )

    // The mark arrives rather than appearing: one scale, once, on the way in.
    val enter = remember { Animatable(0.86f) }
    LaunchedEffect(Unit) {
        enter.animateTo(1f, tween(620, easing = FastOutSlowInEasing))
    }

    Box(
        Modifier
            .fillMaxSize()
            .background(SurfaceBase)
            // A coloured box does not stop a touch in Compose, and the home
            // screen is live underneath this one: without swallowing them,
            // a tap during the launch animation opens whatever card happens
            // to be behind it.
            .pointerInput(Unit) {
                awaitPointerEventScope {
                    while (true) {
                        awaitPointerEvent().changes.forEach { it.consume() }
                    }
                }
            },
        contentAlignment = Alignment.Center,
    ) {
        // A pool of accent light under the wordmark, not a circle with an
        // edge: the edge is what would make it look like a misplaced shape.
        Canvas(Modifier.fillMaxSize()) {
            drawCircle(
                brush = Brush.radialGradient(
                    colors = listOf(
                        AccentDeep.copy(alpha = 0.34f * glow),
                        AccentDeep.copy(alpha = 0.10f * glow),
                        Color.Transparent,
                    ),
                    center = center,
                    radius = size.minDimension * 0.62f,
                ),
                radius = size.minDimension * 0.62f,
                center = center,
            )
        }

        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Box(contentAlignment = Alignment.Center) {
                // The ring is the loading indicator, but it is drawn as part
                // of the mark rather than parked under it: one thing moving on
                // the screen instead of two.
                Canvas(
                    Modifier
                        .size(132.dp)
                        .rotate(spin)
                ) {
                    val stroke = 3.dp.toPx()
                    val inset = stroke / 2f

                    drawArc(
                        brush = Brush.sweepGradient(
                            listOf(Color.Transparent, AccentPrimary, AccentBright, Color.Transparent)
                        ),
                        startAngle = 0f,
                        sweepAngle = 250f,
                        useCenter = false,
                        topLeft = Offset(inset, inset),
                        size = Size(size.width - stroke, size.height - stroke),
                        style = Stroke(width = stroke, cap = StrokeCap.Round),
                    )
                }

                Text(
                    text = "Animeh",
                    modifier = Modifier.scale(enter.value),
                    style = TextStyle(
                        fontSize = 34.sp,
                        fontWeight = FontWeight.Bold,
                        brush = Brush.horizontalGradient(
                            listOf(AccentBright, Color.White, AccentPrimary)
                        ),
                    ),
                )
            }

            Spacer(Modifier.height(20.dp))

            Text(
                text = "Anime dünyası yükleniyor…",
                style = MaterialTheme.typography.bodySmall,
                color = AccentBright.copy(alpha = 0.55f + 0.35f * glow),
            )
        }
    }
}
