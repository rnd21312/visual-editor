<?php
/**
 * Page keys: the identity of one URL of the site ("/", "/tours/پوکت/", "/?page_id=12").
 *
 * The key is always computed by the server — the editor reads it from the preview (<meta name="sve-context">)
 * and the AI API derives it from a URL with the same function — so the editor, the API and the public site can
 * never disagree. Paths are stored decoded (UTF-8), so non-Latin slugs work.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class PageKey {

	/** Query arguments that select different content even with pretty permalinks (language switchers). */
	private const ALWAYS = array( 'lang' );

	/** Query arguments that select content when the site uses plain permalinks. */
	private const PLAIN = array( 'p', 'page_id', 'post_type', 'name', 'pagename', 'cat', 'tag', 'taxonomy', 'term', 'product', 'product_cat', 'author', 'm', 'year', 'monthnum', 'day', 'paged', 'attachment_id' );

	/**
	 * Key of the current request.
	 */
	public static function current(): string {
		static $key = null;
		if ( null === $key ) {
			$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$key = self::build( (string) wp_parse_url( $uri, PHP_URL_PATH ), (string) wp_parse_url( $uri, PHP_URL_QUERY ) );
		}

		return $key;
	}

	/**
	 * Key of a URL of this site (absolute or root-relative), or null for other hosts and empty input.
	 */
	public static function for_url( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( is_string( $host ) && strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return null;
		}

		return self::build( (string) wp_parse_url( $url, PHP_URL_PATH ), (string) wp_parse_url( $url, PHP_URL_QUERY ) );
	}

	/**
	 * Cleans a stored key (also migrates old percent-encoded keys to decoded ones).
	 */
	public static function sanitize( string $value ): string {
		$value = trim( $value );
		$query = '';
		$pos   = strpos( $value, '?' );
		if ( false !== $pos ) {
			$query = substr( $value, $pos + 1 );
			$value = substr( $value, 0, $pos );
		}
		$path = self::clean_path( rawurldecode( $value ) );
		if ( '' === $query ) {
			return $path;
		}
		parse_str( $query, $args );

		return $path . self::query_part( is_array( $args ) ? $args : array(), true );
	}

	private static function build( string $path, string $query ): string {
		$home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$path = rawurldecode( $path );
		if ( '' !== $home ) {
			$decoded_home = rawurldecode( $home );
			if ( $path === $decoded_home || str_starts_with( $path, $decoded_home . '/' ) ) {
				$path = substr( $path, strlen( $decoded_home ) );
			}
		}
		$args = array();
		if ( '' !== $query ) {
			parse_str( $query, $args );
		}

		return self::clean_path( $path ) . self::query_part( is_array( $args ) ? $args : array(), false );
	}

	private static function clean_path( string $path ): string {
		$path = (string) preg_replace( '#[^\p{L}\p{M}\p{N}_\-./~%]#u', '', $path );
		$path = trim( (string) preg_replace( '#/+#', '/', $path ), '/' );

		return '' === $path ? '/' : '/' . mb_substr( $path, 0, 300 ) . '/';
	}

	/**
	 * @param array<string, mixed> $args Query arguments.
	 */
	private static function query_part( array $args, bool $stored ): string {
		$plain = $stored || '' === (string) get_option( 'permalink_structure' );
		$keep  = $plain ? array_merge( self::ALWAYS, self::PLAIN ) : self::ALWAYS;
		$out   = array();
		foreach ( $keep as $name ) {
			if ( isset( $args[ $name ] ) && is_scalar( $args[ $name ] ) && '' !== (string) $args[ $name ] ) {
				$out[ $name ] = mb_substr( (string) preg_replace( '/[^\p{L}\p{N}_\-.,]/u', '', (string) $args[ $name ] ), 0, 80 );
			}
		}
		if ( array() === $out ) {
			return '';
		}
		ksort( $out );

		return '?' . implode( '&', array_map( static fn ( string $k, string $v ): string => $k . '=' . $v, array_keys( $out ), $out ) );
	}
}
