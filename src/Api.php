<?php
/**
 * Keys, permission levels and request limits of the AI (MCP) API.
 *
 * Several keys can exist, each with its own level:
 *  - read  : look only.
 *  - draft : propose changes; they wait in the editor until a person publishes them (default).
 *  - live  : change the site at once (every change keeps a revision); markup is filtered, no scripts.
 *  - admin : full control, like the administrator who created the key: scripts, plugins, settings,
 *            any REST route of WordPress and its plugins.
 * A key acts as the WordPress user who created it. Only a hash of the key is stored.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class Api {

	public const OPTION = 'sve_api';

	public const LEVELS = array( 'read', 'draft', 'live', 'admin' );

	/** Token the settings page's connection test sends: never a valid key. */
	public const PROBE = 'sve_probe';

	/** Usage stats live apart from the keys: touch() runs on every call and must never write a key back. */
	public const USAGE = 'sve_api_usage';

	public const READS_PER_MINUTE = 240;

	public const WRITES_PER_MINUTE = 60;

	public const WRITES_PER_DAY = 3000;

	public const MAX_BODY = 8388608;

	private const FAILS_PER_WINDOW = 30;

	/** @var array<string, mixed>|null Key of the request being served. */
	private static ?array $current = null;

	/**
	 * @return array{enabled: bool, keys: array<int, array<string, mixed>>}
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$usage = get_option( self::USAGE, array() );
		$usage = is_array( $usage ) ? $usage : array();
		$keys  = array();
		foreach ( (array) ( $saved['keys'] ?? array() ) as $key ) {
			if ( is_array( $key ) && ! empty( $key['hash'] ) ) {
				$keys[] = self::key_shape( array_merge( $key, is_array( $usage[ $key['id'] ?? '' ] ?? null ) ? $usage[ $key['id'] ] : array() ) );
			}
		}
		// v1 stored a single key at the top level.
		if ( array() === $keys && ! empty( $saved['hash'] ) ) {
			$keys[] = self::key_shape(
				array(
					'id'    => 'k1',
					'label' => 'AI key',
					'hash'  => $saved['hash'],
					'hint'  => $saved['hint'] ?? '',
					'level' => $saved['level'] ?? 'draft',
				)
			);
		}

		return array(
			'enabled' => ! empty( $saved['enabled'] ),
			'about'   => (string) ( $saved['about'] ?? '' ),
			'keys'    => $keys,
		);
	}

	/**
	 * What each level lets an AI do, for the settings page and the OAuth consent page.
	 *
	 * @return array<string, string>
	 */
	public static function level_labels(): array {
		return array(
			'read'  => __( 'Look only (read)', 'visual-editor' ),
			'draft' => __( 'Propose changes that you approve before they go live (draft)', 'visual-editor' ),
			'live'  => __( 'Edit the site live (revisions are kept)', 'visual-editor' ),
			'admin' => __( 'Full control: everything an administrator can do, including content, users and plugins', 'visual-editor' ),
		);
	}

	/**
	 * Name this site gives its MCP server, unique per site ("wp-shop-example-com"), so an AI client that has
	 * several WordPress sites connected keeps their tools apart.
	 */
	public static function server_name(): string {
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( self::site() ) ), '-' );

		return substr( 'wp-' . ( '' !== $slug ? $slug : 'site' ), 0, 48 );
	}

	/** Host (and folder) of this site, repeated in results so the AI sees which site a change landed on. */
	public static function site(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST ) . rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
	}

	public static function set_about( string $about ): void {
		$s          = self::settings();
		$s['about'] = substr( sanitize_textarea_field( $about ), 0, 500 );
		self::write( $s );
	}

	/**
	 * @param array<string, mixed> $key Stored key.
	 * @return array<string, mixed>
	 */
	private static function key_shape( array $key ): array {
		return array(
			'id'        => (string) ( $key['id'] ?? Sanitizer::new_id() ),
			'label'     => (string) ( $key['label'] ?? 'AI key' ),
			'hash'      => (string) $key['hash'],
			'hint'      => (string) ( $key['hint'] ?? '' ),
			'level'     => in_array( $key['level'] ?? '', self::LEVELS, true ) ? (string) $key['level'] : 'draft',
			'user'      => (int) ( $key['user'] ?? 0 ),
			'created'   => (int) ( $key['created'] ?? 0 ),
			'last_used' => (int) ( $key['last_used'] ?? 0 ),
			'calls'     => (int) ( $key['calls'] ?? 0 ),
		);
	}

	/**
	 * @param array{enabled: bool, about: string, keys: array<int, array<string, mixed>>} $settings Settings.
	 */
	private static function write( array $settings ): void {
		foreach ( $settings['keys'] as $i => $key ) {
			unset( $settings['keys'][ $i ]['last_used'], $settings['keys'][ $i ]['calls'] );
		}
		update_option( self::OPTION, $settings, false );
	}

	public static function endpoint(): string {
		return esc_url_raw( rest_url( 'sve/v1/mcp' ) );
	}

	/**
	 * What the settings screen may show (never key hashes).
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$s = self::settings();

		return array(
			'enabled'  => $s['enabled'],
			'endpoint' => self::endpoint(),
			'about'    => $s['about'],
			'serverName' => self::server_name(),
			'apps'     => OAuth::summary(),
			'keys'     => array_map(
				static function ( array $key ): array {
					$user = get_userdata( $key['user'] );

					return array(
						'id'       => $key['id'],
						'label'    => $key['label'],
						'hint'     => $key['hint'],
						'level'    => $key['level'],
						'user'     => $user ? $user->display_name : '',
						'created'  => $key['created'],
						'lastUsed' => $key['last_used'],
						'calls'    => $key['calls'],
					);
				},
				$s['keys']
			),
			'limits'   => array(
				'readsPerMinute'  => self::READS_PER_MINUTE,
				'writesPerMinute' => self::WRITES_PER_MINUTE,
				'writesPerDay'    => self::WRITES_PER_DAY,
			),
			'live'     => LiveBridge::status(),
		);
	}

	public static function set_enabled( bool $enabled ): void {
		$s            = self::settings();
		$s['enabled'] = $enabled;
		self::write( $s );
	}

	/**
	 * Changes the level or label of a key.
	 */
	public static function update_key( string $id, ?string $level, ?string $label ): bool {
		$s = self::settings();
		foreach ( $s['keys'] as $i => $key ) {
			if ( $key['id'] === $id ) {
				if ( null !== $level && in_array( $level, self::LEVELS, true ) ) {
					$s['keys'][ $i ]['level'] = $level;
				}
				if ( null !== $label && '' !== trim( $label ) ) {
					$s['keys'][ $i ]['label'] = mb_substr( sanitize_text_field( $label ), 0, 60 );
				}
				self::write( $s );

				return true;
			}
		}

		return false;
	}

	/**
	 * Creates a key for the current user. The plain key is returned once and never stored.
	 *
	 * @return array{key: string, id: string}
	 */
	public static function create_key( string $label, string $level ): array {
		$plain     = 'sve_' . bin2hex( random_bytes( 24 ) );
		$s         = self::settings();
		$id        = Sanitizer::new_id();
		$s['keys'][] = self::key_shape(
			array(
				'id'      => $id,
				'label'   => '' !== trim( $label ) ? mb_substr( sanitize_text_field( $label ), 0, 60 ) : 'AI key',
				'hash'    => self::hash( $plain ),
				'hint'    => '…' . substr( $plain, -4 ),
				'level'   => $level,
				'user'    => get_current_user_id(),
				'created' => time(),
			)
		);
		$s['enabled'] = true;
		self::write( $s );

		return array(
			'key' => $plain,
			'id'  => $id,
		);
	}

	public static function revoke( string $id ): void {
		$s         = self::settings();
		$s['keys'] = array_values( array_filter( $s['keys'], static fn ( array $key ): bool => $key['id'] !== $id ) );
		self::write( $s );
		$usage = get_option( self::USAGE, array() );
		if ( is_array( $usage ) && isset( $usage[ $id ] ) ) {
			unset( $usage[ $id ] );
			update_option( self::USAGE, $usage, false );
		}
	}

	private static function hash( string $key ): string {
		return hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
	}

	private static function ip(): string {
		// Behind a CDN or proxy REMOTE_ADDR is the proxy for everyone: return the real client address here.
		return substr( (string) apply_filters( 'sve_client_ip', $_SERVER['REMOTE_ADDR'] ?? 'unknown' ), 0, 64 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	private static function limited( string $message, int $retry ): WP_Error {
		return new WP_Error( 'sve_rate_limited', $message, array( 'status' => 429, 'retry_after' => $retry ) );
	}

	/**
	 * The key a request carries: "Authorization: Bearer …" or "X-SVE-Key: …" (some hosts strip Authorization).
	 */
	private static function token( WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'authorization' );
		if ( '' === $header ) {
			$header = (string) ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		if ( 0 === stripos( $header, 'bearer ' ) ) {
			return trim( substr( $header, 7 ) );
		}

		return trim( (string) $request->get_header( 'x-sve-key' ) );
	}

	/**
	 * Checks the key of a request and, when valid, acts as the user who created it.
	 *
	 * A valid key is never blocked by the failed-attempt counter (otherwise anyone sharing an IP — a proxy, a CDN —
	 * could lock the real client out); wrong keys are throttled per IP.
	 *
	 * @return true|WP_Error
	 */
	public static function authenticate( WP_REST_Request $request ) {
		$settings = self::settings();
		if ( ! $settings['enabled'] || array() === $settings['keys'] ) {
			return new WP_Error( 'sve_api_disabled', 'The AI API is switched off. Turn it on in the dashboard (AI (MCP) page).', array( 'status' => 403 ) );
		}

		$token = self::token( $request );
		if ( '' !== $token ) {
			$hash = self::hash( $token );
			foreach ( $settings['keys'] as $key ) {
				if ( hash_equals( $key['hash'], $hash ) ) {
					self::$current = $key;
					$user          = $key['user'] > 0 ? get_userdata( $key['user'] ) : false;
					if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
						return new WP_Error( 'sve_key_user', 'The user who created this key is no longer an administrator. Create a new key.', array( 'status' => 403 ) );
					}
					wp_set_current_user( $user->ID );
					if ( 'admin' !== $key['level'] ) {
						// Whatever the user may do, markup written through a non-admin key is filtered.
						kses_init_filters();
					}
					return true;
				}
			}
		}

		// An OAuth access token (web AI apps such as Claude.ai): it acts as the administrator who approved the app.
		$grant = '' !== $token ? OAuth::grant( $token ) : null;
		if ( null !== $grant ) {
			$user = $grant['u'] > 0 ? get_userdata( $grant['u'] ) : false;
			if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
				return new WP_Error( 'sve_key_user', 'The user who connected this app is no longer an administrator.', array( 'status' => 403 ) );
			}
			self::$current = array( 'id' => 'oauth:' . $grant['c'], 'label' => $grant['name'], 'level' => $grant['l'], 'user' => $user->ID, 'oauth' => true );
			wp_set_current_user( $user->ID );
			if ( 'admin' !== $grant['l'] ) {
				kses_init_filters();
			}

			return true;
		}

		// The settings page's connection test: proves the header arrives, without counting as a wrong guess.
		if ( self::PROBE === $token ) {
			return new WP_Error( 'sve_bad_key', 'Wrong API key.', array( 'status' => 401 ) );
		}
		if ( '' === $token ) {
			return new WP_Error( 'sve_no_key', 'Missing API key: no Authorization header reached WordPress.', array( 'status' => 401 ) );
		}

		$bucket = 'sve_fail_' . md5( self::ip() );
		$fails  = (int) get_transient( $bucket );
		if ( $fails >= self::FAILS_PER_WINDOW ) {
			return self::limited( 'Too many wrong keys. Try again in a few minutes.', 600 );
		}
		if ( 0 === $fails ) {
			set_transient( $bucket, 1, 10 * MINUTE_IN_SECONDS );
		} else {
			// Keep the window's original expiry: only the counter changes.
			update_option( '_transient_' . $bucket, $fails + 1, false );
			wp_cache_set( $bucket, $fails + 1, 'transient' );
		}

		return new WP_Error( 'sve_bad_key', 'Missing or wrong API key.', array( 'status' => 401 ) );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function current_key(): ?array {
		return self::$current;
	}

	public static function current_level(): string {
		return null === self::$current ? '' : (string) self::$current['level'];
	}

	/**
	 * Whether the current key may do something of a level.
	 */
	public static function allows( string $needed ): bool {
		$rank = array_flip( self::LEVELS );

		return null !== self::$current && $rank[ self::$current['level'] ] >= $rank[ $needed ];
	}

	/**
	 * Per-minute (and, for writes, per-day) counters of a key.
	 */
	public static function throttle( bool $write ): ?WP_Error {
		$key    = null === self::$current ? 'none' : self::$current['id'];
		$minute = (int) floor( time() / 60 );
		$retry  = 60 - ( time() % 60 );
		$name   = 'sve_rl_' . $key . '_' . ( $write ? 'w' : 'r' ) . '_' . $minute;
		$count  = self::bump( $name, 2 * MINUTE_IN_SECONDS );

		if ( $count > ( $write ? self::WRITES_PER_MINUTE : self::READS_PER_MINUTE ) ) {
			return self::limited( 'Slow down: too many requests this minute.', $retry );
		}
		if ( $write && self::bump( 'sve_day_' . $key . '_' . gmdate( 'Ymd' ), DAY_IN_SECONDS ) > self::WRITES_PER_DAY ) {
			return self::limited( 'The daily limit of changes was reached.', 3600 );
		}

		return null;
	}

	/**
	 * Increments a counter (atomic with a persistent object cache).
	 */
	private static function bump( string $name, int $ttl ): int {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $name, 0, 'sve', $ttl );
			$value = wp_cache_incr( $name, 1, 'sve' );

			return false === $value ? 1 : (int) $value;
		}
		$count = (int) get_transient( $name ) + 1;
		set_transient( $name, $count, $ttl );

		return $count;
	}

	/**
	 * Remembers that the current key was used (shown in the settings screen).
	 */
	public static function touch( int $calls ): void {
		if ( null === self::$current || ! empty( self::$current['oauth'] ) ) {
			return;
		}
		$usage = get_option( self::USAGE, array() );
		$usage = is_array( $usage ) ? $usage : array();
		$id    = (string) self::$current['id'];
		$usage[ $id ] = array(
			'calls'     => (int) ( $usage[ $id ]['calls'] ?? 0 ) + $calls,
			'last_used' => time(),
		);
		update_option( self::USAGE, $usage, false );
	}
}
