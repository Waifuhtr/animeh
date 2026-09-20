package com.animeh.app.player.ads

import org.junit.Assert.*
import org.junit.Test

/**
 * The VAST parser, against the shape the ad network actually sends.
 *
 * The fixture below is a real response with its identifying parts removed: the
 * tracking tokens were three kilobytes each and tied to one zone, and the
 * creative was an advertiser's. What is kept is every part that a parser can
 * get wrong, and the first of those is the reason this file exists.
 *
 * **The network sends no quartile events.** Not `start`, not `firstQuartile`,
 * not `midpoint`, not `thirdQuartile`, not `complete` — the five names a
 * parser written from the specification goes looking for. It sends five
 * `progress` events carrying absolute offsets, which is legal VAST 3.0 and
 * completely invisible to a parser expecting the other spelling. Such a parser
 * would play the ad, report nothing, earn nothing, and look like it worked.
 *
 * Kept alongside it: offsets that arrive out of order, `<![CDATA[ … ]]>` with
 * a space on each side of every URL, a duration with milliseconds, a single
 * media file carrying no width, height or bitrate, no `skipoffset`, and the
 * network's own call-to-action in `<Extensions>`.
 */
class VastParserTest {

    private val response = """
        <VAST version="3.0">
        <Ad id="7262276">
        <InLine>
        <AdSystem>ExoClick</AdSystem>
        <AdTitle/>
        <Impression id="msgtr"><![CDATA[ https://example.test/vregister.php?a=vimp&t=imp ]]></Impression>
        <Error><![CDATA[ https://example.test/vregister.php?a=vview&errorcode=[ERRORCODE] ]]></Error>
        <Creatives>
        <Creative sequence="1" id="113438564">
        <Linear>
        <Duration>00:00:29.525</Duration>
        <TrackingEvents>
        <Tracking id="prog_1" event="progress" offset="00:00:10.000"><![CDATA[ https://example.test/p?at=10 ]]></Tracking>
        <Tracking id="prog_2" event="progress" offset="00:00:06.000"><![CDATA[ https://example.test/p?at=25pc ]]></Tracking>
        <Tracking id="prog_3" event="progress" offset="00:00:13.000"><![CDATA[ https://example.test/p?at=50pc ]]></Tracking>
        <Tracking id="prog_4" event="progress" offset="00:00:20.000"><![CDATA[ https://example.test/p?at=75pc ]]></Tracking>
        <Tracking id="prog_5" event="progress" offset="00:00:28.000"><![CDATA[ https://example.test/p?at=100pc ]]></Tracking>
        </TrackingEvents>
        <VideoClicks>
        <ClickThrough><![CDATA[ https://example.test/click.php?d=abc ]]></ClickThrough>
        </VideoClicks>
        <MediaFiles>
        <MediaFile delivery="progressive" type="video/mp4"><![CDATA[ https://cdn.example.test/creative.mp4 ]]></MediaFile>
        </MediaFiles>
        <Icons><Icon><IconClicks><IconClickThrough>vol.example.test</IconClickThrough></IconClicks></Icon></Icons>
        </Linear>
        </Creative>
        </Creatives>
        <Extensions><Extension><TitleCTA>
        <MobileText>Play Now</MobileText>
        <PCText>Play Now</PCText>
        <DisplayUrl><![CDATA[ shop.example.test ]]></DisplayUrl>
        <Tracking><![CDATA[ https://example.test/click.php?d=abc ]]></Tracking>
        </TitleCTA></Extension></Extensions>
        </InLine>
        </Ad>
        </VAST>
    """.trimIndent()

    private fun ad(xml: String = response): VastAd =
        (VastParser.parse(xml) as VastResult.Ad).ad

    @Test
    fun `progress events are found even though no quartile event exists`() {
        val progress = ad().progress

        // Five, not zero. Zero is what a parser looking for `firstQuartile`
        // and friends returns here, and zero tracking is zero revenue.
        assertEquals(5, progress.size)

        // Resolved to milliseconds and sorted, because the response lists
        // them 10, 6, 13, 20, 28 and the player walks the list in order.
        assertEquals(listOf(6_000L, 10_000L, 13_000L, 20_000L, 28_000L), progress.map { it.atMs })
    }

    @Test
    fun `urls lose the whitespace CDATA wraps them in`() {
        val ad = ad()

        // Every URL in the real response sits inside `<![CDATA[ … ]]>` with a
        // space on each side. A URL with a leading space is a request that
        // never leaves the phone.
        assertEquals("https://cdn.example.test/creative.mp4", ad.media)
        assertEquals(listOf("https://example.test/vregister.php?a=vimp&t=imp"), ad.impressions)
        ad.progress.forEach { assertEquals(it.url.trim(), it.url) }
    }

    @Test
    fun `duration carries its milliseconds`() {
        assertEquals(29_525L, ad().durationMs)
    }

    @Test
    fun `the ad declares no skip offset, so the operator's setting decides`() {
        assertNull(ad().skipOffsetMs)
    }

    @Test
    fun `the network's call to action is picked up`() {
        val cta = ad().cta

        assertNotNull(cta)
        assertEquals("Play Now", cta!!.text)
        assertEquals("shop.example.test", cta.displayUrl)
    }

    @Test
    fun `click through and error template survive`() {
        val ad = ad()

        assertEquals("https://example.test/click.php?d=abc", ad.clickThrough)
        assertEquals(1, ad.errors.size)
        assertTrue(ad.errors.first().contains("[ERRORCODE]"))
    }

    @Test
    fun `quartile names are understood too, for a fill that sends them`() {
        // The other spelling has to work as well: one network's response is
        // not every network's, and a different fill can arrive tomorrow.
        val xml = response.replace(
            """<Tracking id="prog_1" event="progress" offset="00:00:10.000">""",
            """<Tracking event="midpoint">"""
        )

        val midpoint = (VastParser.parse(xml) as VastResult.Ad).ad.progress

        // Half of 29.525 seconds, worked out from the duration rather than
        // read from an offset the event does not carry.
        assertTrue(midpoint.any { it.atMs == 14_762L })
    }

    @Test
    fun `a wrapper reports where the real ad is`() {
        val xml = """
            <VAST version="3.0"><Ad id="1"><Wrapper>
            <AdSystem>Test</AdSystem>
            <VASTAdTagURI><![CDATA[ https://example.test/next.xml ]]></VASTAdTagURI>
            <Impression><![CDATA[ https://example.test/wrapimp ]]></Impression>
            </Wrapper></Ad></VAST>
        """.trimIndent()

        val result = VastParser.parse(xml)

        assertTrue(result is VastResult.Redirect)
        assertEquals("https://example.test/next.xml", (result as VastResult.Redirect).uri)

        // The wrapper's own impression still has to fire, or the network that
        // sold the slot is never told it was shown.
        assertEquals(listOf("https://example.test/wrapimp"), result.impressions)
    }

    @Test
    fun `no fill is an answer, not a failure`() {
        // An ad network with nothing to show returns this, and the episode
        // simply carries on. Anything that reads as an error would put a
        // message on screen for something the viewer should never know about.
        assertEquals(VastResult.Empty, VastParser.parse("""<VAST version="3.0"></VAST>"""))
        assertEquals(VastResult.Empty, VastParser.parse(""))
    }

    @Test
    fun `a broken document is reported rather than thrown`() {
        val result = VastParser.parse("<VAST><Ad><InLine>")

        assertTrue(result is VastResult.Unreadable)
    }

    @Test
    fun `an unplayable media file is not chosen over a playable one`() {
        val xml = response.replace(
            """<MediaFile delivery="progressive" type="video/mp4"><![CDATA[ https://cdn.example.test/creative.mp4 ]]></MediaFile>""",
            """<MediaFile delivery="streaming" type="video/mp4"><![CDATA[ rtmp://cdn.example.test/stream ]]></MediaFile>
               <MediaFile delivery="progressive" type="video/mp4" bitrate="1200"><![CDATA[ https://cdn.example.test/big.mp4 ]]></MediaFile>
               <MediaFile delivery="progressive" type="video/mp4" bitrate="400"><![CDATA[ https://cdn.example.test/small.mp4 ]]></MediaFile>"""
        )

        // Streaming delivery is RTMP in practice and no phone plays it; among
        // what is left the lighter file wins, because this is an interruption
        // on somebody's mobile data.
        assertEquals("https://cdn.example.test/small.mp4", (VastParser.parse(xml) as VastResult.Ad).ad.media)
    }

    @Test
    fun `percentage offsets resolve against the duration`() {
        assertEquals(7_500L, VastParser.parseTime("25%", 30_000))
        assertEquals(30_000L, VastParser.parseTime("00:00:30.000", 0))
        assertNull(VastParser.parseTime("", 1000))
        assertNull(VastParser.parseTime("çöp", 1000))
    }
}
