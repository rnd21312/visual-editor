<?php
/**
 * Turns an edit (selector + per-device styles, states, visibility and free CSS) into the CSS the public site
 * prints. The editor has a TypeScript twin (frontend/src/shared/compile.ts) for its live preview; the server
 * compiles again on save so stored CSS never depends on what a client sent.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Compiler {

	/** @var array{tablet: int, mobile: int} */
	private static array $bp = array(
		'tablet' => 1023,
		'mobile' => 767,
	);

	/** Pseudo-classes appended to every selector of the list; pseudo-elements use "::". */
	private const STATES = array(
		'hover'         => ':hover',
		'focus'         => ':focus',
		'active'        => ':active',
		'focus-visible' => ':focus-visible',
		'focus-within'  => ':focus-within',
		'first-child'   => ':first-child',
		'last-child'    => ':last-child',
		'before'        => '::before',
		'after'         => '::after',
		'placeholder'   => '::placeholder',
	);

	/**
	 * @param array<string, int> $bp Breakpoints (max-width of tablet and mobile).
	 */
	public static function set_breakpoints( array $bp ): void {
		self::$bp = array(
			'tablet' => (int) ( $bp['tablet'] ?? 1023 ),
			'mobile' => (int) ( $bp['mobile'] ?? 767 ),
		);
	}

	/**
	 * @return array<string, string> device => media query ('' for base)
	 */
	public static function media(): array {
		return array(
			'base'   => '',
			'tablet' => '(max-width: ' . self::$bp['tablet'] . 'px)',
			'mobile' => '(max-width: ' . self::$bp['mobile'] . 'px)',
		);
	}

	/**
	 * @param array<string, mixed> $edit Edit with sel, styles, states, hide and css.
	 */
	public static function compile( array $edit ): string {
		$sel   = (string) $edit['sel'];
		$parts = self::split( $sel );
		$out   = array();

		foreach ( self::media() as $device => $media ) {
			$body = self::declarations( (array) ( $edit['styles'][ $device ] ?? array() ) );
			if ( '' !== $body ) {
				$out[] = self::wrap( $media, $sel . '{' . $body . '}' );
			}
		}

		foreach ( self::STATES as $state => $suffix ) {
			foreach ( self::media() as $device => $media ) {
				$styles = (array) ( $edit['states'][ $state ][ $device ] ?? array() );
				if ( in_array( $state, array( 'before', 'after' ), true ) && array() !== $styles && ! isset( $styles['content'] ) ) {
					$styles = array( 'content' => '""' ) + $styles;
				}
				$body = self::declarations( $styles );
				if ( '' !== $body ) {
					$out[] = self::wrap( $media, implode( ',', array_map( static fn ( string $s ): string => $s . $suffix, $parts ) ) . '{' . $body . '}' );
				}
			}
		}

		$hide = array(
			'desktop' => '(min-width: ' . ( self::$bp['tablet'] + 1 ) . 'px)',
			'tablet'  => '(min-width: ' . ( self::$bp['mobile'] + 1 ) . 'px) and (max-width: ' . self::$bp['tablet'] . 'px)',
			'mobile'  => '(max-width: ' . self::$bp['mobile'] . 'px)',
		);
		foreach ( $hide as $key => $media ) {
			if ( ! empty( $edit['hide'][ $key ] ) ) {
				$out[] = '@media ' . $media . '{' . $sel . '{display:none !important}}';
			}
		}

		$custom = trim( (string) ( $edit['css'] ?? '' ) );
		if ( '' !== $custom ) {
			$out[] = self::custom( $custom, $sel );
		}

		return implode( "\n", $out );
	}

	/**
	 * Free CSS of an edit: the word "selector" (or a leading "&") stands for the edit's selector. Plain
	 * declarations without braces are wrapped in a rule for the selector.
	 */
	public static function custom( string $css, string $sel ): string {
		if ( ! str_contains( $css, '{' ) ) {
			return $sel . '{' . $css . '}';
		}
		// A list ("a, .b") must stay one unit inside "selector > i": wrap it in :is().
		$self = count( self::split( $sel ) ) > 1 ? ':is(' . $sel . ')' : $sel;
		$css  = (string) preg_replace_callback( '/(?<![\w\-.#])selector(?![\w\-])/', static fn (): string => $self, $css );

		return (string) preg_replace( '/(^|[},]\s*)&/', '$1' . str_replace( '$', '\\$', $self ), $css );
	}

	/**
	 * Splits a selector list at top-level commas (not inside brackets, parentheses or strings).
	 *
	 * @return string[]
	 */
	public static function split( string $sel ): array {
		$parts = array();
		$depth = 0;
		$quote = '';
		$buf   = '';
		$len   = strlen( $sel );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $sel[ $i ];
			if ( '' !== $quote ) {
				$buf .= $c;
				if ( '\\' === $c && $i + 1 < $len ) {
					$buf .= $sel[ ++$i ];
				} elseif ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '(' === $c || '[' === $c ) {
				++$depth;
			} elseif ( ')' === $c || ']' === $c ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ',' === $c && 0 === $depth ) {
				$parts[] = trim( $buf );
				$buf     = '';
				continue;
			}
			$buf .= $c;
		}
		$parts[] = trim( $buf );

		return array_values( array_filter( $parts, static fn ( string $p ): bool => '' !== $p ) );
	}

	/**
	 * @param array<string, mixed> $styles Property → value.
	 */
	private static function declarations( array $styles ): string {
		$parts = array();
		foreach ( $styles as $prop => $value ) {
			$value = trim( (string) preg_replace( '/!important/i', '', (string) $value ) );
			if ( '' !== trim( (string) $prop ) && '' !== $value ) {
				$parts[] = $prop . ':' . $value . ' !important';
			}
		}

		return implode( ';', $parts );
	}

	private static function wrap( string $media, string $rule ): string {
		return '' === $media ? $rule : '@media ' . $media . '{' . $rule . '}';
	}
}
