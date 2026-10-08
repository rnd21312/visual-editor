<?php
/**
 * The editor window: a standalone page (no wp-admin chrome) at /?sve_editor=1 that loads the public site
 * in an iframe next to an inspector, like browser dev tools with a Save button. Works with any theme.
 *
 * @package Suntourz\VisualEditor
 */

declare(strict_types=1);

namespace Suntourz\VisualEditor;

defined( 'ABSPATH' ) || exit;

final class EditorScreen {

	public const QUERY_ARG = 'sve_editor';

	public function __construct( private VisualEdits $edits ) {}

	public function hooks(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 99 );
		add_action( 'admin_footer', array( $this, 'menu_new_window' ) );
		add_filter( 'show_admin_bar', array( $this, 'hide_bar_in_preview' ) );
		add_filter(
			'wp_robots',
			static function ( array $robots ): array {
				return VisualEdits::is_editor_preview() ? array_merge( $robots, array( 'noindex' => true ) ) : $robots;
			}
		);
	}

	/**
	 * Address of the editor, optionally opened on a given page of the site.
	 */
	public static function url( string $page = '' ): string {
		$args = array( self::QUERY_ARG => 1 );
		if ( '' !== $page ) {
			$args['sve_url'] = rawurlencode( $page );
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Top-level "Visual editor" link in the dashboard menu (opens the editor window in a new tab).
	 */
	public function admin_menu(): void {
		global $menu;

		if ( ! current_user_can( VisualEdits::capability() ) ) {
			return;
		}

		// A direct link, not a page: add_menu_page() would mangle the URL.
		$menu[59] = array( __( 'Visual editor', 'suntourz-visual-editor' ), VisualEdits::capability(), self::url(), __( 'Visual editor', 'suntourz-visual-editor' ), 'menu-top menu-sve', 'menu-sve', 'dashicons-admin-appearance' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	public function menu_new_window(): void {
		echo '<script>document.querySelectorAll(\'#adminmenu a[href*="sve_editor=1"]\').forEach(function(a){a.target="_blank";a.rel="noopener"});</script>';
	}

	public function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! current_user_can( VisualEdits::capability() ) ) {
			return;
		}

		$current = set_url_scheme( 'http://' . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$bar->add_node(
			array(
				'id'    => 'sve-edit-visually',
				'title' => '<span class="ab-icon dashicons dashicons-admin-appearance" style="margin-top:2px"></span> ' . esc_html__( 'Edit visually', 'suntourz-visual-editor' ),
				'href'  => self::url( $current ),
				'meta'  => array( 'target' => '_blank' ),
			)
		);
	}

	public function hide_bar_in_preview( bool $show ): bool {
		return VisualEdits::is_editor_preview() ? false : $show;
	}

	/**
	 * Same-origin URL to open first (anything else falls back to the home page).
	 */
	private function start_url(): string {
		$wanted = isset( $_GET['sve_url'] ) ? rawurldecode( wp_unslash( (string) $_GET['sve_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		$home   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host   = (string) wp_parse_url( $wanted, PHP_URL_HOST );

		return '' !== $wanted && $host === $home ? esc_url_raw( $wanted ) : home_url( '/' );
	}

	/**
	 * Pages offered in the editor's page picker: home, pages, posts, and every public custom post type.
	 *
	 * @return array<int, array{label:string, url:string, group:string}>
	 */
	private function pages(): array {
		$out = array( array( 'label' => __( 'Home', 'suntourz-visual-editor' ), 'url' => home_url( '/' ), 'group' => __( 'Main', 'suntourz-visual-editor' ) ) );

		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page > 0 ) {
			$out[] = array( 'label' => get_the_title( $posts_page ), 'url' => (string) get_permalink( $posts_page ), 'group' => __( 'Main', 'suntourz-visual-editor' ) );
		}

		foreach ( get_pages( array( 'number' => 60, 'sort_column' => 'menu_order,post_title' ) ) as $page ) {
			if ( (int) get_option( 'page_on_front' ) === (int) $page->ID || $posts_page === (int) $page->ID ) {
				continue;
			}
			$out[] = array( 'label' => get_the_title( $page ), 'url' => (string) get_permalink( $page ), 'group' => __( 'Pages', 'suntourz-visual-editor' ) );
		}

		$types = array_merge( array( 'post' ), array_values( get_post_types( array( 'public' => true, '_builtin' => false ) ) ) );
		foreach ( $types as $type ) {
			$object = get_post_type_object( $type );
			if ( ! $object || 'attachment' === $type ) {
				continue;
			}
			$group = (string) ( $object->labels->name ?? $type );

			$archive = 'post' === $type ? '' : get_post_type_archive_link( $type );
			if ( $archive ) {
				$out[] = array( 'label' => sprintf( /* translators: %s: post type name */ __( 'All %s', 'suntourz-visual-editor' ), $group ), 'url' => (string) $archive, 'group' => $group );
			}
			foreach ( get_posts( array( 'post_type' => $type, 'numberposts' => 12, 'post_status' => 'publish' ) ) as $post ) {
				$out[] = array( 'label' => get_the_title( $post ), 'url' => (string) get_permalink( $post ), 'group' => $group );
			}
		}

		return $out;
	}

	/**
	 * Changes an AI proposed and nobody has published yet.
	 *
	 * @return array{changes: int}|null
	 */
	private function draft_info(): ?array {
		$draft = $this->edits->draft();

		return null === $draft ? null : array( 'changes' => Mcp::difference( $this->edits->get(), $draft ) );
	}

	public function maybe_render(): void {
		if ( empty( $_GET[ self::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}
		if ( ! current_user_can( VisualEdits::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this site.', 'suntourz-visual-editor' ), '', array( 'response' => 403 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		$config = array(
			'restUrl'    => esc_url_raw( rest_url( 'sve/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'siteName'   => get_bloginfo( 'name' ),
			'homeUrl'    => home_url( '/' ),
			'homePath'   => (string) wp_parse_url( home_url(), PHP_URL_PATH ),
			'adminUrl'   => admin_url(),
			'startUrl'   => $this->start_url(),
			'previewArg' => VisualEdits::PREVIEW_ARG,
			'pages'      => $this->pages(),
			'doc'        => $this->edits->get(),
			'canAdmin'   => current_user_can( 'manage_options' ),
			'templates'  => Templates::listing(),
			'pageTypes'  => Pages::types(),
			'api'        => current_user_can( 'manage_options' ) ? Api::status() : null,
			'draft'      => $this->draft_info(),
		);

		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Visual editor — %s', 'suntourz-visual-editor' ), get_bloginfo( 'name' ) ) ); ?></title>
<?php Assets::print_editor(); ?>
</head>
<body>
<script type="application/json" id="sve-config"><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HEX-escaped JSON. ?></script>
<div id="sve-root"><p style="padding:24px;font:14px system-ui"><?php esc_html_e( 'Loading the editor…', 'suntourz-visual-editor' ); ?></p></div>
</body>
</html>
		<?php
		exit;
	}
}
