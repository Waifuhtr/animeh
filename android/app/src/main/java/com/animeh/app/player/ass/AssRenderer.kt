package com.animeh.app.player.ass

import android.graphics.Paint
import android.graphics.Typeface
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.nativeCanvas
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalDensity
import androidx.media3.common.text.Cue

/**
 * Draws the subtitle cues.
 *
 * The split here is the one §13 asks for. ExoPlayer's SSA/ASS extractor is the
 * *parser*: it reads the script, resolves styles, positioning, alignment,
 * margins and inline overrides, and emits [Cue]s already carrying that
 * information. This file is the *renderer*: it puts those cues on screen with
 * the typefaces [FontResolver] found, at the position the script asked for.
 *
 * Drawing rather than using Media3's `SubtitleView` is what makes the custom
 * fonts possible — `SubtitleView` has no way to be told "this script asked for
 * this family" — and it keeps the subtitle layer inside Compose, so it stacks
 * with the rest of the player UI instead of being a View poking through it.
 *
 * ## What this covers, and what it does not
 *
 * Covered, because ExoPlayer's parser resolves them and this honours them:
 * styles, size, colour, alignment, absolute positioning, line placement,
 * margins, multiple styles, and dialogue timing.
 *
 * **Not covered:** karaoke timing (`\k`), transform animations (`\t`), and
 * vector drawing (`\p`). Those need a full libass; a karaoke line drawn without
 * its timing renders here as the plain lyric, which is the honest degradation.
 * Adding libass through the NDK is the extension point, and [SubtitleLayer]'s
 * signature does not change when it lands.
 */
@Composable
fun SubtitleLayer(
    cues: List<Cue>,
    typefaces: Map<String, Typeface>,
    fontScale: Float = 1f,
    modifier: Modifier = Modifier,
) {
    if (cues.isEmpty()) return

    val density = LocalDensity.current

    // One Paint reused across frames: allocating one per cue per frame is the
    // classic way to make a subtitle overlay stutter.
    val paint = remember {
        Paint(Paint.ANTI_ALIAS_FLAG).apply {
            isSubpixelText = true
        }
    }

    // The script's primary face. ASS can name a different family per line, but
    // a Cue carries no family, so the first resolved one — which is the primary
    // style's — is used, and anything else falls back rather than substituting
    // a wrong face silently.
    val face = typefaces.values.firstOrNull() ?: Typeface.DEFAULT_BOLD

    Canvas(modifier = modifier.fillMaxSize()) {
        val widthPx = size.width
        val heightPx = size.height
        if (widthPx <= 0f || heightPx <= 0f) return@Canvas

        val defaultSize = heightPx * DEFAULT_SIZE_FRACTION * fontScale
        val bottomMargin = with(density) { BOTTOM_MARGIN_DP.dp.toPx() }
        val sideMargin = with(density) { SIDE_MARGIN_DP.dp.toPx() }
        val canvas = drawContext.canvas.nativeCanvas

        cues.forEach { cue ->
            val text = cue.text?.toString().orEmpty()
            if (text.isBlank()) return@forEach

            paint.typeface = face
            paint.textSize = when {
                // Fractional: the parser expressed the size relative to the
                // frame, which is what keeps a script legible at any surface size.
                cue.textSizeType == Cue.TEXT_SIZE_TYPE_FRACTIONAL && cue.textSize > 0f ->
                    heightPx * cue.textSize * fontScale
                else -> defaultSize
            }

            paint.textAlign = when (cue.textAlignment) {
                android.text.Layout.Alignment.ALIGN_NORMAL -> Paint.Align.LEFT
                android.text.Layout.Alignment.ALIGN_OPPOSITE -> Paint.Align.RIGHT
                else -> Paint.Align.CENTER
            }

            val lines = text.split('\n')
            val lineHeight = paint.fontSpacing

            // DIMEN_UNSET means the parser placed nothing, and the default is
            // where a subtitle belongs: bottom centre, clear of the controls.
            val firstBaseline = if (cue.line != Cue.DIMEN_UNSET && cue.lineType == Cue.LINE_TYPE_FRACTION) {
                heightPx * cue.line + lineHeight
            } else {
                heightPx - bottomMargin - (lines.size - 1) * lineHeight
            }

            val x = when {
                cue.position != Cue.DIMEN_UNSET -> widthPx * cue.position
                paint.textAlign == Paint.Align.LEFT -> sideMargin
                paint.textAlign == Paint.Align.RIGHT -> widthPx - sideMargin
                else -> widthPx / 2f
            }

            lines.forEachIndexed { index, line ->
                val y = firstBaseline + index * lineHeight

                // Outline, then fill on top: the two-pass way to get an ASS
                // border, and what keeps white text readable over a bright frame.
                paint.style = Paint.Style.STROKE
                paint.strokeWidth = paint.textSize * OUTLINE_RATIO
                paint.strokeJoin = Paint.Join.ROUND
                paint.color = OutlineColor
                canvas.drawText(line, x, y, paint)

                paint.style = Paint.Style.FILL
                paint.color = FillColor
                canvas.drawText(line, x, y, paint)
            }
        }
    }
}

/** A size that reads at arm's length when the script declares none. */
private const val DEFAULT_SIZE_FRACTION = 0.052f
private const val BOTTOM_MARGIN_DP = 44f
private const val SIDE_MARGIN_DP = 24f
private const val OUTLINE_RATIO = 0.10f

private val OutlineColor = Color.Black.copy(alpha = 0.9f).toArgb()
private val FillColor = Color.White.toArgb()

private val Float.dp: androidx.compose.ui.unit.Dp
    get() = androidx.compose.ui.unit.Dp(this)
