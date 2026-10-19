<?php
/**
 * Plugin Name:       Visual Editor
 * Plugin URI:        https://github.com/rnd21312/visual-editor
 * Description:       Click-to-edit visual editor for any WordPress theme: inspect every element, change text, styles and HTML, move and add elements, save components, and manage SEO — without touching theme files.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            theodore-sooske (Telegram)
 * Author URI:        https://t.me/theodore-sooske
 * License:           GPL-2.0-or-later
 * Text Domain:       visual-editor
 *
 * @package VisualEditor
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'SVE_VERSION', '0.1.0' );
define( 'SVE_FILE', __FILE__ );
define( 'SVE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SVE_URL', plugin_dir_url( __FILE__ ) );

// Zero-dependency PSR-4 autoloader so the zip works without Composer.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'VisualEditor\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = SVE_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		$edits = new \VisualEditor\VisualEdits();
		$edits->hooks();
		( new \VisualEditor\EditorScreen( $edits ) )->hooks();
		( new \VisualEditor\AdminPage( $edits ) )->hooks();
		add_action( 'rest_api_init', array( new \VisualEditor\RestController( $edits, new \VisualEditor\Mcp( $edits ) ), 'register_routes' ) );
	}
);
