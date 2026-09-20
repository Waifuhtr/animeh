<?php
/**
 * What the player is told about advertising.
 *
 * Kept on the server and handed out with the rest of the client config, for
 * one reason that decides the whole shape: a switch in the app would be a
 * switch on *one phone*. The operator turning advertising on has to turn it on
 * for everybody, and turning it off after a bad night has to reach everybody
 * before the next episode starts — neither of which a build flag or a local
 * preference can do.
 *
 * Nothing here is secret. The tag address is a public endpoint an ad network
 * publishes precisely so that clients can request it; serving it is the same
 * judgement already made for Firebase's client keys. What it buys is a
 * frequency, a placement and an off switch that change without a new APK.
 *
 * Empty means off, and off is the default. An install that never touches this
 * screen shows no advertising and makes no request to anybody.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Storage;

use Animeh\Support\PublicUrl;

/**
 * Reads and writes the advertising settings.
 */
final class AdSettings {

	/**
	 * Option holding everything on this screen.
	 */
	public const OPTION = 'animeh_ads';

	/**
	 * Seconds of playback between one ad and the next.
	 *
	 * Four minutes, which is what the operator asked for.
	 */
	public const DEFAULT_INTERVAL = 240;

	/**
	 * The shortest interval that can be set, in seconds.
	 *
	 * A minute. Not a technical limit — a floor under how hostile the app can
	 * be made by a typo. An interval of ten seconds would make an episode
	 * unwatchable and would be worth less per impression, not more, because
	 * nobody would stay.
	 */
	public const MIN_INTERVAL = 60;

	/**
	 * The longest, in seconds. An hour, past which it is off in all but name.
	 */
	public const MAX_INTERVAL = 3600;

	/**
	 * Seconds before the skip button appears, when the ad does not say.
	 */
	public const DEFAULT_SKIP_AFTER = 5;

	/**
	 * The most an ad can be unskippable for, in seconds.
	 */
	public const MAX_SKIP_AFTER = 60;

	/**
	 * Everything an administrator has saved.
	 *
	 * @return array{enabled: bool, tag: string, interval: int, skippable: bool, skip_after: int, preroll: bool}
	 */
	public static function load(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'enabled'    => ! empty( $stored['enabled'] ),
			'tag'        => PublicUrl::http( $stored['tag'] ?? '' ),
			'interval'   => self::clamp_interval( $stored['interval'] ?? self::DEFAULT_INTERVAL ),
			// Default on. An ad nobody can escape is the shape that gets an
			// app uninstalled, and an uninstalled app shows no ads at all.
			'skippable'  => ! isset( $stored['skippable'] ) || ! empty( $stored['skippable'] ),
			'skip_after' => self::clamp_skip( $stored['skip_after'] ?? self::DEFAULT_SKIP_AFTER ),
			'preroll'    => ! empty( $stored['preroll'] ),
		);
	}

	/**
	 * Save what an administrator submitted.
	 *
	 * @param array<string, mixed> $input Raw form values.
	 * @return array{enabled: bool, tag: string, interval: int, skippable: bool, skip_after: int, preroll: bool} What was stored.
	 */
	public static function save( array $input ): array {
		$clean = array(
			'enabled'    => ! empty( $input['enabled'] ),
			'tag'        => PublicUrl::http( $input['tag'] ?? '' ),
			'interval'   => self::clamp_interval( $input['interval'] ?? self::DEFAULT_INTERVAL ),
			'skippable'  => ! empty( $input['skippable'] ),
			'skip_after' => self::clamp_skip( $input['skip_after'] ?? self::DEFAULT_SKIP_AFTER ),
			'preroll'    => ! empty( $input['preroll'] ),
		);

		update_option( self::OPTION, $clean );

		return $clean;
	}

	/**
	 * What `/config` hands every app.
	 *
	 * Absent rather than disabled when there is nothing to show: an app told
	 * nothing about advertising simply never asks for an ad, which is the
	 * right behaviour on an install that has not set this up and keeps the
	 * request count at zero. Same judgement as Firebase's client config.
	 *
	 * The tag goes out only when advertising is actually on. There is no
	 * reason for an address to sit in the config of an app that will not use
	 * it, and a tag that is not published cannot be requested by accident.
	 *
	 * @return array<string, mixed>
	 */
	public static function client_config(): array {
		$settings = self::load();

		if ( ! $settings['enabled'] || '' === $settings['tag'] ) {
			return array();
		}

		return array(
			'tag'        => $settings['tag'],
			'interval'   => $settings['interval'],
			'skippable'  => $settings['skippable'],
			'skip_after' => $settings['skip_after'],
			'preroll'    => $settings['preroll'],
		);
	}

	/**
	 * Seconds between ads, held inside what is watchable.
	 *
	 * @param mixed $raw Submitted value.
	 */
	private static function clamp_interval( $raw ): int {
		$seconds = (int) $raw;

		if ( $seconds <= 0 ) {
			return self::DEFAULT_INTERVAL;
		}

		return max( self::MIN_INTERVAL, min( self::MAX_INTERVAL, $seconds ) );
	}

	/**
	 * Seconds before the skip button appears.
	 *
	 * Zero is allowed and means the ad can be skipped at once; it is a
	 * deliberate setting rather than a mistake, so it is not replaced by the
	 * default the way a negative or unreadable value is.
	 *
	 * @param mixed $raw Submitted value.
	 */
	private static function clamp_skip( $raw ): int {
		$seconds = (int) $raw;

		if ( $seconds < 0 ) {
			return self::DEFAULT_SKIP_AFTER;
		}

		return min( self::MAX_SKIP_AFTER, $seconds );
	}
}
