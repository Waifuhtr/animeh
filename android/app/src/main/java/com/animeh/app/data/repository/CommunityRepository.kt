package com.animeh.app.data.repository

import com.animeh.app.core.AppResult
import com.animeh.app.data.remote.ApiErrorMapper
import com.animeh.app.data.remote.PublicApi
import com.animeh.app.data.remote.UserApi
import com.animeh.app.data.remote.dto.ReviewDto
import com.animeh.app.data.remote.dto.ReviewListDto
import com.animeh.app.data.remote.dto.ReviewRequest
import com.animeh.app.data.remote.dto.VoteRequest
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Reviews, the votes on them, and the labels the catalog is drawn under.
 *
 * The labels are fetched once and held: they change when an admin edits them,
 * which is rare, and every card and chip on every screen reads them.
 */
@Singleton
class CommunityRepository @Inject constructor(
    private val publicApi: PublicApi,
    private val userApi: UserApi,
) {

    private val _terms = MutableStateFlow<Map<String, Map<String, String>>>(emptyMap())

    /** Stored value to display name, by vocabulary. */
    val terms: StateFlow<Map<String, Map<String, String>>> = _terms.asStateFlow()

    /**
     * Load the label map.
     *
     * A failure is silent by design: labels are a nicety, and a screen that
     * refuses to draw because it could not fetch the Turkish for "comedy"
     * would be worse than one showing the imported word.
     */
    suspend fun refreshTerms() {
        val result = ApiErrorMapper.call({ it.terms }) { publicApi.terms() }

        if (result is AppResult.Success) {
            _terms.value = result.data
        }
    }

    suspend fun reviews(
        workId: Long,
        page: Int = 1,
        sort: String = "useful",
    ): AppResult<ReviewListDto> =
        ApiErrorMapper.call { publicApi.reviews(workId, page, PER_PAGE, sort) }

    suspend fun save(
        workId: Long,
        score: Int,
        body: String,
        spoiler: Boolean,
    ): AppResult<ReviewDto?> =
        ApiErrorMapper.call({ it.review }) {
            userApi.saveReview(workId, ReviewRequest(score, body.trim(), spoiler))
        }

    suspend fun delete(reviewId: Long): AppResult<Unit> =
        ApiErrorMapper.call({ Unit }) { userApi.deleteReview(reviewId) }

    /**
     * Agree or disagree with a review.
     *
     * Sending the vote already held withdraws it, which the server decides —
     * the same tap undoing itself is the behaviour a thumb expects.
     */
    suspend fun vote(reviewId: Long, vote: Int): AppResult<ReviewDto?> =
        ApiErrorMapper.call({ it.review }) { userApi.voteReview(reviewId, VoteRequest(vote)) }

    private companion object {
        const val PER_PAGE = 20
    }
}

/**
 * The label a stored value is shown under, or the value itself.
 *
 * Matching is case- and space-insensitive, mirroring the server: an import
 * that says "Slice of Life" and one that says "slice of life" are one term.
 */
fun Map<String, Map<String, String>>.label(kind: String, value: String): String =
    this[kind]?.get(value.trim().lowercase()) ?: value
