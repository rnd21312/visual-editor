<?php
/**
 * Checks for what the AI API writes into CSS, with readable refusals. Selectors and values end up inside a
 * <style> element, so anything that could close a rule, open another one, or load code is refused.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Guard {

	/**
	 * A CSS selector (1–1500 characters) or null when it is not acceptable. BEM (`--`), Tailwind (`\:` `/`) and
	 * attribute selectors are fine; braces, semicolons, comments and markup are not.
	 */
	public static function selector( mixed $value ): ?string {
		$sel = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $sel || strlen( $sel ) > 1500 || str_contains( $sel, '/*' ) || 1 === preg_match( '/[{};<]/', $sel ) ) {
			return null;
		}

		return $sel;
	}

	/**
	 * A CSS property name (custom properties allowed).
	 */
	public static function property( mixed $value ): ?string {
		$prop = strtolower( is_string( $value ) ? trim( $value ) : '' );

		return 1 === preg_match( '/^(--[a-z0-9_-]{1,80}|-?[a-z][a-z-]{0,60})$/', $prop ) ? $prop : null;
	}

	/**
	 * A CSS value, or null when it could break out of its declaration or load active content.
	 */
	public static function value( mixed $value ): ?string {
		$text = trim( preg_replace( '/!important/i', '', is_scalar( $value ) ? (string) $value : '' ) ?? '' );
		if ( '' === $text || strlen( $text ) > 2000 || 1 === preg_match( '/[;{}<>\r\n]|\/\*/', $text ) ) {
			return null;
		}

		return self::dangerous( $text ) ? null : $text;
	}

	/**
	 * Free CSS (rules, media queries, keyframes…) without anything that executes code. Fonts and images may load
	 * from https URLs.
	 */
	public static function css( mixed $value ): ?string {
		$css = is_string( $value ) ? trim( $value ) : '';
		// Browsers read "@9mport" as "@import": check what the CSS means, not how it is spelled.
		if ( '' === $css || strlen( $css ) > 100000 || str_contains( $css, '<' ) || 1 === preg_match( '/@import|@charset/i', self::unescape( $css ) ) ) {
			return null;
		}

		return self::dangerous( $css ) ? null : $css;
	}

	/** Resolves CSS escapes (hex such as `\69 ` and single characters such as `\i`) so keyword checks cannot be dodged. */
	private static function unescape( string $css ): string {
		$css = (string) preg_replace_callback(
			'/\\\\([0-9a-f]{1,6})[ \t\n\r\f]?/i',
			static fn ( array $m ): string => ( $code = (int) hexdec( $m[1] ) ) > 0 && $code < 0x110000 ? html_entity_decode( '&#' . $code . ';', ENT_QUOTES, 'UTF-8' ) : '',
			$css
		);

		return (string) preg_replace( '/\\\\(.)/su', '$1', $css );
	}

	private static function dangerous( string $text ): bool {
		$text = self::unescape( $text );

		return 1 === preg_match( '/expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding|data\s*:\s*text\/html|url\s*\(\s*[\'"]?\s*(?!https?:|\/|\.|#|data:image\/|data:font\/|data:application\/font)/i', $text );
	}

	/**
	 * An attribute name the API may set (no event handlers, no style, no srcdoc).
	 */
	public static function attribute( string $name ): bool {
		$name = strtolower( $name );

		return 1 !== preg_match( '/^on|^style$|^srcdoc$|^formaction$/', $name ) && 1 === preg_match( '/^[a-z][a-z0-9_.:-]*$/', $name );
	}

	/**
	 * Elements whose text or attributes must never be changed by untrusted authors: changing them could run code
	 * (deferred scripts of cookie banners, base URLs, meta refresh…).
	 */
	public static function protected_target( string $selector ): bool {
		return 1 === preg_match( '/(^|[\s>+~,(])(script|style|base|meta|link|noscript|template|iframe|object|embed|head|html)(?![\w-])/i', $selector );
	}
}
