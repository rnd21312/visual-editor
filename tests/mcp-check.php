<?php
/**
 * Self-check for the MCP server with a few WordPress functions stubbed: tool list size, batched edits (one
 * write, all-or-nothing), short outline ids and the permission level.
 * Run: php -d zend.assertions=1 -d assert.exception=1 tests/mcp-check.php
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'SVE_VERSION', 'test' );
	define( 'MINUTE_IN_SECONDS', 60 );

	$GLOBALS['sve_store'] = array();
	$GLOBALS['sve_trans'] = array();

	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['sve_store'][ $key ] ?? $default;
	}
	function update_option( string $key, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['sve_store'][ $key ] = $value;
		return true;
	}
	function delete_option( string $key ): bool {
		unset( $GLOBALS['sve_store'][ $key ] );
		return true;
	}
	function get_transient( string $key ): mixed {
		return $GLOBALS['sve_trans'][ $key ] ?? false;
	}
	function set_transient( string $key, mixed $value, int $ttl = 0 ): bool {
		$GLOBALS['sve_trans'][ $key ] = $value;
		return true;
	}
	function wp_cache_delete( string $key, string $group = '' ): bool {
		return true;
	}
	function wp_json_encode( mixed $data, int $flags = 0 ): string {
		return (string) json_encode( $data, $flags );
	}
	function wp_generate_password( int $length = 12, bool $special = true ): string {
		return substr( md5( uniqid( '', true ) ), 0, $length );
	}
	function sanitize_text_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}
	function sanitize_textarea_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}
	function wp_kses_post( string $html ): string {
		return strip_tags( $html, '<p><a><b><i><strong><em>' );
	}
	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}
	function wp_check_invalid_utf8( string $text ): string {
		return $text;
	}
	function esc_url_raw( string $url ): string {
		return $url;
	}
	function current_user_can( string $cap ): bool {
		return true;
	}
	function home_url( string $path = '' ): string {
		return 'https://shop.test' . $path;
	}
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
	function get_bloginfo( string $show = '' ): string {
		return 'Shop';
	}
	function wp_salt( string $scheme = '' ): string {
		return 'salt';
	}
	if ( ! function_exists( 'mb_substr' ) ) { // WordPress ships its own fallback.
		function mb_substr( string $text, int $start, ?int $length = null ): string {
			return substr( $text, $start, $length );
		}
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

	require __DIR__ . '/../src/Guard.php';
	require __DIR__ . '/../src/Compiler.php';
	require __DIR__ . '/../src/Api.php';
	require __DIR__ . '/../src/VisualEdits.php';
	require __DIR__ . '/../src/Mcp.php';
}

namespace {
	use VisualEditor\Mcp;
	use VisualEditor\VisualEdits;

	$edits = new VisualEdits();
	$mcp   = new Mcp( $edits );
	$level = static function ( string $level ): void {
		update_option( 'sve_api', array( 'enabled' => true, 'level' => $level, 'hash' => 'h' ) );
	};
	$call  = static function ( string $tool, array $args ) use ( $mcp ): array {
		$reply = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => $tool, 'arguments' => $args ) ) );
		return $reply['result'] ?? $reply;
	};

	$level( 'live' );

	// The tool list stays small: it is sent to the AI at the start of every session.
	$list = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ) )['result']['tools'];
	assert( 5 === count( $list ), 'upload_media is listed only with Full access' );
	assert( strlen( (string) json_encode( $list ) ) < 3600, 'tool list too big' );
	$level( 'full' );
	assert( 6 === count( $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ) )['result']['tools'] ) );
	$level( 'live' );

	// Unknown tools are protocol errors.
	$reply = $mcp->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'nope' ) ) );
	assert( -32602 === $reply['error']['code'] );

	// Several changes, one call, one write.
	$batch = array(
		'url'     => '/about/',
		'changes' => array(
			array( 'op' => 'style', 'selector' => 'h1', 'styles' => array( 'color' => 'red' ) ),
			array( 'op' => 'content', 'selector' => 'h1', 'text' => 'About us' ),
			array( 'op' => 'seo', 'title' => 'About' ),
		),
	);
	$result = $call( 'edit', $batch );
	assert( empty( $result['isError'] ), (string) ( $result['content'][0]['text'] ?? '' ) );
	$doc = $edits->get();
	assert( 1 === count( $doc['edits'] ) && 'About us' === $doc['edits'][0]['text'] && 'red' === $doc['edits'][0]['styles']['base']['color'], 'style and content merge into one edit' );
	assert( 'About' === $doc['seo']['/about/']['title'] );
	assert( ! isset( $GLOBALS['sve_store'][ VisualEdits::REVISIONS ] ), 'first write has nothing to keep' );
	$call( 'edit', array( 'url' => '/about/', 'changes' => array( array( 'op' => 'style', 'selector' => 'h2', 'styles' => array( 'margin' => '0' ) ), array( 'op' => 'remove', 'selector' => 'footer' ) ) ) );
	assert( 1 === count( $GLOBALS['sve_store'][ VisualEdits::REVISIONS ] ), 'a batch makes ONE revision' );

	// All or nothing: a bad change leaves the document as it was.
	$before = $edits->get();
	$result = $call( 'edit', array( 'url' => '/about/', 'changes' => array( array( 'op' => 'style', 'selector' => 'h3', 'styles' => array( 'color' => 'blue' ) ), array( 'op' => 'style', 'selector' => 'a{', 'styles' => array( 'color' => 'blue' ) ) ) ) );
	assert( ! empty( $result['isError'] ) && str_contains( $result['content'][0]['text'], 'change 2' ) && str_contains( $result['content'][0]['text'], 'Nothing was applied' ) );
	assert( $before === $edits->get() );

	// Short ids from the last outline stand for their selectors.
	set_transient( 'sve_outline', array( 'map' => array( 'n3' => '#main > h2:nth-child(1 of :not([data-ve-id]))' ) ) );
	$call( 'edit', array( 'url' => '/about/', 'changes' => array( array( 'op' => 'style', 'selector' => 'n3', 'styles' => array( 'color' => 'green' ) ) ) ) );
	assert( in_array( '#main > h2:nth-child(1 of :not([data-ve-id]))', array_column( $edits->get()['edits'], 'sel' ), true ) );
	$result = $call( 'edit', array( 'url' => '/about/', 'changes' => array( array( 'op' => 'style', 'selector' => 'n99', 'styles' => array( 'color' => 'green' ) ) ) ) );
	assert( ! empty( $result['isError'] ) && str_contains( $result['content'][0]['text'], 'call outline again' ) );

	// Read-only keys cannot edit.
	$level( 'read' );
	$result = $call( 'edit', $batch );
	assert( ! empty( $result['isError'] ) && str_contains( $result['content'][0]['text'], 'read-only' ) );

	echo "ok\n";
}
