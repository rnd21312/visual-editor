<?php
/**
 * The editor window: a standalone page (no wp-admin chrome) at /?sve_editor=1 that loads the public site in an
 * iframe next to the builder panels. Works with any theme.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

use VisualEditor\Builder\Router;

defined( 'ABSPATH' ) || exit;

final class EditorScreen {

	public const QUERY_ARG = 'sve_editor';

	private const MENU_SLUG = 'sve-visual-editor';

	public static function hooks(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_render' ), 0 );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 100 );
		add_action( 'admin_menu', array( self::class, 'admin_menu' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( self::class, 'row_action' ), 10, 2 );
	}

	/**
	 * Address of the editor, optionally opened on a page of the site or on content to edit.
	 */
	public static function url( string $page = '', int $post = 0 ): string {
		$args = array( self::QUERY_ARG => 1 );
		if ( '' !== $page ) {
			$args['sve_url'] = rawurlencode( $page );
		}
		if ( $post > 0 ) {
			$args['sve_post'] = $post;
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * "Visual editor" in the dashboard menu: a real menu page that forwards to the editor window.
	 */
	public static function admin_menu(): void {
		$hook = add_menu_page(
			__( 'Visual editor', 'visual-editor' ),
			__( 'Visual editor', 'visual-editor' ),
			Store::capability(),
			self::MENU_SLUG,
			'__return_null',
			'dashicons-admin-appearance',
			58
		);
		add_action(
			'load-' . $hook,
			static function (): void {
				wp_safe_redirect( self::url() );
				exit;
			}
		);
	}

	/**
	 * "Edit visually" link in the posts and pages lists.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @return array<string, string>
	 */
	public static function row_action( array $actions, \WP_Post $post ): array {
		if ( current_user_can( Store::capability() ) && current_user_can( 'edit_post', $post->ID ) && is_post_type_viewable( $post->post_type ) ) {
			$actions['sve'] = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( self::url( (string) get_permalink( $post ), $post->ID ) ), esc_html__( 'Edit visually', 'visual-editor' ) );
		}

		return $actions;
	}

	public static function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( Store::capability() ) || Frontend::is_preview() ) {
			return;
		}
		$current = is_admin() ? '' : set_url_scheme( 'http://' . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$bar->add_node(
			array(
				'id'    => 'sve-edit-visually',
				'title' => '<span class="ab-icon dashicons dashicons-admin-appearance" style="margin-top:2px"></span> ' . esc_html__( 'Edit visually', 'visual-editor' ),
				'href'  => self::url( $current, is_singular() ? (int) get_queried_object_id() : 0 ),
				'meta'  => array( 'target' => '_blank' ),
			)
		);
	}

	/**
	 * Same-origin URL to open first (anything else falls back to the home page).
	 */
	private static function start_url(): string {
		$wanted = isset( $_GET['sve_url'] ) ? wp_unslash( (string) $_GET['sve_url'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		if ( str_contains( $wanted, '%' ) && ! str_contains( $wanted, '://' ) ) {
			$wanted = rawurldecode( $wanted );
		}
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = (string) wp_parse_url( $wanted, PHP_URL_HOST );

		return '' !== $wanted && strtolower( $host ) === strtolower( $home ) ? esc_url_raw( $wanted ) : home_url( '/' );
	}

	public static function maybe_render(): void {
		if ( empty( $_GET[ self::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}
		if ( ! current_user_can( Store::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this site.', 'visual-editor' ), '', array( 'response' => 403 ) );
		}

		nocache_headers();
		send_frame_options_header();
		header( "Content-Security-Policy: frame-ancestors 'self'" );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		$admin  = current_user_can( 'manage_options' );
		$draft  = Store::draft();
		$config = array(
			'restUrl'     => esc_url_raw( rest_url( 'sve/v1/' ) ),
			'wpRestUrl'   => esc_url_raw( rest_url() ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'siteName'    => get_bloginfo( 'name' ),
			'homeUrl'     => home_url( '/' ),
			'adminUrl'    => admin_url(),
			'logoutUrl'   => wp_logout_url( home_url( '/' ) ),
			'startUrl'    => self::start_url(),
			'startPost'   => (int) ( $_GET['sve_post'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'previewArg'  => Frontend::PREVIEW_ARG,
			'doc'         => Store::get(),
			'user'        => array(
				'id'      => get_current_user_id(),
				'name'    => wp_get_current_user()->display_name,
				'admin'   => $admin,
				'trusted' => Sanitizer::user_trusted(),
			),
			'language'    => get_user_locale(),
			'rtl'         => is_rtl(),
			'templates'   => Templates::listing(),
			'builder'     => Router::templates(),
			'types'       => Content::post_types(),
			'taxonomies'  => Content::taxonomies(),
			'libraries'   => array_values( Libraries::catalog() ),
			'menuLocations' => get_registered_nav_menus(),
			'api'         => $admin ? Api::status() : null,
			'draft'       => null !== $draft ? Store::difference( Store::get(), $draft ) : 0,
			'ssr'         => Ssr::available(),
			'woocommerce' => function_exists( 'WC' ),
			'version'     => SVE_VERSION,
			'react'       => Libraries::REACT,
		);

		?>
<!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_user_locale() ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Visual editor — %s', 'visual-editor' ), get_bloginfo( 'name' ) ) ); ?></title>
		<?php Assets::print_editor(); ?>
</head>
<body>
<script type="application/json" id="sve-config"><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HEX-escaped JSON. ?></script>
<div id="sve-root"><p style="padding:24px;font:14px system-ui"><?php esc_html_e( 'Loading the editor…', 'visual-editor' ); ?></p></div>
</body>
</html>
		<?php
		exit;
	}
}
