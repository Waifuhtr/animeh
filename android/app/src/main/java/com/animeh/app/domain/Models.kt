package com.animeh.app.domain

/**
 * What the UI actually renders.
 *
 * Separate from the DTOs on purpose: a screen should not have to know whether a
 * field arrived from the network or from Room, and a wire-format change should
 * not ripple into Compose. The mapping in each direction lives in [Mappers.kt].
 */

data class Work(
    val id: Long,
    val slug: String,
    val title: String,
    val titleEnglish: String = "",
    val synopsis: String = "",
    val posterUrl: String = "",
    val bannerUrl: String = "",
    val score: Double = 0.0,
    val year: Int = 0,
    val season: String = "",
    val status: WorkStatus = WorkStatus.UNKNOWN,
    val format: String = "",
    val studio: String = "",
    val genres: List<String> = emptyList(),
    val totalEpisodes: Int = 0,
    val durationSeconds: Int = 0,
    val published: Boolean = true,
    /**
     * Whether to ask before playing.
     *
     * Not a rating and not a filter: the work stays in every list it would
     * otherwise be in, and the flag only puts a question in front of playback.
     */
    val adult: Boolean = false,
    val seasons: List<Season> = emptyList(),
    val isFavorite: Boolean = false,
    val inWatchlist: Boolean = false,
) {
    /** The title to show, preferring English when the site's language is not
     *  the romanised original. */
    val displayTitle: String get() = title.ifBlank { titleEnglish }

    val hasScore: Boolean get() = score > 0.0
}

enum class WorkStatus {
    AIRING, FINISHED, UPCOMING, UNKNOWN;

    companion object {
        fun from(value: String): WorkStatus = when (value.lowercase()) {
            "airing" -> AIRING
            "finished" -> FINISHED
            "upcoming" -> UPCOMING
            else -> UNKNOWN
        }
    }
}

data class Season(
    val number: Int,
    val title: String = "",
    val episodeCount: Int = 0,
)

data class Episode(
    val id: Long,
    val workId: Long,
    val seasonNumber: Int,
    val number: Int,
    val title: String = "",
    val synopsis: String = "",
    val thumbnailUrl: String = "",
    val durationSeconds: Int = 0,
    val filler: Boolean = false,
    val published: Boolean = true,
    val publishedAt: String = "",
    val progress: Progress? = null,
    // Set only on the "new episodes" rail, where the work is joined in.
    val workTitle: String = "",
    val workPoster: String = "",
    val videoSourceCount: Int = 0,
    val subtitleSourceCount: Int = 0,
) {
    val label: String get() = title.ifBlank { "$number. Bölüm" }
}

data class Progress(
    val positionSeconds: Int,
    val durationSeconds: Int,
    /** Seconds genuinely played, which is what decides completion. */
    val watchedSeconds: Int = 0,
    val completed: Boolean,
) {
    /** 0f..1f, or 0f when the length is unknown. */
    val fraction: Float
        get() = if (durationSeconds <= 0) 0f
        else (positionSeconds.toFloat() / durationSeconds).coerceIn(0f, 1f)

    /**
     * Whether resuming is worth offering rather than starting over.
     *
     * `completed` deliberately does not veto this. It now means "watched
     * enough of it to count", which happens at seventy percent — someone who
     * stopped at minute eighteen of twenty-four has passed that mark and still
     * has six minutes left, and starting them over was the bug. What does veto
     * it is being at the very end, where "continue" would just roll credits.
     *
     * Both margins are a share of the episode, not fixed seconds. Fixed ones
     * were the second bug: thirty seconds in and forty-five off the end leaves
     * a ninety-second clip resumable for thirteen of its ninety seconds, so a
     * short episode looked like the feature simply did not work.
     */
    val isResumable: Boolean
        get() {
            if (positionSeconds < startThreshold(durationSeconds)) return false
            if (durationSeconds <= 0) return true
            return positionSeconds < durationSeconds - endMargin(durationSeconds)
        }

    companion object {
        /** Below this, "continue" would drop the viewer back at the start anyway. */
        const val MIN_RESUME_SECONDS = 10

        /** Never ask for more than this before offering to continue. */
        const val MAX_RESUME_SECONDS = 30

        /** This close to the end there is nothing left to continue into. */
        const val MIN_END_MARGIN_SECONDS = 5
        const val MAX_END_MARGIN_SECONDS = 45

        /** Five percent of the episode, at each end. */
        const val MARGIN_PERCENT = 5

        /** How far in the playhead must be before continuing is worth offering. */
        fun startThreshold(durationSeconds: Int): Int =
            if (durationSeconds <= 0) MIN_RESUME_SECONDS
            else (durationSeconds * MARGIN_PERCENT / 100)
                .coerceIn(MIN_RESUME_SECONDS, MAX_RESUME_SECONDS)

        /** How much of the tail counts as "already finished". */
        fun endMargin(durationSeconds: Int): Int =
            if (durationSeconds <= 0) MIN_END_MARGIN_SECONDS
            else (durationSeconds * MARGIN_PERCENT / 100)
                .coerceIn(MIN_END_MARGIN_SECONDS, MAX_END_MARGIN_SECONDS)
    }
}

data class ContinueItem(
    val workId: Long,
    val workTitle: String,
    val workSlug: String,
    val posterUrl: String,
    val episodeId: Long,
    val episodeNumber: Int,
    val seasonNumber: Int,
    val episodeTitle: String,
    val thumbnailUrl: String,
    val progress: Progress,
)

data class HomeFeed(
    val hero: List<Work> = emptyList(),
    val continueWatching: List<ContinueItem> = emptyList(),
    val latestEpisodes: List<Episode> = emptyList(),
    val popular: List<Work> = emptyList(),
    val airing: List<Work> = emptyList(),
    val recentlyAdded: List<Work> = emptyList(),
) {
    val isEmpty: Boolean
        get() = hero.isEmpty() && popular.isEmpty() && recentlyAdded.isEmpty() &&
            airing.isEmpty() && latestEpisodes.isEmpty()
}

data class Genre(val name: String, val count: Int)

/** Everything needed to start one episode. */
data class Playback(
    val episode: Episode,
    val work: Work,
    val videos: List<MediaSource>,
    val subtitles: List<MediaSource>,
    val fonts: List<SubtitleFont>,
    val markers: Markers,
    val next: Episode?,
    val previous: Episode?,
    val resume: Progress?,
)

data class MediaSource(
    val id: Long,
    val label: String,
    val language: String,
    val mime: String,
    val height: Int,
    val sizeBytes: Long,
    val isDefault: Boolean,
    val url: String,
    /** Tried in order when [url] refuses — the friendly-to-S3 failover. */
    val fallbackUrls: List<String> = emptyList(),
) {
    /** Every address for this source, best first. */
    val allUrls: List<String> get() = listOf(url) + fallbackUrls

    val qualityLabel: String get() = if (height > 0) "${height}p" else label.ifBlank { "—" }
}

data class SubtitleFont(
    val family: String,
    val url: String,
    val origin: String,
)

/** -1 means "not marked"; 0 is a real marker at the very start of the episode. */
data class Markers(
    val introStart: Int = -1,
    val introEnd: Int = -1,
    val outroStart: Int = -1,
) {
    fun isInIntro(positionSeconds: Int): Boolean =
        introStart >= 0 && introEnd > introStart &&
            positionSeconds in introStart until introEnd

    fun isInOutro(positionSeconds: Int): Boolean =
        outroStart >= 0 && positionSeconds >= outroStart
}

data class Announcement(
    val id: Long,
    val title: String,
    val body: String,
    val link: String,
)
