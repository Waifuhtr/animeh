<?php
/**
 * The points economy, written down in one place.
 *
 * Every number a viewer can earn or spend comes from here rather than from
 * whichever call site needed it. A currency whose rate is written in three
 * files is a currency that will eventually pay two different amounts for the
 * same episode.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Rates, reasons and the idempotency keys that stop double payment.
 */
final class Points {

	/**
	 * What one finished episode is worth.
	 *
	 * "Finished" is [WatchProgress::is_complete], the same test the profile
	 * counts with — so the number on the profile and the number in the wallet
	 * can never disagree about what happened.
	 */
	public const PER_EPISODE = 20;

	/**
	 * What finishing a manga chapter is worth.
	 *
	 * Half an episode, because it is half an evening: a chapter is ten
	 * minutes and an episode is twenty-odd, and paying the same for both
	 * would make reading the cheapest way to a frame.
	 */
	public const PER_CHAPTER = 10;

	/**
	 * What one finished thing is worth, by the kind of work it belongs to.
	 *
	 * @param string $kind `anime` or `manga`.
	 */
	public static function per_finish( string $kind ): int {
		return 'manga' === $kind ? self::PER_CHAPTER : self::PER_EPISODE;
	}

	/** An episode was watched to the end. */
	public const REASON_EPISODE = 'episode';

	/** A manga chapter was read to the end. */
	public const REASON_CHAPTER = 'chapter';

	/** An administrator sent points to somebody. */
	public const REASON_GRANT = 'grant';

	/** A frame was bought. */
	public const REASON_FRAME = 'frame';

	/** The whole set, for validating what arrives over the wire. */
	public const REASONS = array( self::REASON_EPISODE, self::REASON_CHAPTER, self::REASON_GRANT, self::REASON_FRAME );

	/**
	 * Most an administrator may send in one go.
	 *
	 * Not a security boundary — an administrator can simply send twice — but a
	 * typo guard. A stray zero on a gift is a lot harder to notice than a
	 * refused one.
	 */
	public const MAX_GRANT = 100000;

	/**
	 * The key that makes an award happen at most once.
	 *
	 * Stored in a unique index rather than checked before writing, so two
	 * progress reports arriving at the same instant — which is exactly what a
	 * watch party produces — pay for the episode once. Reading first and then
	 * writing would pay twice and look correct in every test.
	 *
	 * @param int $episode_id Episode.
	 * @return string
	 */
	public static function episode_key( int $episode_id ): string {
		return self::REASON_EPISODE . ':' . $episode_id;
	}

	/**
	 * The key for buying one frame.
	 *
	 * Also unique, and deliberately: a second purchase of a frame already
	 * owned is a mis-tap, not a donation.
	 *
	 * @param int $frame_id Frame.
	 * @return string
	 */
	public static function frame_key( int $frame_id ): string {
		return self::REASON_FRAME . ':' . $frame_id;
	}

	/**
	 * Whether a balance covers a price.
	 *
	 * @param int $balance Balance.
	 * @param int $price   Price.
	 * @return bool
	 */
	public static function affordable( int $balance, int $price ): bool {
		return $price >= 0 && $balance >= $price;
	}

	/**
	 * Clamp an administrator's gift to something sane.
	 *
	 * Zero is refused rather than clamped: a gift of nothing is a mistake
	 * somewhere upstream, and writing a ledger row for it only makes the
	 * history harder to read.
	 *
	 * @param int $amount Requested amount, possibly negative to take points back.
	 * @return int Clamped amount, or 0 when there is nothing to do.
	 */
	public static function clamp_grant( int $amount ): int {
		if ( 0 === $amount ) {
			return 0;
		}

		return max( -self::MAX_GRANT, min( self::MAX_GRANT, $amount ) );
	}

	/**
	 * A short human label, for the ledger a viewer reads.
	 *
	 * @param string $reason One of [self::REASONS].
	 * @return string
	 */
	public static function label( string $reason ): string {
		switch ( $reason ) {
			case self::REASON_EPISODE:
				return 'Bölüm izlendi';
			case self::REASON_GRANT:
				return 'Yönetim tarafından gönderildi';
			case self::REASON_FRAME:
				return 'Çerçeve satın alındı';
			default:
				return 'Puan hareketi';
		}
	}
}
