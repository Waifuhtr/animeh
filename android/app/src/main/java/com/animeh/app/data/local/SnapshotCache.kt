package com.animeh.app.data.local

import com.animeh.app.data.local.dao.SnapshotDao
import com.animeh.app.data.local.entity.SnapshotEntity
import kotlinx.serialization.SerializationException
import kotlinx.serialization.json.Json
import javax.inject.Inject
import javax.inject.Singleton

/**
 * A screen's last known answer, kept for the moment before the network's
 * current one arrives.
 *
 * The pattern `CatalogRepository.cachedHome` already uses for the home
 * screen, generalised. That one is bespoke because a rail is drawn a row at a
 * time and rebuilding it needs Room's own query machinery — [WorkEntity] and
 * its `rail` column exist for exactly that. Most other screens are not rails:
 * a profile, a wallet balance, a page of the leaderboard are each one
 * response object that a screen draws from whole, so there is nothing here to
 * query *by* — a caller asks for its own key back, gets the same object it
 * saved, and paints it while the network call for the same thing is still in
 * flight.
 *
 * Deliberately not used for the catalogue. An anime or manga list is the one
 * thing this app already knows will keep growing without bound, and a cache
 * with no eviction policy over a growing archive is a phone that fills up
 * over a year of use. Everything that does go through here is one bounded
 * object, whatever the catalogue's size.
 */
@Singleton
class SnapshotCache @Inject constructor(
    @PublishedApi internal val dao: SnapshotDao,
    @PublishedApi internal val json: Json,
) {

    /**
     * What was last saved under [key], or null on a first run, a cleared
     * cache, or a shape the app no longer knows how to read.
     *
     * That last case is why this returns null rather than throwing: an app
     * update can change what a DTO looks like between one launch and the
     * next, and a snapshot saved by yesterday's version has to fail quietly
     * into "nothing cached" rather than crash the screen it exists to speed
     * up. [Json.coerceInputValues] already absorbs a field that merely
     * changed shape; this catches the rest — a value that will not parse as
     * JSON at all, or a top-level shape too different to decode as anything.
     */
    suspend inline fun <reified T> read(key: String): T? {
        val row = dao.get(key) ?: return null

        return try {
            json.decodeFromString(row.json)
        } catch (_: SerializationException) {
            null
        } catch (_: IllegalArgumentException) {
            null
        }
    }

    /** Save [value] under [key], replacing whatever was there. */
    suspend inline fun <reified T> write(key: String, value: T) {
        dao.put(SnapshotEntity(key, json.encodeToString(value)))
    }
}
