<?php
/**
 * Seeing the whole front end, not only the element tree: the raw HTML a visitor gets, every stylesheet and script
 * the page loads (with the CSS text itself), a portable export of a page (markup + CSS + images) that can be
 * rebuilt on another site, and read-only access to theme / plugin / mu-plugin files for admin keys.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor\Mcp;

use RuntimeException;
use VisualEditor\Inspector;

defined( 'ABSPATH' ) || exit;

final class Explore {

	private const MAX_SLICE = 120000;

	private const MAX_CSS = 400000;

	/** Folders under wp-content that admin keys may read. */
	private const ROOTS = array( 'themes', 'plugins', 'mu-plugins', 'uploads' );

	private const TEXT = array( 'php', 'css', 'scss', 'js', 'mjs', 'json', 'html', 'htm', 'txt', 'md', 'svg', 'xml', 'twig', 'tpl', 'po', 'pot', 'map', 'ini', 'yml', 'yaml' );

	/* ------------------------------------------------------------------ page source */

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function source( array $a ): array {
		$page = self::page( $a );
		$html = $page['html'];
		$part = (string) ( $a['part'] ?? 'full' );
		if ( 'head' === $part || 'body' === $part ) {
			$html = 1 === preg_match( '#<' . $part . '\b[^>]*>(.*?)</' . $part . '>#is', $html, $m ) ? $m[1] : '';
		}

		return array( 'url' => $page['url'], 'part' => $part ) + self::slice( $html, $a );
	}

	/**
	 * Everything the page loads: stylesheets (with their CSS when asked), inline styles, scripts, fonts, images,
	 * body classes and which file each asset comes from (theme, plugin, uploads…).
	 *
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function assets( array $a ): array {
		$page   = self::page( $a );
		$parsed = self::parse( $page['html'], $page['url'] );
		if ( ! empty( $a['include_css'] ) ) {
			$parsed['css'] = self::css_text( $parsed, (string) ( $a['match'] ?? '' ) );
		}

		return array( 'url' => $page['url'] ) + $parsed;
	}

	/**
	 * A page as one portable bundle: body markup (scripts and admin bar removed), all its CSS as text with url()
	 * made absolute, the scripts it needs and every image — enough to rebuild it on another site.
	 *
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function export( array $a ): array {
		$page   = self::page( $a );
		$parsed = self::parse( $page['html'], $page['url'] );
		$root   = trim( (string) ( $a['start_at'] ?? '' ) );

		$body = 1 === preg_match( '#<body\b[^>]*>(.*)</body>#is', $page['html'], $m ) ? $m[1] : $page['html'];
		$body = (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $body );
		$body = (string) preg_replace( '#<div id="wpadminbar".*?</div>\s*</div>#is', '', $body );
		$body = (string) preg_replace( '#<!--(?!\[).*?-->#s', '', $body );
		$body = (string) preg_replace( '/\n\s*\n+/', "\n", $body );
		if ( '' !== $root ) {
			$pos = strpos( $body, $root );
			if ( false !== $pos ) {
				$body = substr( $body, $pos );
			}
		}

		$css   = self::css_text( $parsed, '' );
		$all   = implode( "\n\n", array_map( static fn ( array $c ): string => '/* ' . $c['source'] . " */\n" . $c['css'], $css['files'] ) );
		$which = (string) ( $a['part'] ?? 'html' );
		$text  = 'css' === $which ? $all : trim( $body );

		return array(
			'url'         => $page['url'],
			'title'       => $parsed['title'],
			'bodyClass'   => $parsed['bodyClass'],
			'part'        => $which,
			'scripts'     => array_values( array_filter( array_column( $parsed['scripts'], 'src' ) ) ),
			'images'      => $parsed['images'],
			'fonts'       => $parsed['fonts'],
			'cssSize'     => strlen( $all ),
			'htmlSize'    => strlen( trim( $body ) ),
			'rebuild'     => 'On the other site: upload_media for the images, create_content with builder.source = the html (part "html"), then add_css with scope "page" and the css (part "css"; read it in slices with offset), set_library / add_script for the scripts it needs.',
		) + self::slice( $text, $a );
	}

	/* ------------------------------------------------------------------ files */

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function list_files( array $a ): array {
		$dir = self::path( (string) ( $a['path'] ?? '' ) );
		if ( ! is_dir( $dir ) ) {
			throw new RuntimeException( 'Not a folder. Start with path "" (lists ' . implode( ', ', self::ROOTS ) . ') or e.g. "themes/' . get_stylesheet() . '".' );
		}
		if ( WP_CONTENT_DIR === $dir ) {
			$entries = array();
			foreach ( self::ROOTS as $root ) {
				if ( is_dir( WP_CONTENT_DIR . '/' . $root ) ) {
					$entries[] = array( 'name' => $root . '/', 'type' => 'dir' );
				}
			}

			return array( 'path' => '', 'entries' => $entries, 'activeTheme' => 'themes/' . get_stylesheet(), 'parentTheme' => 'themes/' . get_template() );
		}

		$depth   = max( 0, min( 3, (int) ( $a['depth'] ?? 0 ) ) );
		$entries = array();
		self::walk( $dir, self::relative( $dir ), $depth, $entries );

		return array( 'path' => self::relative( $dir ), 'entries' => array_slice( $entries, 0, 800 ), 'truncated' => count( $entries ) > 800 );
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function read_file( array $a ): array {
		$file = self::path( (string) ( $a['path'] ?? '' ) );
		if ( ! is_file( $file ) ) {
			throw new RuntimeException( 'No such file. Use list_files to find it.' );
		}
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::TEXT, true ) ) {
			throw new RuntimeException( 'Only text files (' . implode( ', ', self::TEXT ) . ') can be read.' );
		}
		if ( filesize( $file ) > 4 * MB_IN_BYTES ) {
			throw new RuntimeException( 'The file is larger than 4 MB.' );
		}
		$text = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return array( 'path' => self::relative( $file ), 'modified' => gmdate( 'c', (int) filemtime( $file ) ) ) + self::slice( $text, $a );
	}

	/* ------------------------------------------------------------------ helpers */

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array{html: string, url: string}
	 */
	private static function page( array $a ): array {
		$url = trim( (string) ( $a['url'] ?? '' ) );
		if ( '' === $url && ! empty( $a['post_id'] ) ) {
			$url = (string) get_permalink( (int) $a['post_id'] );
		}
		if ( '' === $url ) {
			$url = '/';
		}
		$url  = str_starts_with( $url, 'http' ) ? $url : home_url( '/' . ltrim( $url, '/' ) );
		$page = Inspector::fetch( $url, ! empty( $a['as_user'] ) );
		if ( is_wp_error( $page ) ) {
			throw new RuntimeException( $page->get_error_message() );
		}
		$page['html'] = (string) preg_replace( '/([?&])' . Inspector::PROBE_ARG . '=[a-f0-9]+&?/', '$1', $page['html'] );

		return $page;
	}

	/**
	 * A window of a long text: offset + length, and where the next window starts.
	 *
	 * @param array<string, mixed> $a Arguments (offset, length).
	 * @return array<string, mixed>
	 */
	private static function slice( string $text, array $a ): array {
		$total  = strlen( $text );
		$offset = max( 0, (int) ( $a['offset'] ?? 0 ) );
		$length = max( 1000, min( self::MAX_SLICE, (int) ( $a['length'] ?? 60000 ) ) );
		$chunk  = (string) substr( $text, $offset, $length );
		// Do not cut a UTF-8 character in half (Persian text is multi-byte).
		$chunk = (string) mb_convert_encoding( $chunk, 'UTF-8', 'UTF-8' );
		$out   = array( 'total' => $total, 'offset' => $offset, 'text' => $chunk );
		if ( $offset + $length < $total ) {
			$out['next'] = $offset + $length;
		}

		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function parse( string $html, string $base ): array {
		$out = array( 'title' => '', 'bodyClass' => '', 'stylesheets' => array(), 'inlineStyles' => array(), 'scripts' => array(), 'fonts' => array(), 'images' => array() );
		if ( 1 === preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			$out['title'] = html_entity_decode( trim( wp_strip_all_tags( $m[1] ) ) );
		}
		if ( 1 === preg_match( '#<body\b[^>]*\bclass=["\']([^"\']*)#i', $html, $m ) ) {
			$out['bodyClass'] = trim( $m[1] );
		}

		preg_match_all( '#<link\b[^>]*>#i', $html, $links );
		foreach ( $links[0] as $tag ) {
			$rel  = strtolower( self::attr( $tag, 'rel' ) );
			$href = self::absolute( html_entity_decode( self::attr( $tag, 'href' ) ), $base );
			if ( str_contains( $rel, 'stylesheet' ) && '' !== $href ) {
				$out['stylesheets'][] = array( 'id' => self::attr( $tag, 'id' ), 'href' => $href, 'media' => self::attr( $tag, 'media' ), 'from' => self::origin( $href ) );
			} elseif ( str_contains( $rel, 'preload' ) && 'font' === self::attr( $tag, 'as' ) ) {
				$out['fonts'][] = $href;
			}
		}

		preg_match_all( '#<style\b([^>]*)>(.*?)</style>#is', $html, $styles, PREG_SET_ORDER );
		foreach ( $styles as $i => $style ) {
			$out['inlineStyles'][] = array( 'id' => '' !== self::attr( $style[1], 'id' ) ? self::attr( $style[1], 'id' ) : 'inline-' . ( $i + 1 ), 'size' => strlen( $style[2] ), 'css' => $style[2] );
		}

		preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $html, $scripts, PREG_SET_ORDER );
		foreach ( $scripts as $script ) {
			$src = self::absolute( html_entity_decode( self::attr( $script[1], 'src' ) ), $base );
			$out['scripts'][] = '' !== $src
				? array( 'id' => self::attr( $script[1], 'id' ), 'src' => $src, 'from' => self::origin( $src ) )
				: array( 'id' => self::attr( $script[1], 'id' ), 'type' => self::attr( $script[1], 'type' ), 'inline' => strlen( $script[2] ), 'start' => mb_substr( trim( $script[2] ), 0, 160 ) );
		}

		preg_match_all( '#<(?:img|source)\b[^>]*>#i', $html, $images );
		foreach ( $images[0] as $tag ) {
			foreach ( array( 'src', 'data-src', 'data-lazy-src' ) as $name ) {
				$src = self::absolute( html_entity_decode( self::attr( $tag, $name ) ), $base );
				if ( '' !== $src && ! str_starts_with( $src, 'data:' ) ) {
					$out['images'][ $src ] = true;
				}
			}
		}
		preg_match_all( '#url\(\s*["\']?([^"\')]+\.(?:png|jpe?g|webp|gif|svg|avif))#i', $html, $backgrounds );
		foreach ( $backgrounds[1] as $src ) {
			$out['images'][ self::absolute( html_entity_decode( $src ), $base ) ] = true;
		}
		$out['images'] = array_slice( array_keys( $out['images'] ), 0, 300 );

		return $out;
	}

	/**
	 * The CSS of every stylesheet (read from disk when it is this site's file, fetched otherwise) and every inline
	 * style, in page order, with url() made absolute.
	 *
	 * @param array<string, mixed> $parsed Result of parse().
	 * @return array{files: array<int, array{source: string, css: string}>, size: int, truncated: bool}
	 */
	private static function css_text( array $parsed, string $match ): array {
		$files = array();
		$size  = 0;
		$cut   = false;
		$add   = static function ( string $source, string $css ) use ( &$files, &$size, &$cut, $match ): void {
			if ( '' !== $match && ! str_contains( $css, $match ) && ! str_contains( $source, $match ) ) {
				return;
			}
			if ( $size + strlen( $css ) > self::MAX_CSS ) {
				$cut = true;
				return;
			}
			$size   += strlen( $css );
			$files[] = array( 'source' => $source, 'css' => $css );
		};

		foreach ( $parsed['stylesheets'] as $sheet ) {
			if ( 'external' === $sheet['from'] && str_contains( $sheet['href'], 'fonts.googleapis.com' ) ) {
				$add( $sheet['href'], '@import url("' . $sheet['href'] . '");' );
				continue;
			}
			$css = self::read_css( $sheet['href'] );
			if ( null !== $css ) {
				$css = self::absolute_urls( $css, $sheet['href'] );
				$add( $sheet['href'], '' !== $sheet['media'] && 'all' !== $sheet['media'] ? '@media ' . $sheet['media'] . " {\n" . $css . "\n}" : $css );
			}
		}
		foreach ( $parsed['inlineStyles'] as $style ) {
			$add( '<style id="' . $style['id'] . '">', $style['css'] );
		}

		return array( 'files' => $files, 'size' => $size, 'truncated' => $cut );
	}

	private static function read_css( string $href ): ?string {
		$path = (string) wp_parse_url( $href, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) ) === strtolower( $home ) && str_ends_with( strtolower( $path ), '.css' ) ) {
			$site = (string) wp_parse_url( site_url(), PHP_URL_PATH );
			$rel  = '' !== $site && str_starts_with( $path, $site ) ? substr( $path, strlen( $site ) ) : $path;
			$file = realpath( ABSPATH . ltrim( rawurldecode( $rel ), '/' ) );
			if ( false !== $file && str_starts_with( wp_normalize_path( $file ), wp_normalize_path( ABSPATH ) ) && is_file( $file ) ) {
				return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		}
		$response = wp_remote_get( $href, array( 'timeout' => 10, 'limit_response_size' => MB_IN_BYTES, 'sslverify' => false ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	private static function absolute_urls( string $css, string $base ): string {
		return (string) preg_replace_callback(
			'#url\(\s*(["\']?)([^"\')]+)\1\s*\)#i',
			static fn ( array $m ): string => 'url("' . self::absolute( $m[2], $base ) . '")',
			$css
		);
	}

	private static function absolute( string $url, string $base ): string {
		$url = trim( $url );
		if ( '' === $url || preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//|\#)#i', $url ) ) {
			return str_starts_with( $url, '//' ) ? 'https:' . $url : $url;
		}
		$parts  = wp_parse_url( $base );
		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( str_starts_with( $url, '/' ) ) {
			return $origin . $url;
		}
		$dir   = preg_replace( '#/[^/]*$#', '/', (string) ( $parts['path'] ?? '/' ) );
		$path  = $dir . $url;
		$clean = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $clean );
			} elseif ( '.' !== $segment ) {
				$clean[] = $segment;
			}
		}

		return $origin . implode( '/', $clean );
	}

	/** Where an asset comes from: "theme", "plugin:name", "uploads", "core" or "external". */
	private static function origin( string $url ): string {
		if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return 'external';
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( 1 === preg_match( '#/wp-content/themes/([^/]+)/#', $path, $m ) ) {
			return 'theme:' . $m[1];
		}
		if ( 1 === preg_match( '#/wp-content/(?:mu-)?plugins/([^/]+)/#', $path, $m ) ) {
			return 'plugin:' . $m[1];
		}
		if ( str_contains( $path, '/wp-content/uploads/' ) ) {
			return 'uploads';
		}

		return str_contains( $path, '/wp-includes/' ) ? 'core' : 'site';
	}

	private static function attr( string $tag, string $name ): string {
		return 1 === preg_match( '#\s' . preg_quote( $name, '#' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $tag, $m ) ? (string) ( $m[1] ?: ( $m[2] ?? '' ) ?: ( $m[3] ?? '' ) ) : '';
	}

	/** A path inside the readable folders of wp-content, or an error. */
	private static function path( string $relative ): string {
		$relative = trim( str_replace( '\\', '/', $relative ), '/' );
		if ( '' === $relative ) {
			return WP_CONTENT_DIR;
		}
		$root = strtok( $relative, '/' );
		if ( ! in_array( $root, self::ROOTS, true ) ) {
			throw new RuntimeException( 'Only these folders of wp-content can be read: ' . implode( ', ', self::ROOTS ) . '.' );
		}
		$real = realpath( WP_CONTENT_DIR . '/' . $relative );
		$base = realpath( WP_CONTENT_DIR . '/' . $root );
		if ( false === $real || false === $base || ! str_starts_with( wp_normalize_path( $real ) . '/', wp_normalize_path( $base ) . '/' ) ) {
			throw new RuntimeException( 'No such file or folder: ' . $relative );
		}
		if ( 1 === preg_match( '#(^|/)(\.env|\.git|\.htpasswd|wp-config\.php)($|/)#i', wp_normalize_path( $real ) ) ) {
			throw new RuntimeException( 'That file is private.' );
		}

		return $real;
	}

	private static function relative( string $path ): string {
		return ltrim( substr( wp_normalize_path( $path ), strlen( wp_normalize_path( (string) realpath( WP_CONTENT_DIR ) ) ) ), '/' );
	}

	/**
	 * @param array<int, array<string, mixed>> $entries Collected entries (by reference).
	 */
	private static function walk( string $dir, string $prefix, int $depth, array &$entries ): void {
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item || '.git' === $item || 'node_modules' === $item || count( $entries ) > 800 ) {
				continue;
			}
			$full = $dir . '/' . $item;
			$name = ( '' !== $prefix ? $prefix . '/' : '' ) . $item;
			if ( is_dir( $full ) ) {
				$entries[] = array( 'name' => $name . '/', 'type' => 'dir' );
				if ( $depth > 0 ) {
					self::walk( $full, $name, $depth - 1, $entries );
				}
			} else {
				$entries[] = array( 'name' => $name, 'size' => (int) filesize( $full ), 'modified' => gmdate( 'Y-m-d H:i', (int) filemtime( $full ) ) );
			}
		}
	}
}
