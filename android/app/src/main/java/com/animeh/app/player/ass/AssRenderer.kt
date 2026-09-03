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
    /** The family the script sets its dialogue in, from [AssParser.primaryFont]. */
    primaryFamily: String? = null,
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

    // The face the dialogue is set in — not simply the first one that
    // resolved.
    //
    // A script names a font per style: one for speech, others for signs,
    // titles and credits. Only some of them will be on the server, and taking
    // whichever happened to resolve first meant a signs font — a dozen arrows
    // and no alphabet — could end up drawing every line of dialogue. From the
    // outside that looks exactly like subtitles disappearing, and it appeared
    // the moment the missing fonts were uploaded, because before that the map
    // was empty and the system face was used.
    //
    // A Cue carries no family, so per-line fidelity is still out of reach
    // without libass; this picks the one family that has to be able to draw a
    // sentence, and leaves everything else to the fallback.
    val face = remember(typefaces, primaryFamily) {
        when (primaryFamily) {
            null -> typefaces.values.firstOrNull()
            else -> typefaces[AssParser.key(primaryFamily)]
        } ?: Typeface.DEFAULT_BOLD
    }

    // Whether that face can actually draw a given character.
    //
    // The last line of defence, and the one that makes "subtitles invisible"
    // impossible rather than unlikely: a font with no Turkish letters, or no
    // letters at all, is caught here and the cue is drawn in the system face
    // instead. Cached per face because `hasGlyph` shapes the character, and a
    // subtitle is redrawn on every frame.
    val probe = remember(face) { Paint().apply { typeface = face } }
    val glyphs = remember(face) { HashMap<Char, Boolean>() }

    val canDraw: (List<String>) -> Boolean = { lines ->
        lines.all { line ->
            line.all { character ->
                !character.isLetterOrDigit() ||
                    glyphs.getOrPut(character) { probe.hasGlyph(character.toString()) }
            }
        }
    }

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

            val lines = text.split('\n')

            paint.typeface = if (canDraw(lines)) face else Typeface.DEFAULT_BOLD
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

            val lineHeight = paint.fontSpacing

            // Measured rather than assumed: the block's height decides where
            // its top has to be for its bottom to land where the cue asked,
            // and a baseline is not the bottom of a glyph — descenders hang
            // below it, which is exactly what was being cut off.
            val metrics = paint.fontMetrics
            val ascent = -metrics.ascent
            val descent = metrics.descent
            val blockHeight = (lines.size - 1) * lineHeight + ascent + descent

            // `line` is a position, and `lineAnchor` says which edge of the
            // text that position marks. Ignoring the anchor was the bug: a
            // decoder that reports "the bottom of this block sits at 95% of
            // the frame" was being read as "the top does", which pushed a
            // whole line and a half off the bottom of the screen.
            val anchored = cue.line != Cue.DIMEN_UNSET && cue.lineType == Cue.LINE_TYPE_FRACTION

            val rawTop = if (anchored) {
                val anchorY = heightPx * cue.line

                when (cue.lineAnchor) {
                    Cue.ANCHOR_TYPE_END -> anchorY - blockHeight
                    Cue.ANCHOR_TYPE_MIDDLE -> anchorY - blockHeight / 2f
                    // ANCHOR_TYPE_START and unset: the position is the top.
                    else -> anchorY
                }
            } else {
                // Nothing placed it, and the default is where a subtitle
                // belongs: bottom centre, clear of the controls.
                heightPx - bottomMargin - blockHeight
            }

            // Whatever the decoder said, the text stays on screen. A cue that
            // would fall outside is clamped rather than clipped, because half
            // a sentence is worse than a sentence in slightly the wrong place.
            val top = rawTop.coerceIn(
                0f,
                (heightPx - blockHeight).coerceAtLeast(0f),
            )

            val firstBaseline = top + ascent

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
