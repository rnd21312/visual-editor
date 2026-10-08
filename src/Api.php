<?php
/**
 * Key, permissions and request limits of the AI API. The key is shown once and only a hash is stored. Every
 * call is rate limited per minute and per day, bad keys are throttled per IP, and what the key may do is
 * chosen by an administrator: read only, propose changes for approval (default), or edit live.
 *
 * @package Suntourz\VisualEditor
 */

declare(strict_types=1);

namespace Suntourz\VisualEditor;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class Api {

	public const OPTION = 'sve_api';

	public const READS_PER_MINUTE = 120;

	public const WRITES_PER_MINUTE = 30;

	public const WRITES_PER_DAY = 600;

	public const MAX_BODY = 262144;

	private const FAILS_PER_WINDOW = 20;

	/**
	 * @return array{enabled: bool, level: string, hash: string, hint: string, created: int, last_used: int, calls: int}
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'enabled'   => ! empty( $saved['enabled'] ),
			'level'     => in_array( $saved['level'] ?? '', array( 'read', 'draft', 'live' ), true ) ? (string) $saved['level'] : 'draft',
			'hash'      => (string) ( $saved['hash'] ?? '' ),
			'hint'      => (string) ( $saved['hint'] ?? '' ),
			'created'   => (int) ( $saved['created'] ?? 0 ),
			'last_used' => (int) ( $saved['last_used'] ?? 0 ),
			'calls'     => (int) ( $saved['calls'] ?? 0 ),
		);
	}

	/**
	 * @param array<string, mixed> $change Fields to overwrite.
	 */
	private static function write( array $change ): void {
		update_option( self::OPTION, array_merge( self::settings(), $change ), false );
	}

	public static function endpoint(): string {
		return esc_url_raw( rest_url( 'sve/v1/mcp' ) );
	}

	/**
	 * What the settings screen may show (never the key itself).
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$s = self::settings();

		return array(
			'enabled'  => $s['enabled'],
			'level'    => $s['level'],
			'hasKey'   => '' !== $s['hash'],
			'keyHint'  => $s['hint'],
			'created'  => $s['created'],
			'lastUsed' => $s['last_used'],
			'calls'    => $s['calls'],
			'endpoint' => self::endpoint(),
			'limits'   => array(
				'readsPerMinute'  => self::READS_PER_MINUTE,
				'writesPerMinute' => self::WRITES_PER_MINUTE,
				'writesPerDay'    => self::WRITES_PER_DAY,
			),
		);
	}

	public static function configure( bool $enabled, string $level ): void {
		self::write(
			array(
				'enabled' => $enabled,
				'level'   => in_array( $level, array( 'read', 'draft', 'live' ), true ) ? $level : 'draft',
			)
		);
	}

	/**
	 * Creates a new key (replacing any old one). The plain key is returned once and never stored.
	 */
	public static function create_key(): string {
		$key = 'sve_' . bin2hex( random_bytes( 24 ) );
		self::write(
			array(
				'enabled'   => true,
				'hash'      => self::hash( $key ),
				'hint'      => '…' . substr( $key, -4 ),
				'created'   => time(),
				'last_used' => 0,
				'calls'     => 0,
			)
		);

		return $key;
	}

	public static function revoke(): void {
		self::write( array( 'hash' => '', 'hint' => '', 'enabled' => false ) );
	}

	private static function hash( string $key ): string {
		return hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
	}

	private static function ip(): string {
		return substr( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ), 0, 64 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	private static function limited( string $message, int $retry ): WP_Error {
		return new WP_Error( 'sve_rate_limited', $message, array( 'status' => 429, 'retry_after' => $retry ) );
	}

	/**
	 * Checks the key of a request. Wrong keys are throttled per IP so the key cannot be guessed.
	 *
	 * @return true|WP_Error
	 */
	public static function authenticate( WP_REST_Request $request ) {
		$settings = self::settings();
		if ( ! $settings['enabled'] || '' === $settings['hash'] ) {
			return new WP_Error( 'sve_api_disabled', 'The AI API is switched off.', array( 'status' => 403 ) );
		}

		$bucket = 'sve_fail_' . md5( self::ip() );
		if ( (int) get_transient( $bucket ) >= self::FAILS_PER_WINDOW ) {
			return self::limited( 'Too many wrong keys. Try again in a few minutes.', 600 );
		}

		$header = (string) $request->get_header( 'authorization' );
		$token  = 0 === stripos( $header, 'bearer ' ) ? trim( substr( $header, 7 ) ) : trim( (string) $request->get_header( 'x-sve-key' ) );

		if ( '' === $token || ! hash_equals( $settings['hash'], self::hash( $token ) ) ) {
			set_transient( $bucket, (int) get_transient( $bucket ) + 1, 10 * MINUTE_IN_SECONDS );

			return new WP_Error( 'sve_bad_key', 'Missing or wrong API key.', array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Per-minute (and, for writes, per-day) counters, shared by all callers of the key.
	 *
	 * @return true|WP_Error
	 */
	public static function throttle( bool $write ): ?WP_Error {
		$minute = (int) floor( time() / 60 );
		$retry  = 60 - ( time() % 60 );
		$kind   = $write ? 'w' : 'r';
		$name   = 'sve_rl_' . $kind . '_' . $minute;
		$count  = (int) get_transient( $name ) + 1;
		set_transient( $name, $count, 2 * MINUTE_IN_SECONDS );

		if ( $count > ( $write ? self::WRITES_PER_MINUTE : self::READS_PER_MINUTE ) ) {
			return self::limited( 'Slow down: too many requests this minute.', $retry );
		}

		if ( $write ) {
			$day   = 'sve_day_' . gmdate( 'Ymd' );
			$total = (int) get_transient( $day ) + 1;
			set_transient( $day, $total, DAY_IN_SECONDS );
			if ( $total > self::WRITES_PER_DAY ) {
				return self::limited( 'The daily limit of changes was reached.', 3600 );
			}
		}

		return null;
	}

	/**
	 * Remembers that the key was used (shown in the settings screen).
	 */
	public static function touch(): void {
		$s = self::settings();
		self::write( array( 'last_used' => time(), 'calls' => $s['calls'] + 1 ) );
	}
}
