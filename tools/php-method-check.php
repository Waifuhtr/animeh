<?php
/**
 * Do the method calls in this plugin reach methods that exist?
 *
 * Usage: php tools/php-method-check.php
 *
 * The third bug of this shape in one day. A missing `use` sent
 * `ChapterNumber::whole()` into the wrong namespace; a rename left
 * `authorised()` calling PHP's `key()`; and new code called
 * `$repo->delete_pages()`, which did not exist, and `$repo->save_source()`
 * and `$repo->episodes()` with the wrong arguments. All three parse. All
 * three fatal on the first request that reaches them.
 *
 * Reflection knows every one of these answers, so this loads the plugin
 * through the smoke harness's stubs and asks. It only judges a call whose
 * receiver it can read off the code with certainty:
 *
 *     Foo::bar( … )                     a static or class-qualified call
 *     ( new Foo( … ) )->bar( … )        a fresh object
 *     $x = new Foo( … );  … $x->bar( … ) a variable assigned in the same file
 *
 * Anything else — a property, a parameter, a return value — is left alone.
 * A checker that guesses at receivers cries wolf, and these three shapes are
 * where the mistakes actually happen.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

define( 'ANIMEH_METHOD_CHECK', true );

require __DIR__ . '/../wordpress-plugin/animeh/tests/smoke/wordpress.php';

$root = dirname( __DIR__ ) . '/wordpress-plugin/animeh/src';

/**
 * The `use` map and namespace of one file.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @return array{namespace: string, aliases: array<string, string>}
 */
function file_context( array $tokens ): array {
	$namespace = '';
	$aliases   = array();
	$count     = count( $tokens );

	for ( $index = 0; $index < $count; ++$index ) {
		$token = $tokens[ $index ];

		if ( ! is_array( $token ) ) {
			continue;
		}

		if ( T_NAMESPACE === $token[0] ) {
			$namespace = trim( read_name( $tokens, $index + 1 ) );
			continue;
		}

		if ( T_USE !== $token[0] ) {
			continue;
		}

		// A closure's `use ( $x )` is not an import.
		$name = trim( read_name( $tokens, $index + 1 ) );
		if ( '' === $name ) {
			continue;
		}

		$alias = substr( strrchr( '\\' . $name, '\\' ), 1 );

		// `use A\B as C;`
		for ( $ahead = $index + 1; $ahead < $count; ++$ahead ) {
			$next = $tokens[ $ahead ];
			if ( is_string( $next ) && ';' === $next ) {
				break;
			}
			if ( is_array( $next ) && T_AS === $next[0] ) {
				$alias = trim( read_name( $tokens, $ahead + 1 ) );
				break;
			}
		}

		$aliases[ $alias ] = $name;
	}

	return array(
		'namespace' => $namespace,
		'aliases'   => $aliases,
	);
}

/**
 * A qualified name starting at an index, e.g. `Animeh\Storage\B2Client`.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @param int               $index  Where to start.
 */
function read_name( array $tokens, int $index ): string {
	$name  = '';
	$count = count( $tokens );

	for ( ; $index < $count; ++$index ) {
		$token = $tokens[ $index ];

		if ( is_array( $token ) ) {
			if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				if ( '' !== $name ) {
					break;
				}
				continue;
			}
			if ( in_array( $token[0], array( T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
				$name .= $token[1];
				continue;
			}
		}

		break;
	}

	return trim( $name, '\\' );
}

/**
 * The class an alias names, if it is one this plugin defines.
 *
 * @param string                                                      $name    As written.
 * @param array{namespace: string, aliases: array<string, string>} $context File context.
 */
function resolve_class( string $name, array $context ): ?string {
	$name = ltrim( $name, '\\' );

	if ( '' === $name || in_array( strtolower( $name ), array( 'self', 'static', 'parent', 'this' ), true ) ) {
		return null;
	}

	$candidates = array();

	if ( isset( $context['aliases'][ $name ] ) ) {
		$candidates[] = $context['aliases'][ $name ];
	}
	if ( '' !== $context['namespace'] ) {
		$candidates[] = $context['namespace'] . '\\' . $name;
	}
	$candidates[] = $name;

	foreach ( $candidates as $candidate ) {
		if ( str_starts_with( $candidate, 'Animeh\\' ) && class_exists( $candidate ) ) {
			return $candidate;
		}
	}

	return null;
}

/**
 * Arguments passed by a call whose `(` is at $open, or -1 when unknowable.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @param int               $open   Index of the opening parenthesis.
 */
function argument_count( array $tokens, int $open ): int {
	$depth     = 0;
	$arguments = 0;
	$seen      = false;
	$count     = count( $tokens );

	for ( $index = $open; $index < $count; ++$index ) {
		$token = $tokens[ $index ];

		if ( is_array( $token ) ) {
			if ( T_ELLIPSIS === $token[0] && 1 === $depth ) {
				return -1;
			}
			if ( $depth >= 1 && ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
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
 * Index of the next non-space token.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @param int               $index  Start after this.
 */
function next_meaningful( array $tokens, int $index ): int {
	$count = count( $tokens );

	for ( ++$index; $index < $count; ++$index ) {
		$token = $tokens[ $index ];
		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $index;
	}

	return $count;
}

/**
 * The type of an argument, when the code says so outright.
 *
 * Only the shapes that cannot be anything else: a cast, a literal, an array.
 * Everything else — a variable, a call, an expression — returns null and is
 * not judged. Arity alone missed two of the three real mistakes this checker
 * exists for, because both passed the right *number* of arguments in the
 * wrong order, and the code said plainly what they were.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @param int               $start  First token of the argument.
 * @param int               $end    One past its last token.
 */
function literal_type( array $tokens, int $start, int $end ): ?string {
	$first = null;
	for ( $index = $start; $index < $end; ++$index ) {
		$token = $tokens[ $index ];
		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$first = $index;
		break;
	}

	if ( null === $first ) {
		return null;
	}

	$token = $tokens[ $first ];

	// A cast settles the type whatever follows it.
	if ( is_array( $token ) ) {
		$casts = array(
			T_INT_CAST    => 'int',
			T_STRING_CAST => 'string',
			T_DOUBLE_CAST => 'float',
			T_BOOL_CAST   => 'bool',
			T_ARRAY_CAST  => 'array',
		);
		if ( isset( $casts[ $token[0] ] ) ) {
			return $casts[ $token[0] ];
		}
		if ( T_ARRAY === $token[0] ) {
			return 'array';
		}
	}
	if ( '[' === $token ) {
		return 'array';
	}

	// A bare literal, and nothing else in the argument.
	$last = null;
	for ( $index = $end - 1; $index > $first; --$index ) {
		$item = $tokens[ $index ];
		if ( is_array( $item ) && in_array( $item[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$last = $index;
		break;
	}

	if ( null !== $last && $last !== $first ) {
		return null;
	}

	if ( ! is_array( $token ) ) {
		return null;
	}

	return match ( $token[0] ) {
		T_LNUMBER                  => 'int',
		T_DNUMBER                  => 'float',
		T_CONSTANT_ENCAPSED_STRING => 'string',
		T_STRING                   => in_array( strtolower( $token[1] ), array( 'true', 'false' ), true ) ? 'bool' : null,
		default                    => null,
	};
}

/**
 * The argument spans of a call, as [start, end) index pairs.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @param int               $open   Index of the opening parenthesis.
 * @return array<int, array{int, int}>
 */
function argument_spans( array $tokens, int $open ): array {
	$depth  = 0;
	$spans  = array();
	$start  = $open + 1;
	$count  = count( $tokens );

	for ( $index = $open; $index < $count; ++$index ) {
		$token = $tokens[ $index ];

		if ( is_string( $token ) ) {
			if ( '(' === $token || '[' === $token || '{' === $token ) {
				++$depth;
				continue;
			}
			if ( ')' === $token || ']' === $token || '}' === $token ) {
				--$depth;
				if ( 0 === $depth ) {
					if ( $index > $start ) {
						$spans[] = array( $start, $index );
					}
					return $spans;
				}
				continue;
			}
			if ( ',' === $token && 1 === $depth ) {
				$spans[] = array( $start, $index );
				$start   = $index + 1;
			}
		}
	}

	return $spans;
}

/**
 * Whether an argument of this type may be passed to a parameter of that one.
 *
 * `declare(strict_types=1)` is on in every file here, so the only widening
 * PHP performs is int to float.
 *
 * @param string $given    Argument type.
 * @param string $declared Parameter type.
 */
function type_fits( string $given, string $declared ): bool {
	$declared = strtolower( ltrim( $declared, '?' ) );

	if ( in_array( $declared, array( 'mixed', 'iterable', 'callable', 'object', 'self', 'static' ), true ) ) {
		return true;
	}
	if ( $given === $declared ) {
		return true;
	}

	return 'int' === $given && 'float' === $declared;
}

/**
 * Check one file.
 *
 * @param string $path File.
 * @return array<int, string>
 */
function check_methods( string $path ): array {
	$tokens  = token_get_all( (string) file_get_contents( $path ) );
	$context = file_context( $tokens );
	$count   = count( $tokens );
	$found   = array();

	// `$x = new Foo(` — remembered so `$x->bar()` can be judged later.
	$variables = array();

	for ( $index = 0; $index < $count; ++$index ) {
		$token = $tokens[ $index ];

		if ( is_array( $token ) && T_VARIABLE === $token[0] ) {
			$equals = next_meaningful( $tokens, $index );
			if ( '=' === ( $tokens[ $equals ] ?? null ) ) {
				$new = next_meaningful( $tokens, $equals );
				if ( is_array( $tokens[ $new ] ?? null ) && T_NEW === $tokens[ $new ][0] ) {
					$class = resolve_class( read_name( $tokens, $new + 1 ), $context );
					if ( null !== $class ) {
						$variables[ $token[1] ] = $class;
					} else {
						unset( $variables[ $token[1] ] );
					}
				} else {
					// Reassigned to something unknowable: forget it rather
					// than judge later calls against a stale class.
					unset( $variables[ $token[1] ] );
				}
			}
		}

		if ( ! is_array( $token ) || ! in_array( $token[0], array( T_DOUBLE_COLON, T_OBJECT_OPERATOR ), true ) ) {
			continue;
		}

		$name_at = next_meaningful( $tokens, $index );
		$method  = $tokens[ $name_at ] ?? null;

		if ( ! is_array( $method ) || T_STRING !== $method[0] ) {
			continue;
		}

		$open = next_meaningful( $tokens, $name_at );
		if ( '(' !== ( $tokens[ $open ] ?? null ) ) {
			continue;
		}

		$class = null;

		if ( T_DOUBLE_COLON === $token[0] ) {
			// Walk back over the class name.
			$start = $index - 1;
			while ( $start >= 0 && is_array( $tokens[ $start ] ) && in_array( $tokens[ $start ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				--$start;
			}
			$begin = $start;
			while ( $begin >= 0 && is_array( $tokens[ $begin ] ) && in_array( $tokens[ $begin ][0], array( T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
				--$begin;
			}
			$class = resolve_class( read_name( $tokens, $begin + 1 ), $context );
		} else {
			$before = $index - 1;
			while ( $before >= 0 && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				--$before;
			}

			$receiver = $tokens[ $before ] ?? null;

			if ( is_array( $receiver ) && T_VARIABLE === $receiver[0] ) {
				$class = $variables[ $receiver[1] ] ?? null;
			} elseif ( ')' === $receiver ) {
				// `( new Foo( … ) )->bar()`: find the matching `(` and see.
				$depth = 0;
				for ( $back = $before; $back >= 0; --$back 	) {
					$item = $tokens[ $back ];
					if ( ')' === $item ) {
						++$depth;
					} elseif ( '(' === $item ) {
						--$depth;
						if ( 0 === $depth ) {
							$inner = next_meaningful( $tokens, $back );
							if ( is_array( $tokens[ $inner ] ?? null ) && T_NEW === $tokens[ $inner ][0] ) {
								$class = resolve_class( read_name( $tokens, $inner + 1 ), $context );
							}
							break;
						}
					}
				}
			}
		}

		if ( null === $class ) {
			continue;
		}

		$reflection = new ReflectionClass( $class );

		if ( ! $reflection->hasMethod( $method[1] ) ) {
			$found[] = sprintf(
				'%s:%d  %s::%s() diye bir metot yok',
				$path,
				$method[2],
				$reflection->getShortName(),
				$method[1]
			);
			continue;
		}

		$signature = $reflection->getMethod( $method[1] );
		$passed    = argument_count( $tokens, $open );

		if ( $passed < 0 ) {
			continue;
		}

		$required = $signature->getNumberOfRequiredParameters();
		$accepts  = $signature->getNumberOfParameters();

		if ( $passed < $required ) {
			$found[] = sprintf(
				'%s:%d  %s::%s() en az %d argüman ister, %d verilmiş',
				$path,
				$method[2],
				$reflection->getShortName(),
				$method[1],
				$required,
				$passed
			);
		} elseif ( ! $signature->isVariadic() && $passed > $accepts ) {
			$found[] = sprintf(
				'%s:%d  %s::%s() en çok %d argüman alır, %d verilmiş',
				$path,
				$method[2],
				$reflection->getShortName(),
				$method[1],
				$accepts,
				$passed
			);
		} else {
			$parameters = $signature->getParameters();

			foreach ( argument_spans( $tokens, $open ) as $position => $span ) {
				$parameter = $parameters[ $position ] ?? null;
				if ( null === $parameter ) {
					continue;
				}

				$declared = $parameter->getType();
				if ( ! $declared instanceof ReflectionNamedType || $declared->allowsNull() ) {
					continue;
				}

				$given = literal_type( $tokens, $span[0], $span[1] );
				if ( null === $given || type_fits( $given, $declared->getName() ) ) {
					continue;
				}

				$found[] = sprintf(
					'%s:%d  %s::%s() %d. argümanı %s ister, %s verilmiş',
					$path,
					$method[2],
					$reflection->getShortName(),
					$method[1],
					$position + 1,
					$declared->getName(),
					$given
				);
			}
		}
	}

	return $found;
}

$problems = array();
$scanned  = 0;

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	++$scanned;
	$problems = array_merge( $problems, check_methods( $file->getPathname() ) );
}

foreach ( $problems as $problem ) {
	echo str_replace( dirname( __DIR__ ) . '/', '', $problem ) . "\n";
}

printf( "%d dosya tarandı, %d sorun.\n", $scanned, count( $problems ) );

exit( array() === $problems ? 0 : 1 );
