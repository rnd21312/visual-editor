<?php
/**
 * Strict validation for everything the AI API writes into CSS. Selectors and values end up inside a
 * <style> element, so anything that could close a rule, open another one, or load code is refused.
 *
 * @package Suntourz\VisualEditor
 */

declare(strict_types=1);

namespace Suntourz\VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Guard {

	/**
	 * A CSS selector (1–500 characters) or null when it is not acceptable.
	 */
	public static function selector( mixed $value ): ?string {
		$sel = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $sel || strlen( $sel ) > 500 || str_contains( $sel, '/*' ) || str_contains( $sel, '--' ) ) {
			return null;
		}

		return 1 === preg_match( '/^[\w\s\-.#\[\]():>+~*,="\'|^$%\\\\]+$/u', $sel ) ? $sel : null;
	}

	/**
	 * A CSS property name.
	 */
	public static function property( mixed $value ): ?string {
		$prop = strtolower( is_string( $value ) ? trim( $value ) : '' );

		return 1 === preg_match( '/^(--[a-z0-9_-]{1,60}|[a-z-]{1,60})$/', $prop ) ? $prop : null;
	}

	/**
	 * A CSS value, or null when it could break out of its declaration or load active content.
	 */
	public static function value( mixed $value ): ?string {
		$text = trim( preg_replace( '/!important/i', '', is_scalar( $value ) ? (string) $value : '' ) ?? '' );
		if ( '' === $text || strlen( $text ) > 400 || 1 === preg_match( '/[;{}<>\\\\\r\n]|\/\*/', $text ) ) {
			return null;
		}
		if ( self::dangerous( $text ) ) {
			return null;
		}

		return $text;
	}

	/**
	 * Free CSS (rules, media queries, keyframes…) without anything that fetches or runs code.
	 */
	public static function css( mixed $value ): ?string {
		$css = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $css || strlen( $css ) > 20000 || str_contains( $css, '<' ) || 1 === preg_match( '/@import|@charset|@font-face\s*{[^}]*src/i', $css ) ) {
			return null;
		}

		return self::dangerous( $css ) ? null : $css;
	}

	private static function dangerous( string $text ): bool {
		return 1 === preg_match( '/expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding|data\s*:\s*text\/html|url\s*\(\s*[\'"]?\s*(?!https?:|\/|\.|#|data:image\/)/i', $text );
	}

	/**
	 * An attribute name the API may set (no event handlers, no style, no srcdoc).
	 */
	public static function attribute( string $name ): bool {
		$name = strtolower( $name );

		return 1 === preg_match( '/^(href|src|srcset|sizes|alt|title|target|rel|placeholder|aria-label|aria-hidden|role|id|class|name|value|type|loading|width|height|poster|download|fetchpriority|data-[a-z0-9_-]+)$/', $name );
	}
}
