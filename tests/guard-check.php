<?php
/**
 * Self-check for Guard and Compiler (CSS the AI can write). Run: php -d zend.assertions=1 -d assert.exception=1 tests/guard-check.php
 *
 * @package VisualEditor
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../src/Guard.php';
require __DIR__ . '/../src/Compiler.php';
require __DIR__ . '/../src/Mcp.php';

use VisualEditor\Compiler;
use VisualEditor\Guard;
use VisualEditor\Mcp;

// Selectors: BEM modifiers are fine, comment and HTML-comment closers are not.
assert( '.btn--primary' === Guard::selector( '.btn--primary' ) );
assert( null === Guard::selector( 'a --> b' ) && null === Guard::selector( 'a /* x */' ) && null === Guard::selector( 'a{}' ) );

// CSS: escaped spellings of blocked keywords are blocked too.
assert( null !== Guard::css( 'a{color:red}' ) );
foreach ( array( '@import "x";', '@\69mport "x";', '@i\mport "x";', 'a{background:u\72l(javascript:1)}', 'a{width:expr\65ssion(1)}' ) as $bad ) {
	assert( null === Guard::css( $bad ), $bad );
}

// Attributes: one shared allowlist.
assert( Guard::attribute( 'fetchpriority' ) && Guard::attribute( 'data-x' ) && ! Guard::attribute( 'onclick' ) && ! Guard::attribute( 'style' ) );

// Compiler: pseudo-classes land on every selector of a list; the keyword is replaced only on its own.
assert( array( 'a', '.b:not(.c, .d)' ) === Compiler::split( 'a, .b:not(.c, .d)' ) );
$edit = array( 'sel' => 'a, .b', 'styles' => array(), 'states' => array( 'hover' => array( 'base' => array( 'color' => 'red' ) ) ), 'css' => 'selector>i{x:y} .selector-list{a:b}' );
assert( 'a:hover,.b:hover{color:red !important}' . "\n" . ':is(a, .b)>i{x:y} .selector-list{a:b}' === Compiler::compile( $edit ) );

// REST bridge: the plugin's own admin API (keys) and application passwords stay out of reach, however the path is spelled.
foreach ( array( '/sve/v1/admin/api/key', 'sve/v1/mcp', '//sve//v1/admin/api', '/SVE/v1/admin/api', '/wp/v2/users/1/application-passwords', '/wp/v2/users/me/application-passwords/abc' ) as $path ) {
	assert( ! Mcp::route_allowed( $path ), $path );
}
foreach ( array( '/wp/v2/posts', '/wc/v3/products/12', '/wp/v2/users/me', '/sveltekit/v1/x' ) as $path ) {
	assert( Mcp::route_allowed( $path ), $path );
}

echo "ok\n";
