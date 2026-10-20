<?php
/**
 * "Site transfer" page under AI (MCP): download the whole front end of this site as one file, and import such a
 * file from another site. Administrators only.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class TransferPage {

	private const SLUG = 'sve-transfer';

	private const REPORT = 'sve_transfer_report';

	public static function hooks(): void {
		$page = new self();
		add_action( 'admin_menu', array( $page, 'menu' ), 20 );
		add_action( 'admin_post_sve_export', array( $page, 'export' ) );
		add_action( 'admin_post_sve_import', array( $page, 'import' ) );
	}

	public function menu(): void {
		add_submenu_page( 'sve-ai', __( 'Site transfer', 'visual-editor' ), __( 'Site transfer', 'visual-editor' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Administrators only.', 'visual-editor' ), 403 );
		}
		check_admin_referer( 'sve_export' );

		$json = (string) wp_json_encode( SiteTransfer::export(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$name = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-frontend-' . gmdate( 'Ymd-His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON download.
		exit;
	}

	public function import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Administrators only.', 'visual-editor' ), 403 );
		}
		check_admin_referer( 'sve_import' );

		$file = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		try {
			if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
				throw new \RuntimeException( __( 'Choose the export file (.json). If it is large, the server upload limit may be too small.', 'visual-editor' ) );
			}
			$data = json_decode( (string) file_get_contents( (string) $file['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_array( $data ) ) {
				throw new \RuntimeException( __( 'The file is not valid JSON.', 'visual-editor' ) );
			}
			$report = SiteTransfer::import(
				$data,
				array(
					'media'   => ! empty( $_POST['media'] ),
					'theme'   => ! empty( $_POST['theme'] ),
					'replace' => ! empty( $_POST['replace'] ),
				)
			);
		} catch ( \Throwable $error ) {
			$report = array( 'done' => array(), 'warnings' => array( $error->getMessage() ), 'failed' => true );
		}
		set_transient( self::REPORT . '_' . get_current_user_id(), $report, HOUR_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&sve_report=1' ) );
		exit;
	}

	public function render(): void {
		$report = isset( $_GET['sve_report'] ) ? get_transient( self::REPORT . '_' . get_current_user_id() ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$limit  = size_format( wp_max_upload_size() );
		?>
<div class="wrap sve-transfer">
	<h1><?php esc_html_e( 'Site transfer', 'visual-editor' ); ?></h1>
	<style>.sve-transfer .sve-card{background:#fff;border:1px solid #c3c4c7;padding:4px 20px 16px;margin:16px 0;max-width:900px}.sve-transfer label{display:block;margin:8px 0}</style>

	<?php if ( is_array( $report ) ) : ?>
		<div class="notice <?php echo empty( $report['failed'] ) ? 'notice-success' : 'notice-error'; ?>">
			<?php foreach ( (array) $report['done'] as $line ) : ?>
				<p>✓ <?php echo esc_html( $line ); ?></p>
			<?php endforeach; ?>
			<?php foreach ( array_slice( (array) $report['warnings'], 0, 40 ) as $line ) : ?>
				<p>⚠ <?php echo esc_html( $line ); ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="sve-card">
		<h2>1 · <?php esc_html_e( 'Export this site\'s front end', 'visual-editor' ); ?></h2>
		<p><?php esc_html_e( 'One file with: Visual Editor styles, elements, components, design tokens, libraries and scripts; builder templates; pages and layout posts (Elementor, block theme templates, theme layouts) with their settings; menus and their locations; widgets; the theme with its customizer and theme options; the front page; a list of the images they use.', 'visual-editor' ); ?></p>
		<p><strong><?php esc_html_e( 'Not included:', 'visual-editor' ); ?></strong> <?php esc_html_e( 'products, blog posts, orders, customers, users, form entries, plugin settings unrelated to layout, snippets, and the theme and plugin files themselves (install the same theme and plugins on the other site).', 'visual-editor' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'sve_export' ); ?>
			<input type="hidden" name="action" value="sve_export">
			<button class="button button-primary"><?php esc_html_e( 'Download export file', 'visual-editor' ); ?></button>
		</form>
	</div>

	<div class="sve-card">
		<h2>2 · <?php esc_html_e( 'Import into this site', 'visual-editor' ); ?></h2>
		<p><?php esc_html_e( 'Install and activate the same theme and page-builder plugins first. Back up this site before importing: pages with the same address are replaced, menus with the same name are rebuilt, and theme settings are overwritten.', 'visual-editor' ); ?></p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='<?php echo esc_js( __( 'Importing… (copying images can take a few minutes)', 'visual-editor' ) ); ?>'">
			<?php wp_nonce_field( 'sve_import' ); ?>
			<input type="hidden" name="action" value="sve_import">
			<p><input type="file" name="file" accept=".json,application/json" required> <span class="description"><?php echo esc_html( sprintf( /* translators: %s: size */ __( 'Upload limit: %s', 'visual-editor' ), $limit ) ); ?></span></p>
			<label><input type="checkbox" name="media" value="1" checked> <?php esc_html_e( 'Copy the images and files into this media library (otherwise they keep loading from the old site)', 'visual-editor' ); ?></label>
			<label><input type="checkbox" name="theme" value="1" checked> <?php esc_html_e( 'Switch to the exported theme (when it is installed here)', 'visual-editor' ); ?></label>
			<label><input type="checkbox" name="replace" value="1" checked> <?php esc_html_e( 'Replace pages and templates that have the same address (otherwise they are added as copies)', 'visual-editor' ); ?></label>
			<p><button class="button button-primary"><?php esc_html_e( 'Import', 'visual-editor' ); ?></button></p>
		</form>
		<p class="description"><?php esc_html_e( 'The Visual Editor part can be rolled back from the editor\'s history panel; pages keep WordPress revisions.', 'visual-editor' ); ?></p>
	</div>
</div>
		<?php
	}
}
