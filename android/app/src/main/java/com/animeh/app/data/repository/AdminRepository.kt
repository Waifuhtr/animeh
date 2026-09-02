package com.animeh.app.data.repository

import android.content.ContentResolver
import android.net.Uri
import com.animeh.app.core.AppError
import com.animeh.app.core.AppResult
import com.animeh.app.data.remote.AdminApi
import com.animeh.app.data.remote.ApiErrorMapper
import com.animeh.app.data.remote.dto.*
import com.animeh.app.domain.Episode
import com.animeh.app.domain.Work
import com.animeh.app.domain.toDomain
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.InputStream
import javax.inject.Inject
import javax.inject.Named
import javax.inject.Singleton

/**
 * Everything the in-app admin panel does.
 *
 * The upload path is the part worth reading. A 1.5 GB episode does not go
 * through WordPress: the server hands back one presigned URL per 32 MB part,
 * the app PUTs each part straight to Backblaze, and the server is told the
 * ETags at the end. That is why an episode can be uploaded from a phone at all
 * — routing it through PHP would hit `upload_max_filesize`, the memory limit
 * and the execution timeout in that order.
 */
@Singleton
class AdminRepository @Inject constructor(
    private val api: AdminApi,
    @Named("base_client") private val uploadClient: OkHttpClient,
    private val contentResolver: ContentResolver,
) {

    suspend fun dashboard(): AppResult<DashboardDto> =
        ApiErrorMapper.call { api.dashboard() }

    suspend fun works(search: String = "", page: Int = 1): AppResult<List<Work>> =
        ApiErrorMapper.call({ dto -> dto.items.map { it.toDomain() } }) {
            api.works(search, page)
        }

    suspend fun saveWork(request: AdminWorkRequest): AppResult<Work> =
        ApiErrorMapper.call({ it.work?.toDomain() ?: throw IllegalStateException("no work in response") }) {
            val id = request.id
            if (id == null || id == 0L) api.createWork(request) else api.updateWork(id, request)
        }

    suspend fun deleteWork(id: Long): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { api.deleteWork(id) }

    suspend fun episodes(workId: Long): AppResult<List<Episode>> =
        ApiErrorMapper.call({ dto -> dto.items.map { it.toDomain() } }) { api.episodes(workId) }

    suspend fun episode(id: Long): AppResult<AdminEpisodeEnvelopeDto> =
        ApiErrorMapper.call { api.episode(id) }

    suspend fun saveEpisode(workId: Long, request: AdminEpisodeRequest): AppResult<Episode> =
        ApiErrorMapper.call({ it.episode?.toDomain() ?: throw IllegalStateException("no episode in response") }) {
            val id = request.id
            if (id == null || id == 0L) api.createEpisode(workId, request) else api.updateEpisode(id, request)
        }

    suspend fun deleteEpisode(id: Long): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { api.deleteEpisode(id) }

    suspend fun sources(episodeId: Long): AppResult<List<AdminSourceDto>> =
        ApiErrorMapper.call({ it.items }) { api.sources(episodeId) }

    suspend fun saveSource(episodeId: Long, request: AdminSourceRequest): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { api.createSource(episodeId, request) }

    suspend fun deleteSource(id: Long): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { api.deleteSource(id) }

    /** Every vocabulary value the catalog uses, with its label if it has one. */
    suspend fun terms(): AppResult<List<AdminTermDto>> =
        ApiErrorMapper.call({ it.items }) { api.terms() }

    /** Set a label, or clear it by passing an empty one. */
    suspend fun saveTerm(kind: String, source: String, display: String): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) {
            api.saveTerm(AdminTermRequest(kind, source, display.trim()))
        }

    suspend fun tenraiSearch(query: String, page: Int = 1): AppResult<List<TenraiSearchResultDto>> =
        ApiErrorMapper.call({ it.items }) { api.tenraiSearch(query, page) }

    suspend fun tenraiImport(
        tenraiId: Long,
        importEpisodes: Boolean = true,
        publish: Boolean = false,
    ): AppResult<TenraiImportResultDto> =
        ApiErrorMapper.call { api.tenraiImport(TenraiImportRequest(tenraiId, importEpisodes, publish)) }

    suspend fun users(search: String = "", page: Int = 1): AppResult<AdminUserListDto> =
        ApiErrorMapper.call { api.users(search, page) }

    suspend fun announcements(): AppResult<List<AdminAnnouncementDto>> =
        ApiErrorMapper.call({ it.items }) { api.announcements() }

    suspend fun saveAnnouncement(announcement: AdminAnnouncementDto): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { api.saveAnnouncement(announcement) }

    suspend fun deleteAnnouncement(id: Long): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { api.deleteAnnouncement(id) }

    suspend fun logs(level: String = "", search: String = "", page: Int = 1): AppResult<LogListDto> =
        ApiErrorMapper.call { api.logs(level, search, page) }

    suspend fun clearLogs(): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { api.clearLogs() }

    suspend fun fonts(): AppResult<List<AdminFontDto>> =
        ApiErrorMapper.call({ it.fonts }) { api.fonts() }

    suspend fun deleteFont(id: Long): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { api.deleteFont(id) }

    suspend fun testStorage(): AppResult<StorageTestResultDto> =
        ApiErrorMapper.call({ it.result }) { api.testStorage() }

    /**
     * Upload a file straight to storage in parts.
     *
     * @param onProgress fraction 0f..1f, called on the caller's dispatcher.
     * @return the object key, which is then attached to an episode as a source.
     */
    suspend fun uploadFile(
        uri: Uri,
        animeTitle: String,
        animeId: Long,
        season: Int,
        episode: Int,
        filename: String,
        contentType: String,
        onProgress: (Float) -> Unit = {},
    ): AppResult<String> = withContext(Dispatchers.IO) {
        val size = fileSize(uri)
            ?: return@withContext AppResult.Failure(AppError.Storage("dosya boyutu okunamadı"))

        val begin = ApiErrorMapper.call({ it.upload }) {
            api.beginUpload(
                UploadBeginRequest(
                    animeTitle = animeTitle,
                    animeId = animeId,
                    season = season,
                    episode = episode,
                    filename = filename,
                    size = size,
                    contentType = contentType,
                )
            )
        }

        val plan = when (begin) {
            is AppResult.Success -> begin.data
            is AppResult.Failure -> return@withContext begin
        }

        // A plan with nothing to upload is not something to proceed with: the
        // parts would never be sent and the server would be asked to complete
        // an upload of nothing. Said plainly here rather than as a confusing
        // refusal from the completion call.
        if (plan.parts.isEmpty() || plan.uploadId.isBlank() || plan.key.isBlank()) {
            return@withContext AppResult.Failure(
                AppError.Storage("sunucu geçerli bir yükleme planı vermedi")
            )
        }

        val etags = mutableListOf<UploadedPartDto>()

        try {
            contentResolver.openInputStream(uri).use { stream ->
                if (stream == null) {
                    return@withContext AppResult.Failure(AppError.Storage("dosya açılamadı"))
                }

                plan.parts.forEachIndexed { index, part ->
                    val chunk = stream.readChunk(plan.partSize.toInt())
                    if (chunk.isEmpty()) return@forEachIndexed

                    val response = uploadClient.newCall(
                        Request.Builder()
                            .url(part.url)
                            .put(chunk.toRequestBody(mediaType(contentType)))
                            .build()
                    ).execute()

                    response.use {
                        if (!it.isSuccessful) {
                            throw UploadFailed("parça ${part.partNumber}: HTTP ${it.code}")
                        }

                        // S3 returns the part's ETag in a header; the complete
                        // call is rejected without every one of them.
                        val etag = it.header("ETag")
                            ?: throw UploadFailed("parça ${part.partNumber}: ETag yok")

                        etags += UploadedPartDto(part.partNumber, etag.trim('"'))
                    }

                    onProgress((index + 1).toFloat() / plan.parts.size)
                }
            }
        } catch (error: Exception) {
            // Abandoned parts are billed as storage until they are cleaned up,
            // so a failed upload tells the server to drop them.
            ApiErrorMapper.call({ Unit }) {
                api.abortUpload(mapOf("key" to plan.key, "upload_id" to plan.uploadId))
            }

            return@withContext AppResult.Failure(
                AppError.Storage(error.message ?: "yükleme başarısız")
            )
        }

        val completed = ApiErrorMapper.call({ it.upload }) {
            api.completeUpload(UploadCompleteRequest(plan.key, plan.uploadId, etags))
        }

        when (completed) {
            is AppResult.Success -> AppResult.Success(completed.data.key.ifBlank { plan.key })
            is AppResult.Failure -> completed
        }
    }

    private fun fileSize(uri: Uri): Long? =
        contentResolver.openAssetFileDescriptor(uri, "r")?.use { it.length.takeIf { l -> l > 0 } }

    /** Read exactly [size] bytes, or fewer at the end of the stream. */
    private fun InputStream.readChunk(size: Int): ByteArray {
        val buffer = ByteArray(size)
        var read = 0
        while (read < size) {
            val count = read(buffer, read, size - read)
            if (count <= 0) break
            read += count
        }
        return if (read == size) buffer else buffer.copyOf(read)
    }

    /** A Content-Type storage will accept, whatever the picker reported. */
    private fun mediaType(value: String) =
        value.toMediaTypeOrNull() ?: "application/octet-stream".toMediaTypeOrNull()

    private class UploadFailed(message: String) : Exception(message)
}
