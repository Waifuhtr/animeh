package com.animeh.app.player.ads

import com.animeh.app.core.ClientLog
import com.animeh.app.data.remote.dto.AdConfigDto
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Whether this install shows advertising, and on what terms.
 *
 * Held here rather than read from a preference or compiled in, for the reason
 * that decided the whole design: the operator turning advertising on has to
 * turn it on for everybody, and turning it off after a bad night has to reach
 * everybody before the next episode starts. A switch inside the app would be
 * a switch on one phone.
 *
 * So the answer arrives with the rest of the client config on every launch,
 * exactly like the server address and the Firebase project, and an app that
 * has never been told anything never asks anybody for an ad.
 */
@Singleton
class AdGate @Inject constructor() {

    private val _plan = MutableStateFlow<AdPlan?>(null)

    /** Null until the server says otherwise, which is also "off". */
    val plan: StateFlow<AdPlan?> = _plan.asStateFlow()

    /** What the player should do right now, or null for nothing. */
    val current: AdPlan? get() = _plan.value

    /**
     * Take what `/config` said.
     *
     * Safe to call on every launch; the common case is the same answer as
     * last time. A change is worth a line in the device log because "why did
     * ads start appearing" and "why did they stop" are both questions
     * somebody will ask, and the answer is always this.
     */
    fun configure(config: AdConfigDto?) {
        val next = when {
            config == null || !config.isUsable -> null
            else -> AdPlan(
                tag = config.tag,
                intervalMs = config.interval.toLong() * 1_000L,
                skippable = config.skippable,
                skipAfterMs = config.skipAfter.coerceAtLeast(0).toLong() * 1_000L,
                preroll = config.preroll,
            )
        }

        if (next == _plan.value) return

        _plan.value = next

        ClientLog.record(
            "Reklam ayarı değişti",
            if (next == null) {
                "kapalı"
            } else {
                "her ${next.intervalMs / 1000} sn" +
                    (if (next.preroll) ", başta da" else "") +
                    (if (next.skippable) ", ${next.skipAfterMs / 1000} sn sonra atlanabilir" else ", atlanamaz")
            }
        )
    }
}

/**
 * The terms, in the units the player works in.
 *
 * Separate from the DTO so that the wire format can change without the player
 * learning about it, and so that seconds become milliseconds exactly once.
 */
data class AdPlan(
    val tag: String,
    val intervalMs: Long,
    val skippable: Boolean,
    val skipAfterMs: Long,
    val preroll: Boolean,
) {
    /**
     * When the skip button may appear, given what the ad itself asked for.
     *
     * The ad wins when it says anything at all: an advertiser who bought five
     * unskippable seconds gets five, whatever this install would have
     * preferred. The operator's number is for the common case, which is an ad
     * that says nothing — including the one this was built against.
     *
     * Null means no skipping.
     */
    fun skipAtMs(adSkipOffsetMs: Long?): Long? = when {
        adSkipOffsetMs != null -> adSkipOffsetMs
        skippable -> skipAfterMs
        else -> null
    }
}
