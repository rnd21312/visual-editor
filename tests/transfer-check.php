<?php
/**
 * Self-check for the site transfer address rewriting and id mapping.
 * Run: php -d zend.assertions=1 -d assert.exception=1 tests/transfer-check.php
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ );

	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}

	require __DIR__ . '/../src/SiteTransfer.php';
}

namespace {
	use VisualEditor\SiteTransfer;

	$call = static fn ( string $name, mixed ...$args ): mixed => ( new ReflectionMethod( SiteTransfer::class, $name ) )->invoke( null, ...$args );

	assert( 'https://a.test/wp-content/uploads/2026/01/p.jpg' === $call( 'original', 'https://a.test/wp-content/uploads/2026/01/p-300x200.jpg' ) );
	assert( 'https://a.test/wp-content/uploads/p.png' === $call( 'original', 'https://a.test/wp-content/uploads/p-scaled.png' ) );

	$urls = array( 'https://a.test/wp-content/uploads/2026/01/p.jpg' => 'https://b.test/wp-content/uploads/2026/10/p.jpg' );
	$in   = array(
		'html'      => '<a href="https://a.test/about/"><img src="https://a.test/wp-content/uploads/2026/01/p-300x200.jpg"></a><img src="https://a.test/wp-content/uploads/x.png">',
		'elementor' => '[{"settings":{"image":{"url":"https:\/\/a.test\/wp-content\/uploads\/2026\/01\/p.jpg","id":12},"link":"https:\/\/a.test\/shop\/"}}]',
		'other'     => 'https://elsewhere.test/a.png',
		'number'    => 5,
	);
	$out = $call( 'rewrite', $in, $urls, 'https://a.test/wp-content/uploads', 'https://a.test', 'https://b.test' );
	assert( '<a href="https://b.test/about/"><img src="https://b.test/wp-content/uploads/2026/10/p.jpg"></a><img src="https://a.test/wp-content/uploads/x.png">' === $out['html'], $out['html'] );
	assert( str_contains( $out['elementor'], 'https:\/\/b.test\/wp-content\/uploads\/2026\/10\/p.jpg' ) && str_contains( $out['elementor'], 'https:\/\/b.test\/shop\/' ), $out['elementor'] );
	assert( 'https://elsewhere.test/a.png' === $out['other'] && 5 === $out['number'] );

	assert( 40 === $call( 'map_ids', '_thumbnail_id', '12', array( 12 => 40 ), array() ) );
	assert( '' === $call( 'map_ids', '_thumbnail_id', '99', array( 12 => 40 ), array() ) );
	assert( '{"id":40,"x":{"id":7}}' === $call( 'map_ids', '_elementor_data', '{"id":12,"x":{"id":7}}', array( 12 => 40 ), array() ) );
	assert( 9 === $call( 'map_ids', '_elementor_template_id', '3', array(), array( 3 => 9 ) ) );

	echo "ok\n";
}
