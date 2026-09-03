package com.animeh.app.player.ass

/**
 * Deciding whether a font answers the family a subtitle asked for.
 *
 * An exact name match is the wrong bar, and insisting on it is why a font
 * library that looks complete still renders wrong. Three things go wrong in
 * practice, all of them normal:
 *
 * 1. **The file carries a longer name than the script uses.** A script asks
 *    for `Sans`; the file to hand is `sans-test.ttf`, whose name table says
 *    "Sans Test". The same typeface, a different string.
 * 2. **The script asks for a weight.** `\fnArial Bold` names a face inside a
 *    family. Uploading the family is the right answer, and refusing it because
 *    the word "Bold" is missing helps nobody — the renderer can embolden.
 * 3. **Punctuation and case differ.** `DejaVuSans`, `DejaVu Sans` and
 *    `dejavu-sans` are one font written three ways.
 *
 * What this deliberately does *not* do is match on similarity. "Sans" must
 * never resolve to "Comic Sans": a substituted typeface renders at different
 * metrics and quietly breaks the typesetting the script was written against,
 * which is worse than a missing font somebody can see and upload. So every
 * match below the exact one requires the **first word to be the same**.
 *
 * The rules are the same ones `Support/FontMatch.php` applies on the server,
 * deliberately: a font the plugin says it has must be a font the player
 * accepts, or the "missing fonts" list becomes a list of lies.
 */
object FontMatch {

    /**
     * Words that name a face inside a family rather than the family itself.
     */
    private val STYLE_WORDS = setOf(
        "regular", "normal", "book", "roman", "text",
        "thin", "hairline", "extralight", "ultralight", "light",
        "medium", "semibold", "demibold", "demi", "bold", "extrabold",
        "ultrabold", "heavy", "black",
        "italic", "oblique",
        "condensed", "cond", "narrow", "expanded", "extended", "wide",
    )

    private val CAMEL = Regex("""(\p{Ll})(\p{Lu})""")
    private val SEPARATORS = Regex("""[^\p{L}\p{N}]+""")
    private val DIGITS = Regex("""^\d+$""")

    /**
     * A name reduced to comparable words.
     *
     * Case, punctuation and runs of whitespace all go; a camel-cased
     * "DejaVuSans" is split at its capitals, because that is one name written
     * without spaces rather than one word.
     */
    fun words(family: String): List<String> {
        // `@Yu Gothic` is a vertical-writing variant of Yu Gothic, not a
        // family called "@Yu Gothic".
        val name = family.trim().trimStart('@')

        // Split camel case before folding case away, or the boundary is lost.
        return CAMEL.replace(name) { "${it.groupValues[1]} ${it.groupValues[2]}" }
            .lowercase()
            .split(SEPARATORS)
            .filter { it.isNotEmpty() }
    }

    /**
     * A name with every separator taken out.
     *
     * The form that makes `DejaVuSans`, `DejaVu Sans` and `dejavu-sans` one
     * string. Word splitting cannot do this on its own: the camel-cased
     * spelling can be split at its capitals and the all-lower-case one cannot,
     * so the two would disagree about where the words are.
     */
    fun compact(family: String): String = words(family).joinToString("")

    /**
     * The family's words with the face-naming ones removed.
     *
     * Never empty when the name had any letters in it: a family genuinely
     * called "Black" would otherwise reduce to nothing and match everything.
     */
    fun base(family: String): List<String> {
        val all = words(family)
        val kept = all.filter { it !in STYLE_WORDS && !DIGITS.matches(it) }

        return kept.ifEmpty { all }
    }

    /**
     * How well a candidate family answers a requested one.
     *
     * @return 0 when it does not answer at all; higher is a better answer.
     */
    fun score(wanted: String, candidate: String): Int {
        val wantedWords = words(wanted)
        val candidateWords = words(candidate)

        if (wantedWords.isEmpty() || candidateWords.isEmpty()) return 0

        if (compact(wanted) == compact(candidate)) return 100

        val wantedBase = base(wanted)
        val candidateBase = base(candidate)

        if (wantedBase.joinToString("") == candidateBase.joinToString("")) return 90

        // Everything below here is a guess about a name, so it is only allowed
        // when the families start with the same word. Without that rule "Sans"
        // resolves to "Comic Sans", and a wrong typeface is worse than none.
        if (wantedBase[0] != candidateBase[0]) return 0

        var shared = 0
        val limit = minOf(wantedBase.size, candidateBase.size)

        while (shared < limit && wantedBase[shared] == candidateBase[shared]) shared++

        if (shared == wantedBase.size || shared == candidateBase.size) {
            // One name is the whole of the other plus something: "Sans" and
            // "Sans Test". The longer the agreement the better the answer, and
            // the fewer extra words the better still.
            val extra = kotlin.math.abs(wantedBase.size - candidateBase.size)

            return maxOf(50, 80 + shared - extra)
        }

        return 0
    }

    /**
     * The best of several candidates, or null when none answers.
     *
     * Ties break on the shorter name, which is the more general family: asked
     * for "Gothic", "Gothic" beats "Gothic Extra".
     */
    fun <T> best(wanted: String, candidates: Iterable<T>, family: (T) -> String): T? {
        var best: T? = null
        var bestScore = 0
        var bestWords = Int.MAX_VALUE

        for (candidate in candidates) {
            val name = family(candidate)
            val score = score(wanted, name)

            if (score == 0) continue

            val length = words(name).size

            if (score > bestScore || (score == bestScore && length < bestWords)) {
                best = candidate
                bestScore = score
                bestWords = length
            }
        }

        return best
    }
}
