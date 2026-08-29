<?php
/**
 * Outbound URL vetting for the media proxy.
 *
 * The proxy fetches a URL an administrator typed and streams it back. That is a
 * textbook server-side request forgery surface: without checks it would let
 * anyone who reaches the admin screen read the cloud metadata endpoint, probe
 * the internal network, or pull files off the host.
 *
 * Free of any WordPress dependency so the rules can be unit tested directly.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Decides whether a URL may be fetched.
 */
final class UrlGuard {

	/**
	 * IPv4 ranges that must never be reachable, as [network, prefix length].
	 *
	 * Loopback, private, link-local (which carries cloud metadata at
	 * 169.254.169.254), carrier-grade NAT, and the reserved blocks that are
	 * commonly reachable inside a datacentre.
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	private const BLOCKED_V4 = array(
		array( '0.0.0.0', 8 ),
		array( '10.0.0.0', 8 ),
		array( '100.64.0.0', 10 ),
		array( '127.0.0.0', 8 ),
		array( '169.254.0.0', 16 ),
		array( '172.16.0.0', 12 ),
		array( '192.0.0.0', 24 ),
		array( '192.168.0.0', 16 ),
		array( '198.18.0.0', 15 ),
		array( '224.0.0.0', 4 ),
		array( '240.0.0.0', 4 ),
	);

	/**
	 * IPv6 ranges that must never be reachable.
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	private const BLOCKED_V6 = array(
		array( '::', 128 ),      // Unspecified.
		array( '::1', 128 ),     // Loopback.
		array( 'fc00::', 7 ),    // Unique local.
		array( 'fe80::', 10 ),   // Link local.
		array( 'ff00::', 8 ),    // Multicast.
		array( '::ffff:0:0', 96 ), // IPv4-mapped, checked again as IPv4.
	);

	/**
	 * Reason a URL was rejected, or null when it is allowed.
	 *
	 * @var string|null
	 */
	public readonly ?string $reason;

	/**
	 * The addresses the host resolved to, for logging.
	 *
	 * @var string[]
	 */
	public readonly array $addresses;

	/**
	 * @param string|null $reason    Rejection reason, or null when allowed.
	 * @param string[]    $addresses Resolved addresses.
	 */
	private function __construct( ?string $reason, array $addresses = array() ) {
		$this->reason    = $reason;
		$this->addresses = $addresses;
	}

	/**
	 * Whether the URL passed every check.
	 */
	public function allowed(): bool {
		return null === $this->reason;
	}

	/**
	 * Vet a URL.
	 *
	 * @param string        $url            URL to check.
	 * @param string[]      $host_allowlist Hosts permitted; empty means any host
	 *                                      that passes the address checks.
	 * @param callable|null $resolver       Resolves a host to addresses. Injected
	 *                                      so the rules can be tested without DNS.
	 * @return self
	 */
	public static function check( string $url, array $host_allowlist = array(), ?callable $resolver = null ): self {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) ) {
			return new self( 'malformed_url' );
		}

		// Scheme is judged before anything else. A `file://` URL has no host,
		// and reporting that as merely malformed would hide from the log what
		// was actually attempted.
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return new self( '' === $scheme ? 'malformed_url' : 'unsupported_scheme' );
		}

		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( '' === $host ) {
			return new self( 'malformed_url' );
		}

		// Credentials in the URL are never needed here and are a common way to
		// smuggle a different host past naive parsing.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new self( 'credentials_in_url' );
		}

		if ( array() !== $host_allowlist && ! self::host_allowed( $host, $host_allowlist ) ) {
			return new self( 'host_not_allowed' );
		}

		$addresses = self::resolve( $host, $resolver );
		if ( array() === $addresses ) {
			return new self( 'unresolvable_host' );
		}

		// Every address the host resolves to must be acceptable, not just the
		// first: a name that returns one public and one private address would
		// otherwise pass here and connect to the private one.
		foreach ( $addresses as $address ) {
			if ( self::is_blocked_address( $address ) ) {
				return new self( 'private_address', $addresses );
			}
		}

		return new self( null, $addresses );
	}

	/**
	 * Whether a host matches an allowlist entry.
	 *
	 * A leading dot means "this domain and its subdomains".
	 *
	 * @param string   $host      Host to test.
	 * @param string[] $allowlist Allowed hosts.
	 */
	public static function host_allowed( string $host, array $allowlist ): bool {
		foreach ( $allowlist as $entry ) {
			$candidate = strtolower( trim( $entry ) );
			if ( '' === $candidate ) {
				continue;
			}
			if ( str_starts_with( $candidate, '.' ) ) {
				if ( $host === substr( $candidate, 1 ) || str_ends_with( $host, $candidate ) ) {
					return true;
				}
				continue;
			}
			if ( $host === $candidate ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an IP address falls inside a blocked range.
	 *
	 * @param string $address IPv4 or IPv6 address.
	 */
	public static function is_blocked_address( string $address ): bool {
		$packed = @inet_pton( $address ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed ) {
			return true;
		}

		if ( 4 === strlen( $packed ) ) {
			return self::in_any_range( $packed, self::BLOCKED_V4 );
		}

		// An IPv4-mapped IPv6 address must be judged by its IPv4 half, or
		// ::ffff:127.0.0.1 would sail past the v4 rules.
		if ( 16 === strlen( $packed ) && str_starts_with( $packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" ) ) {
			return self::in_any_range( substr( $packed, 12 ), self::BLOCKED_V4 );
		}

		return self::in_any_range( $packed, self::BLOCKED_V6 );
	}

	/**
	 * Test a packed address against a list of ranges.
	 *
	 * @param string                              $packed Packed address.
	 * @param array<int, array{0: string, 1: int}> $ranges Ranges to test.
	 */
	private static function in_any_range( string $packed, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			$network = @inet_pton( $range[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $network || strlen( $network ) !== strlen( $packed ) ) {
				continue;
			}
			if ( self::in_range( $packed, $network, $range[1] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a packed address sits inside a packed network.
	 *
	 * @param string $packed  Packed address.
	 * @param string $network Packed network address.
	 * @param int    $bits    Prefix length.
	 */
	private static function in_range( string $packed, string $network, int $bits ): bool {
		$whole_bytes = intdiv( $bits, 8 );
		$spare_bits  = $bits % 8;

		if ( $whole_bytes > 0 && substr( $packed, 0, $whole_bytes ) !== substr( $network, 0, $whole_bytes ) ) {
			return false;
		}
		if ( 0 === $spare_bits ) {
			return true;
		}

		$mask = ( 0xff << ( 8 - $spare_bits ) ) & 0xff;
		return ( ord( $packed[ $whole_bytes ] ) & $mask ) === ( ord( $network[ $whole_bytes ] ) & $mask );
	}

	/**
	 * Resolve a host to its addresses.
	 *
	 * @param string        $host     Hostname or literal address.
	 * @param callable|null $resolver Optional injected resolver.
	 * @return string[]
	 */
	private static function resolve( string $host, ?callable $resolver ): array {
		// A literal address needs no lookup, and must still be checked.
		$literal = trim( $host, '[]' );
		if ( false !== filter_var( $literal, FILTER_VALIDATE_IP ) ) {
			return array( $literal );
		}

		if ( null !== $resolver ) {
			$resolved = $resolver( $host );
			return is_array( $resolved ) ? array_values( array_filter( $resolved, 'is_string' ) ) : array();
		}

		$addresses = array();
		$records   = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( isset( $record['ip'] ) ) {
					$addresses[] = (string) $record['ip'];
				} elseif ( isset( $record['ipv6'] ) ) {
					$addresses[] = (string) $record['ipv6'];
				}
			}
		}

		if ( array() === $addresses ) {
			$resolved = gethostbynamel( $host );
			if ( is_array( $resolved ) ) {
				$addresses = $resolved;
			}
		}

		return $addresses;
	}
}
