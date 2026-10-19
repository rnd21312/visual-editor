<?php
/**
 * Clears page caches after a save so visitors see changes at once (WP Rocket, LiteSpeed, W3 Total Cache,
 * WP Super Cache, WP Fastest Cache, SiteGround, Breeze, Kinsta, WP Engine, Cloudflare plugin, Autoptimize…).
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Cache {

	private static bool $scheduled = false;

	/**
	 * Purges once, at the end of the request (several saves in one request purge once).
	 */
	public static function purge(): void {
		if ( self::$scheduled ) {
			return;
		}
		self::$scheduled = true;
		add_action( 'shutdown', array( self::class, 'run' ), 1 );
	}

	public static function run(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
		$calls = array(
			'rocket_clean_domain',
			'w3tc_flush_all',
			'wp_cache_clear_cache',
			'sg_cachepress_purge_cache',
			'wpfc_clear_all_cache',
			'autoptimize_flush_pagecache',
		);
		foreach ( $calls as $fn ) {
			if ( function_exists( $fn ) ) {
				try {
					$fn();
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				}
			}
		}
		do_action( 'litespeed_purge_all' );
		do_action( 'breeze_clear_all_cache' );
		do_action( 'cloudflare_purge_everything' );
		do_action( 'sve_purge_cache' );
		if ( class_exists( '\WpeCommon' ) && method_exists( '\WpeCommon', 'purge_varnish_cache' ) ) {
			\WpeCommon::purge_varnish_cache();
		}
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'sve' );
		}
		// phpcs:enable
	}
}
