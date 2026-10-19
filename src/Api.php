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

	/** Usage stats live apart from the key: touch() runs on every call and must never write the hash back. */
	public const USAGE = 'sve_api_usage';

	public const READS_PER_MINUTE = 120;

	public const WRITES_PER_MINUTE = 30;

	public const WRITES_PER_DAY = 600;

	public const MAX_BODY = 262144;

	private const FAILS_PER_WINDOW = 20;

	/**
	 * @return array{enabled: bool, level: string, hash: string, hint: string, about: string, created: int, last_used: int, calls: int}
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$usage = get_option( self::USAGE, array() );
		$saved = array_merge( is_array( $saved ) ? $saved : array(), is_array( $usage ) ? $usage : array() );

		return array(
			'enabled'   => ! empty( $saved['enabled'] ),
			'level'     => in_array( $saved['level'] ?? '', array( 'read', 'draft', 'live' ), true ) ? (string) $saved['level'] : 'draft',
			'hash'      => (string) ( $saved['hash'] ?? '' ),
			'hint'      => (string) ( $saved['hint'] ?? '' ),
			'about'     => (string) ( $saved['about'] ?? '' ),
			'created'   => (int) ( $saved['created'] ?? 0 ),
			'last_used' => (int) ( $saved['last_used'] ?? 0 ),
			'calls'     => (int) ( $saved['calls'] ?? 0 ),
		);
	}

	/**
	 * @param array<string, mixed> $change Fields to overwrite.
	 */
	private static function write( array $change ): void {
		update_option( self::OPTION, array_diff_key( array_merge( self::settings(), $change ), array_flip( array( 'last_used', 'calls' ) ) ), false );
	}

	public static function endpoint(): string {
		return esc_url_raw( rest_url( 'sve/v1/mcp' ) );
	}

	/**
	 * Name this site gives its MCP server, unique per site ("wp-shop-example-com"), so an AI client that has
	 * several WordPress sites connected keeps their tools apart.
	 */
	public static function server_name(): string {
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( self::site() ) ), '-' );

		return substr( 'wp-' . ( '' !== $slug ? $slug : 'site' ), 0, 48 );
	}

	/** Host (and folder) of this site, repeated in every result so the AI sees which site a change landed on. */
	public static function site(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST ) . rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
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
			'about'    => $s['about'],
			'serverName' => self::server_name(),
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

	public static function configure( bool $enabled, string $level, ?string $about = null ): void {
		self::write(
			array(
				'enabled' => $enabled,
				'level'   => in_array( $level, array( 'read', 'draft', 'live' ), true ) ? $level : 'draft',
			) + ( null === $about ? array() : array( 'about' => substr( sanitize_textarea_field( $about ), 0, 500 ) ) )
		);
	}

	/**
	 * Creates a new key (replacing any old one). The plain key is returned once and never stored.
	 */
	public static function create_key(): string {
		$key = 'sve_' . bin2hex( random_bytes( 24 ) );
		self::write(
			array(
				'enabled' => true,
				'hash'    => self::hash( $key ),
				'hint'    => '…' . substr( $key, -4 ),
				'created' => time(),
			)
		);
		delete_option( self::USAGE );

		return $key;
	}

	public static function revoke(): void {
		self::write( array( 'hash' => '', 'hint' => '', 'enabled' => false ) );
	}

	private static function hash( string $key ): string {
		return hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
	}

	private static function ip(): string {
		// Behind a CDN or proxy REMOTE_ADDR is the proxy for everyone: return the real client address here.
		$ip = (string) apply_filters( 'sve_client_ip', $_SERVER['REMOTE_ADDR'] ?? 'unknown' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		return substr( $ip, 0, 64 );
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

		$header = (string) $request->get_header( 'authorization' );
		$token  = 0 === stripos( $header, 'bearer ' ) ? trim( substr( $header, 7 ) ) : trim( (string) $request->get_header( 'x-sve-key' ) );

		// The right key always gets in: the key is 192 random bits, and a lockout keyed on the IP must not
		// shut out the real client when someone else behind the same address guesses wrong.
		if ( '' !== $token && hash_equals( $settings['hash'], self::hash( $token ) ) ) {
			return true;
		}

		$bucket = 'sve_fail_' . md5( self::ip() );
		$fails  = (int) get_transient( $bucket );
		if ( $fails >= self::FAILS_PER_WINDOW ) {
			return self::limited( 'Too many wrong keys. Try again in a few minutes.', 600 );
		}
		set_transient( $bucket, $fails + 1, 10 * MINUTE_IN_SECONDS );

		return new WP_Error( 'sve_bad_key', 'Missing or wrong API key.', array( 'status' => 401 ) );
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
		update_option( self::USAGE, array( 'last_used' => time(), 'calls' => self::settings()['calls'] + 1 ), false );
	}
}
