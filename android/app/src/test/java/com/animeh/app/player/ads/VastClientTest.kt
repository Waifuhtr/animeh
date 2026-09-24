package com.animeh.app.player.ads

import kotlinx.coroutines.test.runTest
import okhttp3.OkHttpClient
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test

/**
 * The bug this file exists to keep fixed: a single bad request used to cost
 * the break for good.
 *
 * `AdBreakController` marks a break shown the moment it decides to try, not
 * the moment something actually plays — so a fetch that fails once, for any
 * reason, looked identical to a network with nothing to sell. On the phone
 * that showed up as the loading text flashing and the episode carrying on,
 * and it did not need a dead ad server to happen: a request that goes out at
 * the same moment as the episode's own manifest and first segments only
 * needed to lose that race once.
 *
 * A real HTTP server rather than a fake body, because the failure lives in
 * the transport — a timeout, a 500, a connection that resets — not in
 * anything [VastParser] would see. [VastParserTest] already owns the
 * question of what a response means; this owns whether one bad attempt is
 * still allowed to be the whole story.
 */
class VastClientTest {

    private lateinit var server: MockWebServer
    private lateinit var client: VastClient

    @Before
    fun setUp() {
        server = MockWebServer()
        server.start()
        client = VastClient(OkHttpClient())
    }

    @After
    fun tearDown() {
        server.shutdown()
    }

    @Test
    fun `one failed attempt is retried and still returns the ad`() = runTest {
        server.enqueue(MockResponse().setResponseCode(500))
        server.enqueue(MockResponse().setBody(INLINE_AD))

        val ad = client.request(server.url("/vast").toString())

        assertNotNull("a second attempt should have found the ad", ad)
        assertEquals(2, server.requestCount)
    }

    @Test
    fun `two failed attempts in a row give up rather than loop`() = runTest {
        server.enqueue(MockResponse().setResponseCode(500))
        server.enqueue(MockResponse().setResponseCode(500))

        val ad = client.request(server.url("/vast").toString())

        assertNull("both attempts failed, so this is no fill, not a third try", ad)
        assertEquals(2, server.requestCount)
    }

    @Test
    fun `a clean first response never spends a retry`() = runTest {
        server.enqueue(MockResponse().setBody(INLINE_AD))

        val ad = client.request(server.url("/vast").toString())

        assertNotNull(ad)
        assertEquals(
            "the ordinary case must not pay the retry's delay",
            1,
            server.requestCount,
        )
    }

    @Test
    fun `an explicit no-fill is not retried into two requests`() = runTest {
        server.enqueue(MockResponse().setBody("<VAST version=\"3.0\"></VAST>"))

        val ad = client.request(server.url("/vast").toString())

        assertNull(ad)
        assertEquals(
            "a server that answered with nothing to sell is not a transport failure",
            1,
            server.requestCount,
        )
    }

    private companion object {
        private const val INLINE_AD = """
            <VAST version="3.0">
            <Ad id="1">
            <InLine>
            <AdSystem>Test</AdSystem>
            <AdTitle/>
            <Impression><![CDATA[ https://example.test/imp ]]></Impression>
            <Creatives>
            <Creative sequence="1" id="1">
            <Linear>
            <Duration>00:00:15.000</Duration>
            <TrackingEvents></TrackingEvents>
            <MediaFiles>
            <MediaFile delivery="progressive" type="video/mp4"><![CDATA[ https://example.test/ad.mp4 ]]></MediaFile>
            </MediaFiles>
            </Linear>
            </Creative>
            </Creatives>
            </InLine>
            </Ad>
            </VAST>
        """
    }
}
