package com.animeh.app.player.ass

import android.content.Context
import android.graphics.Typeface
import android.util.Log
import com.animeh.app.data.local.dao.FontDao
import com.animeh.app.data.local.entity.FontEntity
import com.animeh.app.domain.SubtitleFont
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.File
import javax.inject.Inject
import javax.inject.Named
import javax.inject.Singleton

/**
 * Finding the fonts a subtitle asks for.
 *
 * §14 sets the order and it is followed exactly:
 *
 *   1. the app's own font cache
 *   2. the fonts the backend serves for this work
 *   3. fonts embedded in the subtitle itself
 *   4. a licensed public source
 *
 * and then stops. **No scraping.** The brief is explicit that fonts must not be
 * pulled from arbitrary sites and that an unmatched family must be reported
 * rather than force-matched to something that looks similar — a substituted
 * font silently breaks the timing and positioning the typesetter chose.
 *
 * Step 4 is deliberately not implemented as an automatic download. Deciding
 * whether a given family is licensed for redistribution is not something this
 * code can do correctly, so an unresolved family is reported to the admin, who
 * uploads it through the plugin. That is the flow §15 describes.
 */
@Singleton
class FontResolver @Inject constructor(
    // Hilt binds the application context under this qualifier; a bare `Context`
    // has no binding in the singleton component at all.
    @ApplicationContext private val context: Context,
    private val fontDao: FontDao,
    @Named("base_client") private val client: OkHttpClient,
) {

    /** What a resolution attempt produced. */
    data class Resolution(
        val typefaces: Map<String, Typeface>,
        val missing: List<String>,
    )

    private val cacheDir: File by lazy {
        File(context.filesDir, "subtitle-fonts").apply { mkdirs() }
    }

    /** Loaded typefaces, so a next-episode does not re-parse the same files. */
    private val loaded = mutableMapOf<String, Typeface>()

    /**
     * Resolve every family a script needs.
     *
     * @param required families from [AssParser.requiredFonts].
     * @param offered  fonts the backend listed for this work.
     */
    suspend fun resolve(
        required: List<String>,
        offered: List<SubtitleFont>,
    ): Resolution = withContext(Dispatchers.IO) {
        val typefaces = mutableMapOf<String, Typeface>()
        val missing = mutableListOf<String>()

        // Indexed by normalised key: a script asking for "DejaVu Sans" and a
        // registry holding "dejavu sans" are the same font, and matching on the
        // raw string is why font lookups usually fail.
        val offeredByKey = offered.associateBy { AssParser.key(it.family) }

        for (family in required) {
            val key = AssParser.key(family)

            // Plain `if` rather than `?.let { … continue }`: a non-local
            // `continue` out of an inline lambda needs a newer language
            // version than this project is pinned to, and an `if` block
            // carries no such restriction.
            val alreadyLoaded = loaded[key]
            if (alreadyLoaded != null) {
                typefaces[key] = alreadyLoaded
                continue
            }

            val cached = fontDao.byFamily(key)
            if (cached != null && File(cached.localPath).exists()) {
                val typeface = load(cached.localPath)
                if (typeface != null) {
                    loaded[key] = typeface
                    typefaces[key] = typeface
                    continue
                }
            }

            val remote = offeredByKey[key]
            if (remote != null && remote.url.isNotBlank()) {
                val file = download(remote.url, key)
                if (file != null) {
                    val typeface = load(file.absolutePath)
                    if (typeface != null) {
                        loaded[key] = typeface
                        typefaces[key] = typeface
                        fontDao.upsert(
                            FontEntity(
                                family = key,
                                url = remote.url,
                                localPath = file.absolutePath,
                                sizeBytes = file.length(),
                            )
                        )
                        continue
                    }
                }
            }

            // Reported, not substituted. A near-match would render at the wrong
            // metrics and quietly break the typesetting.
            missing += family
        }

        Resolution(typefaces, missing)
    }

    /** A typeface for one family, or null when it was never resolved. */
    fun typefaceFor(family: String): Typeface? = loaded[AssParser.key(family)]

    /** Register a font extracted from the subtitle container itself. */
    suspend fun registerEmbedded(family: String, bytes: ByteArray): Boolean =
        withContext(Dispatchers.IO) {
            val key = AssParser.key(family)
            val file = File(cacheDir, "${key.hashCode()}.ttf")

            try {
                file.writeBytes(bytes)
            } catch (error: Exception) {
                Log.w(TAG, "embedded font write failed for $family", error)
                return@withContext false
            }

            val typeface = load(file.absolutePath) ?: return@withContext false

            loaded[key] = typeface
            fontDao.upsert(FontEntity(key, "", file.absolutePath, file.length()))

            true
        }

    suspend fun clearCache() = withContext(Dispatchers.IO) {
        loaded.clear()
        fontDao.clear()
        cacheDir.listFiles()?.forEach { it.delete() }
        Unit
    }

    fun cacheSizeBytes(): Long =
        cacheDir.listFiles()?.sumOf { it.length() } ?: 0L

    private fun download(url: String, key: String): File? {
        val target = File(cacheDir, "${key.hashCode()}.font")

        if (target.exists() && target.length() > 0) return target

        return try {
            client.newCall(Request.Builder().url(url).build()).execute().use { response ->
                if (!response.isSuccessful) {
                    Log.w(TAG, "font download failed: HTTP ${response.code} for $url")
                    return null
                }

                val body = response.body ?: return null
                target.outputStream().use { output -> body.byteStream().copyTo(output) }

                target.takeIf { it.length() > 0 }
            }
        } catch (error: Exception) {
            // A missing font must not take the episode down with it; the
            // subtitle still renders in the fallback face and the family is
            // reported as missing.
            Log.w(TAG, "font download failed for $url", error)
            target.delete()
            null
        }
    }

    private fun load(path: String): Typeface? = try {
        Typeface.createFromFile(path)
    } catch (error: Exception) {
        Log.w(TAG, "font file unreadable: $path", error)
        null
    }

    private companion object {
        const val TAG = "FontResolver"
    }
}
