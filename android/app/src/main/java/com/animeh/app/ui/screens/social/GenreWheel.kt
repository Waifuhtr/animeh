package com.animeh.app.ui.screens.social

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.nativeCanvas
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.animeh.app.data.remote.dto.GenreSliceDto
import com.animeh.app.ui.theme.TextMuted
import kotlin.math.cos
import kotlin.math.min
import kotlin.math.sin

/**
 * What somebody watches, as a ring.
 *
 * A donut rather than a pie because the labels go inside the band, where they
 * sit on their own slice's colour and stay readable — a pie puts them near the
 * centre where five of them collide.
 *
 * Slices are drawn in the order given, which the server sorts biggest first
 * and breaks ties alphabetically, so the same history always draws the same
 * wheel. One that reshuffles between loads looks broken even when the numbers
 * are right.
 */
@Composable
fun GenreWheel(
    slices: List<GenreSliceDto>,
    modifier: Modifier = Modifier,
) {
    if (slices.isEmpty()) return

    val total = slices.sumOf { it.count }.coerceAtLeast(1)
    val colors = wheelColors(slices.size)

    val labelPaint = android.graphics.Paint().apply {
        isAntiAlias = true
        color = android.graphics.Color.WHITE
        textAlign = android.graphics.Paint.Align.CENTER
    }

    Column(modifier.fillMaxWidth()) {
        Box(Modifier.fillMaxWidth().height(240.dp), Alignment.Center) {
            Canvas(Modifier.fillMaxWidth().height(240.dp)) {
                val diameter = min(size.width, size.height) * 0.82f
                val band = diameter * 0.26f
                val radius = (diameter - band) / 2f

                val centre = Offset(size.width / 2f, size.height / 2f)
                val topLeft = Offset(centre.x - radius, centre.y - radius)
                val arcSize = Size(radius * 2f, radius * 2f)

                labelPaint.textSize = band * 0.30f

                var start = -90f

                slices.forEachIndexed { index, slice ->
                    val sweep = 360f * slice.count / total

                    drawArc(
                        color = colors[index % colors.size],
                        startAngle = start,
                        sweepAngle = sweep,
                        useCenter = false,
                        topLeft = topLeft,
                        size = arcSize,
                        // A stroked arc is the band itself, so there is no
                        // second circle punched out of the middle and no seam
                        // where the two would meet.
                        style = Stroke(width = band),
                    )

                    // Only when the slice is wide enough for the word to fit
                    // inside its own colour; anything narrower is left to the
                    // legend below.
                    if (sweep >= MIN_LABEL_SWEEP) {
                        val middle = Math.toRadians((start + sweep / 2f).toDouble())
                        val x = centre.x + radius * cos(middle).toFloat()
                        val y = centre.y + radius * sin(middle).toFloat()

                        drawContext.canvas.nativeCanvas.drawText(
                            slice.name,
                            x,
                            // drawText places the baseline; the visual centre
                            // of the glyphs sits above it.
                            y + labelPaint.textSize / 3f,
                            labelPaint,
                        )
                    }

                    start += sweep
                }
            }
        }

        // The legend carries the counts and covers whatever was too narrow to
        // label inside the band.
        Spacer(Modifier.height(8.dp))

        Text(
            slices.joinToString("  ·  ") { "${it.name} ${it.count}" },
            style = MaterialTheme.typography.labelSmall,
            color = TextMuted,
            textAlign = TextAlign.Center,
            modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp),
        )
    }
}

/**
 * Slice colours.
 *
 * A fixed set rather than generated hues: seven hand-picked colours that hold
 * up against a dark background and against each other beat an even hue
 * rotation, where two neighbours land on the same muddy green.
 */
private fun wheelColors(count: Int): List<Color> {
    val palette = listOf(
        Color(0xFF8AA9FF),
        Color(0xFFE05252),
        Color(0xFF6FBF73),
        Color(0xFF9B7BD4),
        Color(0xFFF59332),
        Color(0xFF4FBFC4),
        Color(0xFFD98BC0),
    )

    return if (count <= palette.size) palette.take(count.coerceAtLeast(1)) else palette
}

/** Below this, the word is wider than the slice it would sit on. */
private const val MIN_LABEL_SWEEP = 34f
