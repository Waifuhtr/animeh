package com.animeh.app.player.ass

/**
 * A subtitle script, parsed far enough to draw it.
 *
 * This exists because going through ExoPlayer's text track threw away the two
 * things that matter most for ASS. A `Cue` is the rendered text and a place to
 * put it: the **style is resolved away**, so there is no way to know that the
 * sign at the top of the frame and the dialogue at the bottom are meant to be
 * different fonts, sizes and colours. And the track has to be selected,
 * loaded and decoded by the player before a single cue arrives, which is a
 * chain of things that can quietly not happen — an episode resumed part-way
 * through, a track the selector did not pick, a lazily loaded source nobody
 * asked for. Subtitles that need the track turning off and on again to appear
 * are that chain failing.
 *
 * The script is already downloaded, to find out which fonts it needs. Reading
 * the rest of it costs nothing and answers both: every line carries its own
 * style, and which lines are on screen is a comparison against the playhead
 * rather than something to be waited for.
 *
 * ## What this reads, and what it does not
 *
 * Read: script resolution, styles (font, size, weight, slant, colours,
 * outline, alignment, margins), dialogue timing, and the inline overrides that
 * change any of those for one line — `\an`, `\pos`, `\fn`, `\fs`, `\b`, `\i`,
 * `\c`, `\1c`, `\3c`, `\bord`.
 *
 * Not read: karaoke timing (`\k`), transforms (`\t`), animation (`\move`,
 * `\fad`), rotation (`\frx`, `\fry`, `\frz`), and vector drawing (`\p`). The
 * first four degrade to the plain line, which is the honest failure. A drawing
 * is skipped outright rather than printed — a line of `m 132 72 l 423 72` on
 * screen would be worse than the shape being absent.
 *
 * An override applies to the whole line rather than from where it appears.
 * Scripts that change colour mid-sentence therefore take the first colour;
 * doing better needs per-character runs, which is the next honest step and not
 * this one.
 */
data class AssStyle(
    val name: String,
    val family: String,
    val sizePx: Float,
    val bold: Boolean,
    val italic: Boolean,
    val fill: Int,
    val outline: Int,
    val outlineWidth: Float,
    val alignment: Int,
    val marginL: Int,
    val marginR: Int,
    val marginV: Int,
)

/** One line of dialogue, with every style question already answered. */
data class AssLine(
    val startMs: Long,
    val endMs: Long,
    /** Draw order: lower layers first, so a sign's shadow sits under it. */
    val layer: Int,
    /** The text as it should appear; `\N` is already a newline. */
    val text: String,
    val family: String,
    /** In script units — see [AssScript.playResY]. */
    val sizePx: Float,
    val bold: Boolean,
    val italic: Boolean,
    val fill: Int,
    val outline: Int,
    val outlineWidth: Float,
    /** Numpad alignment, 1 to 9. */
    val alignment: Int,
    val marginL: Int,
    val marginR: Int,
    val marginV: Int,
    /** From `\pos`, in script units, or null when the line is not placed. */
    val posX: Float?,
    val posY: Float?,
)

/**
 * A parsed script.
 *
 * [playResX] and [playResY] are the coordinate system every measurement in
 * [lines] is expressed in; the renderer scales from them to the surface it has.
 */
class AssScript(
    val playResX: Int,
    val playResY: Int,
    val lines: List<AssLine>,
    /** The family the dialogue style uses, for lines that ask for nothing. */
    val primaryFamily: String?,
) {

    /** Every family the script asks for, in the order first seen. */
    val families: List<String> = lines.map { it.family }.distinct()

    /**
     * The lines on screen at this moment, in the order they should be drawn.
     *
     * A linear scan: a script is a few hundred lines and this runs ten times a
     * second, which is nothing next to decoding a frame of video.
     */
    fun activeAt(positionMs: Long): List<AssLine> =
        lines.filter { positionMs >= it.startMs && positionMs < it.endMs }
            .sortedBy { it.layer }

    val isEmpty: Boolean get() = lines.isEmpty()
}

/**
 * Reading a script into [AssScript].
 *
 * Kept separate from [AssParser] — which answers "which fonts does this need"
 * without any notion of drawing — because this is the part that has to be
 * right about numbers, and it is worth being able to test on its own.
 */
object AssReader {

    /**
     * Parse a whole script.
     *
     * Never throws. A field it cannot read falls back to the style's value,
     * and a style it cannot read falls back to a plain white sans — a subtitle
     * in the wrong font is a subtitle, and an exception is a black screen.
     */
    fun read(content: String): AssScript {
        var playResX = 384
        var playResY = 288

        val styles = LinkedHashMap<String, AssStyle>()
        val lines = mutableListOf<AssLine>()

        var section = ""
        var styleFormat: List<String> = emptyList()
        var eventFormat: List<String> = emptyList()

        content.lineSequence().forEach { rawLine ->
            val line = rawLine.trim()

            if (line.startsWith("[") && line.endsWith("]")) {
                section = line.trim('[', ']').lowercase()
                return@forEach
            }

            when {
                section.startsWith("script") -> when {
                    line.startsWith("PlayResX:", true) ->
                        line.removePrefix("PlayResX:").trim().toIntOrNull()?.let { playResX = it }
                    line.startsWith("PlayResY:", true) ->
                        line.removePrefix("PlayResY:").trim().toIntOrNull()?.let { playResY = it }
                }

                section.contains("styles") -> when {
                    line.startsWith("Format:", true) ->
                        styleFormat = columns(line.removePrefix("Format:"))

                    line.startsWith("Style:", true) ->
                        style(line.removePrefix("Style:"), styleFormat)?.let { styles[it.name.lowercase()] = it }
                }

                section.contains("events") -> when {
                    line.startsWith("Format:", true) ->
                        eventFormat = columns(line.removePrefix("Format:"))

                    // `Comment:` lines are the script's own notes and are not
                    // drawn; this file uses them to mark the ending and the
                    // preview, which would otherwise appear as dialogue.
                    line.startsWith("Dialogue:", true) ->
                        event(line.removePrefix("Dialogue:"), eventFormat, styles)?.let { lines += it }
                }
            }
        }

        val primary = styles["default"]?.family ?: styles.values.firstOrNull()?.family

        return AssScript(
            playResX = playResX.coerceAtLeast(1),
            playResY = playResY.coerceAtLeast(1),
            lines = lines.sortedBy { it.startMs },
            primaryFamily = primary,
        )
    }

    /** One `Style:` row. */
    private fun style(rest: String, format: List<String>): AssStyle? {
        val fields = rest.split(",").map { it.trim() }

        fun at(name: String, fallback: Int): String =
            fields.getOrNull(format.indexOf(name).takeIf { it >= 0 } ?: fallback).orEmpty()

        val name = at("name", 0)
        val family = AssParser.normalise(at("fontname", 1)) ?: return null

        if (name.isBlank()) return null

        return AssStyle(
            name = name,
            family = family,
            sizePx = at("fontsize", 2).toFloatOrNull() ?: 48f,
            // ASS booleans are 0 and -1, and a few tools write 1.
            bold = at("bold", 7).trim().let { it == "-1" || it == "1" },
            italic = at("italic", 8).trim().let { it == "-1" || it == "1" },
            fill = colour(at("primarycolour", 3)) ?: WHITE,
            outline = colour(at("outlinecolour", 5)) ?: BLACK,
            outlineWidth = at("outline", 16).toFloatOrNull() ?: 2f,
            alignment = at("alignment", 18).toIntOrNull()?.takeIf { it in 1..9 } ?: 2,
            marginL = at("marginl", 19).toIntOrNull() ?: 0,
            marginR = at("marginr", 20).toIntOrNull() ?: 0,
            marginV = at("marginv", 21).toIntOrNull() ?: 0,
        )
    }

    /** One `Dialogue:` row, with its style applied and its overrides on top. */
    private fun event(
        rest: String,
        format: List<String>,
        styles: Map<String, AssStyle>,
    ): AssLine? {
        val textIndex = format.indexOf("text").takeIf { it >= 0 } ?: 9

        // Only the fields before the text are comma-separated; the text itself
        // may contain commas and is everything left over.
        val fields = rest.split(",", limit = textIndex + 1)
        if (fields.size <= textIndex) return null

        fun at(name: String, fallback: Int): String =
            fields.getOrNull(format.indexOf(name).takeIf { it >= 0 } ?: fallback).orEmpty().trim()

        val startMs = time(at("start", 1)) ?: return null
        val endMs = time(at("end", 2)) ?: return null
        if (endMs <= startMs) return null

        val raw = fields[textIndex]

        // A drawing is a path, not words. Printing its commands would put
        // "m 132 72 l 423 72" on screen, which is worse than the shape being
        // missing.
        if (DRAWING.containsMatchIn(raw)) return null

        val style = styles[at("style", 3).lowercase()]
            ?: styles.values.firstOrNull()
            ?: FALLBACK_STYLE

        val text = plain(raw)
        if (text.isBlank()) return null

        val overrides = raw.let { OVERRIDE_BLOCK.findAll(it).joinToString("") { m -> m.value } }

        val position = POSITION.find(overrides)
        val alignment = (ALIGNMENT.find(overrides)?.groupValues?.get(1)
            ?: LEGACY_ALIGNMENT.find(overrides)?.groupValues?.get(1))
            ?.toIntOrNull()
            ?.takeIf { it in 1..9 }

        return AssLine(
            startMs = startMs,
            endMs = endMs,
            layer = at("layer", 0).toIntOrNull() ?: 0,
            text = text,
            family = FAMILY.find(overrides)?.groupValues?.get(1)?.let { AssParser.normalise(it) } ?: style.family,
            sizePx = SIZE.find(overrides)?.groupValues?.get(1)?.toFloatOrNull() ?: style.sizePx,
            bold = BOLD.find(overrides)?.groupValues?.get(1)?.let { it != "0" } ?: style.bold,
            italic = ITALIC.find(overrides)?.groupValues?.get(1)?.let { it != "0" } ?: style.italic,
            fill = FILL.find(overrides)?.groupValues?.get(1)?.let { colour(it) } ?: style.fill,
            outline = BORDER_COLOUR.find(overrides)?.groupValues?.get(1)?.let { colour(it) } ?: style.outline,
            outlineWidth = BORDER.find(overrides)?.groupValues?.get(1)?.toFloatOrNull() ?: style.outlineWidth,
            alignment = alignment ?: style.alignment,
            // A line's own margin of zero means "use the style's".
            marginL = at("marginl", 5).toIntOrNull()?.takeIf { it > 0 } ?: style.marginL,
            marginR = at("marginr", 6).toIntOrNull()?.takeIf { it > 0 } ?: style.marginR,
            marginV = at("marginv", 7).toIntOrNull()?.takeIf { it > 0 } ?: style.marginV,
            posX = position?.groupValues?.get(1)?.trim()?.toFloatOrNull(),
            posY = position?.groupValues?.get(2)?.trim()?.toFloatOrNull(),
        )
    }

    /** `H:MM:SS.cc` as milliseconds. */
    fun time(value: String): Long? {
        val parts = value.trim().split(":")
        if (parts.size != 3) return null

        val hours = parts[0].toLongOrNull() ?: return null
        val minutes = parts[1].toLongOrNull() ?: return null
        val seconds = parts[2].toDoubleOrNull() ?: return null

        return ((hours * 3600 + minutes * 60) * 1000) + (seconds * 1000).toLong()
    }

    /**
     * `&HAABBGGRR` as ARGB.
     *
     * Two things catch people out: the channels are backwards, and the first
     * byte is *transparency* rather than opacity — `&H00FFFFFF` is opaque
     * white, not invisible white.
     */
    fun colour(value: String): Int? {
        val hex = value.trim().removePrefix("&H").removePrefix("&h").removeSuffix("&")
            .takeWhile { it.isDigit() || it in 'a'..'f' || it in 'A'..'F' }

        if (hex.isEmpty() || hex.length > 8) return null

        val packed = hex.toLongOrNull(16) ?: return null

        val blue = ((packed shr 16) and 0xFF).toInt()
        val green = ((packed shr 8) and 0xFF).toInt()
        val red = (packed and 0xFF).toInt()
        val transparency = if (hex.length > 6) ((packed shr 24) and 0xFF).toInt() else 0

        return ((255 - transparency) shl 24) or (red shl 16) or (green shl 8) or blue
    }

    /** The text as it should read: overrides gone, breaks real. */
    fun plain(raw: String): String =
        raw.replace(OVERRIDE_BLOCK, "")
            .replace(LINE_BREAK, "\n")
            .replace(HARD_SPACE, " ")
            .trim()

    private fun columns(rest: String): List<String> =
        rest.split(",").map { it.trim().lowercase() }

    private const val WHITE = 0xFFFFFFFF.toInt()
    private const val BLACK = 0xFF000000.toInt()

    private val FALLBACK_STYLE = AssStyle(
        name = "Default",
        family = "sans-serif",
        sizePx = 48f,
        bold = false,
        italic = false,
        fill = WHITE,
        outline = BLACK,
        outlineWidth = 2f,
        alignment = 2,
        marginL = 0,
        marginR = 0,
        marginV = 0,
    )

    private val OVERRIDE_BLOCK = Regex("""\{[^}]*\}""")
    private val LINE_BREAK = Regex("""\\[Nn]""")
    private val HARD_SPACE = Regex("""\\h""")

    /** A non-zero `\p` turns the line into vector drawing commands. */
    private val DRAWING = Regex("""\\p[1-9]""")

    private val POSITION = Regex("""\\pos\(([^,]+),([^)]+)\)""")
    private val ALIGNMENT = Regex("""\\an(\d)""")
    private val LEGACY_ALIGNMENT = Regex("""\\a(\d{1,2})(?!n)""")
    private val FAMILY = Regex("""\\fn([^\\}]+)""")
    private val SIZE = Regex("""\\fs([\d.]+)""")
    private val BOLD = Regex("""\\b([01])(?![a-z])""")
    private val ITALIC = Regex("""\\i([01])(?![a-z])""")
    private val FILL = Regex("""\\1?c(&H[0-9a-fA-F]+&?)""")
    private val BORDER_COLOUR = Regex("""\\3c(&H[0-9a-fA-F]+&?)""")
    private val BORDER = Regex("""\\bord([\d.]+)""")
}
