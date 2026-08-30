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
}
