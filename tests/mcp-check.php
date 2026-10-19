<?php
/**
 * Self-check for the compact MCP surface: three tools, write detection, blocked REST routes.
 * Run: php -d zend.assertions=1 -d assert.exception=1 tests/mcp-check.php
 *
 * @package VisualEditor
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'SVE_VERSION', '1' );
spl_autoload_register(
	static function ( string $class ): void {
		$file = __DIR__ . '/../src/' . str_replace( '\\', '/', substr( $class, 13 ) ) . '.php';
		if ( str_starts_with( $class, 'VisualEditor\\' ) && is_readable( $file ) ) {
			require_once $file;
		}
	}
);
function get_option( string $key, mixed $default = false ): mixed {
	return $default;
}
function __( string $text ): string {
	return $text;
}
class WP_Error {}

use VisualEditor\Mcp\Tools;

$as = static function ( string $level ): void {
	( new ReflectionProperty( VisualEditor\Api::class, 'current' ) )->setValue( null, array( 'level' => $level, 'id' => 'k' ) );
};

// The list sent at every session start stays tiny.
$as( 'admin' );
$list = Tools::listing();
assert( array( 'site', 'do', 'wp' ) === array_column( $list, 'name' ) );
assert( strlen( (string) json_encode( $list ) ) < 3000, 'tools/list must stay small' );
assert( count( Tools::full_listing() ) > 40, 'the full catalogue is still there' );

// Writes are found through the `do` and `wp` wrappers (rate limits and draft logic depend on it).
assert( ! Tools::is_write( 'site' ) && ! Tools::is_write( 'wp', array( 'route' => '/wp/v2/posts' ) ) && ! Tools::is_write( 'wp', array( 'search' => 'x', 'method' => 'POST' ) ) );
assert( Tools::is_write( 'wp', array( 'route' => '/wp/v2/posts', 'method' => 'post' ) ) );
assert( ! Tools::is_write( 'do', array( 'tool' => 'get_page_outline', 'args' => array( 'url' => '/' ) ) ) );
assert( Tools::is_write( 'do', array( 'tool' => 'set_style', 'args' => array() ) ) && ! Tools::is_write( 'do', array( 'tool' => 'set_style', 'help' => true ) ) );

// A batch is a write when any step writes.
assert( Tools::is_write( 'do', array( 'tool' => 'batch', 'args' => array( 'steps' => array( array( 'tool' => 'get_page_outline' ), array( 'tool' => 'set_style' ) ) ) ) ) );
assert( ! Tools::is_write( 'do', array( 'tool' => 'batch', 'args' => array( 'steps' => array( array( 'tool' => 'get_page_outline' ) ) ) ) ) );

// The AI can reach WordPress, but never this plugin's key management or application passwords.
foreach ( array( '/sve/v1/admin/api/keys', 'sve/v1/mcp', '//sve//v1/admin/api', '/SVE/v1/admin/api', '/wp/v2/users/1/application-passwords', '/wp/v2/users/me/application-passwords/abc', '/wp/v2/users/%61pplication-passwords' ) as $path ) {
	assert( ! Tools::route_allowed( $path ), $path );
}
foreach ( array( '/wp/v2/posts', '/wc/v3/products/12', '/wp/v2/users/me', '/sveltekit/v1/x' ) as $path ) {
	assert( Tools::route_allowed( $path ), $path );
}

echo "ok\n";
