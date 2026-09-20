package com.animeh.app.player.ads

import java.io.ByteArrayInputStream
import javax.xml.parsers.DocumentBuilderFactory
import org.w3c.dom.Element
import org.w3c.dom.Node
import org.xml.sax.ErrorHandler
import org.xml.sax.SAXParseException

/**
 * A parsed VAST response: one ad, ready to play.
 *
 * Deliberately free of Android. It reads with `javax.xml.parsers`, which the
 * platform and the desktop JVM both have, so this file compiles and *runs*
 * outside an emulator — which is the only reason it could be checked against
 * a real response from the ad network before shipping. That check found the
 * thing this whole class turns on; see [progress].
 */
data class VastAd(
    /** The video to play. */
    val media: String,
    /** How long it runs, from `<Duration>`. Zero when the response omits it. */
    val durationMs: Long,
    /**
     * When the ad itself says it may be skipped, from `skipoffset`.
     *
     * Null means the ad does not say, which is the common case and is why the
     * operator's own setting exists. When both are present the ad wins: an
     * advertiser who paid for five unskippable seconds gets five.
     */
    val skipOffsetMs: Long?,
    /** Where a tap goes. Null when the ad has no click-through. */
    val clickThrough: String?,
    /** Fired once, when the first frame is shown. */
    val impressions: List<String>,
    /**
     * Fired as playback passes each offset.
     *
     * This is the part that had to be seen rather than assumed. VAST's
     * well-known names — `start`, `firstQuartile`, `midpoint`,
     * `thirdQuartile` — are what a parser written from the specification
     * expects, and the network this app actually talks to sends none of them.
     * It sends five `progress` events carrying absolute offsets instead,
     * which is legal VAST 3.0 and completely invisible to a parser looking
     * for the other spelling.
     *
     * A parser that had guessed would have fired nothing, reported nothing,
     * and earned nothing, while looking like it worked.
     *
     * Both spellings are handled and both land here, already resolved to
     * milliseconds and sorted, so the player has one list to walk.
     */
    val progress: List<VastTracking>,
    /** Fired when the ad reaches its end. */
    val complete: List<String>,
    /** Fired when the viewer skips. */
    val skipped: List<String>,
    /** Fired on a tap, alongside opening [clickThrough]. */
    val clickTracking: List<String>,
    /**
     * Error templates, still carrying VAST's `[ERRORCODE]` placeholder.
     *
     * Reported rather than swallowed. A network that is never told its
     * creative failed keeps sending the one that fails.
     */
    val errors: List<String>,
    /** The network's own call-to-action, when it sends one. */
    val cta: VastCta?,
)

/** One tracking URL and the playback offset that fires it. */
data class VastTracking(val atMs: Long, val url: String)

/**
 * The button an ad network asks to be drawn over its creative.
 *
 * Carried in `<Extensions>`, so it is nobody's standard and everything here
 * is optional. Drawn when present because it is what the advertiser bought;
 * ignored entirely when absent.
 */
data class VastCta(val text: String, val displayUrl: String, val clickUrl: String?)

/**
 * What a response turned out to be.
 *
 * "No fill" is a first-class answer rather than a failure. An ad network with
 * nothing to show right now returns an empty VAST, and that is an ordinary
 * Tuesday: the episode simply carries on. Treating it as an error would put a
 * message on screen for something the viewer should never know happened.
 */
sealed interface VastResult {
    data class Ad(val ad: VastAd) : VastResult
    /** A `<Wrapper>`: the real ad is at this address. */
    data class Redirect(val uri: String, val impressions: List<String>, val errors: List<String>) : VastResult
    data object Empty : VastResult
    data class Unreadable(val reason: String) : VastResult
}

/**
 * Turns a VAST document into something the player can act on.
 *
 * Tolerant on purpose. Every field an ad network sends is one it might one day
 * send differently, and an ad that fails to parse is revenue that silently
 * stops. So anything unrecognised is skipped rather than fatal, and the only
 * thing that actually has to be there is a playable media file.
 */
object VastParser {

    /** Media types worth handing to the player, best first. */
    private val PLAYABLE = listOf("video/mp4", "video/webm", "video/3gpp")

    /**
     * How deep a wrapper chain may go before it is called a loop.
     *
     * Five, which is what the specification suggests. A chain is normal — one
     * network sells to another — but a chain with no end is a phone fetching
     * for ever on somebody's mobile data.
     */
    const val MAX_REDIRECTS = 5

    fun parse(xml: String): VastResult {
        val trimmed = xml.trim()

        if (trimmed.isEmpty()) return VastResult.Empty

        val root = runCatching {
            DocumentBuilderFactory.newInstance().apply {
                // This document comes from outside. An external entity in it
                // would be the server's own files, read out through a phone.
                runCatching { setFeature("http://apache.org/xml/features/disallow-doctype-decl", true) }
                isExpandEntityReferences = false
            }
                .newDocumentBuilder()
                .apply {
                    // Silenced, not ignored. Left alone the parser prints the
                    // fault to stderr itself and *then* throws, so a malformed
                    // response would write to the log of every phone that got
                    // one. The throw is what this code acts on; the printing
                    // is the library talking over it.
                    setErrorHandler(object : ErrorHandler {
                        override fun warning(exception: SAXParseException) = Unit
                        override fun error(exception: SAXParseException) = throw exception
                        override fun fatalError(exception: SAXParseException) = throw exception
                    })
                }
                .parse(ByteArrayInputStream(trimmed.toByteArray(Charsets.UTF_8)))
                .documentElement
        }.getOrElse { failure ->
            return VastResult.Unreadable(failure.message ?: "belge ayrıştırılamadı")
        }

        val ad = root.children("Ad").firstOrNull() ?: return VastResult.Empty

        ad.children("Wrapper").firstOrNull()?.let { wrapper ->
            val uri = wrapper.text("VASTAdTagURI")

            return if (uri.isEmpty()) {
                VastResult.Empty
            } else {
                VastResult.Redirect(uri, wrapper.texts("Impression"), wrapper.texts("Error"))
            }
        }

        val inline = ad.children("InLine").firstOrNull() ?: return VastResult.Empty
        val linear = inline.descendants("Linear").firstOrNull() ?: return VastResult.Empty

        val media = pickMedia(linear) ?: return VastResult.Empty
        val duration = parseTime(linear.text("Duration"), 0) ?: 0

        return VastResult.Ad(
            VastAd(
                media = media,
                durationMs = duration,
                skipOffsetMs = linear.getAttribute("skipoffset").trim()
                    .takeIf { it.isNotEmpty() }
                    ?.let { parseTime(it, duration) },
                clickThrough = linear.descendants("ClickThrough").firstOrNull()?.trimmedText(),
                impressions = inline.texts("Impression"),
                progress = trackingFor(linear, duration),
                complete = eventUrls(linear, setOf("complete")),
                skipped = eventUrls(linear, setOf("skip")),
                clickTracking = linear.descendants("ClickTracking").mapNotNull { it.trimmedText() },
                errors = inline.texts("Error"),
                cta = ctaFor(inline),
            )
        )
    }

    /**
     * The media file to play.
     *
     * The response this was built against carries exactly one file, with no
     * width, height or bitrate on it — so the selection cannot lean on those
     * being there. Type is what can be trusted: a file the platform cannot
     * decode is worse than no ad, because it is a black screen the viewer
     * waits through.
     *
     * Among playable types the smallest declared bitrate wins, since this is
     * an interruption on somebody's mobile data and nobody is studying its
     * fidelity. With no bitrates declared the first one stands.
     */
    private fun pickMedia(linear: Element): String? {
        val files = linear.descendants("MediaFile")
            .mapNotNull { element ->
                val url = element.trimmedText() ?: return@mapNotNull null
                val type = element.getAttribute("type").trim().lowercase()

                // `delivery="streaming"` means RTMP in practice, which no
                // phone plays. Progressive, or unstated, is a plain file.
                if (element.getAttribute("delivery").trim().equals("streaming", ignoreCase = true)) {
                    return@mapNotNull null
                }

                val rank = PLAYABLE.indexOf(type)

                // An unknown type is kept but ranked last: a network that
                // sends `video/mpeg4` rather than `video/mp4` is likelier to
                // be playable than not, and the alternative is no ad at all.
                Triple(url, if (rank < 0) PLAYABLE.size else rank, element.getAttribute("bitrate").trim().toIntOrNull())
            }

        if (files.isEmpty()) return null

        return files.sortedWith(
            compareBy({ it.second }, { it.third ?: Int.MAX_VALUE })
        ).first().first
    }

    /**
     * Every time-based tracking URL, resolved to milliseconds and sorted.
     *
     * Two spellings feed this. `progress` events carry their own `offset` and
     * are what this app's network sends; the quartile names carry none and
     * are worked out from the duration. Both end up as the same pair, so the
     * player never learns which kind it was handed.
     */
    private fun trackingFor(linear: Element, durationMs: Long): List<VastTracking> {
        val quartiles = mapOf(
            "start" to 0.0,
            "firstquartile" to 0.25,
            "midpoint" to 0.5,
            "thirdquartile" to 0.75,
        )

        return linear.descendants("Tracking").mapNotNull { element ->
            val url = element.trimmedText() ?: return@mapNotNull null
            val event = element.getAttribute("event").trim().lowercase()

            val at = when {
                "progress" == event -> parseTime(element.getAttribute("offset"), durationMs)
                quartiles.containsKey(event) -> (durationMs * quartiles.getValue(event)).toLong()
                else -> null
            } ?: return@mapNotNull null

            VastTracking(at.coerceAtLeast(0), url)
        }.sortedBy { it.atMs }
    }

    /** Tracking URLs for events that fire on a moment rather than a time. */
    private fun eventUrls(linear: Element, events: Set<String>): List<String> =
        linear.descendants("Tracking")
            .filter { events.contains(it.getAttribute("event").trim().lowercase()) }
            .mapNotNull { it.trimmedText() }

    /** The network's call-to-action, when its extensions carry one. */
    private fun ctaFor(inline: Element): VastCta? {
        val block = inline.descendants("TitleCTA").firstOrNull() ?: return null

        // Mobile first, since that is every viewer of this app, then the
        // desktop wording as a fallback rather than an empty button.
        val text = block.text("MobileText").ifEmpty { block.text("PCText") }
        val display = block.text("DisplayUrl")

        if (text.isEmpty() || display.isEmpty()) return null

        return VastCta(text, display, block.descendants("Tracking").firstOrNull()?.trimmedText())
    }

    /**
     * `HH:MM:SS(.mmm)` or a percentage of [durationMs], in milliseconds.
     *
     * Both shapes are legal for an offset and this app's network uses both in
     * the same response — absolute times on its `progress` events, percentages
     * in the query strings they carry.
     */
    fun parseTime(raw: String, durationMs: Long): Long? {
        val text = raw.trim()

        if (text.isEmpty()) return null

        if (text.endsWith("%")) {
            val percent = text.dropLast(1).toDoubleOrNull() ?: return null

            return (durationMs * percent / 100.0).toLong()
        }

        val parts = text.split(":")
        if (parts.size != 3) return null

        val hours = parts[0].toLongOrNull() ?: return null
        val minutes = parts[1].toLongOrNull() ?: return null
        val seconds = parts[2].toDoubleOrNull() ?: return null

        return ((hours * 3600 + minutes * 60) * 1000) + (seconds * 1000).toLong()
    }

    // --- DOM helpers -------------------------------------------------------
    //
    // Written out rather than pulled in, because the alternative is an XML
    // library in an app that needs precisely this much of one.

    /** Direct children with this tag. */
    private fun Element.children(tag: String): List<Element> {
        val found = mutableListOf<Element>()
        val nodes = childNodes

        for (index in 0 until nodes.length) {
            val node = nodes.item(index)

            if (node.nodeType == Node.ELEMENT_NODE && (node as Element).tagName == tag) {
                found.add(node)
            }
        }

        return found
    }

    /** Descendants with this tag, at any depth. */
    private fun Element.descendants(tag: String): List<Element> {
        val nodes = getElementsByTagName(tag)

        return (0 until nodes.length).mapNotNull { nodes.item(it) as? Element }
    }

    /**
     * An element's text, trimmed, or null when there is none.
     *
     * The trim is not tidiness. Every URL in the response this was built
     * against sits inside `<![CDATA[ … ]]>` *with a space on each side*, and a
     * URL with a leading space is a request that never leaves.
     */
    private fun Element.trimmedText(): String? = textContent?.trim()?.takeIf { it.isNotEmpty() }

    /** The first descendant with this tag, as trimmed text, or empty. */
    private fun Element.text(tag: String): String =
        descendants(tag).firstOrNull()?.trimmedText() ?: ""

    /** Every descendant with this tag, as trimmed text. */
    private fun Element.texts(tag: String): List<String> =
        descendants(tag).mapNotNull { it.trimmedText() }
}
