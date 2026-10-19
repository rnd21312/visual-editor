<?php
/**
 * REST API of the visual editor (for people who may use the editor) plus the public form endpoint, React island
 * modules, and the key-protected MCP endpoint for AI assistants.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

use VisualEditor\Builder\Renderer;
use VisualEditor\Builder\Router;
use VisualEditor\Builder\SourceEditor;
use VisualEditor\Mcp\Server;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class RestController {

	private const NS = 'sve/v1';

	public static function register_routes(): void {
		$editor = static fn (): bool => current_user_can( Store::capability() );
		$admin  = static fn (): bool => current_user_can( 'manage_options' );
		$r      = static function ( string $route, array $handlers ): void {
			register_rest_route( self::NS, $route, $handlers );
		};
		$get  = WP_REST_Server::READABLE;
		$put  = WP_REST_Server::EDITABLE;
		$post = WP_REST_Server::CREATABLE;
		$del  = WP_REST_Server::DELETABLE;

		// Editor document.
		$r(
			'/doc',
			array(
				array( 'methods' => $get, 'callback' => static fn (): WP_REST_Response => new WP_REST_Response( Store::get() ), 'permission_callback' => $editor ),
				array( 'methods' => $put, 'callback' => array( self::class, 'save_doc' ), 'permission_callback' => $editor ),
			)
		);
		// Unsaved editor state rendered by the preview.
		$r(
			'/working',
			array(
				array(
					'methods'             => $put,
					'callback'            => static function ( WP_REST_Request $q ): WP_REST_Response {
						$body = (array) $q->get_json_params();
						Store::set_working( is_array( $body['doc'] ?? null ) ? $body['doc'] : null );
						return new WP_REST_Response( array( 'ok' => true ) );
					},
					'permission_callback' => $editor,
				),
				array(
					'methods'             => $del,
					'callback'            => static function (): WP_REST_Response {
						Store::set_working( null );
						return new WP_REST_Response( array( 'ok' => true ) );
					},
					'permission_callback' => $editor,
				),
			)
		);
		$r(
			'/content/(?P<id>\\d+)/working',
			array(
				array(
					'methods'             => $put,
					'callback'            => static function ( WP_REST_Request $q ): mixed {
						if ( ! current_user_can( 'edit_post', (int) $q['id'] ) ) {
							return new WP_Error( 'sve_forbidden', 'You may not edit this content.', array( 'status' => 403 ) );
						}
						$body = (array) $q->get_json_params();
						Router::set_working( (int) $q['id'], isset( $body['source'] ) ? SourceEditor::ids( (string) $body['source'] ) : null, isset( $body['mode'] ) ? (string) $body['mode'] : null, Sanitizer::user_trusted() );
						return new WP_REST_Response( array( 'ok' => true ) );
					},
					'permission_callback' => $editor,
				),
				array(
					'methods'             => $del,
					'callback'            => static function ( WP_REST_Request $q ): WP_REST_Response {
						Router::set_working( (int) $q['id'], null, null, false );
						return new WP_REST_Response( array( 'ok' => true ) );
					},
					'permission_callback' => $editor,
				),
			)
		);
		// Old route of v0.1 editors.
		$r( '/admin/visual', array( array( 'methods' => $get, 'callback' => static fn (): WP_REST_Response => new WP_REST_Response( Store::get() ), 'permission_callback' => $editor ) ) );

		// Content.
		$r(
			'/content',
			array(
				array( 'methods' => $get, 'callback' => static fn ( WP_REST_Request $q ): WP_REST_Response => new WP_REST_Response( Content::list_posts( $q->get_params() ) ), 'permission_callback' => $editor ),
				array( 'methods' => $post, 'callback' => array( self::class, 'save_content' ), 'permission_callback' => $editor ),
			)
		);
		$r(
			'/content/(?P<id>\d+)',
			array(
				array( 'methods' => $get, 'callback' => static fn ( WP_REST_Request $q ): mixed => self::out( Content::get_post( (int) $q['id'] ) ), 'permission_callback' => $editor ),
				array( 'methods' => $put, 'callback' => array( self::class, 'save_content' ), 'permission_callback' => $editor ),
				array( 'methods' => $del, 'callback' => static fn ( WP_REST_Request $q ): mixed => self::out( Content::delete_post( (int) $q['id'], (bool) $q->get_param( 'force' ) ) ), 'permission_callback' => $editor ),
			)
		);
		$r( '/content/(?P<id>\d+)/duplicate', array( array( 'methods' => $post, 'callback' => static fn ( WP_REST_Request $q ): mixed => self::out( Content::duplicate_post( (int) $q['id'] ) ), 'permission_callback' => $editor ) ) );
		$r( '/content/(?P<id>\d+)/draft', array( array( 'methods' => $del, 'callback' => static fn ( WP_REST_Request $q ): WP_REST_Response => new WP_REST_Response( array( 'deleted' => delete_post_meta( (int) $q['id'], '_sve_source_draft' ) ) ), 'permission_callback' => $editor ) ) );
		$r( '/types', array( array( 'methods' => $get, 'callback' => static fn (): WP_REST_Response => new WP_REST_Response( array( 'types' => Content::post_types(), 'taxonomies' => Content::taxonomies() ) ), 'permission_callback' => $editor ) ) );
		$r( '/templates', array( array( 'methods' => $get, 'callback' => static fn (): WP_REST_Response => new WP_REST_Response( array( 'theme' => Templates::listing(), 'builder' => Router::templates() ) ), 'permission_callback' => $editor ) ) );
		$r( '/render', array( array( 'methods' => $post, 'callback' => array( self::class, 'render' ), 'permission_callback' => $editor ) ) );

		// Terms, menus, media.
		$r(
			'/terms',
			array(
				array( 'methods' => $get, 'callback' => static fn ( WP_REST_Request $q ): mixed => self::out( Content::list_terms( (string) $q->get_param( 'taxonomy' ), (string) $q->get_param( 'search' ) ) ), 'permission_callback' => $editor ),
				array( 'methods' => $post, 'callback' => static fn ( WP_REST_Request $q ): mixed => self::out( Content::save_term( (array) $q->get_json_params() ) ), 'permission_callback' => $editor ),
			)
		);
		$r(
			'/menus',
			array(
				array( 'methods' => $get, 'callback' => static fn (): WP_REST_Response => new WP_REST_Response( Content::menus() ), 'permission_callback' => $editor ),
				array( 'methods' => $post, 'callback' => static fn ( WP_REST_Request $q ): mixed => self::out( Content::save_menu( (array) $q->get_json_params() ) ), 'permission_callback' => $editor ),
			)
		);
		$r(
			'/media',
			array(
				array( 'methods' => $get, 'callback' => static fn ( WP_REST_Request $q ): WP_REST_Response => new WP_REST_Response( array( 'items' => Content::list_media( (string) $q->get_param( 'search' ), (int) ( $q->get_param( 'page' ) ?: 1 ), (string) ( $q->get_param( 'type' ) ?: 'image' ) ) ) ), 'permission_callback' => $editor ),
				array( 'methods' => $post, 'callback' => array( self::class, 'upload' ), 'permission_callback' => $editor ),
			)
		);

		// History and AI drafts.
		$r( '/revisions', array( array( 'methods' => $get, 'callback' => static fn (): WP_REST_Response => new WP_REST_Response( Store::revisions() ), 'permission_callback' => $editor ) ) );
		$r( '/revisions/(?P<id>[A-Za-z0-9_-]+)/restore', array( array( 'methods' => $post, 'callback' => array( self::class, 'restore' ), 'permission_callback' => $admin ) ) );
		$r(
			'/draft',
			array(
				array( 'methods' => $get, 'callback' => array( self::class, 'draft' ), 'permission_callback' => $editor ),
				array(
					'methods'             => $del,
					'callback'            => static function (): WP_REST_Response {
						Store::set_draft( null );
						Store::log( 'discard_draft', 'Discarded the AI draft' );
						return new WP_REST_Response( array( 'discarded' => true ) );
					},
					'permission_callback' => $editor,
				),
			)
		);
		$r(
			'/draft/publish',
			array(
				array(
					'methods'             => $post,
					'callback'            => static function (): mixed {
						$doc = Store::publish_draft();
						if ( null === $doc ) {
							return new WP_Error( 'sve_no_draft', 'There is no draft.', array( 'status' => 404 ) );
						}
						Store::log( 'publish_draft', 'Published the AI draft' );
						return new WP_REST_Response( $doc );
					},
					'permission_callback' => $admin,
				),
			)
		);

		// Live collaboration.
		$r( '/live', array( array( 'methods' => $get, 'callback' => array( self::class, 'live' ), 'permission_callback' => $editor ) ) );
		$r(
			'/live/jobs/(?P<id>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => $post,
					'callback'            => static fn ( WP_REST_Request $q ): WP_REST_Response => new WP_REST_Response( array( 'ok' => LiveBridge::complete( (string) $q['id'], (array) $q->get_json_params() ) ) ),
					'permission_callback' => $editor,
				),
			)
		);

		// AI access (administrators).
		$r(
			'/api',
			array(
				array( 'methods' => $get, 'callback' => static fn (): WP_REST_Response => new WP_REST_Response( Api::status() ), 'permission_callback' => $admin ),
				array(
					'methods'             => $put,
					'callback'            => static function ( WP_REST_Request $q ): WP_REST_Response {
						$body = (array) $q->get_json_params();
						Api::set_enabled( ! empty( $body['enabled'] ) );
						if ( isset( $body['about'] ) ) {
							Api::set_about( (string) $body['about'] );
						}
						return new WP_REST_Response( Api::status() );
					},
					'permission_callback' => $admin,
				),
			)
		);
		$r(
			'/api/apps',
			array(
				array(
					'methods'             => $del,
					'callback'            => static function (): WP_REST_Response {
						OAuth::revoke_all();
						return new WP_REST_Response( Api::status() );
					},
					'permission_callback' => $admin,
				),
			)
		);
		$r(
			'/api/keys',
			array(
				array(
					'methods'             => $post,
					'callback'            => static function ( WP_REST_Request $q ): WP_REST_Response {
						$body = (array) $q->get_json_params();
						$key  = Api::create_key( (string) ( $body['label'] ?? '' ), (string) ( $body['level'] ?? 'draft' ) );
						return new WP_REST_Response( $key + Api::status() );
					},
					'permission_callback' => $admin,
				),
			)
		);
		$r(
			'/api/keys/(?P<id>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => $put,
					'callback'            => static function ( WP_REST_Request $q ): WP_REST_Response {
						$body = (array) $q->get_json_params();
						Api::update_key( (string) $q['id'], isset( $body['level'] ) ? (string) $body['level'] : null, isset( $body['label'] ) ? (string) $body['label'] : null );
						return new WP_REST_Response( Api::status() );
					},
					'permission_callback' => $admin,
				),
				array(
					'methods'             => $del,
					'callback'            => static function ( WP_REST_Request $q ): WP_REST_Response {
						Api::revoke( (string) $q['id'] );
						return new WP_REST_Response( Api::status() );
					},
					'permission_callback' => $admin,
				),
			)
		);

		// Site settings and plugins (administrators).
		$r(
			'/settings',
			array(
				array( 'methods' => $get, 'callback' => static fn (): WP_REST_Response => new WP_REST_Response( array( 'options' => Content::options(), 'ssr' => Ssr::available() ) ), 'permission_callback' => $admin ),
				array( 'methods' => $put, 'callback' => static fn ( WP_REST_Request $q ): mixed => self::out( Content::update_options( (array) $q->get_json_params(), false ) ), 'permission_callback' => $admin ),
			)
		);
		$r( '/classes', array( array( 'methods' => $get, 'callback' => array( self::class, 'classes' ), 'permission_callback' => $editor ) ) );
		$r( '/libraries', array( array( 'methods' => $get, 'callback' => static fn (): WP_REST_Response => new WP_REST_Response( array_values( Libraries::catalog() ) ), 'permission_callback' => $editor ) ) );
		$r( '/submissions', array( array( 'methods' => $get, 'callback' => static fn ( WP_REST_Request $q ): WP_REST_Response => new WP_REST_Response( Forms::list( (string) $q->get_param( 'form' ) ) ), 'permission_callback' => $editor ) ) );

		// Public: forms and island modules.
		$r( '/form', array( array( 'methods' => $post, 'callback' => static fn ( WP_REST_Request $q ): mixed => self::out( Forms::submit( $q ) ), 'permission_callback' => '__return_true' ) ) );
		$r(
			'/island/(?P<id>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => $get,
					'callback'            => static function ( WP_REST_Request $q ): void {
						Islands::serve( (string) $q['id'] );
					},
					'permission_callback' => '__return_true',
				),
			)
		);

		// Public URL, protected by the API key inside the callback; hidden from the REST index.
		$r(
			'/mcp',
			array(
				array( 'methods' => $post, 'callback' => array( self::class, 'mcp' ), 'permission_callback' => '__return_true', 'show_in_index' => false ),
				array(
					'methods'             => $get,
					'callback'            => static function (): WP_REST_Response {
						$response = new WP_REST_Response( array( 'error' => 'Use POST (MCP Streamable HTTP, JSON responses).' ), 405 );
						$response->header( 'Allow', 'POST' );
						return $response;
					},
					'permission_callback' => '__return_true',
					'show_in_index'       => false,
				),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	private static function out( mixed $result ) {
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Saves the editor document. A client that started from an outdated revision gets 409 with the current
	 * document, unless it sends its base document too ("merge"), in which case both changes are kept.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_doc( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! is_array( $body['doc'] ?? null ) ) {
			return new WP_Error( 'sve_bad_request', __( 'Invalid request body.', 'visual-editor' ), array( 'status' => 400 ) );
		}
		$result = Store::save(
			$body['doc'],
			Sanitizer::user_trusted(),
			isset( $body['rev'] ) ? (int) $body['rev'] : null,
			is_array( $body['base'] ?? null ) ? $body['base'] : null
		);
		if ( ! $result['ok'] ) {
			return new WP_REST_Response( $result, 409 );
		}
		Store::log( 'save', (string) ( $body['summary'] ?? __( 'Saved in the editor', 'visual-editor' ) ), array( 'rev' => $result['doc']['rev'] ) );

		return new WP_REST_Response( $result );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_content( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		if ( isset( $request['id'] ) ) {
			$body['id'] = (int) $request['id'];
		}
		if ( isset( $body['builder']['source'] ) && is_string( $body['builder']['source'] ) ) {
			$body['builder']['source'] = SourceEditor::ids( $body['builder']['source'] );
		}
		$result = Content::save_post( $body, Sanitizer::user_trusted() );
		if ( is_array( $result ) ) {
			Store::log( isset( $body['id'] ) ? 'update_content' : 'create_content', ( isset( $body['id'] ) ? 'Updated ' : 'Created ' ) . get_the_title( (int) $result['id'] ), array( 'post' => (int) $result['id'] ) );
			$result['post'] = Content::get_post( (int) $result['id'] );
		}

		return self::out( $result );
	}

	public static function render( WP_REST_Request $request ): WP_REST_Response {
		Store::use_working(); // Render with the editor's unsaved components.
		$body = (array) $request->get_json_params();
		$vars = array();
		if ( ! empty( $body['post'] ) ) {
			$post = get_post( (int) $body['post'] );
			if ( $post instanceof \WP_Post ) {
				$vars['post'] = new Builder\PostNode( $post );
			}
		}
		$html = '';
		if ( ! empty( $body['component'] ) ) {
			$component = Renderer::find_component( (string) $body['component'] );
			$html      = null !== $component ? Renderer::component_html( $component, (array) ( $body['props'] ?? array() ), '', 0, true ) : '';
		} else {
			$html = Renderer::render( (string) ( $body['source'] ?? '' ), $vars, Sanitizer::user_trusted() );
		}

		return new WP_REST_Response( array( 'html' => $html ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( ! empty( $files['file'] ) ) {
			if ( ! current_user_can( 'upload_files' ) ) {
				return new WP_Error( 'sve_forbidden', 'You may not upload files.', array( 'status' => 403 ) );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$id = media_handle_upload( 'file', 0 );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			return new WP_REST_Response( array( 'id' => (int) $id, 'url' => (string) wp_get_attachment_url( (int) $id ) ) );
		}

		return self::out( Content::upload( (array) $request->get_json_params() ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function restore( WP_REST_Request $request ) {
		$doc = Store::restore( (string) $request['id'] );
		if ( null === $doc ) {
			return new WP_Error( 'sve_no_revision', __( 'That version no longer exists.', 'visual-editor' ), array( 'status' => 404 ) );
		}
		Store::log( 'restore_revision', 'Restored revision ' . (string) $request['id'] );

		return new WP_REST_Response( $doc );
	}

	public static function draft(): WP_REST_Response {
		$draft = Store::draft();
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'meta_key'       => '_sve_source_draft', // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
			)
		);

		return new WP_REST_Response(
			array(
				'doc'     => $draft,
				'changes' => null !== $draft ? Store::changes( Store::get(), $draft ) : array(),
				'layouts' => array_map( static fn ( int $id ): array => array( 'id' => $id, 'title' => get_the_title( $id ), 'url' => (string) get_permalink( $id ) ), array_map( 'intval', $posts ) ),
			)
		);
	}

	/**
	 * Editor heartbeat: waiting AI jobs, recent activity, current revision and draft size.
	 */
	public static function live( WP_REST_Request $request ): WP_REST_Response {
		$draft = Store::draft();

		return new WP_REST_Response(
			array(
				'jobs'     => LiveBridge::poll( (string) $request->get_param( 'url' ) ),
				'activity' => Store::activity( (float) $request->get_param( 'since' ) ),
				'rev'      => (int) Store::get()['rev'],
				'draft'    => null !== $draft ? Store::difference( Store::get(), $draft ) : 0,
				'now'      => microtime( true ),
			)
		);
	}

	/**
	 * Every class name used in builder layouts (for compiling Tailwind).
	 */
	public static function classes(): WP_REST_Response {
		global $wpdb;
		$rows    = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_sve_source','_sve_source_edit')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$classes = array();
		foreach ( $rows as $value ) {
			$value = maybe_unserialize( $value );
			$text  = is_array( $value ) ? (string) ( $value['source'] ?? '' ) : (string) $value;
			preg_match_all( '/\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $text, $m );
			foreach ( array_merge( $m[1], $m[2] ) as $list ) {
				foreach ( preg_split( '/\s+/', $list ) ?: array() as $class ) {
					if ( '' !== $class && ! str_contains( $class, '{{' ) ) {
						$classes[ $class ] = true;
					}
				}
			}
		}

		return new WP_REST_Response( array( 'classes' => array_keys( $classes ) ) );
	}

	/* ------------------------------------------------------------------ MCP */

	public static function mcp( WP_REST_Request $request ): WP_REST_Response {
		$auth = Api::authenticate( $request );
		if ( is_wp_error( $auth ) ) {
			return self::error_response( $auth );
		}

		$body = (string) $request->get_body();
		if ( strlen( $body ) > Api::MAX_BODY ) {
			return self::json( array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32600, 'message' => 'The request is too large.' ) ), 413 );
		}
		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			return self::json( array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32700, 'message' => 'Parse error.' ) ), 400 );
		}

		$batch    = array_is_list( $payload );
		$messages = $batch ? array_slice( $payload, 0, 20 ) : array( $payload );
		$replies  = array();
		$calls    = 0;

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			$limit = Api::throttle( Server::is_write( $message ) );
			if ( null !== $limit ) {
				if ( array() === $replies ) {
					return self::error_response( $limit );
				}
				// Keep what was already answered; the limited message gets its own error.
				$data      = (array) $limit->get_error_data();
				$replies[] = array( 'jsonrpc' => '2.0', 'id' => $message['id'] ?? null, 'error' => array( 'code' => -32000, 'message' => $limit->get_error_message() . ' Retry in ' . (int) ( $data['retry_after'] ?? 60 ) . 's.' ) );
				continue;
			}
			$reply = Server::handle( $message );
			++$calls;
			if ( null !== $reply ) {
				$replies[] = $reply;
			}
		}
		Api::touch( $calls );

		if ( array() === $replies ) {
			return self::json( array(), 202 );
		}

		return self::json( $batch ? $replies : $replies[0], 200 );
	}

	/**
	 * @param array<mixed> $data Response body.
	 */
	private static function json( array $data, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( array() === $data ? null : $data, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	private static function error_response( WP_Error $error ): WP_REST_Response {
		$data     = (array) $error->get_error_data();
		$response = self::json( array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32000, 'message' => $error->get_error_message() ) ), (int) ( $data['status'] ?? 400 ) );
		if ( isset( $data['retry_after'] ) ) {
			$response->header( 'Retry-After', (string) (int) $data['retry_after'] );
		}
		if ( 401 === (int) ( $data['status'] ?? 0 ) ) {
			// Tells OAuth-capable clients (Claude.ai connectors) where to find the authorization server.
			$response->header( 'WWW-Authenticate', 'Bearer realm="visual-editor", resource_metadata="' . OAuth::resource_metadata_url() . '"' );
		}

		return $response;
	}
}
