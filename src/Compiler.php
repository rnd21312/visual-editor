<?php
/**
 * PHP twin of the editor's compileEdit() (frontend/src/lib/visualCss.ts): turns an edit into the CSS the
 * public site prints. Edits created through the API need it because no editor is there to compile them.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Compiler {

	private const MEDIA = array(
		'base'   => '',
		'tablet' => '(max-width: 1023px)',
		'mobile' => '(max-width: 767px)',
	);

	private const HIDE = array(
		'desktop' => '(min-width: 1024px)',
		'tablet'  => '(min-width: 768px) and (max-width: 1023px)',
		'mobile'  => '(max-width: 767px)',
	);

	/**
	 * @param array<string, mixed> $edit Edit with sel, styles, states, hide and css.
	 */
	public static function compile( array $edit ): string {
		$sel = (string) $edit['sel'];
		$out = array();

		foreach ( self::MEDIA as $device => $media ) {
			$body = self::declarations( (array) ( $edit['styles'][ $device ] ?? array() ) );
			if ( '' !== $body ) {
				$out[] = self::wrap( $media, $sel . '{' . $body . '}' );
			}
		}

		foreach ( array( 'hover', 'focus', 'active' ) as $state ) {
			foreach ( self::MEDIA as $device => $media ) {
				$body = self::declarations( (array) ( $edit['states'][ $state ][ $device ] ?? array() ) );
				if ( '' !== $body ) {
					$out[] = self::wrap( $media, self::suffix( $sel, ':' . $state ) . '{' . $body . '}' );
				}
			}
		}

		foreach ( self::HIDE as $key => $media ) {
			if ( ! empty( $edit['hide'][ $key ] ) ) {
				$out[] = '@media ' . $media . '{' . $sel . '{display:none !important}}';
			}
		}

		$custom = trim( (string) ( $edit['css'] ?? '' ) );
		if ( '' !== $custom ) {
			// The word "selector" only when it stands alone: not .selector-list, [data-selector], --selector-gap or "selector".
			$self  = count( self::split( $sel ) ) > 1 ? ':is(' . $sel . ')' : $sel;
			$out[] = (string) preg_replace_callback( '/(?<![\w\-.#\[:"\'])selector(?![\w\-\]"\'])/', static fn (): string => $self, $custom );
		}

		return implode( "\n", $out );
	}

	/**
	 * The selectors of a list, split on top-level commas (not those inside (), [] or quotes).
	 *
	 * @return array<int, string>
	 */
	public static function split( string $sel ): array {
		$parts = array();
		$depth = 0;
		$quote = '';
		$start = 0;
		$len   = strlen( $sel );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $sel[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $c ) {
					++$i;
				} elseif ( $c === $quote ) {
					$quote = '';
				}
			} elseif ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '(' === $c || '[' === $c ) {
				++$depth;
			} elseif ( ')' === $c || ']' === $c ) {
				--$depth;
			} elseif ( ',' === $c && 0 === $depth ) {
				$parts[] = trim( substr( $sel, $start, $i - $start ) );
				$start   = $i + 1;
			}
		}
		$parts[] = trim( substr( $sel, $start ) );

		return array_values( array_filter( $parts, static fn ( string $part ): bool => '' !== $part ) );
	}

	/** Appends a pseudo-class to every selector of a list: "a, b" + ":hover" → "a:hover,b:hover". */
	private static function suffix( string $sel, string $suffix ): string {
		return implode( ',', array_map( static fn ( string $part ): string => $part . $suffix, self::split( $sel ) ) );
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
