package com.animeh.app.player.ass

import org.junit.Assert.*
import org.junit.Test

/**
 * Font extraction from an ASS script.
 *
 * The expected outputs here match the TypeScript implementation in the web
 * player deliberately: two implementations of the same rule that drift apart
 * mean a subtitle renders correctly on one and not the other.
 */
class AssParserTest {

    private val script = """
        [Script Info]
        Title: Test
        PlayResX: 1920
        PlayResY: 1080

        [V4+ Styles]
        Format: Name, Fontname, Fontsize, PrimaryColour, Bold, Italic, Alignment
        Style: Default,Open Sans,48,&H00FFFFFF,0,0,2
        Style: Sign,Animeh Gothic,64,&H00FFFFFF,1,0,8
        Style: Vertical,@Yu Mincho,40,&H00FFFFFF,0,0,2

        [Events]
        Format: Layer, Start, End, Style, Text
        Dialogue: 0,0:00:01.00,0:00:03.00,Default,Merhaba dünya
        Dialogue: 0,0:00:04.00,0:00:06.00,Default,{\fnMonoSpace Extra}Kod
        Dialogue: 0,0:00:07.00,0:00:09.00,Sign,{\pos(960,120)}Levha
    """.trimIndent()

    @Test
    fun `reads every family a script asks for`() {
        val fonts = AssParser.requiredFonts(script)

        assertTrue("Open Sans eksik: $fonts", fonts.contains("Open Sans"))
        assertTrue("Animeh Gothic eksik: $fonts", fonts.contains("Animeh Gothic"))
        // A font named only in an inline override is the usual reason a
        // "complete" font set still renders wrong.
        assertTrue("\\fn override alınmadı: $fonts", fonts.contains("MonoSpace Extra"))
    }

    @Test
    fun `strips the vertical-writing prefix`() {
        val fonts = AssParser.requiredFonts(script)

        // "@" marks a vertical variant; it is a rendering instruction, not part
        // of the name, and leaving it on turns every lookup into a miss.
        assertTrue("@ soyulmadı: $fonts", fonts.contains("Yu Mincho"))
        assertFalse(fonts.any { it.startsWith("@") })
    }

    @Test
    fun `reports each family once, in the order first seen`() {
        val fonts = AssParser.requiredFonts(script)
        assertEquals(fonts.size, fonts.toSet().size)
        assertEquals("Open Sans", fonts.first())
    }

    @Test
    fun `honours the Format line rather than assuming column order`() {
        // Scripts do reorder these columns, and a parser reading field 1
        // blindly picks up the font size instead of the name.
        val reordered = """
            [V4+ Styles]
            Format: Name, Fontsize, Fontname, PrimaryColour
            Style: Default,48,Reordered Sans,&H00FFFFFF
        """.trimIndent()

        assertEquals(listOf("Reordered Sans"), AssParser.requiredFonts(reordered))
    }

    @Test
    fun `falls back to the standard layout when no Format line is present`() {
        val noFormat = """
            [V4+ Styles]
            Style: Default,Fallback Sans,48,&H00FFFFFF
        """.trimIndent()

        assertEquals(listOf("Fallback Sans"), AssParser.requiredFonts(noFormat))
    }

    @Test
    fun `an empty script asks for nothing`() {
        assertTrue(AssParser.requiredFonts("").isEmpty())
        assertTrue(AssParser.requiredFonts("[Script Info]\nTitle: x").isEmpty())
    }

    @Test
    fun `reads the declared resolution`() {
        assertEquals(1920 to 1080, AssParser.playResolution(script))
    }

    @Test
    fun `an old script without PlayResX gets the SSA default`() {
        // Assuming 1920 would shrink every subtitle to a fifth of its intended
        // size.
        assertEquals(384 to 288, AssParser.playResolution("[Script Info]\nTitle: old"))
    }

    @Test
    fun `detects embedded fonts`() {
        assertFalse(AssParser.hasEmbeddedFonts(script))
        assertTrue(AssParser.hasEmbeddedFonts("$script\n\n[Fonts]\nfontname: x.ttf"))
    }

    @Test
    fun `lookup keys ignore case and spacing`() {
        // "DejaVu Sans" from a script and "dejavu  sans" from a registry are
        // the same font; matching on the raw string is why lookups fail.
        assertEquals(AssParser.key("DejaVu Sans"), AssParser.key("dejavu  sans"))
        assertEquals(AssParser.key("Open Sans"), AssParser.key(" OPEN SANS "))
        assertNotEquals(AssParser.key("Open Sans"), AssParser.key("OpenSans"))
    }

    @Test
    fun `normalise rejects a name that is only whitespace`() {
        assertNull(AssParser.normalise("   "))
        assertNull(AssParser.normalise("@"))
        assertEquals("Arial", AssParser.normalise("  @Arial  "))
    }
}
