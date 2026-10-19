<?php
/**
 * Per-page and per-template SEO set in the editor or through the AI API.
 *
 * Works on its own (prints title, description, Open Graph, canonical, robots and JSON-LD) and, when an SEO plugin
 * is active, feeds the same values into Yoast, Rank Math, All in One SEO or SEOPress instead of printing duplicates.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Seo {

	public static function hooks(): void {
		// Suntourz theme.
		add_filter( 'stz_seo_override', array( self::class, 'theme_override' ) );
		add_filter( 'stz_seo_canonical', array( self::class, 'canonical' ) );

		// Core.
		add_filter( 'get_canonical_url', array( self::class, 'canonical' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ), 20 );
		add_filter( 'pre_get_document_title', array( self::class, 'title' ), 99 );
		add_action( 'wp_head', array( self::class, 'print_tags' ), 5 );
		add_action( 'wp_head', array( self::class, 'print_jsonld' ), 20 );

		// Yoast.
		add_filter( 'wpseo_title', array( self::class, 'plugin_title' ), 99 );
		add_filter( 'wpseo_opengraph_title', array( self::class, 'plugin_og_title' ), 99 );
		add_filter( 'wpseo_metadesc', array( self::class, 'plugin_description' ), 99 );
		add_filter( 'wpseo_opengraph_desc', array( self::class, 'plugin_og_description' ), 99 );
		add_filter( 'wpseo_opengraph_image', array( self::class, 'plugin_image' ), 99 );
		add_filter( 'wpseo_canonical', array( self::class, 'canonical' ), 99 );
		add_filter( 'wpseo_robots_array', array( self::class, 'robots' ), 99 );
		// Rank Math.
		add_filter( 'rank_math/frontend/title', array( self::class, 'plugin_title' ), 99 );
		add_filter( 'rank_math/frontend/description', array( self::class, 'plugin_description' ), 99 );
		add_filter( 'rank_math/frontend/canonical', array( self::class, 'canonical' ), 99 );
		add_filter( 'rank_math/frontend/robots', array( self::class, 'robots' ), 99 );
		add_filter( 'rank_math/opengraph/facebook/og_title', array( self::class, 'plugin_og_title' ), 99 );
		add_filter( 'rank_math/opengraph/facebook/og_description', array( self::class, 'plugin_og_description' ), 99 );
		add_filter( 'rank_math/opengraph/facebook/og_image', array( self::class, 'plugin_image' ), 99 );
		// All in One SEO.
		add_filter( 'aioseo_title', array( self::class, 'plugin_title' ), 99 );
		add_filter( 'aioseo_description', array( self::class, 'plugin_description' ), 99 );
		add_filter( 'aioseo_canonical_url', array( self::class, 'canonical' ), 99 );
		// SEOPress.
		add_filter( 'seopress_titles_title', array( self::class, 'plugin_title' ), 99 );
		add_filter( 'seopress_titles_desc', array( self::class, 'plugin_description' ), 99 );
		add_filter( 'seopress_titles_canonical', array( self::class, 'seopress_canonical' ), 99 );
	}

	/**
	 * SEO overrides of the current page (template values with %title% / %site% / %excerpt%, then the page's own).
	 *
	 * @return array<string, mixed>
	 */
	public static function current(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		if ( is_admin() ) {
			return array();
		}

		$all      = (array) Store::get()['seo'];
		$template = (array) ( $all[ 'tpl:' . Templates::current()['key'] ] ?? array() );
		$page     = (array) ( $all[ PageKey::current() ] ?? array() );
		$post_id  = is_singular() ? (int) get_queried_object_id() : 0;
		$meta     = $post_id > 0 ? get_post_meta( $post_id, '_sve_seo', true ) : array();
		$meta     = is_array( $meta ) ? $meta : array();

		$tokens = array(
			'%title%'   => self::current_title(),
			'%site%'    => (string) get_bloginfo( 'name' ),
			'%tagline%' => (string) get_bloginfo( 'description' ),
			'%excerpt%' => $post_id > 0 ? wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ) : '',
			'%sep%'     => '–',
		);
		foreach ( array( 'title', 'description', 'ogTitle', 'ogDescription' ) as $key ) {
			if ( isset( $template[ $key ] ) ) {
				$template[ $key ] = trim( strtr( (string) $template[ $key ], $tokens ) );
			}
		}
		if ( empty( $template['image'] ) && $post_id > 0 && has_post_thumbnail( $post_id ) && ! empty( $template ) ) {
			$template['image'] = (string) get_the_post_thumbnail_url( $post_id, 'large' );
		}

		$cache = array_merge( $template, $meta, $page ); // The page's own settings win.

		return $cache;
	}

	private static function current_title(): string {
		if ( is_singular() ) {
			return (string) get_the_title();
		}
		$object = get_queried_object();
		if ( $object instanceof \WP_Term ) {
			return $object->name;
		}
		if ( $object instanceof \WP_Post_Type ) {
			return (string) $object->labels->name;
		}
		if ( is_search() ) {
			return (string) get_search_query();
		}

		return (string) get_bloginfo( 'name' );
	}

	private static function seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
	}

	/** The Suntourz theme reads the stz_seo_* filters itself; SEO plugins get our values through their filters. */
	private static function prints_own(): bool {
		return ! defined( 'STZ_THEME_VERSION' ) && ! self::seo_plugin_active();
	}

	/**
	 * @param array<string, mixed> $out Theme SEO fields.
	 * @return array<string, mixed>
	 */
	public static function theme_override( array $out ): array {
		$seo = self::current();
		foreach ( array( 'title', 'description', 'image' ) as $key ) {
			if ( ! empty( $seo[ $key ] ) ) {
				$out[ $key ] = (string) $seo[ $key ];
			}
		}
		if ( ! empty( $seo['noindex'] ) ) {
			$out['noindex'] = true;
		}

		return $out;
	}

	public static function canonical( mixed $url ): mixed {
		$canonical = (string) ( self::current()['canonical'] ?? '' );

		return '' !== $canonical ? $canonical : $url;
	}

	public static function seopress_canonical( mixed $html ): mixed {
		$canonical = (string) ( self::current()['canonical'] ?? '' );

		return '' !== $canonical ? '<link rel="canonical" href="' . esc_url( $canonical ) . '">' : $html;
	}

	/**
	 * Works for core wp_robots (array of directives) and the plugins' robots arrays.
	 *
	 * @param mixed $robots Robots directives.
	 * @return mixed
	 */
	public static function robots( mixed $robots ): mixed {
		if ( ! is_array( $robots ) ) {
			return $robots;
		}
		$seo = self::current();
		if ( ! empty( $seo['noindex'] ) ) {
			$robots['noindex'] = isset( $robots['index'] ) && is_string( $robots['index'] ) ? 'noindex' : true;
			unset( $robots['index'] );
		}
		if ( ! empty( $seo['nofollow'] ) ) {
			$robots['nofollow'] = isset( $robots['follow'] ) && is_string( $robots['follow'] ) ? 'nofollow' : true;
			unset( $robots['follow'] );
		}

		return $robots;
	}

	public static function title( mixed $title ): mixed {
		$custom = (string) ( self::current()['title'] ?? '' );

		return '' !== $custom && self::prints_own() ? $custom : $title;
	}

	public static function plugin_title( mixed $title ): mixed {
		$custom = (string) ( self::current()['title'] ?? '' );

		return '' !== $custom ? $custom : $title;
	}

	public static function plugin_og_title( mixed $title ): mixed {
		$seo    = self::current();
		$custom = (string) ( $seo['ogTitle'] ?? $seo['title'] ?? '' );

		return '' !== $custom ? $custom : $title;
	}

	public static function plugin_description( mixed $desc ): mixed {
		$custom = (string) ( self::current()['description'] ?? '' );

		return '' !== $custom ? $custom : $desc;
	}

	public static function plugin_og_description( mixed $desc ): mixed {
		$seo    = self::current();
		$custom = (string) ( $seo['ogDescription'] ?? $seo['description'] ?? '' );

		return '' !== $custom ? $custom : $desc;
	}

	public static function plugin_image( mixed $image ): mixed {
		$custom = (string) ( self::current()['image'] ?? '' );

		return '' !== $custom ? $custom : $image;
	}

	public static function print_tags(): void {
		if ( ! self::prints_own() || is_admin() ) {
			return;
		}

		$seo   = self::current();
		$title = (string) ( $seo['ogTitle'] ?? $seo['title'] ?? '' );
		$desc  = (string) ( $seo['description'] ?? '' );
		$og    = (string) ( $seo['ogDescription'] ?? $desc );
		if ( '' !== $desc ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
		}
		if ( ! empty( $seo['keywords'] ) ) {
			printf( '<meta name="keywords" content="%s">' . "\n", esc_attr( (string) $seo['keywords'] ) );
		}
		if ( '' !== $og ) {
			printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $og ) );
		}
		if ( '' !== $title ) {
			printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
			printf( '<meta name="twitter:card" content="%s">' . "\n", ! empty( $seo['image'] ) ? 'summary_large_image' : 'summary' );
		}
		if ( '' !== $title || '' !== $og ) {
			printf( '<meta property="og:type" content="%s">' . "\n", is_singular( 'post' ) ? 'article' : 'website' );
			printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( (string) get_bloginfo( 'name' ) ) );
		}
		if ( ! empty( $seo['image'] ) ) {
			printf( '<meta property="og:image" content="%s">' . "\n", esc_url( (string) $seo['image'] ) );
			printf( '<meta name="twitter:image" content="%s">' . "\n", esc_url( (string) $seo['image'] ) );
		}
		// Core prints (and lets us filter) the canonical of single posts and pages only.
		if ( ! empty( $seo['canonical'] ) && ( ! is_singular() || ! has_action( 'wp_head', 'rel_canonical' ) ) ) {
			printf( '<link rel="canonical" href="%s">' . "\n", esc_url( (string) $seo['canonical'] ) );
		}
	}

	public static function print_jsonld(): void {
		if ( is_admin() ) {
			return;
		}
		$json = (string) ( self::current()['jsonld'] ?? '' );
		$data = '' === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return;
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HEX-escaped JSON.
		);
	}
}
