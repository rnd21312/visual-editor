<?php
/**
 * Bridge between the AI API and an open editor window: the AI asks for things only a real browser knows
 * (computed styles, element boxes, screenshots, what is visible on mobile), the editor answers within seconds.
 * The same channel lets the AI point at elements or open pages in the person's editor while they work together.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class LiveBridge {

	private const JOBS = 'sve_live_jobs';

	private const SEEN = 'sve_live_seen';

	private const ONLINE_FOR = 25;

	public const TYPES = array( 'inspect', 'screenshot', 'find', 'navigate', 'highlight', 'reload', 'message' );

	/**
	 * @return array{online: bool, seen: int, user: string, url: string}
	 */
	public static function status(): array {
		$seen = self::read( self::SEEN );
		$seen = is_array( $seen ) ? $seen : array();
		$time = (int) ( $seen['time'] ?? 0 );

		return array(
			'online' => time() - $time <= self::ONLINE_FOR,
			'seen'   => $time,
			'user'   => (string) ( $seen['user'] ?? '' ),
			'url'    => (string) ( $seen['url'] ?? '' ),
		);
	}

	/**
	 * Editor heartbeat: remembers the editor is open and returns waiting jobs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function poll( string $url ): array {
		update_option(
			self::SEEN,
			array(
				'time' => time(),
				'user' => wp_get_current_user()->display_name,
				'url'  => esc_url_raw( $url ),
			),
			false
		);
		$jobs = self::jobs();
		$out  = array();
		foreach ( $jobs as $id => $job ) {
			if ( 'pending' === $job['status'] ) {
				$jobs[ $id ]['status'] = 'running';
				$out[]                 = $job;
			}
		}
		if ( array() !== $out ) {
			self::save( $jobs );
		}

		return $out;
	}

	/**
	 * Result of a job sent by the editor.
	 *
	 * @param array<string, mixed> $result Result.
	 */
	public static function complete( string $id, array $result ): bool {
		$jobs = self::jobs();
		if ( ! isset( $jobs[ $id ] ) ) {
			return false;
		}
		$jobs[ $id ]['status'] = 'done';
		$jobs[ $id ]['result'] = $result;
		self::save( $jobs );

		return true;
	}

	/**
	 * Asks the open editor for something and waits for the answer.
	 *
	 * @param array<string, mixed> $args Job arguments.
	 * @return array<string, mixed>|null Result, or null when no editor answered in time.
	 */
	public static function run( string $type, array $args, int $timeout = 25, bool $wait = true ): ?array {
		if ( ! self::status()['online'] ) {
			return null;
		}
		$id          = Sanitizer::new_id();
		$jobs        = self::jobs();
		$jobs[ $id ] = array(
			'id'      => $id,
			'type'    => $type,
			'args'    => $args,
			'status'  => 'pending',
			'created' => time(),
			'by'      => Store::actor(),
		);
		self::save( $jobs );
		if ( ! $wait ) {
			return array( 'queued' => $id );
		}

		$until = microtime( true ) + $timeout;
		while ( microtime( true ) < $until ) {
			usleep( 250000 );
			$job = self::jobs()[ $id ] ?? null;
			if ( null === $job ) {
				return null;
			}
			if ( 'done' === $job['status'] ) {
				$jobs = self::jobs();
				unset( $jobs[ $id ] );
				self::save( $jobs );
				return (array) $job['result'];
			}
		}
		$jobs = self::jobs();
		unset( $jobs[ $id ] );
		self::save( $jobs );

		return null;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function jobs(): array {
		$jobs = self::read( self::JOBS );
		$jobs = is_array( $jobs ) ? $jobs : array();
		// Forget jobs nobody picked up.
		return array_filter( $jobs, static fn ( $job ): bool => is_array( $job ) && time() - (int) ( $job['created'] ?? 0 ) < 120 );
	}

	/**
	 * @param array<string, array<string, mixed>> $jobs Jobs.
	 */
	private static function save( array $jobs ): void {
		update_option( self::JOBS, $jobs, false );
	}

	/**
	 * Reads an option straight from the database (another request changes it while this one waits).
	 */
	private static function read( string $name ): mixed {
		global $wpdb;
		wp_cache_delete( $name, 'options' );
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return null === $value ? null : maybe_unserialize( $value );
	}
}
