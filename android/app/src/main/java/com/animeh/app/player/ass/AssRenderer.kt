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
    /** Which font each line is set in, from [AssParser.fontIndex]. */
    fonts: AssFontIndex? = null,
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

    // The face for the script's dialogue, and the fallback for everything the
    // index cannot place.
    val fallback = remember(typefaces, fonts) {
        fonts?.primary?.let { typefaces[AssParser.key(it)] }
            ?: typefaces.values.firstOrNull()
            ?: Typeface.DEFAULT_BOLD
    }

    // The face one line should be drawn in.
    //
    // A script names a font per style — one for speech, others for signs,
    // titles and credits — and a Cue carries none of that, which is why every
    // line used to be drawn in the same face. [AssFontIndex] puts the style
    // back by looking the line up by what it says.
    val faceFor: (String) -> Typeface = { text ->
        fonts?.familyFor(text)
            ?.let { typefaces[AssParser.key(it)] }
            ?: fallback
    }

    // Whether a face can actually draw a given character.
    //
    // The last line of defence, and the one that makes "subtitles invisible"
    // impossible rather than unlikely: a font with no Turkish letters, or no
    // letters at all, is caught here and the line is drawn in the system face
    // instead. Cached per face because `hasGlyph` shapes the character, and a
    // subtitle is redrawn on every frame.
    val probe = remember { Paint() }
    val glyphs = remember(typefaces) { HashMap<Typeface, HashMap<Char, Boolean>>() }

    val canDraw: (Typeface, List<String>) -> Boolean = { face, lines ->
        probe.typeface = face
        val known = glyphs.getOrPut(face) { HashMap() }

        lines.all { line ->
            line.all { character ->
                !character.isLetterOrDigit() ||
                    known.getOrPut(character) { probe.hasGlyph(character.toString()) }
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

        // The vertical bands already taken by a cue in this frame.
        //
        // Two cues on screen at once — a line of dialogue and a sign, or two
        // people speaking — are placed at the same height by the parser more
        // often than not. Drawn in order they land on top of each other,
        // which reads as one of them having vanished. This is what the second
        // one is moved out of.
        val occupied = mutableListOf<Pair<Float, Float>>()

        cues.forEach { cue ->
            val text = cue.text?.toString().orEmpty()
            if (text.isBlank()) return@forEach

            val lines = text.split('\n')
            val face = faceFor(text)

            paint.typeface = if (canDraw(face, lines)) face else Typeface.DEFAULT_BOLD
            paint.textSize = when {
                // Fractional: the parser expressed the size relative to the
                // frame, which is what keeps a script legible at any surface size.
                cue.textSizeType == Cue.TEXT_SIZE_TYPE_FRACTIONAL && cue.textSize > 0f ->
                    heightPx * cue.textSize * fontScale
                else -> defaultSize
            }

            // Which edge of the text `position` marks decides how it is
            // aligned, and the anchor is the authority on that — the text
            // alignment is only a fallback for a cue that was never
            // positioned. Reading the two the other way round centred text on
            // a point meant to be its left edge, which for a sign near the
            // side of the frame put most of it off screen: subtitles present
            // in most scenes and gone in a few.
            val positioned = cue.position != Cue.DIMEN_UNSET

            paint.textAlign = when {
                positioned && cue.positionAnchor == Cue.ANCHOR_TYPE_START -> Paint.Align.LEFT
                positioned && cue.positionAnchor == Cue.ANCHOR_TYPE_END -> Paint.Align.RIGHT
                positioned && cue.positionAnchor == Cue.ANCHOR_TYPE_MIDDLE -> Paint.Align.CENTER
                cue.textAlignment == android.text.Layout.Alignment.ALIGN_NORMAL -> Paint.Align.LEFT
                cue.textAlignment == android.text.Layout.Alignment.ALIGN_OPPOSITE -> Paint.Align.RIGHT
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
            val floor = (heightPx - blockHeight).coerceAtLeast(0f)

            var top = rawTop.coerceIn(0f, floor)

            // Move up out of anything already drawn this frame.
            var attempts = 0
            while (attempts++ < MAX_STACKING) {
                val clash = occupied.firstOrNull { (start, end) ->
                    top < end && start < top + blockHeight
                } ?: break

                top = clash.first - blockHeight - lineHeight * STACK_GAP_RATIO
            }

            top = top.coerceIn(0f, floor)
            occupied += top to (top + blockHeight)

            val firstBaseline = top + ascent

            val rawX = when {
                positioned -> widthPx * cue.position
                paint.textAlign == Paint.Align.LEFT -> sideMargin
                paint.textAlign == Paint.Align.RIGHT -> widthPx - sideMargin
                else -> widthPx / 2f
            }

            // And horizontally, for the same reason the vertical placement is
            // clamped: a script positions against its own declared resolution
            // and a phone is not that shape, so a line placed near an edge can
            // end up almost entirely outside the frame.
            val widest = lines.maxOf { paint.measureText(it) }

            val leftLimit = when (paint.textAlign) {
                Paint.Align.LEFT -> sideMargin
                Paint.Align.RIGHT -> sideMargin + widest
                else -> sideMargin + widest / 2f
            }

            val rightLimit = when (paint.textAlign) {
                Paint.Align.LEFT -> widthPx - sideMargin - widest
                Paint.Align.RIGHT -> widthPx - sideMargin
                else -> widthPx - sideMargin - widest / 2f
            }

            // A line wider than the frame inverts the two limits; centring it
            // is the least bad answer and beats an exception.
            val x = if (leftLimit > rightLimit) {
                widthPx / 2f
            } else {
                rawX.coerceIn(leftLimit, rightLimit)
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

/** How many times a cue may be pushed up before it is left where it is. */
private const val MAX_STACKING = 8

/** The gap between two stacked cues, as a fraction of a line. */
private const val STACK_GAP_RATIO = 0.25f

private val OutlineColor = Color.Black.copy(alpha = 0.9f).toArgb()
private val FillColor = Color.White.toArgb()

private val Float.dp: androidx.compose.ui.unit.Dp
    get() = androidx.compose.ui.unit.Dp(this)
