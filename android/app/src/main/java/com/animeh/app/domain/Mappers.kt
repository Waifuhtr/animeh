package com.animeh.app.domain

import com.animeh.app.data.local.entity.EpisodeEntity
import com.animeh.app.data.local.entity.LibraryEntity
import com.animeh.app.data.local.entity.ProgressEntity
import com.animeh.app.data.local.entity.WorkEntity
import com.animeh.app.data.remote.dto.*

/**
 * DTO ↔ domain ↔ entity, all in one file.
 *
 * Kept together rather than spread across the layers so that adding a field is
 * one edit in one place, and so a field silently dropped in one direction is
 * visible next to the direction that keeps it.
 */

/* ── Network → domain ─────────────────────────────────────────────────── */

fun WorkDto.toDomain(): Work = Work(
    id = id,
    slug = slug,
    title = title,
    titleEnglish = titleEnglish,
    synopsis = synopsis,
    posterUrl = posterUrl,
    bannerUrl = bannerUrl.ifBlank { posterUrl },
    score = score,
    year = year,
    season = season,
    status = WorkStatus.from(status),
    format = format,
    studio = studio,
    genres = genres,
    totalEpisodes = totalEpisodes,
    durationSeconds = durationSeconds,
    published = published,
    seasons = seasons.map { Season(it.number, it.title, it.episodeCount) },
    isFavorite = isFavorite,
    inWatchlist = inWatchlist,
)

fun EpisodeDto.toDomain(): Episode = Episode(
    id = id,
    workId = workId,
    seasonNumber = seasonNumber,
    number = number,
    title = title,
    synopsis = synopsis,
    thumbnailUrl = thumbnailUrl,
    durationSeconds = durationSeconds,
    filler = filler,
    published = published,
    publishedAt = publishedAt,
    progress = progress?.toDomain(),
    workTitle = workTitle,
    workPoster = workPoster,
    videoSourceCount = sourceCounts?.video ?: 0,
    subtitleSourceCount = sourceCounts?.subtitle ?: 0,
)

fun ProgressDto.toDomain(): Progress =
    Progress(positionSeconds, durationSeconds, watchedSeconds, completed)

fun HistoryDto.toDomain(): ContinueItem = ContinueItem(
    workId = workId,
    workTitle = workTitle,
    workSlug = workSlug,
    posterUrl = posterUrl,
    episodeId = episodeId,
    episodeNumber = episodeNumber,
    seasonNumber = seasonNumber,
    episodeTitle = episodeTitle,
    thumbnailUrl = thumbnailUrl.ifBlank { posterUrl },
    progress = Progress(positionSeconds, durationSeconds, watchedSeconds, completed),
)

fun HomeDto.toDomain(): HomeFeed = HomeFeed(
    hero = hero.map { it.toDomain() },
    continueWatching = continueWatching.map { it.toDomain() },
    latestEpisodes = latestEpisodes.map { it.toDomain() },
    popular = popular.map { it.toDomain() },
    airing = airing.map { it.toDomain() },
    recentlyAdded = recentlyAdded.map { it.toDomain() },
)

fun MediaSourceDto.toDomain(): MediaSource = MediaSource(
    id = id,
    label = label,
    language = language,
    mime = mime,
    height = height,
    sizeBytes = sizeBytes,
    isDefault = isDefault,
    url = url,
    fallbackUrls = fallbackUrls,
)

fun PlaybackDto.toDomain(): Playback = Playback(
    episode = episode.toDomain(),
    work = work.toDomain(),
    // Highest first: the quality menu reads top-down and the picker's "best
    // under the ceiling" scan relies on this order.
    videos = videos.map { it.toDomain() }.sortedByDescending { it.height },
    subtitles = subtitles.map { it.toDomain() },
    fonts = fonts.map { SubtitleFont(it.family, it.url, it.origin) },
    markers = Markers(markers.introStart, markers.introEnd, markers.outroStart),
    next = next?.toDomain(),
    previous = previous?.toDomain(),
    resume = resume?.toDomain(),
)

fun AnnouncementDto.toDomain(): Announcement = Announcement(id, title, body, link)

fun GenreDto.toDomain(): Genre = Genre(name, count)

/* ── Domain → cache ───────────────────────────────────────────────────── */

fun Work.toEntity(rail: String = "", railOrder: Int = 0): WorkEntity = WorkEntity(
    id = id,
    slug = slug,
    title = title,
    titleEnglish = titleEnglish,
    synopsis = synopsis,
    posterUrl = posterUrl,
    bannerUrl = bannerUrl,
    score = score,
    year = year,
    season = season,
    status = status.name.lowercase(),
    format = format,
    studio = studio,
    // Comma-joined; a genre containing a comma would break this, and none do.
    genres = genres.joinToString(","),
    totalEpisodes = totalEpisodes,
    durationSeconds = durationSeconds,
    rail = rail,
    railOrder = railOrder,
)

fun Episode.toEntity(): EpisodeEntity = EpisodeEntity(
    id = id,
    workId = workId,
    seasonNumber = seasonNumber,
    number = number,
    title = title,
    synopsis = synopsis,
    thumbnailUrl = thumbnailUrl,
    durationSeconds = durationSeconds,
    filler = filler,
    publishedAt = publishedAt,
)

/* ── Cache → domain ───────────────────────────────────────────────────── */

fun WorkEntity.toDomain(): Work = Work(
    id = id,
    slug = slug,
    title = title,
    titleEnglish = titleEnglish,
    synopsis = synopsis,
    posterUrl = posterUrl,
    bannerUrl = bannerUrl,
    score = score,
    year = year,
    season = season,
    status = WorkStatus.from(status),
    format = format,
    studio = studio,
    genres = genres.split(",").filter { it.isNotBlank() },
    totalEpisodes = totalEpisodes,
    durationSeconds = durationSeconds,
)

fun EpisodeEntity.toDomain(progress: Progress? = null): Episode = Episode(
    id = id,
    workId = workId,
    seasonNumber = seasonNumber,
    number = number,
    title = title,
    synopsis = synopsis,
    thumbnailUrl = thumbnailUrl,
    durationSeconds = durationSeconds,
    filler = filler,
    publishedAt = publishedAt,
    progress = progress,
)

fun ProgressEntity.toDomain(): Progress =
    Progress(positionSeconds, durationSeconds, watchedSeconds, completed)

fun libraryKey(workId: Long, list: String): String = "$list:$workId"

fun libraryEntry(workId: Long, list: String): LibraryEntity =
    LibraryEntity(libraryKey(workId, list), workId, list, System.currentTimeMillis())
