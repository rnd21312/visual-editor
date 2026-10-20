<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		Frontend::hooks();
		Seo::hooks();
		Ssr::hooks();
		Builder\Router::hooks();
		EditorScreen::hooks();
		AdminPage::hooks();
		TransferPage::hooks();
		SiteTransfer::hooks();
		( new OAuth() )->hooks();
		add_action( 'init', array( Forms::class, 'register' ) );
		add_action( 'rest_api_init', array( RestController::class, 'register_routes' ) );
		add_action( 'init', array( self::class, 'textdomain' ) );
	}

	public static function textdomain(): void {
		load_plugin_textdomain( 'visual-editor', false, dirname( plugin_basename( SVE_FILE ) ) . '/languages' );
	}

	public static function activate(): void {
		// v0.1 → v1: page keys are migrated on read (Sanitizer::shape); store once so the migration sticks.
		$doc = get_option( Store::OPTION, null );
		if ( is_array( $doc ) && (int) ( $doc['v'] ?? 1 ) < 2 ) {
			update_option( Store::OPTION, Sanitizer::shape( $doc ), false );
		}
		Builder\Router::register();
		flush_rewrite_rules( false );
	}

	public static function deactivate(): void {
		flush_rewrite_rules( false );
	}
}
