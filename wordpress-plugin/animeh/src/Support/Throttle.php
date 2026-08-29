<?php
/**
 * Bandwidth pacing for the media proxy.
 *
 * The player exists to behave well on weak connections, and that cannot be
 * judged on a fast link. This turns a requested kbps into a chunk size and a
 * per-chunk delay.
 *
 * Free of any WordPress dependency so the arithmetic can be unit tested.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Paces a byte stream to an approximate rate.
 */
final class Throttle {

	/**
	 * Smallest chunk worth writing.
	 *
	 * Below this the syscall overhead dominates and the pacing gets noisy.
	 */
	private const MIN_CHUNK = 4 * 1024;

	/**
	 * Largest chunk.
	 *
	 * Caps how long a single write blocks, so a slow client still sees steady
	 * progress rather than long silences.
	 */
	private const MAX_CHUNK = 256 * 1024;

	/**
	 * Target rate in bytes per second; zero means unlimited.
	 *
	 * @var int
	 */
	public readonly int $bytes_per_second;

	/**
	 * Chunk size to write at a time.
	 *
	 * @var int
	 */
	public readonly int $chunk_size;

	/**
	 * @param int $kbps Target rate in kilobits per second; 0 or less disables pacing.
	 */
	public function __construct( int $kbps ) {
		$bytes_per_second       = $kbps > 0 ? (int) round( ( $kbps * 1000 ) / 8 ) : 0;
		$this->bytes_per_second = max( 0, $bytes_per_second );

		if ( 0 === $this->bytes_per_second ) {
			$this->chunk_size = self::MAX_CHUNK;
			return;
		}

		// Aim for roughly ten writes a second: frequent enough that the client
		// sees a smooth rate, sparse enough that sleeping stays cheap.
		$target = (int) round( $this->bytes_per_second / 10 );
		$this->chunk_size = max( self::MIN_CHUNK, min( self::MAX_CHUNK, $target ) );
	}

	/**
	 * Whether pacing is active.
	 */
	public function enabled(): bool {
		return $this->bytes_per_second > 0;
	}

	/**
	 * Microseconds to sleep after writing `$bytes`.
	 *
	 * @param int $bytes Bytes just written.
	 */
	public function delay_for( int $bytes ): int {
		if ( ! $this->enabled() || $bytes <= 0 ) {
			return 0;
		}
		return (int) round( ( $bytes / $this->bytes_per_second ) * 1_000_000 );
	}

	/**
	 * Seconds a transfer of `$bytes` should take at this rate.
	 *
	 * @param int $bytes Transfer size.
	 */
	public function seconds_for( int $bytes ): float {
		if ( ! $this->enabled() ) {
			return 0.0;
		}
		return $bytes / $this->bytes_per_second;
	}
}
