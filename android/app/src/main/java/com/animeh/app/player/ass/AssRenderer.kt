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
 * Draws the subtitles.
 *
 * Two sources, and the first one that has anything wins:
 *
 * 1. **The script itself**, parsed by [AssReader] and timed against the
 *    playhead. Every line arrives carrying its own font, size, weight,
 *    colours, alignment and position, because that is what an ASS style *is*.
 * 2. **The player's text track**, for subtitles that are not ASS — an SRT or a
 *    WebVTT still has to be drawn, and Media3 parses those perfectly well.
 *
 * The first exists because the second cannot answer the two questions that
 * matter here. A [Cue] is rendered text and a place to put it, with the style
 * resolved away: nothing distinguishes a sign at the top of the frame from the
 * dialogue at the bottom, so every line ends up in one font. And a cue only
 * arrives once a track has been selected, loaded and decoded, which is a chain
 * that can quietly not happen — an episode resumed part-way through being the
 * case that kept catching us.
 *
 * ## What this covers, and what it does not
 *
 * Covered: styles, per-line font, size, bold, italic, fill and outline colour,
 * outline width, numpad alignment, margins, `\pos`, and every override that
 * changes one of those for a line.
 *
 * **Not covered:** karaoke timing (`\k`), transforms (`\t`), movement and
 * fades (`\move`, `\fad`), rotation, and mid-line style changes — a line that
 * switches colour halfway takes the first colour. Vector drawings (`\p`) are
 * skipped by the reader rather than printed as commands. Those need a full
 * libass; adding it through the NDK is the extension point, and this
 * composable's signature does not change when it lands.
 */
@Composable
fun SubtitleLayer(
    /** Lines read from the script, when the subtitle is an ASS one. */
    lines: List<AssLine>,
    /** The script those lines are measured against. */
    script: AssScript?,
    /** The player's own cues, drawn when there is no script. */
    cues: List<Cue>,
    typefaces: Map<String, Typeface>,
    fontScale: Float = 1f,
    modifier: Modifier = Modifier,
) {
    val density = LocalDensity.current

    // One Paint reused across frames: allocating one per line per frame is the
    // classic way to make a subtitle overlay stutter.
    val paint = remember {
        Paint(Paint.ANTI_ALIAS_FLAG).apply {
            isSubpixelText = true
        }
    }

    // Faces are synthesised for weight and slant, so a family uploaded once
    // covers its bold and italic styles rather than needing three files.
    val faces = remember(typefaces) { HashMap<String, Typeface>() }

    val faceFor: (String, Boolean, Boolean) -> Typeface = { family, bold, italic ->
        val key = "${AssParser.key(family)}|$bold|$italic"

        faces.getOrPut(key) {
            // The family, or the system face — never another family from the
            // same script. Drawing a page of dialogue in the one decorative
            // font that happened to be uploaded is worse than drawing it in
            // the phone's own sans: the metrics are wrong, the glyphs may not
            // exist, and it looks like the app picked a font at random.
            val base = typefaces[AssParser.key(family)] ?: Typeface.SANS_SERIF

            val style = when {
                bold && italic -> Typeface.BOLD_ITALIC
                bold -> Typeface.BOLD
                italic -> Typeface.ITALIC
                else -> Typeface.NORMAL
            }

            if (style == Typeface.NORMAL) base else Typeface.create(base, style)
        }
    }

    if (script != null && lines.isNotEmpty()) {
        ScriptLayer(lines, script, faceFor, paint, fontScale, modifier)
        return
    }

    if (cues.isNotEmpty()) {
        CueLayer(cues, typefaces, paint, density, fontScale, modifier)
    }
}

/** The ASS path: every line placed the way its style asks. */
@Composable
private fun ScriptLayer(
    lines: List<AssLine>,
    script: AssScript,
    faceFor: (String, Boolean, Boolean) -> Typeface,
    paint: Paint,
    fontScale: Float,
    modifier: Modifier,
) {
    Canvas(modifier = modifier.fillMaxSize()) {
        val widthPx = size.width
        val heightPx = size.height
        if (widthPx <= 0f || heightPx <= 0f) return@Canvas

        // The script is written against its own resolution; this is the only
        // conversion between the two, and everything below is in script units
        // until it passes through here.
        val scaleX = widthPx / script.playResX
        val scaleY = heightPx / script.playResY

        val canvas = drawContext.canvas.nativeCanvas

        // Bands already taken, so two lines timed together do not land on top
        // of each other. Only lines the script did not place: one it placed
        // is where the typesetter wanted it.
        val occupied = mutableListOf<Pair<Float, Float>>()

        lines.forEach { line ->
            val rows = line.text.split('\n')

            paint.typeface = faceFor(line.family, line.bold, line.italic)
            paint.textSize = line.sizePx * scaleY * fontScale
            paint.textAlign = when (line.alignment % 3) {
                1 -> Paint.Align.LEFT
                0 -> Paint.Align.RIGHT
                else -> Paint.Align.CENTER
            }

            val metrics = paint.fontMetrics
            val ascent = -metrics.ascent
            val descent = metrics.descent
            val lineHeight = paint.fontSpacing
            val blockHeight = (rows.size - 1) * lineHeight + ascent + descent

            val widest = rows.maxOf { paint.measureText(it) }

            // Numpad alignment: 1-3 sit on the bottom, 4-6 in the middle,
            // 7-9 at the top; the remainder decides left, centre or right.
            val vertical = (line.alignment - 1) / 3

            val anchorX = line.posX?.let { it * scaleX } ?: when (paint.textAlign) {
                Paint.Align.LEFT -> line.marginL * scaleX
                Paint.Align.RIGHT -> widthPx - line.marginR * scaleX
                else -> (line.marginL * scaleX + (widthPx - line.marginR * scaleX)) / 2f
            }

            val anchorY = line.posY?.let { it * scaleY } ?: when (vertical) {
                2 -> line.marginV * scaleY
                1 -> heightPx / 2f
                else -> heightPx - line.marginV * scaleY
            }

            // `\pos` names the point the alignment corner sits on, so the top
            // of the block is derived from both rather than from the position
            // alone — reading it as the top is what pushed placed signs off
            // the frame.
            val rawTop = when (vertical) {
                2 -> anchorY
                1 -> anchorY - blockHeight / 2f
                else -> anchorY - blockHeight
            }

            var top = rawTop.coerceIn(0f, (heightPx - blockHeight).coerceAtLeast(0f))

            if (line.posY == null) {
                var attempts = 0
                while (attempts++ < MAX_STACKING) {
                    val clash = occupied.firstOrNull { (start, end) ->
                        top < end && start < top + blockHeight
                    } ?: break

                    top = clash.first - blockHeight - lineHeight * STACK_GAP_RATIO
                }

                top = top.coerceIn(0f, (heightPx - blockHeight).coerceAtLeast(0f))
                occupied += top to (top + blockHeight)
            }

            // Whatever the script said, the words stay on screen: it is
            // written for a 16:9 frame and a phone is not always one.
            val leftLimit = when (paint.textAlign) {
                Paint.Align.LEFT -> 0f
                Paint.Align.RIGHT -> widest
                else -> widest / 2f
            }
            val rightLimit = when (paint.textAlign) {
                Paint.Align.LEFT -> widthPx - widest
                Paint.Align.RIGHT -> widthPx
                else -> widthPx - widest / 2f
            }

            val x = if (leftLimit > rightLimit) widthPx / 2f else anchorX.coerceIn(leftLimit, rightLimit)
            val firstBaseline = top + ascent

            // The outline is drawn as a stroke under the fill, which is the
            // two-pass way to get an ASS border. Android centres a stroke on
            // the glyph edge and ASS measures it outwards, hence the doubling.
            val border = line.outlineWidth * scaleY * 2f

            rows.forEachIndexed { index, row ->
                val y = firstBaseline + index * lineHeight

                if (border > 0f) {
                    paint.style = Paint.Style.STROKE
                    paint.strokeWidth = border
                    paint.strokeJoin = Paint.Join.ROUND
                    paint.color = line.outline
                    canvas.drawText(row, x, y, paint)
                }

                paint.style = Paint.Style.FILL
                paint.color = line.fill
                canvas.drawText(row, x, y, paint)
            }
        }
    }
}

/**
 * The fallback path, for a subtitle that is not ASS.
 *
 * Deliberately plain: an SRT has no styling to honour, so this is one font,
 * bottom centre, out of the way of the controls.
 */
@Composable
private fun CueLayer(
    cues: List<Cue>,
    typefaces: Map<String, Typeface>,
    paint: Paint,
    density: androidx.compose.ui.unit.Density,
    fontScale: Float,
    modifier: Modifier,
) {
    val face = remember(typefaces) { typefaces.values.firstOrNull() ?: Typeface.SANS_SERIF }

    Canvas(modifier = modifier.fillMaxSize()) {
        val widthPx = size.width
        val heightPx = size.height
        if (widthPx <= 0f || heightPx <= 0f) return@Canvas

        val bottomMargin = with(density) { BOTTOM_MARGIN_DP.dp.toPx() }
        val sideMargin = with(density) { SIDE_MARGIN_DP.dp.toPx() }
        val canvas = drawContext.canvas.nativeCanvas
        val occupied = mutableListOf<Pair<Float, Float>>()

        cues.forEach { cue ->
            val text = cue.text?.toString().orEmpty()
            if (text.isBlank()) return@forEach

            val rows = text.split('\n')

            paint.typeface = face
            paint.textSize = when {
                cue.textSizeType == Cue.TEXT_SIZE_TYPE_FRACTIONAL && cue.textSize > 0f ->
                    heightPx * cue.textSize * fontScale
                else -> heightPx * DEFAULT_SIZE_FRACTION * fontScale
            }

            val positioned = cue.position != Cue.DIMEN_UNSET

            paint.textAlign = when {
                positioned && cue.positionAnchor == Cue.ANCHOR_TYPE_START -> Paint.Align.LEFT
                positioned && cue.positionAnchor == Cue.ANCHOR_TYPE_END -> Paint.Align.RIGHT
                positioned && cue.positionAnchor == Cue.ANCHOR_TYPE_MIDDLE -> Paint.Align.CENTER
                cue.textAlignment == android.text.Layout.Alignment.ALIGN_NORMAL -> Paint.Align.LEFT
                cue.textAlignment == android.text.Layout.Alignment.ALIGN_OPPOSITE -> Paint.Align.RIGHT
                else -> Paint.Align.CENTER
            }

            val metrics = paint.fontMetrics
            val ascent = -metrics.ascent
            val lineHeight = paint.fontSpacing
            val blockHeight = (rows.size - 1) * lineHeight + ascent + metrics.descent

            val anchored = cue.line != Cue.DIMEN_UNSET && cue.lineType == Cue.LINE_TYPE_FRACTION

            val rawTop = if (anchored) {
                val anchorY = heightPx * cue.line

                when (cue.lineAnchor) {
                    Cue.ANCHOR_TYPE_END -> anchorY - blockHeight
                    Cue.ANCHOR_TYPE_MIDDLE -> anchorY - blockHeight / 2f
                    else -> anchorY
                }
            } else {
                heightPx - bottomMargin - blockHeight
            }

            val floor = (heightPx - blockHeight).coerceAtLeast(0f)
            var top = rawTop.coerceIn(0f, floor)

            var attempts = 0
            while (attempts++ < MAX_STACKING) {
                val clash = occupied.firstOrNull { (start, end) ->
                    top < end && start < top + blockHeight
                } ?: break

                top = clash.first - blockHeight - lineHeight * STACK_GAP_RATIO
            }

            top = top.coerceIn(0f, floor)
            occupied += top to (top + blockHeight)

            val widest = rows.maxOf { paint.measureText(it) }

            val rawX = when {
                positioned -> widthPx * cue.position
                paint.textAlign == Paint.Align.LEFT -> sideMargin
                paint.textAlign == Paint.Align.RIGHT -> widthPx - sideMargin
                else -> widthPx / 2f
            }

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

            val x = if (leftLimit > rightLimit) widthPx / 2f else rawX.coerceIn(leftLimit, rightLimit)
            val firstBaseline = top + ascent

            rows.forEachIndexed { index, row ->
                val y = firstBaseline + index * lineHeight

                paint.style = Paint.Style.STROKE
                paint.strokeWidth = paint.textSize * OUTLINE_RATIO
                paint.strokeJoin = Paint.Join.ROUND
                paint.color = OutlineColor
                canvas.drawText(row, x, y, paint)

                paint.style = Paint.Style.FILL
                paint.color = FillColor
                canvas.drawText(row, x, y, paint)
            }
        }
    }
}

/** A size that reads at arm's length when the format declares none. */
private const val DEFAULT_SIZE_FRACTION = 0.052f
private const val BOTTOM_MARGIN_DP = 44f
private const val SIDE_MARGIN_DP = 24f
private const val OUTLINE_RATIO = 0.10f

/** How many times a line may be pushed up before it is left where it is. */
private const val MAX_STACKING = 8

/** The gap between two stacked lines, as a fraction of a line. */
private const val STACK_GAP_RATIO = 0.25f

private val OutlineColor = Color.Black.copy(alpha = 0.9f).toArgb()
private val FillColor = Color.White.toArgb()

private val Float.dp: androidx.compose.ui.unit.Dp
    get() = androidx.compose.ui.unit.Dp(this)
