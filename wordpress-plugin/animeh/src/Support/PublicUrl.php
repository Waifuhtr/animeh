<?php
/**
 * An address an operator typed that something else will be asked to fetch.
 *
 * Two places need the same judgement and for the same reason: the download and
 * source links on the application page, which become `href`s a stranger
 * clicks, and the advertising tag, which becomes a request every phone running
 * the app makes. Both are typed into a settings field by a person, and both
 * leave this server to be acted on somewhere else.
 *
 * The rule is the scheme. `javascript:` in a field that renders into a public
 * page is stored XSS; `file:` or `content:` in a field an Android client
 * fetches is asking that client to read its own disk. Only `http` and `https`
 * describe something another machine can be safely asked to fetch, so only
 * those two survive.
 *
 * This is deliberately *not* {@see UrlGuard}. That one defends the server
 * against being made to fetch an address — DNS resolution, private ranges,
 * redirect refusal — which is the right shape when this server is the one
 * making the request. Here it is not: the phone fetches the advertising tag
 * and the reviewer's browser follows the download link. Resolving those hosts
 * here would prove nothing about what happens there, and would reject a
 * perfectly good CDN that answers differently from where this code runs.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

namespace Animeh\Support;

/**
 * Scheme-checks an operator-supplied address.
 */
final class PublicUrl {

	/**
	 * The only two schemes another machine can be handed.
	 */
	private const ALLOWED = array( 'http', 'https' );

	/**
	 * The address, or the fallback when it is not one that can be handed on.
	 *
	 * Falling back rather than erroring is the point at the call sites: a page
	 * keeps a working download button, and a bad paste does not leave the
	 * player asking a phone to open something strange. The admin screen is
	 * where the operator is told, because that is where they can fix it.
	 *
	 * @param mixed  $raw      What was submitted.
	 * @param string $fallback Used when the address is empty or unusable.
	 * @return string
	 */
	public static function http( $raw, string $fallback = '' ): string {
		$url = esc_url_raw( trim( (string) $raw ) );

		if ( '' === $url ) {
			return $fallback;
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		return in_array( $scheme, self::ALLOWED, true ) ? $url : $fallback;
	}
}
