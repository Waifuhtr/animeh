package com.animeh.app.ui.screens.leaderboard

import androidx.compose.runtime.Immutable
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.animeh.app.core.AppResult
import com.animeh.app.data.remote.dto.LeaderboardEntryDto
import com.animeh.app.data.remote.dto.StandingDto
import com.animeh.app.data.repository.RewardsRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

/** The four boards, in the order the tabs show them. */
enum class BoardMetric(val key: String, val label: String) {
    EPISODES(RewardsRepository.METRIC_EPISODES, "Bölüm"),
    SECONDS(RewardsRepository.METRIC_SECONDS, "Süre"),
    WORKS(RewardsRepository.METRIC_WORKS, "Anime"),
    // Anime and manga together, in the one unit that already weighs a
    // chapter fairly against an episode instead of pretending a page is a
    // second — see LeaderboardRepository::METRIC_POINTS on the server.
    POINTS(RewardsRepository.METRIC_POINTS, "Puan"),
}

@Immutable
data class BoardState(
    val loading: Boolean = true,
    val entries: List<LeaderboardEntryDto> = emptyList(),
    val me: StandingDto? = null,
    val error: String? = null,
)

@HiltViewModel
class LeaderboardViewModel @Inject constructor(
    private val rewards: RewardsRepository,
) : ViewModel() {

    private val _metric = MutableStateFlow(BoardMetric.EPISODES)
    val metric: StateFlow<BoardMetric> = _metric.asStateFlow()

    /**
     * One state per board, kept side by side.
     *
     * Switching tabs should show the board that was already loaded rather than
     * a spinner: the server caches each one for five minutes, so going back
     * and forth would otherwise mean the same request over and over for a list
     * that has not changed.
     */
    private val _boards = MutableStateFlow<Map<BoardMetric, BoardState>>(emptyMap())
    val boards: StateFlow<Map<BoardMetric, BoardState>> = _boards.asStateFlow()

    init {
        load(BoardMetric.EPISODES)
    }

    fun select(metric: BoardMetric) {
        _metric.value = metric
        if (_boards.value[metric]?.entries.isNullOrEmpty()) load(metric)
    }

    fun refresh() {
        load(_metric.value)
    }

    private fun load(metric: BoardMetric) {
        viewModelScope.launch {
            val current = _boards.value[metric] ?: BoardState()

            // A board opened for the first time this process has nothing in
            // [current] yet — that gap is what the disk cache fills, the same
            // way the in-memory map already fills it on a second visit to a
            // tab within one session.
            val seeded = if (current.entries.isEmpty()) {
                rewards.cachedLeaderboard(metric.key)?.let {
                    current.copy(entries = it.entries, me = it.me)
                } ?: current
            } else {
                current
            }

            put(metric, seeded.copy(loading = true, error = null))

            when (val result = rewards.leaderboard(metric.key, limit = 50)) {
                is AppResult.Success -> put(
                    metric,
                    BoardState(
                        loading = false,
                        entries = result.data.entries,
                        me = result.data.me,
                    ),
                )

                // A board already on screen — cached or from the last load —
                // is worth more than an error message over a blip; a metric
                // with nothing to show yet still gets one.
                is AppResult.Failure -> put(
                    metric,
                    seeded.copy(
                        loading = false,
                        error = if (seeded.entries.isEmpty()) "Sıralama yüklenemedi." else null,
                    ),
                )
            }
        }
    }

    private fun put(metric: BoardMetric, state: BoardState) {
        _boards.value = _boards.value + (metric to state)
    }
}
