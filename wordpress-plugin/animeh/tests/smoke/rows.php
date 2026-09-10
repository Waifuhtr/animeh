<?php
/**
 * @package Animeh
 */

/**
 * Rows shaped like the real tables — including the state the site is actually
 * in: upgraded from an older schema, so a row that was written before a
 * column existed does not have that key.
 */
function animeh_work_row( array $overrides = array() ): array {
	return array_merge(
		array(
			'id' => 1, 'kind' => 'anime', 'tenrai_id' => 0, 'mal_id' => 0, 'tmdb_id' => 0,
			'nh_id' => 0, 'slug' => 'overflow', 'title' => 'Overflow', 'title_english' => '',
			'title_japanese' => '', 'synonyms' => '[]', 'synopsis' => 'x', 'poster_url' => '',
			'banner_url' => '', 'trailer_url' => '', 'score' => 6.5, 'popularity' => 10,
			'year' => 2020, 'season' => 'winter', 'status' => 'finished', 'format' => 'TV',
			'rating' => '', 'studio' => 'S', 'author' => '', 'genres' => '[]',
			'total_episodes' => 8, 'duration_seconds' => 480, 'published' => 1, 'adult' => 1,
			'created_by' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		),
		$overrides
	);
}

function animeh_episode_row( array $overrides = array() ): array {
	return array_merge(
		array(
			'id' => 10, 'work_id' => 1, 'season_number' => 1, 'number' => '1.00',
			'title' => '', 'synopsis' => '', 'thumbnail_url' => '', 'duration_seconds' => 480,
			'intro_start' => -1, 'intro_end' => -1, 'outro_start' => -1, 'filler' => 0,
			'published' => 1, 'published_at' => '2026-01-01 00:00:00',
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			'work_kind' => 'anime', 'work_title' => 'Overflow', 'work_slug' => 'overflow',
			'work_poster' => '',
		),
		$overrides
	);
}
