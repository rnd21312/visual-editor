<?php
/**
 * Self-check for the front-end explorer (asset parsing, absolute URLs, windows, file path boundary).
 * Run: php -d zend.assertions=1 -d assert.exception=1 tests/explore-check.php
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace {
	define( 'ABSPATH', sys_get_temp_dir() . '/sve-explore/' );
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
	define( 'MB_IN_BYTES', 1048576 );

	function home_url( string $path = '' ): string {
		return 'https://shop.test' . $path;
	}
	function site_url(): string {
		return 'https://shop.test';
	}
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
	function wp_normalize_path( string $path ): string {
		return str_replace( '\\', '/', $path );
	}
	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}
	function get_stylesheet(): string {
		return 'child';
	}
	function get_template(): string {
		return 'parent';
	}
	function wp_remote_get(): \WP_Error {
		return new \WP_Error();
	}
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof \WP_Error;
	}
	class WP_Error {}

	@mkdir( WP_CONTENT_DIR . '/themes/child/css', 0777, true );
	file_put_contents( WP_CONTENT_DIR . '/themes/child/css/app.css', '.ig-app{background:url(../img/bg.png)}' );
	file_put_contents( ABSPATH . 'wp-config.php', '<?php // secret' );

	require __DIR__ . '/../src/Inspector.php';
	require __DIR__ . '/../src/Mcp/Explore.php';
}

namespace {
	use VisualEditor\Mcp\Explore;

	$call = static fn ( string $name, mixed ...$args ): mixed => ( new ReflectionMethod( Explore::class, $name ) )->invoke( null, ...$args );

	assert( 'https://shop.test/wp-content/themes/child/img/bg.png' === $call( 'absolute', '../img/bg.png', 'https://shop.test/wp-content/themes/child/css/app.css' ) );
	assert( 'https://shop.test/a.png' === $call( 'absolute', '/a.png', 'https://shop.test/x/y.css' ) );
	assert( 'https://cdn.test/a.png' === $call( 'absolute', '//cdn.test/a.png', 'https://shop.test/' ) );
	assert( 'data:image/png;base64,x' === $call( 'absolute', 'data:image/png;base64,x', 'https://shop.test/' ) );

	$html   = '<html><head><title>Home</title><link rel="stylesheet" id="app-css" href="/wp-content/themes/child/css/app.css?ver=1" media="all"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Vazirmatn"><style id="x">.hero{color:red}</style></head><body class="home ig"><div class="ig-app"><img src="/wp-content/uploads/a.jpg"></div><script src="/wp-content/plugins/woo/w.js"></script><script>var a=1;</script></body></html>';
	$parsed = $call( 'parse', $html, 'https://shop.test/' );
	assert( 'Home' === $parsed['title'] && 'home ig' === $parsed['bodyClass'] );
	assert( 'theme:child' === $parsed['stylesheets'][0]['from'] && 'external' === $parsed['stylesheets'][1]['from'] );
	assert( 'plugin:woo' === $parsed['scripts'][0]['from'] && 8 === $parsed['scripts'][1]['inline'] );
	assert( array( 'https://shop.test/wp-content/uploads/a.jpg' ) === $parsed['images'] );

	$css = $call( 'css_text', $parsed, '' );
	assert( str_contains( $css['files'][0]['css'], 'url("https://shop.test/wp-content/themes/child/img/bg.png")' ), 'theme CSS is read from disk with absolute urls' );
	assert( str_starts_with( $css['files'][1]['css'], '@import' ) && '.hero{color:red}' === $css['files'][2]['css'] );
	assert( 1 === count( $call( 'css_text', $parsed, 'ig-app' )['files'] ), 'match keeps only files with the class' );

	$window = $call( 'slice', str_repeat( 'ب', 2000 ), array( 'length' => 1001 ) );
	assert( 4000 === $window['total'] && 1001 === $window['next'] && mb_check_encoding( $window['text'], 'UTF-8' ) );

	assert( str_ends_with( wp_normalize_path( $call( 'path', 'themes/child/css/app.css' ) ), 'themes/child/css/app.css' ) );
	foreach ( array( '../wp-config.php', 'themes/../../wp-config.php', 'languages/x.po' ) as $bad ) {
		try {
			$call( 'path', $bad );
			assert( false, $bad );
		} catch ( \RuntimeException $e ) {
			assert( true );
		}
	}

	echo "ok\n";
}
