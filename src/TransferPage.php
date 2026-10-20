<?php
/**
 * "Site transfer" page under AI (MCP): export all of the site or the parts you pick (as a download or a link for
 * the other site), and import such a package with a live progress bar — every step is a short request, so large
 * sites do not hit the hosting time limit. Administrators only.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class TransferPage {

	private const SLUG = 'sve-transfer';

	public static function hooks(): void {
		$page = new self();
		add_action( 'admin_menu', array( $page, 'menu' ), 20 );
		add_action( 'admin_post_sve_export', array( $page, 'export' ) );
		add_action( 'wp_ajax_sve_transfer', array( $page, 'ajax' ) );
	}

	public function menu(): void {
		add_submenu_page( 'sve-ai', __( 'Site transfer', 'visual-editor' ), __( 'Site transfer', 'visual-editor' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * @return array<int, string>
	 */
	private static function posted_groups(): array {
		return SiteTransfer::groups( array_map( 'sanitize_key', (array) wp_unslash( $_POST['groups'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- checked by the callers.
	}

	public function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Administrators only.', 'visual-editor' ), 403 );
		}
		check_admin_referer( 'sve_transfer' );

		$pages = array_map( 'intval', (array) ( $_POST['pages'] ?? array() ) );
		try {
			$package = SiteTransfer::package( self::posted_groups(), $pages );
		} catch ( \Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ) );
		}
		nocache_headers();
		header( 'Content-Type: ' . ( str_ends_with( $package['file'], '.zip' ) ? 'application/zip' : 'application/json; charset=utf-8' ) );
		header( 'Content-Disposition: attachment; filename="' . $package['name'] . '"' );
		header( 'Content-Length: ' . (int) filesize( $package['file'] ) );
		readfile( $package['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $package['file'] );
		exit;
	}

	/** Every action of the page that answers without a reload: link, start (file or link), step. */
	public function ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Administrators only.', 'visual-editor' ) ), 403 );
		}
		check_ajax_referer( 'sve_transfer' );
		$do = sanitize_key( (string) ( $_POST['do'] ?? '' ) );
		try {
			switch ( $do ) {
				case 'link':
					wp_send_json_success( SiteTransfer::export_link( self::posted_groups(), array_map( 'intval', (array) ( $_POST['pages'] ?? array() ) ) ) );
					break;
				case 'start':
					$options = array(
						'media'   => ! empty( $_POST['media'] ),
						'theme'   => ! empty( $_POST['theme'] ),
						'replace' => ! empty( $_POST['replace'] ),
					);
					$groups  = array_map( 'sanitize_key', (array) wp_unslash( $_POST['groups'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					$url     = esc_url_raw( wp_unslash( (string) ( $_POST['url'] ?? '' ) ) );
					if ( '' !== $url ) {
						wp_send_json_success( TransferJob::start_from_url( $url, $groups, $options ) );
					}
					$file = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
						throw new \RuntimeException( sprintf( /* translators: %s: size */ __( 'Choose the package file, or paste the export link. Files larger than %s cannot be uploaded here: use the link instead.', 'visual-editor' ), size_format( wp_max_upload_size() ) ) );
					}
					$target = SiteTransfer::folder() . '/upload-' . bin2hex( random_bytes( 8 ) );
					move_uploaded_file( (string) $file['tmp_name'], $target );
					wp_send_json_success( TransferJob::start( $target, $groups, $options ) );
					break;
				case 'step':
					wp_send_json_success( TransferJob::load( sanitize_key( (string) ( $_POST['job'] ?? '' ) ) )->step() );
					break;
			}
		} catch ( \Throwable $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ) );
		}
		wp_send_json_error( array( 'message' => 'Unknown action.' ) );
	}

	public function render(): void {
		$labels = SiteTransfer::labels();
		$pages  = get_pages( array( 'post_status' => array( 'publish', 'private', 'draft' ), 'sort_column' => 'menu_order,post_title' ) );
		?>
<div class="wrap sve-transfer">
	<h1><?php esc_html_e( 'Site transfer', 'visual-editor' ); ?></h1>
	<style>
		.sve-transfer .sve-card{background:#fff;border:1px solid #c3c4c7;padding:4px 20px 16px;margin:16px 0;max-width:900px}
		.sve-transfer .sve-groups label{display:block;margin:8px 0}.sve-transfer .sve-groups small{display:block;color:#646970;margin-inline-start:24px}
		.sve-transfer .sve-pages{max-height:180px;overflow:auto;border:1px solid #dcdcde;padding:6px 10px;margin:6px 0}
		.sve-transfer progress{width:100%;height:18px}.sve-transfer .sve-log{max-height:260px;overflow:auto;background:#f6f7f7;border:1px solid #dcdcde;padding:8px;margin-top:8px}
		.sve-transfer .sve-log p{margin:2px 0}.sve-transfer .sve-warn{color:#996800}.sve-transfer .sve-err{color:#b32d2e}
		.sve-transfer pre{background:#f6f7f7;border:1px solid #dcdcde;padding:8px;white-space:pre-wrap;word-break:break-all}
	</style>

	<div class="sve-card">
		<h2>1 · <?php esc_html_e( 'Export', 'visual-editor' ); ?></h2>
		<form id="sve-export" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'sve_transfer' ); ?>
			<input type="hidden" name="action" value="sve_export">
			<p>
				<button type="button" class="button" data-sve-all="1"><?php esc_html_e( 'Whole site', 'visual-editor' ); ?></button>
				<button type="button" class="button" data-sve-all="0"><?php esc_html_e( 'Look only (design, pages, templates, menus)', 'visual-editor' ); ?></button>
			</p>
			<div class="sve-groups">
				<?php foreach ( $labels as $group => $label ) : ?>
					<label><input type="checkbox" name="groups[]" value="<?php echo esc_attr( $group ); ?>" <?php checked( in_array( $group, SiteTransfer::DEFAULT_GROUPS, true ) ); ?>> <strong><?php echo esc_html( $label[0] ); ?></strong><small><?php echo esc_html( $label[1] ); ?></small></label>
					<?php if ( 'pages' === $group && array() !== $pages ) : ?>
						<details style="margin-inline-start:24px"><summary><?php esc_html_e( 'Only some pages…', 'visual-editor' ); ?></summary>
							<div class="sve-pages">
								<?php foreach ( $pages as $page ) : ?>
									<label><input type="checkbox" name="pages[]" value="<?php echo (int) $page->ID; ?>"> <?php echo esc_html( '' !== $page->post_title ? $page->post_title : '#' . $page->ID ); ?> <code>/<?php echo esc_html( urldecode( $page->post_name ) ); ?>/</code></label>
								<?php endforeach; ?>
							</div>
							<small><?php esc_html_e( 'None ticked: every page.', 'visual-editor' ); ?></small>
						</details>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<p class="description"><?php esc_html_e( 'Never included: orders, customers and users, form entries, licences and API keys. To clone everything including users and orders, use a full backup plugin (Duplicator).', 'visual-editor' ); ?></p>
			<p>
				<button class="button button-primary"><?php esc_html_e( 'Download file', 'visual-editor' ); ?></button>
				<button type="button" class="button" id="sve-link"><?php esc_html_e( 'Create a link for the other site', 'visual-editor' ); ?></button>
			</p>
			<div id="sve-link-out"></div>
		</form>
	</div>

	<div class="sve-card">
		<h2>2 · <?php esc_html_e( 'Import into this site', 'visual-editor' ); ?></h2>
		<p><?php esc_html_e( 'Back up this site first. Pages, products and templates with the same address are replaced, menus with the same name are rebuilt and theme settings are overwritten. Anything not in the package — for example this site\'s own products when you import only the look — stays as it is.', 'visual-editor' ); ?></p>
		<form id="sve-import" enctype="multipart/form-data">
			<p><label><strong><?php esc_html_e( 'Package file', 'visual-editor' ); ?></strong> <input type="file" name="file" accept=".json,.zip,application/json,application/zip"></label>
				<span class="description"><?php echo esc_html( sprintf( /* translators: %s: size */ __( 'up to %s', 'visual-editor' ), size_format( wp_max_upload_size() ) ) ); ?></span></p>
			<p><label><strong><?php esc_html_e( 'or export link', 'visual-editor' ); ?></strong><br><input type="url" name="url" class="large-text" placeholder="https://other-site/wp-json/sve/v1/transfer/…"></label></p>
			<div class="sve-groups">
				<p><strong><?php esc_html_e( 'Import these parts (when the package has them):', 'visual-editor' ); ?></strong></p>
				<?php foreach ( $labels as $group => $label ) : ?>
					<label><input type="checkbox" name="groups[]" value="<?php echo esc_attr( $group ); ?>" checked> <?php echo esc_html( $label[0] ); ?></label>
				<?php endforeach; ?>
			</div>
			<hr>
			<label><input type="checkbox" name="media" value="1" checked> <?php esc_html_e( 'Copy the images and files into this media library (otherwise they keep loading from the old site)', 'visual-editor' ); ?></label><br>
			<label><input type="checkbox" name="theme" value="1" checked> <?php esc_html_e( 'Switch to the exported theme', 'visual-editor' ); ?></label><br>
			<label><input type="checkbox" name="replace" value="1" checked> <?php esc_html_e( 'Replace pages, products and templates with the same address (otherwise they are added as copies)', 'visual-editor' ); ?></label>
			<p><button class="button button-primary"><?php esc_html_e( 'Import', 'visual-editor' ); ?></button></p>
		</form>
		<div id="sve-progress" hidden>
			<progress max="100" value="0"></progress>
			<p id="sve-stage"></p>
			<div class="sve-log"></div>
		</div>
	</div>
</div>
<script>
(function () {
	var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	var nonce = <?php echo wp_json_encode( wp_create_nonce( 'sve_transfer' ) ); ?>;
	var stages = <?php echo wp_json_encode( self::stage_labels() ); ?>;
	var exportForm = document.getElementById('sve-export');
	var importForm = document.getElementById('sve-import');
	var log = document.querySelector('.sve-log');

	function post(data) {
		data.append('action', 'sve_transfer');
		data.append('_ajax_nonce', nonce);
		return fetch(ajax, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r || !r.success) throw new Error(r && r.data && r.data.message ? r.data.message : <?php echo wp_json_encode( __( 'The server did not answer properly (time limit or error). Try again: the import continues where it stopped.', 'visual-editor' ) ); ?>);
			return r.data;
		});
	}
	function line(text, cls) {
		var p = document.createElement('p');
		p.textContent = text;
		if (cls) p.className = cls;
		log.appendChild(p);
		log.scrollTop = log.scrollHeight;
	}

	exportForm.querySelectorAll('[data-sve-all]').forEach(function (b) {
		b.addEventListener('click', function () {
			var all = b.dataset.sveAll === '1';
			exportForm.querySelectorAll('input[name="groups[]"]').forEach(function (c) {
				c.checked = all || <?php echo wp_json_encode( SiteTransfer::DEFAULT_GROUPS ); ?>.indexOf(c.value) >= 0;
			});
		});
	});

	document.getElementById('sve-link').addEventListener('click', function () {
		var out = document.getElementById('sve-link-out');
		var data = new FormData(exportForm);
		data.append('do', 'link');
		out.textContent = <?php echo wp_json_encode( __( 'Exporting…', 'visual-editor' ) ); ?>;
		post(data).then(function (r) {
			out.innerHTML = '';
			var pre = document.createElement('pre');
			pre.textContent = r.link;
			out.appendChild(pre);
			var info = document.createElement('p');
			info.className = 'description';
			info.textContent = <?php echo wp_json_encode( __( 'Paste this link in "Import" on the other site, or give it to the AI (import_site). Valid for a day. Size:', 'visual-editor' ) ); ?> + ' ' + r.size;
			out.appendChild(info);
		}).catch(function (e) { out.textContent = e.message; });
	});

	importForm.addEventListener('submit', function (e) {
		e.preventDefault();
		if (!confirm(<?php echo wp_json_encode( __( 'Import now? Make sure you have a backup of this site.', 'visual-editor' ) ); ?>)) return;
		var data = new FormData(importForm);
		data.append('do', 'start');
		importForm.querySelector('button').disabled = true;
		document.getElementById('sve-progress').hidden = false;
		log.innerHTML = '';
		line(<?php echo wp_json_encode( __( 'Uploading and reading the package…', 'visual-editor' ) ); ?>);
		var shown = 0, warned = 0, retries = 0;
		function show(s) {
			document.querySelector('#sve-progress progress').value = s.progress;
			document.getElementById('sve-stage').textContent = (stages[s.stage] || s.stage) + (s.step ? ' — ' + s.step : '') + ' (' + s.progress + '%)';
			s.report.slice(shown).forEach(function (t) { line('✓ ' + t); });
			s.warnings.slice(warned).forEach(function (t) { line('⚠ ' + t, 'sve-warn'); });
			shown = s.report.length;
			warned = s.warnings.length;
		}
		function step(job) {
			var d = new FormData();
			d.append('do', 'step');
			d.append('job', job);
			post(d).then(function (s) {
				retries = 0;
				show(s);
				if (!s.done) step(job);
				else importForm.querySelector('button').disabled = false;
			}).catch(function (err) {
				if (++retries <= 3) { line('… ' + err.message, 'sve-warn'); setTimeout(function () { step(job); }, 3000); return; }
				line(err.message, 'sve-err');
				importForm.querySelector('button').disabled = false;
			});
		}
		post(data).then(function (s) { show(s); step(s.job); }).catch(function (err) {
			line(err.message, 'sve-err');
			importForm.querySelector('button').disabled = false;
		});
	});
})();
</script>
		<?php
	}

	/**
	 * @return array<string, string>
	 */
	private static function stage_labels(): array {
		return array(
			'theme'      => __( 'Theme', 'visual-editor' ),
			'attributes' => __( 'Product attributes', 'visual-editor' ),
			'terms'      => __( 'Categories and tags', 'visual-editor' ),
			'media'      => __( 'Copying images', 'visual-editor' ),
			'posts'      => __( 'Pages, posts and products', 'visual-editor' ),
			'meta'       => __( 'Their settings, images and categories', 'visual-editor' ),
			'menus'      => __( 'Menus', 'visual-editor' ),
			'options'    => __( 'Settings', 'visual-editor' ),
			'snippets'   => __( 'Code snippets', 'visual-editor' ),
			'document'   => __( 'Visual Editor', 'visual-editor' ),
			'finish'     => __( 'Clearing caches', 'visual-editor' ),
			'done'       => __( 'Done', 'visual-editor' ),
		);
	}
}
