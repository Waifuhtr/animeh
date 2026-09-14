package com.animeh.app.data.repository

import android.content.ContentResolver
import android.graphics.Bitmap
import android.media.MediaMetadataRetriever
import android.net.Uri
import android.provider.OpenableColumns
import com.animeh.app.core.AppError
import com.animeh.app.core.AppResult
import com.animeh.app.data.remote.ApiErrorMapper
import com.animeh.app.data.remote.PublicApi
import com.animeh.app.data.remote.UserApi
import com.animeh.app.data.remote.dto.*
import kotlinx.coroutines.Deferred
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.sync.Semaphore
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.ByteArrayOutputStream
import java.io.IOException
import java.io.InputStream
import javax.inject.Inject
import javax.inject.Named
import javax.inject.Singleton

/**
 * AnimehTok's data layer.
 *
 * The feed and the pages around it are ordinary reads. The upload is the part
 * worth reading, and it is the same shape the episode uploader already proved:
 * the server signs one URL per part, the phone PUTs each part straight to
 * Backblaze, and the server is told the ETags at the end. A video never passes
 * through PHP, which is what keeps `upload_max_filesize`, the memory limit and
 * the execution timeout out of the picture.
 *
 * Two things this deliberately does not do: it never writes watch history, and
 * it never asks for points. Time spent here is not time spent watching an
 * episode, and the numbers say so.
 */
@Singleton
class ShortsRepository @Inject constructor(
    private val publicApi: PublicApi,
    private val userApi: UserApi,
    @Named("base_client") private val uploadClient: OkHttpClient,
    private val contentResolver: ContentResolver,
    private val compressor: ShortsCompressor,
) {

    /* ── Reading ─────────────────────────────────────────────────────── */

    suspend fun feed(
        tab: String = TAB_FOR_YOU,
        offset: Int = 0,
        perPage: Int = FEED_PAGE,
    ): AppResult<List<ShortDto>> =
        ApiErrorMapper.call({ it.items }) { publicApi.shortsFeed(tab, offset, perPage) }

    suspend fun short(id: Long): AppResult<ShortDto> =
        ApiErrorMapper.call { publicApi.short(id) }

    suspend fun comments(
        shortId: Long,
        parent: Long = 0,
        offset: Int = 0,
        perPage: Int = 20,
    ): AppResult<ShortCommentListDto> =
        ApiErrorMapper.call { publicApi.shortComments(shortId, parent, offset, perPage) }

    suspend fun tagPage(tag: String, offset: Int = 0): AppResult<ShortTagPageDto> =
        ApiErrorMapper.call { publicApi.shortTag(tag, offset) }

    suspend fun trendingTags(): AppResult<List<ShortTagDto>> =
        ApiErrorMapper.call({ it.items }) { publicApi.trendingShortTags() }

    suspend fun soundPage(id: Long, offset: Int = 0): AppResult<ShortSoundPageDto> =
        ApiErrorMapper.call { publicApi.shortSound(id, offset) }

    suspend fun creatorPage(id: Long, offset: Int = 0): AppResult<ShortCreatorPageDto> =
        ApiErrorMapper.call { publicApi.shortCreator(id, offset) }

    suspend fun search(query: String): AppResult<ShortSearchDto> =
        ApiErrorMapper.call { publicApi.searchShorts(query) }

    suspend fun mine(offset: Int = 0): AppResult<List<ShortDto>> =
        ApiErrorMapper.call({ it.items }) { userApi.myShorts(offset) }

    suspend fun saved(offset: Int = 0): AppResult<List<ShortDto>> =
        ApiErrorMapper.call({ it.items }) { userApi.savedShorts(offset) }

    suspend fun stats(): AppResult<ShortStatsEnvelopeDto> =
        ApiErrorMapper.call { userApi.myShortStats() }

    /* ── The bell ────────────────────────────────────────────────────── */

    suspend fun notifications(): AppResult<ShortNotificationListDto> =
        ApiErrorMapper.call { userApi.myShortNotifications() }

    /** Opening the bell is what marks it read. */
    suspend fun notificationsSeen(): AppResult<ShortNotificationListDto> =
        ApiErrorMapper.call { userApi.markShortNotificationsSeen() }

    /* ── This account's AnimehTok profile ────────────────────────────── */

    suspend fun profile(): AppResult<ShortCreatorDto> =
        ApiErrorMapper.call { userApi.myShortProfile() }

    suspend fun saveProfile(bio: String, link: String): AppResult<ShortCreatorDto> =
        ApiErrorMapper.call { userApi.saveShortProfile(ShortProfileRequest(bio, link)) }

    /* ── Reacting ────────────────────────────────────────────────────── */

    suspend fun setLiked(id: Long, liked: Boolean): AppResult<ShortDto> =
        ApiErrorMapper.call { if (liked) userApi.likeShort(id) else userApi.unlikeShort(id) }

    suspend fun setSaved(id: Long, saved: Boolean): AppResult<ShortDto> =
        ApiErrorMapper.call { if (saved) userApi.saveShort(id) else userApi.unsaveShort(id) }

    suspend fun setFollowing(creatorId: Long, following: Boolean): AppResult<ShortFollowDto> =
        ApiErrorMapper.call {
            if (following) userApi.followCreator(creatorId) else userApi.unfollowCreator(creatorId)
        }

    /**
     * Count a view.
     *
     * Fire and forget on purpose: nothing on screen depends on the answer, and
     * a failed count is not worth a message. It also decides what the For You
     * feed shows next, which is the reason it is sent at all.
     */
    suspend fun countView(id: Long) {
        ApiErrorMapper.call({ Unit }) { publicApi.countShortView(id) }
    }

    suspend fun comment(shortId: Long, body: String, parent: Long = 0): AppResult<ShortCommentDto> =
        ApiErrorMapper.call { userApi.addShortComment(shortId, ShortCommentRequest(body, parent)) }

    suspend fun deleteComment(id: Long): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { userApi.deleteShortComment(id) }

    suspend fun setCommentLiked(id: Long, liked: Boolean): AppResult<ShortCommentDto> =
        ApiErrorMapper.call {
            if (liked) userApi.likeShortComment(id) else userApi.unlikeShortComment(id)
        }

    suspend fun updateDescription(id: Long, description: String): AppResult<ShortDto> =
        ApiErrorMapper.call { userApi.updateShort(id, ShortUpdateRequest(description)) }

    suspend fun delete(id: Long): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { userApi.deleteShort(id) }

    /* ── Uploading ───────────────────────────────────────────────────── */

    /**
     * What a picked video turns out to be.
     *
     * Read before anything is sent so the phone can refuse a two-hour film
     * without uploading it first, and so the server has the numbers the feed
     * needs to size a frame before the first byte of video arrives.
     */
    data class VideoFacts(
        val sizeBytes: Long,
        val durationMs: Long,
        val width: Int,
        val height: Int,
        val filename: String,
    )

    /**
     * Measure a picked video.
     *
     * Everything here can be absent — a content provider is not obliged to
     * answer any of it — so each field falls back rather than failing, and the
     * caller checks the ones it actually needs.
     */
    suspend fun inspect(uri: Uri): AppResult<VideoFacts> = withContext(Dispatchers.IO) {
        val size = fileSize(uri)
            ?: return@withContext AppResult.Failure(AppError.Storage("dosya boyutu okunamadı"))

        withRetriever(uri) { retriever ->
            AppResult.Success(
                VideoFacts(
                    sizeBytes = size,
                    durationMs = retriever.meta(MediaMetadataRetriever.METADATA_KEY_DURATION),
                    width = retriever.meta(MediaMetadataRetriever.METADATA_KEY_VIDEO_WIDTH).toInt(),
                    height = retriever.meta(MediaMetadataRetriever.METADATA_KEY_VIDEO_HEIGHT).toInt(),
                    filename = displayName(uri) ?: "video.mp4",
                )
            )
        } ?: AppResult.Failure(AppError.Storage("video okunamadı"))
    }

    /**
     * Upload a video and register it.
     *
     * @param onProgress fraction 0f..1f, called on this coroutine's dispatcher.
     */
    suspend fun upload(
        uri: Uri,
        facts: VideoFacts,
        description: String,
        soundTitle: String = "",
        adult: Boolean = false,
        trim: ShortTrim? = null,
        fitMode: String = FIT_ORIGINAL,
        onProgress: (Float) -> Unit = {},
    ): AppResult<ShortDto> = withContext(Dispatchers.IO) {
        // Re-encoded first, when it is worth it. A phone records at ten to
        // twenty-five megabits and nothing downstream can make that stream
        // instantly — the bytes are simply there. Best-effort: when it cannot
        // be done, the original goes up exactly as it did before.
        val shrunk = compressor.shrink(
            source = uri,
            widthPx = facts.width,
            heightPx = facts.height,
            sizeBytes = facts.sizeBytes,
            durationMs = facts.durationMs,
            trim = trim,
        ) { fraction ->
            onProgress(COMPRESS_SHARE * fraction)
        }

        // Best-effort is the right trade for a shrink and the wrong one for a
        // cut. Falling back to the original after a failed re-encode would put
        // the whole video up when the uploader asked for fifteen seconds of
        // it, and they would only find out by watching what they posted.
        if (trim != null && shrunk == null) {
            return@withContext AppResult.Failure(
                AppError.Storage("video kesilemedi: bu cihaz videoyu yeniden kodlayamadı")
            )
        }

        val sending = shrunk?.let(Uri::fromFile) ?: uri
        val measured = shrunk?.let { file ->
            facts.copy(
                sizeBytes = file.length(),
                filename = file.name,
                // Only when there is a re-encoded file, because only then is
                // the cut actually in the bytes being sent.
                durationMs = trim?.durationMs ?: facts.durationMs,
            )
        } ?: facts

        try {
            sendUp(sending, measured, description, soundTitle, adult, fitMode, onProgress)
        } finally {
            // The re-encoded copy has done its job either way.
            compressor.discard(shrunk)
        }
    }

    /**
     * Put a file in the bucket and register the row.
     *
     * Split out of [upload] so the compression step above reads as one thing
     * and this one keeps the shape the episode uploader proved.
     */
    private suspend fun sendUp(
        uri: Uri,
        facts: VideoFacts,
        description: String,
        soundTitle: String,
        adult: Boolean,
        fitMode: String,
        onProgress: (Float) -> Unit,
    ): AppResult<ShortDto> = withContext(Dispatchers.IO) {
        val begin = ApiErrorMapper.call {
            userApi.beginShortUpload(
                ShortUploadBeginRequest(
                    filename = facts.filename,
                    size = facts.sizeBytes,
                    contentType = "video/mp4",
                    description = description,
                )
            )
        }

        val plan = when (begin) {
            is AppResult.Success -> begin.data
            is AppResult.Failure -> return@withContext begin
        }

        // A plan with nothing to upload is not something to proceed with: the
        // parts would never be sent and the server would be asked to complete
        // an upload of nothing.
        if (plan.parts.isEmpty() || plan.uploadId.isBlank() || plan.key.isBlank()) {
            return@withContext AppResult.Failure(
                AppError.Storage("sunucu geçerli bir yükleme planı vermedi")
            )
        }

        val etags = try {
            sendParts(uri, plan, onProgress)
        } catch (error: Exception) {
            return@withContext AppResult.Failure(
                AppError.Storage(error.message ?: "yükleme başarısız")
            )
        }

        val completed = ApiErrorMapper.call {
            userApi.completeShortUpload(
                ShortUploadCompleteRequest(
                    key = plan.key,
                    uploadId = plan.uploadId,
                    slug = plan.slug,
                    parts = etags,
                    description = description,
                    durationMs = facts.durationMs,
                    width = facts.width,
                    height = facts.height,
                    size = facts.sizeBytes,
                    soundTitle = soundTitle,
                    adult = adult,
                    fitMode = fitMode,
                )
            )
        }

        val short = when (completed) {
            is AppResult.Success -> completed.data
            is AppResult.Failure -> return@withContext completed
        }

        onProgress(COMPRESS_SHARE + UPLOAD_SHARE + 0.02f)

        // The cover is best-effort: a video without one still plays, and the
        // feed falls back to its first frame. Failing the whole upload over a
        // thumbnail would be the wrong trade.
        val withCover = uploadCover(short.id, uri)

        onProgress(1f)

        AppResult.Success(if (withCover is AppResult.Success) withCover.data else short)
    }

    /**
     * Put every part in the bucket, several at a time.
     *
     * One at a time was the whole problem. A phone's uplink is not one
     * connection's worth of bandwidth: a single PUT settles at a fraction of
     * what the link can carry, and with the server handing out one 32 MB part
     * for a whole short video there was not even a second part to overlap it
     * with. Four in flight is where a mobile link stops gaining and starts
     * competing with itself.
     *
     * Reading stays sequential and single-threaded. The bytes come off one
     * stream in order — a content provider need not support seeking, and the
     * disk is never the slow part — and each part is handed to whichever
     * uploader is free. Memory is bounded by what is in flight, which is why
     * the producer waits for a slot rather than reading the file into a list.
     */
    private suspend fun sendParts(
        uri: Uri,
        plan: ShortUploadPlanDto,
        onProgress: (Float) -> Unit,
    ): List<UploadedPartDto> = coroutineScope {
        val done = java.util.concurrent.atomic.AtomicInteger(0)
        val slots = Semaphore(UPLOAD_LANES)
        val sent = mutableListOf<Deferred<UploadedPartDto>>()

        // Opened and closed by hand rather than with `use`: the loop suspends
        // on the semaphore, and a suspending call inside an inline lambda is
        // the kind of thing that is legal but not obviously legal. A `finally`
        // says the same thing with nothing to work out.
        val stream = contentResolver.openInputStream(uri) ?: throw UploadFailed("dosya açılamadı")

        try {
            for (part in plan.parts) {
                // Held before the read, so at most this many parts are ever in
                // memory at once whatever the file's size.
                slots.acquire()

                val chunk = try {
                    stream.readChunk(plan.partSize.toInt())
                } catch (unreadable: Exception) {
                    slots.release()
                    throw unreadable
                }

                if (chunk.isEmpty()) {
                    slots.release()
                    break
                }

                sent += async(Dispatchers.IO) {
                    try {
                        val tag = putPart(part, chunk)

                        // Whole parts rather than bytes: an upload of six parts
                        // moves the bar six times, which is five more than it
                        // moved before.
                        val finished = done.incrementAndGet().toFloat() / plan.parts.size
                        onProgress(COMPRESS_SHARE + (UPLOAD_SHARE * finished))

                        tag
                    } finally {
                        slots.release()
                    }
                }
            }
        } finally {
            stream.close()
        }

        // awaitAll rather than a loop of await: the first failure cancels the
        // rest instead of leaving three uploads running for an upload that is
        // already lost.
        sent.awaitAll()
    }

    /** One part, retried once, because a dropped connection is not a failure. */
    private fun putPart(part: UploadPartDto, chunk: ByteArray): UploadedPartDto {
        var last: Exception? = null

        repeat(PART_ATTEMPTS) {
            try {
                uploadClient.newCall(
                    Request.Builder()
                        .url(part.url)
                        .put(chunk.toRequestBody(VIDEO_MEDIA_TYPE))
                        .build()
                ).execute().use { response ->
                    if (!response.isSuccessful) {
                        throw UploadFailed("parça ${part.partNumber}: HTTP ${response.code}")
                    }

                    val etag = response.header("ETag")
                        ?: throw UploadFailed("parça ${part.partNumber}: ETag yok")

                    return UploadedPartDto(part.partNumber, etag.trim('"'))
                }
            } catch (dropped: IOException) {
                // Only the transport is retried. A refused part is refused
                // again, and trying twice only makes the failure slower.
                last = dropped
            }
        }

        throw last ?: UploadFailed("parça ${part.partNumber}: gönderilemedi")
    }

    /**
     * Grab a frame and store it as the video's cover.
     *
     * A second into the video rather than at zero: the first frame of a phone
     * recording is very often black.
     */
    private suspend fun uploadCover(id: Long, uri: Uri): AppResult<ShortDto> {
        val jpeg = frameJpeg(uri) ?: return AppResult.Failure(AppError.Storage("kare alınamadı"))

        return ApiErrorMapper.call {
            userApi.uploadShortCover(id, jpeg.toRequestBody(JPEG_MEDIA_TYPE))
        }
    }

    private fun frameJpeg(uri: Uri): ByteArray? = withRetriever(uri) { retriever ->
        val frame = retriever.getFrameAtTime(COVER_FRAME_US)
            ?: retriever.getFrameAtTime(0)
            ?: return@withRetriever null

        // A frame straight off a 1080p recording is a quarter of a megabyte of
        // JPEG, and it is shown on a grid three to a row. Capped at the same
        // short edge the video itself is capped at, so a cover is never larger
        // than the video it stands in for.
        val cover = fit(frame, ShortsCompressor.MAX_SHORT_EDGE)

        try {
            ByteArrayOutputStream().use { out ->
                cover.compress(Bitmap.CompressFormat.JPEG, COVER_QUALITY, out)
                out.toByteArray()
            }
        } finally {
            if (cover !== frame) cover.recycle()
            frame.recycle()
        }
    }

    /** [source] scaled so neither edge passes [shortEdge], or itself if it already fits. */
    private fun fit(source: Bitmap, shortEdge: Int): Bitmap {
        val smallest = minOf(source.width, source.height)

        if (smallest <= shortEdge || smallest <= 0) return source

        val factor = shortEdge.toFloat() / smallest

        return Bitmap.createScaledBitmap(
            source,
            (source.width * factor).toInt().coerceAtLeast(1),
            (source.height * factor).toInt().coerceAtLeast(1),
            true,
        )
    }

    /**
     * Open a retriever on a content URI, run [block], and always close both.
     *
     * The descriptor is opened explicitly rather than inlined, for two reasons
     * a compiler here cannot check: `setDataSource` takes a non-null
     * FileDescriptor, so `openFileDescriptor(…)?.fileDescriptor` does not type
     * — and a descriptor opened and never closed leaks until the process ends,
     * which on a feed that uploads several videos is a real handle leak.
     *
     * Null when the file could not be opened or read at all; every caller has
     * something sensible to do with that.
     */
    private fun <T> withRetriever(uri: Uri, block: (MediaMetadataRetriever) -> T?): T? {
        val descriptor = try {
            contentResolver.openFileDescriptor(uri, "r")
        } catch (error: Exception) {
            null
        } ?: return null

        val retriever = MediaMetadataRetriever()

        return try {
            descriptor.use { handle ->
                retriever.setDataSource(handle.fileDescriptor)
                block(retriever)
            }
        } catch (error: Exception) {
            null
        } finally {
            // The retriever holds a hardware decoder and a phone has a small
            // number of those, so this is not optional.
            runCatching { retriever.release() }
        }
    }

    /* ── Internals ───────────────────────────────────────────────────── */

    private fun MediaMetadataRetriever.meta(key: Int): Long =
        extractMetadata(key)?.toLongOrNull() ?: 0L

    private fun fileSize(uri: Uri): Long? {
        contentResolver.query(uri, arrayOf(OpenableColumns.SIZE), null, null, null)?.use { cursor ->
            if (cursor.moveToFirst()) {
                val index = cursor.getColumnIndex(OpenableColumns.SIZE)
                if (index >= 0 && !cursor.isNull(index)) return cursor.getLong(index)
            }
        }

        // The same fallback the episode uploader has been using: some providers
        // answer the cursor with nothing and the descriptor with a length.
        return contentResolver.openAssetFileDescriptor(uri, "r")?.use { it.length.takeIf { l -> l > 0 } }
    }

    private fun displayName(uri: Uri): String? {
        contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)
            ?.use { cursor ->
                if (cursor.moveToFirst()) {
                    val index = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME)
                    if (index >= 0) return cursor.getString(index)
                }
            }

        return null
    }

    /**
     * Exactly [size] bytes, or whatever is left.
     *
     * `read` is allowed to return fewer bytes than asked for at any time, so a
     * single call would produce short parts that S3 rejects.
     */
    private fun InputStream.readChunk(size: Int): ByteArray {
        val buffer = ByteArray(size)
        var filled = 0

        while (filled < size) {
            val read = read(buffer, filled, size - filled)
            if (read <= 0) break
            filled += read
        }

        return if (filled == size) buffer else buffer.copyOf(filled)
    }

    private class UploadFailed(message: String) : Exception(message)

    companion object {
        const val TAB_FOR_YOU = "foryou"
        const val TAB_FOLLOWING = "following"

        /**
         * How a video should meet the edge of the screen.
         *
         * [FIT_ORIGINAL] keeps the whole frame and lets the feed put bars
         * where the video does not reach; [FIT_FILL] covers the screen and
         * lets whatever hangs over the edge go. The uploader picks, and the
         * pixels are kept either way — this decides how they are shown, not
         * what is stored.
         */
        const val FIT_ORIGINAL = "original"
        const val FIT_FILL = "fill"

        /** What a notification is. The server decides; these read it. */
        const val NOTE_FOLLOW = "follow"
        const val NOTE_LIKE = "like"
        const val NOTE_COMMENT = "comment"

        /** How many videos one feed request brings back. */
        const val FEED_PAGE = 10

        /**
         * How many a grid page brings back.
         *
         * 21, which is seven full rows of three. It has to match what the
         * server's routes default to: a page that comes back short is how the
         * grid knows it has reached the end, and a client that guessed a
         * different number would either stop early or ask forever.
         */
        const val GRID_PAGE = 21

        /** Longest video the server will take, so the phone can say so first. */
        const val MAX_DURATION_MS = 180_000L

        /** Shortest cut worth uploading, and the closest the trim handles go. */
        const val MIN_DURATION_MS = 1_000L

        /** Largest file the server will take. */
        const val MAX_SIZE_BYTES = 300L * 1024 * 1024

        /**
         * How many parts are in the air at once.
         *
         * Four. A phone's uplink saturates somewhere around here; past it the
         * connections mostly compete with each other, and each one in flight
         * is another part's worth of bytes held in memory.
         */
        private const val UPLOAD_LANES = 4

        /** Tries per part. The second one is for a dropped connection. */
        private const val PART_ATTEMPTS = 2

        private const val COVER_FRAME_US = 1_000_000L
        private const val COVER_QUALITY = 82

        /**
         * How the progress bar is divided.
         *
         * Re-encoding is not instant and neither is the upload, so one bar
         * covers both rather than filling twice and looking stuck the second
         * time. The remainder is the completion call and the cover.
         */
        const val COMPRESS_SHARE = 0.35f
        private const val UPLOAD_SHARE = 0.55f

        private val VIDEO_MEDIA_TYPE = "video/mp4".toMediaTypeOrNull()
        private val JPEG_MEDIA_TYPE = "image/jpeg".toMediaTypeOrNull()
    }
}
