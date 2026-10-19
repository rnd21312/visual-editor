<?php
/**
 * "AI access (MCP)" page in the WordPress dashboard: switch the endpoint on, create the key, choose what the
 * AI may do, describe the site to it, test the connection, copy ready-made setup snippets for each AI tool
 * and see what the AI changed. Plain PHP forms (no JavaScript build), administrators only.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class AdminPage {

	private const SLUG = 'sve-ai';

	/** The key just created: shown once on the response to the form, never stored. */
	private string $new_key = '';

	/** @var array{ok: bool, msg: string}|null */
	private ?array $test = null;

	public function __construct( private VisualEdits $edits ) {}

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	public function menu(): void {
		$hook = add_menu_page(
			__( 'AI access (MCP)', 'visual-editor' ),
			__( 'AI (MCP)', 'visual-editor' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-superhero-alt',
			60
		);
		add_action( 'load-' . $hook, array( $this, 'handle' ) );
	}

	/* ------------------------------------------------------------------ actions */

	public function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		check_admin_referer( 'sve_ai' );

		$do = isset( $_POST['sve_do'] ) ? sanitize_key( wp_unslash( (string) $_POST['sve_do'] ) ) : '';
		switch ( $do ) {
			case 'save':
				Api::configure(
					! empty( $_POST['enabled'] ),
					isset( $_POST['level'] ) ? sanitize_key( wp_unslash( (string) $_POST['level'] ) ) : 'draft',
					isset( $_POST['about'] ) ? wp_unslash( (string) $_POST['about'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Api::configure.
				);
				$this->back( 'saved' );
				break;
			case 'create':
				$this->new_key = Api::create_key();
				break;
			case 'revoke':
				Api::revoke();
				$this->back( 'revoked' );
				break;
			case 'discard':
				$this->edits->set_draft( null );
				$this->back( 'discarded' );
				break;
			case 'test':
				$this->test = $this->probe();
				break;
		}
	}

	private function back( string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'sve_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Calls the site's own endpoint with a token that is never valid and reads how it answers: that shows whether
	 * the REST API is reachable and whether the server passes the Authorization header on.
	 *
	 * @return array{ok: bool, msg: string}
	 */
	private function probe(): array {
		$response = wp_remote_post(
			Api::endpoint(),
			array(
				'timeout'   => 10,
				'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array( 'Authorization' => 'Bearer ' . Api::PROBE, 'Content-Type' => 'application/json' ),
				'body'      => (string) wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'msg' => sprintf( /* translators: %s: error text */ __( 'This site could not call its own address (%s). That only affects this test; AI tools connect from outside.', 'visual-editor' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 401 === $code && str_contains( $body, 'Wrong API key' ) ) {
			return array( 'ok' => true, 'msg' => __( 'Works: the endpoint answers and the Authorization header arrives.', 'visual-editor' ) );
		}
		if ( 401 === $code && str_contains( $body, 'Missing API key' ) ) {
			return array( 'ok' => false, 'msg' => __( 'The endpoint answers, but your server drops the Authorization header. On Apache add this to .htaccess: RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}] — or send the key in an "X-Sve-Key" header instead.', 'visual-editor' ) );
		}
		if ( 403 === $code ) {
			return array( 'ok' => false, 'msg' => __( 'The endpoint is there, but AI access is switched off or has no key yet.', 'visual-editor' ) );
		}
		if ( 404 === $code ) {
			return array( 'ok' => false, 'msg' => __( 'The REST API is not reachable at the endpoint. Check Settings → Permalinks and any security plugin that blocks /wp-json.', 'visual-editor' ) );
		}

		return array( 'ok' => false, 'msg' => sprintf( /* translators: %d: HTTP status */ __( 'Unexpected answer from the endpoint (HTTP %d).', 'visual-editor' ), $code ) );
	}

	/* ------------------------------------------------------------------ page */

	/**
	 * @return array<string, array{label: string, hint: string}>
	 */
	private static function levels(): array {
		return array(
			'read'  => array( 'label' => __( 'Read only', 'visual-editor' ), 'hint' => __( 'Looks at pages, templates and content (GET requests). Changes nothing.', 'visual-editor' ) ),
			'draft' => array( 'label' => __( 'Propose changes — I approve them (recommended)', 'visual-editor' ), 'hint' => __( 'Editor changes (styles, text, SEO) wait as a draft until you review and save them in the visual editor.', 'visual-editor' ) ),
			'live'  => array( 'label' => __( 'Edit live', 'visual-editor' ), 'hint' => __( 'Editor changes are published at once; each keeps a version you can restore.', 'visual-editor' ) ),
			'full'  => array( 'label' => __( 'Full access — everything an administrator can do', 'visual-editor' ), 'hint' => __( 'Also content, media, menus, settings, users, plugins and WooCommerce through the REST API, acting as you. Deleting and user changes may not be undoable. Only for an assistant you trust.', 'visual-editor' ) ),
		);
	}

	/**
	 * Setup commands for each AI tool.
	 *
	 * @return array<string, array{label: string, text: string}>
	 */
	private static function snippets( string $name, string $endpoint, string $key ): array {
		$json   = static fn ( array $data ): string => (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$bearer = 'Bearer ' . $key;

		return array(
			'claude-code'    => array(
				'label' => 'Claude Code',
				'text'  => sprintf( 'claude mcp add --transport http %s %s --header "Authorization: %s"', $name, $endpoint, $bearer ),
			),
			'claude-desktop' => array(
				'label' => 'Claude Desktop (claude_desktop_config.json)',
				'text'  => $json( array( 'mcpServers' => array( $name => array( 'command' => 'npx', 'args' => array( '-y', 'mcp-remote', $endpoint, '--header', 'Authorization:${SVE_AUTH}' ), 'env' => array( 'SVE_AUTH' => $bearer ) ) ) ) ),
			),
			'cursor'         => array(
				'label' => 'Cursor (.cursor/mcp.json)',
				'text'  => $json( array( 'mcpServers' => array( $name => array( 'url' => $endpoint, 'headers' => array( 'Authorization' => $bearer ) ) ) ) ),
			),
			'vscode'         => array(
				'label' => 'VS Code (.vscode/mcp.json)',
				'text'  => $json( array( 'servers' => array( $name => array( 'type' => 'http', 'url' => $endpoint, 'headers' => array( 'Authorization' => $bearer ) ) ) ) ),
			),
			'curl'           => array(
				'label' => 'curl (test)',
				'text'  => sprintf( "curl -X POST %s \\\n  -H \"Authorization: %s\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}'", $endpoint, $bearer ),
			),
		);
	}

	public function render(): void {
		$s        = Api::settings();
		$status   = Api::status();
		$levels   = self::levels();
		$draft    = $this->edits->draft();
		$pending  = null === $draft ? 0 : Mcp::difference( $this->edits->get(), $draft );
		$key      = '' !== $this->new_key ? $this->new_key : 'YOUR_API_KEY';
		$notices  = array(
			'saved'     => __( 'Settings saved.', 'visual-editor' ),
			'revoked'   => __( 'Key revoked. Every AI tool that used it stops working.', 'visual-editor' ),
			'discarded' => __( 'Proposed changes discarded.', 'visual-editor' ),
		);
		$notice   = isset( $_GET['sve_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['sve_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$time     = static fn ( int $t ): string => $t > 0 ? wp_date( 'Y-m-d H:i', $t ) : '—';
		?>
<div class="wrap sve-ai">
	<h1><?php esc_html_e( 'AI access (MCP)', 'visual-editor' ); ?></h1>
	<style>
		.sve-ai .sve-card{background:#fff;border:1px solid #c3c4c7;padding:4px 20px 16px;margin:16px 0;max-width:900px}
		.sve-ai pre{background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto;white-space:pre-wrap;word-break:break-all;margin:6px 0}
		.sve-ai .sve-radio{display:block;margin:8px 0}.sve-ai .sve-radio small{display:block;margin-left:24px;color:#646970}
		.sve-ai details{margin:8px 0}.sve-ai summary{cursor:pointer;font-weight:600}
	</style>

	<?php if ( isset( $notices[ $notice ] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
	<?php endif; ?>
	<?php if ( null !== $this->test ) : ?>
		<div class="notice <?php echo $this->test['ok'] ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( $this->test['msg'] ); ?></p></div>
	<?php endif; ?>

	<p><?php esc_html_e( 'Lets an AI assistant (Claude, Cursor, any MCP client) look at this site and work on it. Each site has its own endpoint, key and name, so several sites can be connected to the same assistant.', 'visual-editor' ); ?></p>

	<?php if ( $pending > 0 ) : ?>
		<div class="sve-card" style="border-left:4px solid #dba617">
			<h2><?php esc_html_e( 'Waiting for your approval', 'visual-editor' ); ?></h2>
			<p><?php echo esc_html( sprintf( /* translators: %d: number of changes */ _n( 'The AI proposed %d change.', 'The AI proposed %d changes.', $pending, 'visual-editor' ), $pending ) ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'sve_ai' ); ?>
				<a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( EditorScreen::url() ); ?>"><?php esc_html_e( 'Review in the visual editor', 'visual-editor' ); ?></a>
				<button class="button" name="sve_do" value="discard"><?php esc_html_e( 'Discard', 'visual-editor' ); ?></button>
			</form>
		</div>
	<?php endif; ?>

	<div class="sve-card">
		<h2>1 · <?php esc_html_e( 'API key', 'visual-editor' ); ?></h2>
		<?php if ( '' !== $this->new_key ) : ?>
			<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Copy the key now — it is shown only once.', 'visual-editor' ); ?></strong></p>
				<pre id="sve-new-key"><?php echo esc_html( $this->new_key ); ?></pre>
				<p><button type="button" class="button button-primary" data-sve-copy="sve-new-key"><?php esc_html_e( 'Copy key', 'visual-editor' ); ?></button></p></div>
		<?php endif; ?>
		<?php if ( $status['hasKey'] ) : ?>
			<p><?php echo esc_html( sprintf( /* translators: 1: key ending, 2: created, 3: last used, 4: calls */ __( 'Key %1$s · created %2$s · last used %3$s · %4$d calls', 'visual-editor' ), $status['keyHint'], $time( $status['created'] ), $time( $status['lastUsed'] ), $status['calls'] ) ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'No key yet.', 'visual-editor' ); ?></p>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'sve_ai' ); ?>
			<button class="button button-primary" name="sve_do" value="create"><?php echo esc_html( $status['hasKey'] ? __( 'Create a new key (replaces the old one)', 'visual-editor' ) : __( 'Create API key', 'visual-editor' ) ); ?></button>
			<?php if ( $status['hasKey'] ) : ?>
				<button class="button" name="sve_do" value="revoke" onclick="return confirm('<?php echo esc_js( __( 'Revoke the key? Every AI tool using it stops working immediately.', 'visual-editor' ) ); ?>')"><?php esc_html_e( 'Revoke key', 'visual-editor' ); ?></button>
			<?php endif; ?>
		</form>
		<p class="description"><?php esc_html_e( 'The AI acts as the administrator who creates the key. If that user stops being an administrator, the key stops working.', 'visual-editor' ); ?></p>
	</div>

	<div class="sve-card">
		<h2>2 · <?php esc_html_e( 'What the AI may do, and what this site is', 'visual-editor' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'sve_ai' ); ?>
			<p><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> <strong><?php esc_html_e( 'AI access switched on', 'visual-editor' ); ?></strong></label></p>
			<?php foreach ( $levels as $value => $level ) : ?>
				<label class="sve-radio"><input type="radio" name="level" value="<?php echo esc_attr( $value ); ?>" <?php checked( $s['level'], $value ); ?>> <strong><?php echo esc_html( $level['label'] ); ?></strong><small><?php echo esc_html( $level['hint'] ); ?></small></label>
			<?php endforeach; ?>
			<p><label for="sve-about"><strong><?php esc_html_e( 'Describe this site to the AI', 'visual-editor' ); ?></strong></label><br>
				<textarea id="sve-about" name="about" rows="3" maxlength="500" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Tour booking shop for Phuket, Persian-speaking customers, WooCommerce.', 'visual-editor' ); ?>"><?php echo esc_textarea( $s['about'] ); ?></textarea><br>
				<span class="description"><?php esc_html_e( 'The AI sees this when it connects. With several sites connected, it is how it tells them apart.', 'visual-editor' ); ?></span></p>
			<p><button class="button button-primary" name="sve_do" value="save"><?php esc_html_e( 'Save settings', 'visual-editor' ); ?></button></p>
		</form>
		<p class="description"><?php echo esc_html( sprintf( /* translators: 1: reads per minute, 2: changes per minute, 3: changes per day */ __( 'Limits: %1$d reads and %2$d changes per minute, %3$d changes per day. Wrong keys are blocked after a few tries.', 'visual-editor' ), $status['limits']['readsPerMinute'], $status['limits']['writesPerMinute'], $status['limits']['writesPerDay'] ) ); ?></p>
	</div>

	<div class="sve-card">
		<h2>3 · <?php esc_html_e( 'Connect an AI tool', 'visual-editor' ); ?></h2>
		<p><?php esc_html_e( 'Endpoint', 'visual-editor' ); ?>: <code><?php echo esc_html( $status['endpoint'] ); ?></code> · <?php esc_html_e( 'Server name', 'visual-editor' ); ?>: <code><?php echo esc_html( $status['serverName'] ); ?></code></p>
		<form method="post" style="margin:8px 0">
			<?php wp_nonce_field( 'sve_ai' ); ?>
			<button class="button" name="sve_do" value="test"><?php esc_html_e( 'Test the connection', 'visual-editor' ); ?></button>
		</form>
		<?php foreach ( self::snippets( $status['serverName'], $status['endpoint'], $key ) as $id => $snippet ) : ?>
			<details <?php echo 'claude-code' === $id ? 'open' : ''; ?>>
				<summary><?php echo esc_html( $snippet['label'] ); ?></summary>
				<pre id="sve-snip-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $snippet['text'] ); ?></pre>
				<button type="button" class="button" data-sve-copy="sve-snip-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Copy', 'visual-editor' ); ?></button>
			</details>
		<?php endforeach; ?>
		<?php if ( '' === $this->new_key ) : ?>
			<p class="description"><?php esc_html_e( 'The snippets show YOUR_API_KEY: put the key you copied in its place (create a new one if you lost it).', 'visual-editor' ); ?></p>
		<?php endif; ?>
	</div>

	<div class="sve-card">
		<h2>4 · <?php esc_html_e( 'What the AI changed', 'visual-editor' ); ?></h2>
		<?php $log = array_slice( array_reverse( Api::log_entries() ), 0, 50 ); ?>
		<?php if ( array() === $log ) : ?>
			<p><?php esc_html_e( 'Nothing yet.', 'visual-editor' ); ?></p>
		<?php else : ?>
			<table class="widefat striped"><tbody>
				<?php foreach ( $log as $entry ) : ?>
					<tr><td style="white-space:nowrap;width:1%"><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $entry['t'] ) ); ?></td><td><code style="word-break:break-all"><?php echo esc_html( (string) $entry['what'] ); ?></code></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Earlier versions of the editor document can be restored in the visual editor (✦ dialog). Posts keep WordPress revisions.', 'visual-editor' ); ?></p>
	</div>

	<script>
		document.querySelectorAll('[data-sve-copy]').forEach(function (button) {
			button.addEventListener('click', function () {
				var text = document.getElementById(button.dataset.sveCopy).textContent;
				if (navigator.clipboard) navigator.clipboard.writeText(text);
				button.textContent = '✓';
			});
		});
	</script>
</div>
		<?php
	}
}
