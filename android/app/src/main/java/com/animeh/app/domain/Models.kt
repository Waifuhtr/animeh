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

    /** Whether resuming is worth offering rather than starting over. */
    val isResumable: Boolean get() = !completed && positionSeconds > RESUME_THRESHOLD_SECONDS

    companion object {
        /** Below this, "continue" would drop the viewer back at the start anyway. */
        const val RESUME_THRESHOLD_SECONDS = 30
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
