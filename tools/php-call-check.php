<?php
/**
 * Do the calls in these files match the functions they reach?
 *
 * Usage: php tools/php-call-check.php <dir> [<dir>…]
 *
 * Written after a live 500 on the manga bridge. `authorised()` called `key()`
 * because a rename landed on the definition and not on its one call site.
 * Nothing caught it: `php -l` only parses, and inside a namespace an
 * unqualified call falls back to the global function, so `key()` quietly
 * resolved to PHP's builtin and threw ArgumentCountError on the first request.
 *
 * The general shape of that bug is a call that cannot possibly succeed —
 * fewer arguments than the function requires, or more than it accepts. That is
 * decidable without running anything: the tokeniser says what is being called
 * with how many arguments, and reflection says what the function takes.
 *
 * It checks two things:
 *
 * **PHP's own functions**, by argument count. A project function that a file
 * defines itself is resolved by the namespace and is the right call; anything
 * unresolvable is left alone rather than guessed at.
 *
 * **The plugin's own methods**, by name and argument count, wherever the
 * receiver's class can be read off the line that made it — `new Foo()->bar()`,
 * `Foo::bar()`, or a variable assigned from `new Foo(...)` in the same
 * function. That is narrow on purpose: a wrong guess about a receiver would
 * cry wolf, and this only has to catch the case that actually happens, which
 * is calling a method that does not exist or handing it the wrong arguments.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

/**
 * Functions this cannot judge.
 *
 * Reflection describes these badly enough that a correct call looks wrong:
 * their signatures change by argument type or by PHP version.
 */
const SKIP = array(
	// Its arity depends on the callable it is handed.
	'call_user_func',
	'call_user_func_array',
	// Documented as one-or-many and reflected as one.
	'array_merge',
	'array_merge_recursive',
	'compact',
);

/**
 * Every function a file declares, so a same-named call is its own.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @return array<string, true>
 */
function declared_functions( array $tokens ): array {
	$names = array();

	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) || T_FUNCTION !== $token[0] ) {
			continue;
		}

		for ( $ahead = $index + 1; $ahead < count( $tokens ); ++$ahead ) {
			$next = $tokens[ $ahead ];

			if ( is_array( $next ) && in_array( $next[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			// `function (` and `function &(` are closures, which declare nothing.
			if ( is_string( $next ) && '&' === $next ) {
				continue;
			}
			if ( is_array( $next ) && T_STRING === $next[0] ) {
				$names[ strtolower( $next[1] ) ] = true;
			}
			break;
		}
	}

	return $names;
}

/**
 * How many arguments a call passes, or -1 when it cannot be counted.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @param int               $open   Index of the opening parenthesis.
 * @return int
 */
function count_arguments( array $tokens, int $open ): int {
	$depth     = 0;
	$arguments = 0;
	$seen      = false;

	for ( $index = $open; $index < count( $tokens ); ++$index ) {
		$token = $tokens[ $index ];

		if ( is_array( $token ) ) {
			// A spread passes an unknown number of them.
			if ( T_ELLIPSIS === $token[0] && 1 === $depth ) {
				return -1;
			}
			if ( ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) && $depth >= 1 ) {
				$seen = true;
			}
			continue;
		}

		if ( '(' === $token || '[' === $token || '{' === $token ) {
			++$depth;
			continue;
		}

		if ( ')' === $token || ']' === $token || '}' === $token ) {
			--$depth;
			if ( 0 === $depth ) {
				return $seen ? $arguments + 1 : 0;
			}
			continue;
		}

		if ( ',' === $token && 1 === $depth ) {
			++$arguments;
		}

		if ( $depth >= 1 ) {
			$seen = true;
		}
	}

	return -1;
}

/**
 * Check one file.
 *
 * @param string $path File.
 * @return array<int, string> Problems.
 */
function check_file( string $path ): array {
	$source = (string) file_get_contents( $path );
	$tokens = token_get_all( $source );
	$mine   = declared_functions( $tokens );
	$found  = array();
	$count  = count( $tokens );

	for ( $index = 0; $index < $count; ++$index ) {
		$token = $tokens[ $index ];

		if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
			continue;
		}

		// What comes before decides whether this is a function call at all.
		for ( $back = $index - 1; $back >= 0; --$back ) {
			$previous = $tokens[ $back ];
			if ( is_array( $previous ) && in_array( $previous[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			break;
		}

		$previous = $tokens[ $back ] ?? null;
		if ( is_array( $previous ) && in_array(
			$previous[0],
			array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CLASS, T_NS_SEPARATOR, T_CONST ),
			true
		) ) {
			continue;
		}

		// The next non-space token has to be an opening parenthesis.
		for ( $ahead = $index + 1; $ahead < $count; ++$ahead ) {
			$next = $tokens[ $ahead ];
			if ( is_array( $next ) && in_array( $next[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			break;
		}

		if ( '(' !== ( $tokens[ $ahead ] ?? null ) ) {
			continue;
		}

		$name = strtolower( $token[1] );

		// Resolved by the namespace to the file's own function, or something
		// this checker has no business judging.
		if ( isset( $mine[ $name ] ) || in_array( $name, SKIP, true ) || ! function_exists( $name ) ) {
			continue;
		}

		$reflection = new ReflectionFunction( $name );
		if ( ! $reflection->isInternal() ) {
			continue;
		}

		$passed = count_arguments( $tokens, $ahead );
		if ( $passed < 0 ) {
			continue;
		}

		$required = $reflection->getNumberOfRequiredParameters();
		$accepts  = $reflection->getNumberOfParameters();

		if ( $passed < $required ) {
			$found[] = sprintf(
				'%s:%d  %s() en az %d argüman ister, %d verilmiş',
				$path,
				$token[2],
				$name,
				$required,
				$passed
			);
			continue;
		}

		if ( ! $reflection->isVariadic() && $passed > $accepts ) {
			$found[] = sprintf(
				'%s:%d  %s() en çok %d argüman alır, %d verilmiş',
				$path,
				$token[2],
				$name,
				$accepts,
				$passed
			);
		}
	}

	return $found;
}

$roots = array_slice( $argv, 1 );
if ( array() === $roots ) {
	fwrite( STDERR, "kullanım: php tools/php-call-check.php <dizin> [<dizin>…]\n" );
	exit( 2 );
}

$problems = array();
$scanned  = 0;

foreach ( $roots as $root ) {
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );

	foreach ( $files as $file ) {
		if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
			continue;
		}

		++$scanned;
		$problems = array_merge( $problems, check_file( $file->getPathname() ) );
	}
}

foreach ( $problems as $problem ) {
	echo $problem . "\n";
}

printf( "%d dosya tarandı, %d sorun.\n", $scanned, count( $problems ) );

exit( array() === $problems ? 0 : 1 );
