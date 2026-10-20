<?php
/**
 * Plugin Name:       Visual Editor
 * Plugin URI:        https://github.com/rnd21312/visual-editor
 * Description:       Visual site builder for any WordPress theme: build and edit pages, posts, products and templates visually, reusable components, React islands, GSAP motion, design tokens, SEO — and a full MCP server so an AI assistant can see and build the site with you.
 * Version:           1.3.0
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

define( 'SVE_VERSION', '1.3.0' );
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
		} elseif ( str_starts_with( $class_name, $prefix . 'Builder\\' ) ) {
			require_once SVE_DIR . 'src/Builder/Data.php'; // Small value classes (SafeHtml, PostNode…) live together.
		}
	}
);

add_action( 'plugins_loaded', array( \VisualEditor\Plugin::class, 'boot' ) );
register_activation_hook( __FILE__, array( \VisualEditor\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \VisualEditor\Plugin::class, 'deactivate' ) );
