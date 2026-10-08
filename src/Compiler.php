<?php
/**
 * PHP twin of the editor's compileEdit() (frontend/src/lib/visualCss.ts): turns an edit into the CSS the
 * public site prints. Edits created through the API need it because no editor is there to compile them.
 *
 * @package Suntourz\VisualEditor
 */

declare(strict_types=1);

namespace Suntourz\VisualEditor;

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
					$out[] = self::wrap( $media, $sel . ':' . $state . '{' . $body . '}' );
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
			$out[] = (string) preg_replace_callback( '/\bselector\b/', static fn (): string => $sel, $custom );
		}

		return implode( "\n", $out );
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
