<?php
/**
 * REST API of the visual editor: the document, the page finder, API-key settings, AI drafts and revisions
 * (all for people who may use the editor), plus the key-protected MCP endpoint for AI assistants.
 *
 * @package Suntourz\VisualEditor
 */

declare(strict_types=1);

namespace Suntourz\VisualEditor;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class RestController {

	private const NS = 'sve/v1';

	public function __construct( private VisualEdits $edits, private Mcp $mcp ) {}

	public function register_routes(): void {
		$editor = static fn (): bool => current_user_can( VisualEdits::capability() );
		$admin  = static fn (): bool => current_user_can( 'manage_options' );

		register_rest_route(
			self::NS,
			'/admin/visual',
			array(
				array( 'methods' => WP_REST_Server::READABLE, 'callback' => fn (): WP_REST_Response => new WP_REST_Response( $this->edits->get() ), 'permission_callback' => $editor ),
				array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( $this, 'save' ), 'permission_callback' => $editor ),
			)
		);

		register_rest_route( self::NS, '/admin/pages', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'pages' ), 'permission_callback' => $editor ) );

		register_rest_route(
			self::NS,
			'/admin/draft',
			array(
				array( 'methods' => WP_REST_Server::READABLE, 'callback' => fn (): WP_REST_Response => new WP_REST_Response( $this->edits->draft() ), 'permission_callback' => $editor ),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => function (): WP_REST_Response {
						$this->edits->set_draft( null );

						return new WP_REST_Response( array( 'discarded' => true ) );
					},
					'permission_callback' => $editor,
				),
			)
		);

		register_rest_route( self::NS, '/admin/revisions', array( 'methods' => WP_REST_Server::READABLE, 'callback' => fn (): WP_REST_Response => new WP_REST_Response( $this->edits->revisions() ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/admin/revisions/(?P<id>\d+)/restore', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'restore' ), 'permission_callback' => $admin ) );

		register_rest_route(
			self::NS,
			'/admin/api',
			array(
				array( 'methods' => WP_REST_Server::READABLE, 'callback' => fn (): WP_REST_Response => new WP_REST_Response( $this->api_status() ), 'permission_callback' => $admin ),
				array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( $this, 'configure_api' ), 'permission_callback' => $admin ),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/api/key',
			array(
				array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'create_key' ), 'permission_callback' => $admin ),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => function (): WP_REST_Response {
						Api::revoke();

						return new WP_REST_Response( $this->api_status() );
					},
					'permission_callback' => $admin,
				),
			)
		);

		// Public URL, protected by the API key inside the callback; hidden from the REST index.
		register_rest_route( self::NS, '/mcp', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'mcp' ), 'permission_callback' => '__return_true', 'show_in_index' => false ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function api_status(): array {
		$draft = $this->edits->draft();

		return Api::status() + array( 'draftChanges' => null === $draft ? 0 : Mcp::difference( $this->edits->get(), $draft ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'sve_bad_request', __( 'Invalid request body.', 'suntourz-visual-editor' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( $this->edits->save( $body ) );
	}

	public function pages( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			Pages::search(
				array(
					'search' => sanitize_text_field( (string) $request->get_param( 'search' ) ),
					'type'   => sanitize_key( (string) $request->get_param( 'type' ) ),
					'page'   => (int) $request->get_param( 'page' ),
				)
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore( WP_REST_Request $request ) {
		$doc = $this->edits->restore( (int) $request['id'] );

		return null === $doc ? new WP_Error( 'sve_no_revision', __( 'That version no longer exists.', 'suntourz-visual-editor' ), array( 'status' => 404 ) ) : new WP_REST_Response( $doc );
	}

	public function configure_api( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) $request->get_json_params();
		Api::configure( ! empty( $body['enabled'] ), (string) ( $body['level'] ?? 'draft' ) );

		return new WP_REST_Response( $this->api_status() );
	}

	public function create_key(): WP_REST_Response {
		$key = Api::create_key();

		return new WP_REST_Response( array( 'key' => $key ) + $this->api_status() );
	}

	/* ------------------------------------------------------------------ MCP */

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function mcp( WP_REST_Request $request ) {
		$auth = Api::authenticate( $request );
		if ( is_wp_error( $auth ) ) {
			return $this->error_response( $auth );
		}

		$body = (string) $request->get_body();
		if ( strlen( $body ) > Api::MAX_BODY ) {
			return $this->json( array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32600, 'message' => 'The request is too large.' ) ), 413 );
		}
		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			return $this->json( array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32700, 'message' => 'Parse error.' ) ), 400 );
		}

		$batch    = array_is_list( $payload );
		$messages = $batch ? array_slice( $payload, 0, 20 ) : array( $payload );
		$replies  = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			$limit = Api::throttle( $this->mcp->is_write( $message ) );
			if ( null !== $limit ) {
				return $this->error_response( $limit );
			}
			$reply = $this->mcp->handle( $message );
			if ( null !== $reply ) {
				$replies[] = $reply;
			}
		}
		Api::touch();

		if ( array() === $replies ) {
			return $this->json( array(), 202 );
		}

		return $this->json( $batch ? $replies : $replies[0], 200 );
	}

	/**
	 * @param array<mixed> $data Response body.
	 */
	private function json( array $data, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( array() === $data ? null : $data, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	private function error_response( WP_Error $error ): WP_REST_Response {
		$data     = (array) $error->get_error_data();
		$response = $this->json( array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32000, 'message' => $error->get_error_message() ) ), (int) ( $data['status'] ?? 400 ) );
		if ( isset( $data['retry_after'] ) ) {
			$response->header( 'Retry-After', (string) (int) $data['retry_after'] );
		}

		return $response;
	}
}
