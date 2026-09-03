package com.animeh.app.player.ass

/**
 * Enough of the ASS/SSA format to know what a script needs.
 *
 * This is *not* a renderer. §13 is explicit that plain text views are not
 * acceptable for ASS and that a real renderer is required; drawing is
 * [AssRenderer]'s job. What this does is the part that has to happen before
 * rendering can start: work out which font families the script asks for, so
 * they can be fetched (§14) and the missing ones reported.
 *
 * Two places a family name appears, and both are read:
 *
 * 1. The `Fontname` field of each `Style:` line in `[V4+ Styles]`.
 * 2. An `\fn` override inside a dialogue line, which can name a font no style
 *    declares — the usual reason a "complete" font set still renders wrong.
 *
 * Pure Kotlin, no Android types, so the extraction is unit-testable and can be
 * checked against the same expected output as the TypeScript implementation in
 * the web player.
 */
object AssParser {

    /**
     * Families the script needs, in the order first seen.
     *
     * @param content the .ass file, decoded as text.
     */
    fun requiredFonts(content: String): List<String> {
        val families = LinkedHashSet<String>()

        var section = ""
        var styleFormat: List<String> = emptyList()

        content.lineSequence().forEach { rawLine ->
            val line = rawLine.trim()

            if (line.startsWith("[") && line.endsWith("]")) {
                section = line.trim('[', ']').lowercase()
                styleFormat = emptyList()
                return@forEach
            }

            when {
                section.contains("styles") && line.startsWith("Format:", ignoreCase = true) -> {
                    // The Format line names the columns; a Style line's fields
                    // are positional against it, and scripts do vary the order.
                    styleFormat = line.removePrefix("Format:").split(",").map { it.trim().lowercase() }
                }

                section.contains("styles") && line.startsWith("Style:", ignoreCase = true) -> {
                    val fields = line.removePrefix("Style:").split(",").map { it.trim() }
                    val index = styleFormat.indexOf("fontname")

                    val name = when {
                        index >= 0 && index < fields.size -> fields[index]
                        // No Format line: fall back to the standard V4+ layout,
                        // where Fontname is the second field.
                        fields.size > 1 -> fields[1]
                        else -> ""
                    }

                    normalise(name)?.let { families += it }
                }

                section.contains("events") && line.startsWith("Dialogue:", ignoreCase = true) -> {
                    FONT_OVERRIDE.findAll(line).forEach { match ->
                        normalise(match.groupValues[1])?.let { families += it }
                    }
                }
            }
        }

        return families.toList()
    }

    /**
     * The family the script's dialogue is set in.
     *
     * A script names a font per style: one for dialogue, and usually others
     * for signs, titles and credits. Only the dialogue one has to be able to
     * draw a sentence — a signs font is often a dozen arrows and no alphabet
     * at all — so it is the one the renderer should reach for, and picking
     * "whichever font happened to resolve first" is how a page of dialogue
     * ends up drawn in a font with no letters in it.
     *
     * The style named `Default` wins where there is one, because that is the
     * convention every ASS tool follows; otherwise the first style declared,
     * which is where the dialogue style sits in practice.
     *
     * @param content the .ass file, decoded as text.
     * @return the family, or null when the script declares no styles.
     */
    fun primaryFont(content: String): String? {
        var section = ""
        var styleFormat: List<String> = emptyList()

        var first: String? = null
        var default: String? = null

        content.lineSequence().forEach { rawLine ->
            val line = rawLine.trim()

            if (line.startsWith("[") && line.endsWith("]")) {
                section = line.trim('[', ']').lowercase()
                styleFormat = emptyList()
                return@forEach
            }

            if (!section.contains("styles")) return@forEach

            if (line.startsWith("Format:", ignoreCase = true)) {
                styleFormat = line.removePrefix("Format:").split(",").map { it.trim().lowercase() }
                return@forEach
            }

            if (!line.startsWith("Style:", ignoreCase = true)) return@forEach

            val fields = line.removePrefix("Style:").split(",").map { it.trim() }

            val nameIndex = styleFormat.indexOf("name").takeIf { it >= 0 } ?: 0
            val fontIndex = styleFormat.indexOf("fontname").takeIf { it >= 0 } ?: 1

            val family = normalise(fields.getOrNull(fontIndex).orEmpty()) ?: return@forEach

            if (first == null) first = family

            if (default == null && fields.getOrNull(nameIndex)?.trim().equals("Default", ignoreCase = true)) {
                default = family
            }
        }

        return default ?: first
    }

    /**
     * Which font each line of a script is set in.
     *
     * A script names a font per style, and a subtitle that uses five styles is
     * meant to be drawn in five fonts. Media3's [androidx.media3.common.text.Cue]
     * carries none of that — it is the rendered text and a position, with the
     * style resolved away — so drawing everything in one face was the only
     * thing the renderer could do with it.
     *
     * The script is already downloaded to find out which fonts it needs, so
     * the answer is here for free: read each dialogue line with the style it
     * uses, and index the *rendered* form of its text. The renderer then looks
     * a cue up by what it says. Two different lines with the same words and
     * different styles is the case this cannot tell apart; the first one wins,
     * which is the ordinary style rather than a sign.
     *
     * @param content the .ass file, decoded as text.
     */
    fun fontIndex(content: String): AssFontIndex {
        val styleFonts = mutableMapOf<String, String>()
        val byText = mutableMapOf<String, String>()

        var section = ""
        var styleFormat: List<String> = emptyList()
        var eventFormat: List<String> = emptyList()

        content.lineSequence().forEach { rawLine ->
            val line = rawLine.trim()

            if (line.startsWith("[") && line.endsWith("]")) {
                section = line.trim('[', ']').lowercase()
                return@forEach
            }

            if (section.contains("styles")) {
                if (line.startsWith("Format:", ignoreCase = true)) {
                    styleFormat = columns(line.removePrefix("Format:"))
                    return@forEach
                }

                if (!line.startsWith("Style:", ignoreCase = true)) return@forEach

                // A style line's fields are positional against the Format
                // line, and scripts do vary the order.
                val fields = line.removePrefix("Style:").split(",").map { it.trim() }
                val name = fields.getOrNull(styleFormat.indexOf("name").takeIf { it >= 0 } ?: 0).orEmpty()
                val family = normalise(fields.getOrNull(styleFormat.indexOf("fontname").takeIf { it >= 0 } ?: 1).orEmpty())

                if (name.isNotBlank() && family != null) {
                    styleFonts[name.lowercase()] = family
                }

                return@forEach
            }

            if (!section.contains("events")) return@forEach

            if (line.startsWith("Format:", ignoreCase = true)) {
                eventFormat = columns(line.removePrefix("Format:"))
                return@forEach
            }

            if (!line.startsWith("Dialogue:", ignoreCase = true)) return@forEach

            val styleIndex = eventFormat.indexOf("style").takeIf { it >= 0 } ?: 3
            val textIndex = eventFormat.indexOf("text").takeIf { it >= 0 } ?: 9

            // Only the fields before the text are comma-separated; the text
            // itself may contain commas and is everything that is left.
            val fields = line.removePrefix("Dialogue:").split(",", limit = textIndex + 1)
            if (fields.size <= textIndex) return@forEach

            val raw = fields[textIndex]
            val key = textKey(raw)
            if (key.isEmpty() || byText.containsKey(key)) return@forEach

            // An inline \fn override names a font no style declares, and is
            // the usual reason a "complete" font set still renders wrong.
            val override = FONT_OVERRIDE.find(raw)?.groupValues?.get(1)?.let { normalise(it) }
            val family = override ?: styleFonts[fields.getOrNull(styleIndex)?.trim()?.lowercase()]

            if (family != null) {
                byText[key] = family
            }
        }

        return AssFontIndex(byText, primaryFont(content))
    }

    /**
     * A line of dialogue as the renderer will see it.
     *
     * Override blocks go, the two line breaks become one, and whitespace is
     * flattened — which is roughly what Media3's parser does on its way to a
     * Cue, and is what makes the two sides agree on what a line "is".
     */
    fun textKey(text: String): String =
        text.replace(OVERRIDE_BLOCK, "")
            .replace(LINE_BREAK, "\n")
            .replace(HARD_SPACE, " ")
            .replace(WHITESPACE, " ")
            .trim()
            .lowercase()

    /** A Format line's column names, lower-cased. */
    private fun columns(rest: String): List<String> =
        rest.split(",").map { it.trim().lowercase() }

    /** Whether the script embeds its own fonts, which need no fetching. */
    fun hasEmbeddedFonts(content: String): Boolean =
        content.contains("[Fonts]", ignoreCase = true)

    /**
     * The script's declared resolution, used to scale rendering.
     *
     * Defaults to 384×288 — the SSA original — because a script omitting
     * PlayResX is an old one, and assuming 1920 would shrink every subtitle to
     * a fifth of its intended size.
     */
    fun playResolution(content: String): Pair<Int, Int> {
        var width = 384
        var height = 288

        content.lineSequence().forEach { rawLine ->
            val line = rawLine.trim()
            when {
                line.startsWith("PlayResX:", ignoreCase = true) ->
                    line.removePrefix("PlayResX:").trim().toIntOrNull()?.let { width = it }
                line.startsWith("PlayResY:", ignoreCase = true) ->
                    line.removePrefix("PlayResY:").trim().toIntOrNull()?.let { height = it }
            }
        }

        return width to height
    }

    /**
     * Clean up a family name as written in a script.
     *
     * The `@` prefix marks a vertical-writing variant of the same family; it is
     * a rendering instruction, not part of the name, and leaving it on turns
     * every lookup into a miss.
     */
    fun normalise(raw: String): String? {
        val name = raw.trim().removePrefix("@").trim()
        return name.takeIf { it.isNotBlank() }
    }

    /** Compare the way a font lookup should: case- and space-insensitively. */
    fun key(family: String): String =
        family.lowercase().replace(WHITESPACE, " ").trim()

    private val FONT_OVERRIDE = Regex("""\\fn([^\\}]+)""")
    private val WHITESPACE = Regex("""\s+""")
    private val OVERRIDE_BLOCK = Regex("""\{[^}]*\}""")
    private val LINE_BREAK = Regex("""\\[Nn]""")
    private val HARD_SPACE = Regex("""\\h""")
}

/**
 * The font each line of one script is set in.
 *
 * Built by [AssParser.fontIndex]. A cue the index has never seen — a format it
 * did not parse, or a line whose text the decoder rewrote — falls back to
 * [primary], the family the dialogue style uses, which is the right answer far
 * more often than any other single font.
 */
class AssFontIndex(
    private val byText: Map<String, String>,
    /** The family the dialogue style is set in. */
    val primary: String?,
) {

    /** The family a line of text should be drawn in, if one is known. */
    fun familyFor(text: String): String? = byText[AssParser.textKey(text)] ?: primary

    /** How many distinct lines the index knows, for logging and tests. */
    val size: Int get() = byText.size
}
