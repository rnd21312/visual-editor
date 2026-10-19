<?php
/**
 * The main file must point WordPress at classes that exist (a stale namespace prefix is a fatal on activation).
 * Run: php -d zend.assertions=1 -d assert.exception=1 tests/boot-check.php
 *
 * @package VisualEditor
 */

declare(strict_types=1);

$main = (string) file_get_contents( __DIR__ . '/../visual-editor.php' );
assert( str_contains( $main, "\$prefix = 'VisualEditor\\\\';" ), 'autoloader prefix is VisualEditor\\' );
preg_match_all( '/\\\\([A-Za-z]+(?:\\\\[A-Za-z]+)+)::class/', $main, $m );
assert( array() !== $m[1], 'boot hooks found' );
foreach ( $m[1] as $class ) {
	assert( str_starts_with( $class, 'VisualEditor\\' ), $class );
	$file = __DIR__ . '/../src/' . str_replace( '\\', '/', substr( $class, 13 ) ) . '.php';
	assert( is_readable( $file ), $class . ' has no file' );
}

echo "ok\n";
