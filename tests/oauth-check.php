<?php
/**
 * Self-check for the OAuth flow rules (registration, redirect addresses, PKCE, one-time codes, rotating refresh
 * tokens, expiry, disconnect) with a few WordPress functions stubbed.
 * Run: php -d zend.assertions=1 -d assert.exception=1 tests/oauth-check.php
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ );

	$GLOBALS['sve_store'] = array();

	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['sve_store'][ $key ] ?? $default;
	}
	function update_option( string $key, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['sve_store'][ $key ] = $value;
		return true;
	}
	function wp_cache_delete( string $key, string $group = '' ): bool {
		return true;
	}
	function sanitize_text_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}

	$wpdb = new class() {
		public string $prefix = 'wp_';
		public function prepare( string $query, mixed ...$args ): string {
			return $query;
		}
		public function get_var( string $query ): string {
			return '1';
		}
		public function query( string $query ): int {
			return 1;
		}
	};

	require __DIR__ . '/../src/VisualEdits.php';
	require __DIR__ . '/../src/OAuth.php';
}

namespace {
	use VisualEditor\OAuth;

	// Where an app may be sent back to.
	foreach ( array( 'https://claude.ai/api/mcp/auth_callback', 'http://localhost:33418/callback', 'http://127.0.0.1:8080/cb', 'cursor://anysphere.cursor-retrieval/oauth/callback', 'vscode://vscode.github-authentication/did-authenticate' ) as $ok ) {
		assert( OAuth::redirect_uri_ok( $ok ), $ok );
	}
	foreach ( array( 'javascript:alert(1)', 'data:text/html,x', 'http://evil.example/cb', 'https://user:pw@claude.ai/cb', 'https://claude.ai/cb#frag', 'myapp://host/cb', 'https:///nohost', '' ) as $bad ) {
		assert( ! OAuth::redirect_uri_ok( $bad ), $bad );
	}

	// Registration.
	assert( isset( OAuth::register( array( 'redirect_uris' => array( 'http://evil.example/cb' ) ) )['error'] ) );
	assert( isset( OAuth::register( array( 'redirect_uris' => array() ) )['error'] ) );
	$uri    = 'https://claude.ai/api/mcp/auth_callback';
	$client = OAuth::register( array( 'client_name' => '<b>Claude</b>', 'redirect_uris' => array( $uri ) ) )['client'];
	$id     = $client['client_id'];
	assert( 'Claude' === $client['name'] );

	// Authorization request checks: a wrong redirect address is never redirected to; PKCE S256 is required.
	$verifier  = rtrim( strtr( base64_encode( random_bytes( 40 ) ), '+/', '-_' ), '=' );
	$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	$request   = array( 'client_id' => $id, 'redirect_uri' => $uri, 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'xyz' );
	assert( isset( OAuth::check_authorize( array( 'redirect_uri' => 'https://evil.example/' ) + $request )['fatal'] ) );
	assert( isset( OAuth::check_authorize( array( 'client_id' => 'nope' ) + $request )['fatal'] ) );
	assert( 'invalid_request' === OAuth::check_authorize( array( 'code_challenge_method' => 'plain' ) + $request )['error'] );
	assert( 'invalid_request' === OAuth::check_authorize( array( 'code_challenge' => '' ) + $request )['error'] );
	assert( 'unsupported_response_type' === OAuth::check_authorize( array( 'response_type' => 'token' ) + $request )['error'] );
	$check = OAuth::check_authorize( $request );
	assert( ! isset( $check['error'], $check['fatal'] ) && $challenge === $check['challenge'] && 'xyz' === $check['state'] );

	// A code works once, only with the right verifier, redirect address and app.
	$exchange = static fn ( string $code, array $over = array() ): array => OAuth::exchange( $over + array( 'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $uri, 'client_id' => $id ) );
	$code     = OAuth::create_code( $id, 7, $uri, $challenge );
	assert( ! $exchange( $code, array( 'code_verifier' => str_repeat( 'a', 43 ) ) )['ok'], 'wrong verifier' );
	assert( ! $exchange( $code )['ok'], 'a failed attempt still burns the code' );
	$code = OAuth::create_code( $id, 7, $uri, $challenge );
	assert( ! $exchange( $code, array( 'redirect_uri' => 'https://claude.ai/other' ) )['ok'], 'other redirect address' );
	$code = OAuth::create_code( $id, 7, $uri, $challenge );
	assert( ! $exchange( $code, array( 'client_id' => 'sve_c_other' ) )['ok'], 'other app' );
	$code   = OAuth::create_code( $id, 7, $uri, $challenge );
	$tokens = $exchange( $code );
	assert( $tokens['ok'] && 'Bearer' === $tokens['body']['token_type'] && 3600 === $tokens['body']['expires_in'] );
	assert( ! $exchange( $code )['ok'], 'second use of the same code' );

	// The access token identifies the administrator; nothing else does.
	$access  = $tokens['body']['access_token'];
	$refresh = $tokens['body']['refresh_token'];
	assert( array( 'u' => 7, 'c' => $id ) === OAuth::grant( $access ) );
	assert( null === OAuth::grant( $refresh ), 'a refresh token is no access token' );
	assert( null === OAuth::grant( 'sve_at_' . str_repeat( '0', 64 ) ) && null === OAuth::grant( '' ) && null === OAuth::grant( 'sve_probe' ) );
	assert( ! str_contains( (string) json_encode( $GLOBALS['sve_store'] ), $access ), 'only hashes are stored' );

	// Refresh tokens rotate.
	$next = OAuth::exchange( array( 'grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $id ) );
	assert( $next['ok'] && OAuth::grant( $next['body']['access_token'] ) === array( 'u' => 7, 'c' => $id ) );
	assert( ! OAuth::exchange( array( 'grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $id ) )['ok'], 'old refresh token' );
	assert( ! OAuth::exchange( array( 'grant_type' => 'refresh_token', 'refresh_token' => $next['body']['refresh_token'], 'client_id' => 'sve_c_other' ) )['ok'], 'refresh by another app' );
	assert( 'unsupported_grant_type' === OAuth::exchange( array( 'grant_type' => 'password' ) )['error'] );

	// Expiry.
	$state = $GLOBALS['sve_store'][ OAuth::OPTION ];
	foreach ( $state['tokens'] as $hash => $record ) {
		$state['tokens'][ $hash ]['e'] = time() - 1;
	}
	update_option( OAuth::OPTION, $state );
	assert( null === OAuth::grant( $next['body']['access_token'] ), 'expired token' );

	// Disconnect.
	$again = $exchange( OAuth::create_code( $id, 7, $uri, $challenge ) );
	assert( $again['ok'] && null !== OAuth::grant( $again['body']['access_token'] ) );
	assert( 1 === count( OAuth::summary() ) );
	OAuth::revoke_all();
	assert( null === OAuth::grant( $again['body']['access_token'] ) && array() === OAuth::summary() );

	echo "ok\n";
}
