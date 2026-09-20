package com.animeh.app.player.ads

import com.animeh.app.core.ClientLog
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.IOException
import javax.inject.Inject
import javax.inject.Named
import javax.inject.Singleton

/**
 * Fetching an ad, and telling the network what happened to it.
 *
 * Two halves with opposite rules. Asking for an ad is something the viewer is
 * waiting on, so it is quick to give up: an ad that takes eight seconds to
 * arrive has already cost more than it earns. Reporting on one is something
 * nobody waits on, so it is fire-and-forget — a tracking call that failed must
 * never be allowed to hold up an episode, and must never be retried into a
 * double count.
 */
@Singleton
class VastClient @Inject constructor(
    @Named("ad_client") private val client: OkHttpClient,
) {

    /**
     * Reporting runs here rather than on whatever called in.
     *
     * A supervisor job because these are independent: one pixel that cannot
     * be reached should not cancel the four beside it. Nothing ever joins
     * this scope — by design, since the caller is a player that has already
     * moved on.
     */
    private val reporting = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    /**
     * Ask for an ad.
     *
     * Follows wrappers, because one network selling to another is ordinary
     * and the real creative can be a hop or two away. Gives up at
     * [VastParser.MAX_REDIRECTS], which is what stops a mis-configured chain
     * from fetching in a circle on somebody's mobile data.
     *
     * Impressions collected from wrappers along the way are carried into the
     * result: the network that sold the slot has to hear about the showing
     * too, or it is never paid for it.
     *
     * @return the ad, or null for no fill and for every kind of failure —
     *   which the caller treats identically, because from the viewer's side
     *   they are: the episode carries on.
     */
    suspend fun request(tag: String): VastAd? = withContext(Dispatchers.IO) {
        var url = cacheBusted(tag)
        val inherited = mutableListOf<String>()

        repeat(VastParser.MAX_REDIRECTS) {
            val body = fetch(url) ?: return@withContext null

            when (val result = VastParser.parse(body)) {
                is VastResult.Ad ->
                    return@withContext result.ad.copy(
                        impressions = inherited + result.ad.impressions
                    )

                is VastResult.Redirect -> {
                    inherited += result.impressions
                    url = cacheBusted(result.uri)
                }

                is VastResult.Empty -> return@withContext null

                is VastResult.Unreadable -> {
                    // Worth a line: a response that stopped parsing is the
                    // shape of a network changing something, and the symptom
                    // on a phone is silence.
                    ClientLog.record("Reklam yanıtı okunamadı", result.reason)

                    return@withContext null
                }
            }
        }

        ClientLog.record("Reklam yönlendirmesi bitmedi", "$MAX_HOPS_MESSAGE")

        null
    }

    /**
     * Tell the network the ad was seen, or reached a point, or was skipped.
     *
     * Every URL is requested once and the answer is thrown away. A tracking
     * endpoint that returns 500 has still counted in every implementation
     * worth the name, and a retry is how one showing becomes two in somebody
     * else's billing.
     */
    fun report(urls: List<String>) {
        urls.forEach { url ->
            reporting.launch {
                runCatching {
                    client.newCall(Request.Builder().url(url).build()).execute().close()
                }
            }
        }
    }

    /**
     * Report that the ad failed, with the code VAST defines for it.
     *
     * Done rather than skipped because it is the only way the other side
     * learns its creative does not play here. A network never told keeps
     * sending the file that fails, and the slot earns nothing for either
     * party until somebody notices by hand.
     */
    fun reportError(templates: List<String>, code: Int) {
        report(templates.map { it.replace("[ERRORCODE]", code.toString()) })
    }

    /** GET, or null. */
    private fun fetch(url: String): String? =
        runCatching {
            client.newCall(
                Request.Builder()
                    .url(url)
                    // Asked for explicitly: the response is XML and some ad
                    // servers answer differently without it.
                    .header("Accept", "application/xml, text/xml, */*")
                    .build()
            ).execute().use { response ->
                if (!response.isSuccessful) throw IOException("HTTP ${response.code}")

                response.body?.string()
            }
        }.getOrNull()

    /**
     * A parameter nothing reads, so that nothing serves a stored answer.
     *
     * Every ad request has to reach the network: the whole point is a
     * different fill each time, and a proxy or a CDN that recognised the
     * address would hand back the same creative for an evening. Appended
     * rather than replacing anything, so a tag that already carries its own
     * is untouched.
     */
    private fun cacheBusted(url: String): String {
        val separator = if (url.contains('?')) '&' else '?'

        return "$url${separator}animeh_cb=${System.nanoTime()}"
    }

    private companion object {
        const val MAX_HOPS_MESSAGE = "en fazla ${VastParser.MAX_REDIRECTS} adım izleniyor"
    }
}

/**
 * The VAST error codes this app can honestly report.
 *
 * A short list on purpose. Reporting a code that does not describe what
 * happened is worse than reporting none: it sends whoever reads it looking in
 * the wrong place.
 */
object VastError {
    /** 301 — the wrapper chain did not end in a playable ad. */
    const val WRAPPER_UNREACHABLE = 301

    /** 401 — no media file this device can play. */
    const val NO_SUPPORTED_MEDIA = 401

    /** 405 — the file was found but would not decode. */
    const val MEDIA_NOT_PLAYABLE = 405
}
