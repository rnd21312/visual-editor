<?php
/**
 * Forms built in the editor: any <form data-sve-form="contact"> is submitted by the runtime to this endpoint.
 * Submissions are stored (private post type, visible in the editor and to the AI) and e-mailed to the site.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class Forms {

	public const POST_TYPE = 'sve_submission';

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'        => __( 'Form submissions', 'visual-editor' ),
				'public'       => false,
				'show_ui'      => false,
				'supports'     => array( 'title', 'custom-fields' ),
				'capabilities' => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap' => true,
			)
		);
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function submit( WP_REST_Request $request ) {
		$params = $request->get_body_params();
		if ( array() === $params ) {
			$params = (array) $request->get_json_params();
		}
		$form = substr( sanitize_key( (string) ( $params['_sve_form'] ?? 'form' ) ), 0, 60 );
		if ( ! empty( $params['_sve_hp'] ) ) {
			return array( 'ok' => true ); // Honeypot filled: a bot. Pretend success.
		}
		$ip     = substr( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 0, 64 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$bucket = 'sve_form_' . md5( $ip );
		$count  = (int) get_transient( $bucket );
		if ( $count >= 10 ) {
			return new WP_Error( 'sve_form_limit', __( 'Too many submissions. Please try again later.', 'visual-editor' ), array( 'status' => 429 ) );
		}
		set_transient( $bucket, $count + 1, HOUR_IN_SECONDS );

		$fields = array();
		foreach ( $params as $name => $value ) {
			if ( str_starts_with( (string) $name, '_sve_' ) || count( $fields ) >= 50 ) {
				continue;
			}
			$value                                         = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			$fields[ mb_substr( sanitize_text_field( (string) $name ), 0, 80 ) ] = mb_substr( sanitize_textarea_field( $value ), 0, 5000 );
		}
		if ( array() === array_filter( $fields ) ) {
			return new WP_Error( 'sve_form_empty', __( 'The form is empty.', 'visual-editor' ), array( 'status' => 400 ) );
		}

		$page = esc_url_raw( (string) ( $params['_sve_page'] ?? '' ) );
		$id   = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => $form . ' — ' . wp_date( 'Y-m-d H:i' ),
				'meta_input'  => array(
					'_sve_form'   => $form,
					'_sve_fields' => $fields,
					'_sve_page'   => $page,
				),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$body = '';
		foreach ( $fields as $name => $value ) {
			$body .= $name . ': ' . $value . "\n";
		}
		$reply = '';
		foreach ( $fields as $name => $value ) {
			if ( is_email( $value ) ) {
				$reply = $value;
				break;
			}
		}
		wp_mail(
			(string) apply_filters( 'sve_form_recipient', get_option( 'admin_email' ), $form ),
			/* translators: 1: form name, 2: site name */
			sprintf( __( 'New “%1$s” form submission — %2$s', 'visual-editor' ), $form, get_bloginfo( 'name' ) ),
			$body . "\n" . $page,
			'' !== $reply ? array( 'Reply-To: ' . $reply ) : array()
		);
		do_action( 'sve_form_submitted', $form, $fields, (int) $id );

		return array( 'ok' => true );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function list( string $form = '', int $limit = 50 ): array {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => max( 1, min( 200, $limit ) ),
		);
		if ( '' !== $form ) {
			$args['meta_key']   = '_sve_form'; // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['meta_value'] = $form; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		return array_map(
			static fn ( \WP_Post $p ): array => array(
				'id'     => $p->ID,
				'form'   => (string) get_post_meta( $p->ID, '_sve_form', true ),
				'date'   => $p->post_date,
				'page'   => (string) get_post_meta( $p->ID, '_sve_page', true ),
				'fields' => (array) get_post_meta( $p->ID, '_sve_fields', true ),
			),
			get_posts( $args )
		);
	}
}
