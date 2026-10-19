<?php
/**
 * Where builder content is used.
 *
 *  - Pages / posts / products with their own builder source (post meta), in one of three modes:
 *      theme  = inside the theme's header and footer (replaces the content area),
 *      canvas = full page with the builder's own header/footer parts,
 *      blank  = full page, nothing else (landing pages).
 *  - Builder templates (post type sve_template) that replace a theme template for many URLs: single product,
 *    single post, category archive, search results, 404… with optional include / exclude conditions.
 *  - Parts: a builder header and footer used by canvas pages and, optionally, instead of the theme's own.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor\Builder;

use VisualEditor\Frontend;
use VisualEditor\Html;
use VisualEditor\Templates;

defined( 'ABSPATH' ) || exit;

final class Router {

	public const POST_TYPE = 'sve_template';

	public const MODES = array( 'theme', 'canvas', 'blank' );

	/** @var array<string, mixed>|null Template chosen for this request. */
	private static ?array $template = null;

	private static bool $resolved = false;

	public static function hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_filter( 'template_include', array( self::class, 'template_include' ), 99 );
		add_filter( 'the_content', array( self::class, 'content_marker' ), 0 );
		add_filter( 'the_content', array( self::class, 'content_replace' ), PHP_INT_MAX );
		add_action( 'wp_body_open', array( self::class, 'theme_header' ), 1 );
		add_action( 'wp_footer', array( self::class, 'theme_footer' ), 0 );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
	}

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'           => __( 'Builder templates', 'visual-editor' ),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'revisions', 'custom-fields' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			)
		);
		foreach ( array( '_sve_source', '_sve_mode', '_sve_trusted' ) as $key ) {
			register_meta(
				'post',
				$key,
				array(
					'single'            => true,
					'type'              => 'string',
					'show_in_rest'      => false,
					'revisions_enabled' => '_sve_source' === $key,
					'auth_callback'     => static fn (): bool => current_user_can( 'edit_pages' ),
				)
			);
		}
	}

	/* ------------------------------------------------------------------ sources */

	/**
	 * Builder source of a post (page, post, product or template), or null when it has none.
	 *
	 * @return array{source: string, mode: string, trusted: bool}|null
	 */
	public static function source( int $post_id ): ?array {
		if ( $post_id <= 0 ) {
			return null;
		}
		$source = get_post_meta( $post_id, '_sve_source', true );
		$mode   = (string) get_post_meta( $post_id, '_sve_mode', true );
		if ( Frontend::is_preview() ) {
			// The editor previews its unsaved layout.
			$working = get_post_meta( $post_id, '_sve_source_edit', true );
			if ( is_array( $working ) && isset( $working['source'] ) && (int) ( $working['user'] ?? 0 ) === get_current_user_id() ) {
				$source = (string) $working['source'];
				$mode   = (string) ( $working['mode'] ?? $mode );
			}
		}
		if ( ! is_string( $source ) || '' === trim( $source ) ) {
			return null;
		}

		return array(
			'source'  => $source,
			'mode'    => in_array( $mode, self::MODES, true ) ? $mode : 'theme',
			'trusted' => '1' === get_post_meta( $post_id, '_sve_trusted', true ),
		);
	}

	/**
	 * Stores the builder source of a post. Untrusted authors' markup is filtered; the post content is refreshed
	 * with a static render so search, feeds and SEO plugins see the page too.
	 */
	public static function set_source( int $post_id, string $source, ?string $mode, bool $trusted ): void {
		$clean = Html::clean( $source, $trusted, 2000000 );
		update_post_meta( $post_id, '_sve_source', wp_slash( $clean ) );
		update_post_meta( $post_id, '_sve_trusted', $trusted ? '1' : '' );
		if ( null !== $mode && in_array( $mode, self::MODES, true ) ) {
			update_post_meta( $post_id, '_sve_mode', $mode );
		}
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && self::POST_TYPE !== $post->post_type ) {
			$html = '';
			try {
				$html = Renderer::render( $clean, array( 'post' => new PostNode( $post ) ), $trusted );
			} catch ( \Throwable $e ) {
				$html = '';
			}
			$html = trim( (string) preg_replace( '/\sdata-(sid|sve-c)="[^"]*"/', '', $html ) );
			remove_filter( 'content_save_pre', 'wp_filter_post_kses' );
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( "<!-- wp:html -->\n" . ( $trusted ? $html : wp_kses_post( $html ) ) . "\n<!-- /wp:html -->" ),
				)
			);
			if ( ! $trusted ) {
				kses_init_filters();
			}
		}
		\VisualEditor\Cache::purge();
	}

	/**
	 * Unsaved layout of the editor (only its preview shows it).
	 */
	public static function set_working( int $post_id, ?string $source, ?string $mode, bool $trusted ): void {
		if ( null === $source ) {
			delete_post_meta( $post_id, '_sve_source_edit' );
			return;
		}
		update_post_meta(
			$post_id,
			'_sve_source_edit',
			wp_slash(
				array(
					'source' => Html::clean( $source, $trusted, 2000000 ),
					'mode'   => in_array( $mode, self::MODES, true ) ? $mode : 'theme',
					'user'   => get_current_user_id(),
					'time'   => time(),
				)
			)
		);
	}

	public static function remove_source( int $post_id ): void {
		delete_post_meta( $post_id, '_sve_source' );
		delete_post_meta( $post_id, '_sve_mode' );
		delete_post_meta( $post_id, '_sve_trusted' );
	}

	/* ------------------------------------------------------------------ templates */

	/**
	 * Builder templates.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function templates( bool $active_only = false ): array {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => $active_only ? array( 'publish' ) : array( 'publish', 'draft' ),
				'posts_per_page' => 200,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);

		return array_map( array( self::class, 'template_info' ), $posts );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function template_info( \WP_Post $post ): array {
		$conditions = get_post_meta( $post->ID, '_sve_conditions', true );

		return array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'target'     => (string) get_post_meta( $post->ID, '_sve_target', true ),
			'mode'       => self::source( $post->ID )['mode'] ?? ( (string) get_post_meta( $post->ID, '_sve_mode', true ) ?: 'theme' ),
			'active'     => 'publish' === $post->post_status,
			'priority'   => (int) $post->menu_order,
			'conditions' => is_array( $conditions ) ? $conditions : array(),
			'replace'    => (string) get_post_meta( $post->ID, '_sve_replace', true ),
			'modified'   => mysql2date( 'c', $post->post_modified_gmt, false ),
		);
	}

	/**
	 * Targets of the current request, most specific first.
	 *
	 * @return array<int, string>
	 */
	private static function targets(): array {
		$key = Templates::current()['key'];
		$out = array( $key );
		if ( str_starts_with( $key, 'single:' ) ) {
			$out[] = 'single:*';
		} elseif ( str_starts_with( $key, 'archive:' ) || str_starts_with( $key, 'tax:' ) || in_array( $key, array( 'blog-index', 'author', 'date' ), true ) ) {
			$out[] = 'archive:*';
		} elseif ( str_starts_with( $key, 'page' ) ) {
			$out[] = 'page-default';
		}

		return $out;
	}

	/**
	 * Builder template for the current request.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current_template(): ?array {
		if ( self::$resolved ) {
			return self::$template;
		}
		self::$resolved = true;

		// The editor can preview any template on a sample URL.
		if ( Frontend::is_preview() && isset( $_GET['sve_tpl'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post = get_post( (int) $_GET['sve_tpl'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ) {
				self::$template = self::template_info( $post );
				return self::$template;
			}
		}

		$queried = get_queried_object_id();
		$best    = null;
		foreach ( self::targets() as $rank => $target ) {
			foreach ( self::templates( true ) as $template ) {
				if ( $template['target'] !== $target || ! self::matches( $template['conditions'], $queried ) ) {
					continue;
				}
				$score = ( 100 - $rank ) * 1000 + $template['priority'] + ( array() !== (array) ( $template['conditions']['include'] ?? array() ) ? 500 : 0 );
				if ( null === $best || $score > $best['score'] ) {
					$best = $template + array( 'score' => $score );
				}
			}
		}
		self::$template = $best;

		return $best;
	}

	/**
	 * @param array<string, mixed> $conditions include / exclude ids (posts or terms).
	 */
	private static function matches( array $conditions, int $queried ): bool {
		$include = array_map( 'intval', (array) ( $conditions['include'] ?? array() ) );
		$exclude = array_map( 'intval', (array) ( $conditions['exclude'] ?? array() ) );
		if ( in_array( $queried, $exclude, true ) ) {
			return false;
		}
		if ( array() !== $include && ! in_array( $queried, $include, true ) ) {
			// A product/post also matches when one of its terms is included.
			if ( is_singular() ) {
				$terms = wp_get_object_terms( $queried, get_object_taxonomies( (string) get_post_type( $queried ) ), array( 'fields' => 'ids' ) );
				return is_array( $terms ) && array() !== array_intersect( $include, array_map( 'intval', $terms ) );
			}
			return false;
		}

		return true;
	}

	/**
	 * Header or footer part (active builder template with target "header" / "footer").
	 *
	 * @return array<string, mixed>|null
	 */
	public static function part( string $which ): ?array {
		static $parts = array();
		if ( ! array_key_exists( $which, $parts ) ) {
			$parts[ $which ] = null;
			foreach ( self::templates( true ) as $template ) {
				if ( $template['target'] === $which && self::matches( $template['conditions'], (int) get_queried_object_id() ) ) {
					$parts[ $which ] = $template;
					break;
				}
			}
		}

		return $parts[ $which ];
	}

	/* ------------------------------------------------------------------ front end */

	/**
	 * Picks the builder's page shell when the page or a builder template asks for it.
	 */
	public static function template_include( string $template ): string {
		if ( is_admin() || is_feed() || is_embed() ) {
			return $template;
		}
		$post_id = is_singular() ? (int) get_queried_object_id() : 0;
		$own     = self::source( $post_id );
		if ( null !== $own && 'theme' !== $own['mode'] ) {
			$GLOBALS['sve_shell'] = array( 'mode' => $own['mode'], 'post' => $post_id, 'template' => null ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return SVE_DIR . 'templates/canvas.php';
		}
		$builder = self::current_template();
		if ( null !== $builder && ( null === $own || 'theme' === $own['mode'] ) ) {
			$GLOBALS['sve_shell'] = array( 'mode' => $builder['mode'], 'post' => $post_id, 'template' => $builder ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return SVE_DIR . ( 'theme' === $builder['mode'] ? 'templates/theme.php' : 'templates/canvas.php' );
		}

		return $template;
	}

	/**
	 * Renders the main content of the shell: a builder template, or the page's own source.
	 */
	public static function main_content(): string {
		$shell = (array) ( $GLOBALS['sve_shell'] ?? array() );
		if ( ! empty( $shell['template'] ) ) {
			$source = self::source( (int) $shell['template']['id'] );
			if ( null === $source ) {
				return '';
			}
			return self::wrap( (int) $shell['template']['id'], Renderer::render( $source['source'], array(), $source['trusted'] ) );
		}
		$post = get_post( (int) ( $shell['post'] ?? 0 ) );

		return $post instanceof \WP_Post ? self::wrap( $post->ID, Renderer::post_content( $post ) ) : '';
	}

	public static function part_html( string $which ): string {
		$part = self::part( $which );
		if ( null === $part ) {
			return '';
		}
		$source = self::source( (int) $part['id'] );

		return null === $source ? '' : self::wrap( (int) $part['id'], Renderer::render( $source['source'], array(), $source['trusted'] ) );
	}

	/**
	 * In the editor's preview, marks which source a region comes from.
	 */
	private static function wrap( int $source_id, string $html ): string {
		return Frontend::is_preview() ? '<div data-sve-source="' . $source_id . '" style="display:contents">' . $html . '</div>' : $html;
	}

	/**
	 * Theme-mode pages: the content is replaced by the builder output (after wpautop & co. ran on a marker only).
	 */
	public static function content_marker( mixed $content ): mixed {
		$post = get_post();
		if ( ! $post instanceof \WP_Post || ! in_the_loop() && ! is_singular() ) {
			return $content;
		}
		$source = self::source( $post->ID );
		if ( null === $source || 'theme' !== $source['mode'] ) {
			return $content;
		}

		return '<!--sve-content:' . $post->ID . '-->';
	}

	public static function content_replace( mixed $content ): mixed {
		if ( ! is_string( $content ) || ! str_contains( $content, '<!--sve-content:' ) ) {
			return $content;
		}

		return (string) preg_replace_callback(
			'#(?:<p>\s*)?<!--sve-content:(\d+)-->(?:\s*</p>)?#',
			static function ( array $m ): string {
				$post = get_post( (int) $m[1] );
				if ( ! $post instanceof \WP_Post ) {
					return '';
				}
				$source = self::source( $post->ID );
				return null === $source ? '' : self::wrap( $post->ID, Renderer::render( $source['source'], array( 'post' => new PostNode( $post ) ), $source['trusted'] ) );
			},
			$content
		);
	}

	/**
	 * Builder header instead of the theme's (theme-mode pages, when the part says "replace the theme header").
	 */
	public static function theme_header(): void {
		if ( isset( $GLOBALS['sve_shell'] ) && 'theme' !== ( $GLOBALS['sve_shell']['mode'] ?? '' ) ) {
			return; // Canvas pages print parts themselves.
		}
		$part = self::part( 'header' );
		if ( null === $part || '' === $part['replace'] ) {
			return;
		}
		printf( '<style>%s{display:none!important}</style>', esc_html( str_replace( array( '{', '}', '<' ), '', $part['replace'] ) ) );
		echo self::part_html( 'header' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered builder output.
	}

	public static function theme_footer(): void {
		if ( isset( $GLOBALS['sve_shell'] ) && 'theme' !== ( $GLOBALS['sve_shell']['mode'] ?? '' ) ) {
			return;
		}
		$part = self::part( 'footer' );
		if ( null === $part || '' === $part['replace'] ) {
			return;
		}
		printf( '<style>%s{display:none!important}</style>', esc_html( str_replace( array( '{', '}', '<' ), '', $part['replace'] ) ) );
		echo self::part_html( 'footer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public static function body_class( array $classes ): array {
		if ( isset( $GLOBALS['sve_shell'] ) ) {
			$classes[] = 'sve-shell';
			$classes[] = 'sve-mode-' . sanitize_html_class( (string) $GLOBALS['sve_shell']['mode'] );
		}

		return $classes;
	}

	/**
	 * What the editor's preview is made of (for <meta name="sve-context">).
	 *
	 * @return array<string, mixed>
	 */
	public static function context(): array {
		$post_id  = is_singular() ? (int) get_queried_object_id() : 0;
		$own      = self::source( $post_id );
		$template = self::current_template();
		$shell    = (array) ( $GLOBALS['sve_shell'] ?? array() );
		$canvas   = isset( $shell['mode'] ) && 'theme' !== $shell['mode'];

		$header = self::part( 'header' );
		$footer = self::part( 'footer' );

		return array(
			'post'     => null !== $own ? array( 'id' => $post_id, 'mode' => $own['mode'] ) : null,
			'template' => null !== $template ? array( 'id' => $template['id'], 'target' => $template['target'], 'mode' => $template['mode'], 'title' => $template['title'] ) : null,
			'header'   => null !== $header && ( $canvas || '' !== $header['replace'] ) && 'blank' !== ( $shell['mode'] ?? '' ) ? $header['id'] : null,
			'footer'   => null !== $footer && ( $canvas || '' !== $footer['replace'] ) && 'blank' !== ( $shell['mode'] ?? '' ) ? $footer['id'] : null,
		);
	}
}
