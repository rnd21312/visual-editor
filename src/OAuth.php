<?php
/**
 * OAuth 2.1 for the MCP endpoint, so web AI apps (Claude.ai custom connectors and other MCP clients that do
 * not take a pasted key) can connect: the app registers itself (RFC 7591), sends the administrator to a consent
 * page on this site, and receives a short-lived access token plus a rotating refresh token (authorization code
 * flow with PKCE S256, RFC 7636). Tokens are random, stored only as hashes, and act as the administrator who
 * approved. What they may do is the permission level chosen in the settings, exactly like the pasted key.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class OAuth {

	public const OPTION = 'sve_oauth';

	private const CODE_TTL = 60;

	private const ACCESS_TTL = 3600;

	private const REFRESH_TTL = 2592000; // 30 days.

	private const MAX_CLIENTS = 50;

	private const MAX_TOKENS = 300;

	/* ------------------------------------------------------------------ state */

	/**
	 * @return array{clients: array<string, array<string, mixed>>, tokens: array<string, array<string, mixed>>, codes: array<string, array<string, mixed>>}
	 */
	private static function load(): array {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return $state + array( 'clients' => array(), 'tokens' => array(), 'codes' => array() );
	}

	/**
	 * @param array<string, mixed> $state State to store (expired codes and tokens are dropped first).
	 */
	private static function save( array $state ): void {
		$now = time();
		foreach ( array( 'tokens', 'codes' ) as $kind ) {
			$state[ $kind ] = array_filter( $state[ $kind ], static fn ( array $record ): bool => $record['e'] > $now );
		}
		if ( count( $state['tokens'] ) > self::MAX_TOKENS ) {
			uasort( $state['tokens'], static fn ( array $a, array $b ): int => $a['e'] <=> $b['e'] );
			$state['tokens'] = array_slice( $state['tokens'], -self::MAX_TOKENS, null, true );
		}
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Read-modify-write under a lock so two requests (a refresh and a revoke, say) cannot undo each other.
	 *
	 * @param callable(array<string, mixed>): mixed $change Receives the state by reference.
	 */
	private static function mutate( callable $change ): mixed {
		return Store::locked(
			static function () use ( $change ): mixed {
				$state  = self::load();
				$result = $change( $state );
				self::save( $state );

				return $result;
			},
			'sve_oauth'
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function client( string $id ): ?array {
		$client = self::load()['clients'][ $id ] ?? null;

		return is_array( $client ) ? $client : null;
	}

	/* ------------------------------------------------------------------ rules (no HTTP: tested on their own) */

	/**
	 * Where an app may be sent back to: https, a loopback address (desktop tools), or the custom schemes of
	 * the editors that use them. Never javascript:, data: or anything with a fragment or user info.
	 */
	public static function redirect_uri_ok( string $uri ): bool {
		if ( strlen( $uri ) > 500 || 1 === preg_match( '/[\x00-\x20#]/', $uri ) ) {
			return false;
		}
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) $parts['host'] );
		if ( 'https' === $scheme || in_array( $scheme, array( 'cursor', 'vscode', 'vscode-insiders' ), true ) ) {
			return true;
		}

		return 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true );
	}

	/**
	 * Dynamic client registration.
	 *
	 * @param array<string, mixed> $meta What the app sent.
	 * @return array{client: array<string, mixed>}|array{error: string, description: string}
	 */
	public static function register( array $meta ): array {
		$uris = $meta['redirect_uris'] ?? null;
		if ( ! is_array( $uris ) || array() === $uris || count( $uris ) > 5 ) {
			return array( 'error' => 'invalid_redirect_uri', 'description' => 'Give 1 to 5 redirect_uris.' );
		}
		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || ! self::redirect_uri_ok( $uri ) ) {
				return array( 'error' => 'invalid_redirect_uri', 'description' => 'Redirect addresses must be https, a loopback address, or an editor scheme.' );
			}
		}

		$name = trim( substr( sanitize_text_field( (string) ( $meta['client_name'] ?? '' ) ), 0, 80 ) );
		$id   = 'sve_c_' . bin2hex( random_bytes( 12 ) );
		$new  = array( 'name' => '' !== $name ? $name : 'AI app', 'uris' => array_values( $uris ), 'created' => time(), 'last_used' => 0 );

		self::mutate(
			static function ( array &$state ) use ( $id, $new ): void {
				$state['clients'][ $id ] = $new;
				if ( count( $state['clients'] ) > self::MAX_CLIENTS ) {
					uasort( $state['clients'], static fn ( array $a, array $b ): int => $a['created'] <=> $b['created'] );
					$state['clients'] = array_slice( $state['clients'], -self::MAX_CLIENTS, null, true );
				}
			}
		);

		return array( 'client' => array( 'client_id' => $id ) + $new );
	}

	/**
	 * Checks an authorization request. "fatal" means show an error page (never redirect to an unchecked
	 * address); "error" means redirect back to the app with that OAuth error.
	 *
	 * @param array<string, mixed> $q Query or form fields.
	 * @return array<string, mixed>
	 */
	public static function check_authorize( array $q ): array {
		$client = self::client( (string) ( $q['client_id'] ?? '' ) );
		$uri    = (string) ( $q['redirect_uri'] ?? '' );
		if ( null === $client || ! in_array( $uri, $client['uris'], true ) ) {
			return array( 'fatal' => 'This app is not registered, or its redirect address does not match.' );
		}

		$out = array( 'client_id' => (string) $q['client_id'], 'client' => $client, 'uri' => $uri, 'state' => substr( (string) ( $q['state'] ?? '' ), 0, 500 ) );
		if ( 'code' !== ( $q['response_type'] ?? '' ) ) {
			return $out + array( 'error' => 'unsupported_response_type' );
		}
		$challenge = (string) ( $q['code_challenge'] ?? '' );
		if ( 'S256' !== ( $q['code_challenge_method'] ?? '' ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge ) ) {
			return $out + array( 'error' => 'invalid_request' );
		}

		return $out + array( 'challenge' => $challenge );
	}

	/**
	 * A one-time authorization code for an approved request, valid for a minute.
	 */
	public static function create_code( string $client, int $user, string $uri, string $challenge, string $level = 'draft' ): string {
		$code = bin2hex( random_bytes( 32 ) );
		self::mutate(
			static function ( array &$state ) use ( $code, $client, $user, $uri, $challenge, $level ): void {
				$state['codes'][ hash( 'sha256', $code ) ] = array( 'c' => $client, 'u' => $user, 'r' => $uri, 'p' => $challenge, 'l' => $level, 'e' => time() + self::CODE_TTL );
			}
		);

		return $code;
	}

	/**
	 * The token endpoint: authorization_code (with PKCE) and refresh_token (rotating).
	 *
	 * @param array<string, mixed> $p Request fields.
	 * @return array{ok: true, body: array<string, mixed>}|array{ok: false, error: string, description: string}
	 */
	public static function exchange( array $p ): array {
		$fail = static fn ( string $error, string $description = '' ): array => array( 'ok' => false, 'error' => $error, 'description' => $description );
		$type = (string) ( $p['grant_type'] ?? '' );

		if ( 'authorization_code' === $type ) {
			$code     = (string) ( $p['code'] ?? '' );
			$verifier = (string) ( $p['code_verifier'] ?? '' );
			if ( '' === $code || 1 !== preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) {
				return $fail( 'invalid_grant', 'Missing code or code_verifier.' );
			}

			return self::mutate(
				static function ( array &$state ) use ( $p, $code, $verifier, $fail ): array {
					$hash = hash( 'sha256', $code );
					$rec  = $state['codes'][ $hash ] ?? null;
					unset( $state['codes'][ $hash ] ); // One use only, whatever the outcome.

					if ( ! is_array( $rec ) || $rec['e'] < time() || $rec['c'] !== (string) ( $p['client_id'] ?? '' ) || $rec['r'] !== (string) ( $p['redirect_uri'] ?? '' ) ) {
						return $fail( 'invalid_grant', 'The code is unknown, used, expired or for another app.' );
					}
					$expected = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
					if ( ! hash_equals( $rec['p'], $expected ) || ! isset( $state['clients'][ $rec['c'] ] ) ) {
						return $fail( 'invalid_grant', 'PKCE verification failed.' );
					}
					$state['clients'][ $rec['c'] ]['last_used'] = time();

					return array( 'ok' => true, 'body' => self::issue( $state, $rec['c'], (int) $rec['u'], self::level_of( $rec ) ) );
				}
			);
		}

		if ( 'refresh_token' === $type ) {
			$token = (string) ( $p['refresh_token'] ?? '' );

			return self::mutate(
				static function ( array &$state ) use ( $p, $token, $fail ): array {
					$hash = hash( 'sha256', $token );
					$rec  = $state['tokens'][ $hash ] ?? null;
					if ( ! is_array( $rec ) || 'r' !== $rec['t'] || $rec['e'] < time() || $rec['c'] !== (string) ( $p['client_id'] ?? '' ) || ! isset( $state['clients'][ $rec['c'] ] ) ) {
						return $fail( 'invalid_grant', 'The refresh token is unknown, used or expired.' );
					}
					unset( $state['tokens'][ $hash ] ); // Rotated: the old one stops working.
					$state['clients'][ $rec['c'] ]['last_used'] = time();

					return array( 'ok' => true, 'body' => self::issue( $state, $rec['c'], (int) $rec['u'], self::level_of( $rec ) ) );
				}
			);
		}

		return $fail( 'unsupported_grant_type' );
	}

	/**
	 * @param array<string, mixed> $rec Code or token record.
	 */
	private static function level_of( array $rec ): string {
		return in_array( $rec['l'] ?? '', Api::LEVELS, true ) ? (string) $rec['l'] : 'draft';
	}

	/**
	 * @param array<string, mixed> $state State (by reference).
	 * @return array<string, mixed>
	 */
	private static function issue( array &$state, string $client, int $user, string $level ): array {
		$access  = 'sve_at_' . bin2hex( random_bytes( 32 ) );
		$refresh = 'sve_rt_' . bin2hex( random_bytes( 32 ) );

		$state['tokens'][ hash( 'sha256', $access ) ]  = array( 't' => 'a', 'c' => $client, 'u' => $user, 'l' => $level, 'e' => time() + self::ACCESS_TTL );
		$state['tokens'][ hash( 'sha256', $refresh ) ] = array( 't' => 'r', 'c' => $client, 'u' => $user, 'l' => $level, 'e' => time() + self::REFRESH_TTL );

		return array( 'access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TTL, 'refresh_token' => $refresh, 'scope' => 'mcp' );
	}

	/**
	 * Who an access token belongs to, or null when it is unknown, expired or its app was removed.
	 *
	 * @return array{u: int, c: string, l: string}|null
	 */
	public static function grant( string $token ): ?array {
		if ( ! str_starts_with( $token, 'sve_at_' ) ) {
			return null;
		}
		$state = self::load();
		$rec   = $state['tokens'][ hash( 'sha256', $token ) ] ?? null;
		if ( ! is_array( $rec ) || 'a' !== $rec['t'] || $rec['e'] < time() || ! isset( $state['clients'][ $rec['c'] ] ) ) {
			return null;
		}

		return array( 'u' => (int) $rec['u'], 'c' => (string) $rec['c'], 'l' => self::level_of( $rec ), 'name' => (string) $state['clients'][ $rec['c'] ]['name'] );
	}

	/** Disconnects every app: tokens, codes and registrations. */
	public static function revoke_all(): void {
		self::mutate(
			static function ( array &$state ): void {
				$state = array( 'clients' => array(), 'tokens' => array(), 'codes' => array() );
			}
		);
	}

	/**
	 * Connected apps for the settings page.
	 *
	 * @return array<int, array{name: string, created: int, last_used: int}>
	 */
	public static function summary(): array {
		return array_values( array_map( static fn ( array $c ): array => array_intersect_key( $c, array_flip( array( 'name', 'created', 'last_used' ) ) ), self::load()['clients'] ) );
	}

	/* ------------------------------------------------------------------ HTTP */

	public function hooks(): void {
		add_action( 'init', array( $this, 'route' ), 1 );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
	}

	public static function issuer(): string {
		return untrailingslashit( home_url() );
	}

	/** Where an unauthenticated MCP client learns which server to ask for a token (RFC 9728). */
	public static function resource_metadata_url(): string {
		return esc_url_raw( home_url( '/.well-known/oauth-protected-resource' ) );
	}

	public function rest(): void {
		foreach ( array( 'register' => 'rest_register', 'token' => 'rest_token' ) as $route => $callback ) {
			register_rest_route( 'sve/v1', '/oauth/' . $route, array( 'methods' => 'POST', 'callback' => array( $this, $callback ), 'permission_callback' => '__return_true', 'show_in_index' => false ) );
		}
	}

	private function reply( array $body, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	public function rest_register( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Api::settings()['enabled'] ) {
			return $this->reply( array( 'error' => 'access_denied', 'error_description' => 'AI access is switched off on this site.' ), 403 );
		}
		// A few registrations an hour are normal; floods are not.
		$bucket = 'sve_dcr_' . gmdate( 'YmdH' );
		$count  = (int) get_transient( $bucket );
		if ( $count >= 30 ) {
			return $this->reply( array( 'error' => 'temporarily_unavailable' ), 429 );
		}
		set_transient( $bucket, $count + 1, HOUR_IN_SECONDS );

		$result = self::register( (array) $request->get_json_params() );
		if ( isset( $result['error'] ) ) {
			return $this->reply( array( 'error' => $result['error'], 'error_description' => $result['description'] ), 400 );
		}

		return $this->reply(
			array(
				'client_id'                  => $result['client']['client_id'],
				'client_name'                => $result['client']['name'],
				'redirect_uris'              => $result['client']['uris'],
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
				'client_id_issued_at'        => $result['client']['created'],
			),
			201
		);
	}

	public function rest_token( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Api::settings()['enabled'] ) {
			return $this->reply( array( 'error' => 'temporarily_unavailable' ), 503 );
		}
		if ( null !== Api::throttle( false ) ) {
			return $this->reply( array( 'error' => 'temporarily_unavailable' ), 429 );
		}

		$result = self::exchange( (array) $request->get_params() );
		if ( ! $result['ok'] ) {
			return $this->reply( array( 'error' => $result['error'], 'error_description' => $result['description'] ), 400 );
		}

		return $this->reply( $result['body'], 200 );
	}

	/** Metadata documents and the consent page, answered before WordPress routes the request. */
	public function route(): void {
		$path = (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( str_starts_with( $path, '/.well-known/oauth-authorization-server' ) || str_starts_with( $path, '/.well-known/oauth-protected-resource' ) ) {
			if ( ! Api::settings()['enabled'] ) {
				return;
			}
			$server = str_starts_with( $path, '/.well-known/oauth-authorization-server' );
			$this->json(
				$server
					? array(
						'issuer'                                    => self::issuer(),
						'authorization_endpoint'                    => home_url( '/?sve_oauth=authorize' ),
						'token_endpoint'                            => rest_url( 'sve/v1/oauth/token' ),
						'registration_endpoint'                     => rest_url( 'sve/v1/oauth/register' ),
						'response_types_supported'                  => array( 'code' ),
						'grant_types_supported'                     => array( 'authorization_code', 'refresh_token' ),
						'code_challenge_methods_supported'          => array( 'S256' ),
						'token_endpoint_auth_methods_supported'     => array( 'none' ),
						'scopes_supported'                          => array( 'mcp' ),
						'authorization_response_iss_parameter_supported' => true,
					)
					: array(
						'resource'                 => Api::endpoint(),
						'authorization_servers'    => array( self::issuer() ),
						'bearer_methods_supported' => array( 'header' ),
						'scopes_supported'         => array( 'mcp' ),
					)
			);
		}

		if ( isset( $_GET['sve_oauth'] ) && 'authorize' === $_GET['sve_oauth'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->authorize();
		}
	}

	/**
	 * @param array<string, mixed> $data Document to send.
	 */
	private function json( array $data ): never {
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private function page( string $title, string $body, int $status = 200 ): never {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' ); // The consent page must not sit inside another page.
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $title ) . '</title>'
			. '<style>body{font:16px/1.5 system-ui,sans-serif;background:#f0f0f1;margin:0;display:grid;place-items:center;min-height:100vh}main{background:#fff;max-width:440px;padding:28px;border-radius:8px;box-shadow:0 1px 4px #0002}h1{font-size:20px;margin-top:0}button{font:inherit;padding:8px 18px;border-radius:4px;border:1px solid #2271b1;background:#fff;color:#2271b1;cursor:pointer;margin-right:8px}button.p{background:#2271b1;color:#fff}.s{color:#646970;font-size:14px}</style></head><body><main>'
			. $body . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		exit;
	}

	/**
	 * @param array<string, mixed> $check Result of check_authorize().
	 * @param array<string, string> $params Query for the app.
	 */
	private function back( array $check, array $params ): never {
		if ( '' !== $check['state'] ) {
			$params['state'] = $check['state'];
		}
		$params['iss'] = self::issuer();
		wp_redirect( add_query_arg( $params, $check['uri'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- the address was checked against the app's registered ones.
		exit;
	}

	/** The level the administrator picked on the consent page. */
	private function chosen_level(): string {
		$level = isset( $_POST['sve_level'] ) ? sanitize_key( wp_unslash( (string) $_POST['sve_level'] ) ) : 'draft'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by the caller.

		return in_array( $level, Api::LEVELS, true ) ? $level : 'draft';
	}

	private function authorize(): never {
		if ( ! Api::settings()['enabled'] ) {
			$this->page( 'Not available', '<h1>AI access is switched off</h1><p>An administrator has to switch it on in the AI (MCP) settings first.</p>', 503 );
		}

		$source = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ? $_POST : $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		$q      = array();
		foreach ( array( 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state' ) as $field ) {
			$q[ $field ] = isset( $source[ $field ] ) ? (string) wp_unslash( $source[ $field ] ) : '';
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( add_query_arg( array( 'sve_oauth' => 'authorize' ) + $q, home_url( '/' ) ) ) );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->page( 'Not allowed', '<h1>Administrators only</h1><p>Sign in as an administrator to connect an AI app.</p>', 403 );
		}

		$check = self::check_authorize( $q );
		if ( isset( $check['fatal'] ) ) {
			$this->page( 'Cannot connect', '<h1>Cannot connect</h1><p>' . esc_html( $check['fatal'] ) . '</p>', 400 );
		}

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			check_admin_referer( 'sve_oauth' );
			if ( 'allow' === ( $_POST['sve_decision'] ?? '' ) && ! isset( $check['error'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$this->back( $check, array( 'code' => self::create_code( $check['client_id'], get_current_user_id(), $check['uri'], $check['challenge'], $this->chosen_level() ) ) );
			}
			$this->back( $check, array( 'error' => $check['error'] ?? 'access_denied' ) );
		}
		if ( isset( $check['error'] ) ) {
			$this->back( $check, array( 'error' => $check['error'] ) );
		}

		$options = '';
		foreach ( Api::level_labels() as $value => $label ) {
			$options .= '<option value="' . esc_attr( $value ) . '"' . selected( 'draft', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$hidden = '';
		foreach ( $q as $name => $value ) {
			$hidden .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		$user = wp_get_current_user();

		$this->page(
			__( 'Connect an AI app', 'visual-editor' ),
			'<h1>' . esc_html( sprintf( /* translators: %s: app name */ __( 'Connect “%s”?', 'visual-editor' ), $check['client']['name'] ) ) . '</h1>'
			. '<p>' . esc_html( sprintf( /* translators: 1: site name, 2: user name */ __( 'This app is asking to work on %1$s as %2$s.', 'visual-editor' ), get_bloginfo( 'name' ), $user->display_name ) ) . '</p>'
			. '<p><label for="sve-level">' . esc_html__( 'It will be able to:', 'visual-editor' ) . '</label><br><select id="sve-level" name="sve_level" style="font:inherit;max-width:100%">' . $options . '</select></p>'
			. '<p class="s">' . esc_html( sprintf( /* translators: %s: address */ __( 'After you allow it, you are sent back to %s. You can disconnect apps any time in the AI (MCP) settings.', 'visual-editor' ), (string) wp_parse_url( $check['uri'], PHP_URL_HOST ) ) ) . '</p>'
			. '<form method="post">' . $hidden . wp_nonce_field( 'sve_oauth', '_wpnonce', true, false )
			. '<button class="p" name="sve_decision" value="allow">' . esc_html__( 'Allow', 'visual-editor' ) . '</button>'
			. '<button name="sve_decision" value="deny">' . esc_html__( 'Deny', 'visual-editor' ) . '</button></form>'
		);
	}
}
