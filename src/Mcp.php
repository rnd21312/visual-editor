<?php
/**
 * Model Context Protocol server (JSON-RPC 2.0 over HTTP) so AI assistants can use the visual editor: find
 * pages and templates, read how a page is built, restyle it, change content, insert and remove elements,
 * and manage SEO — all through the same validation as the editor itself.
 *
 * Writes follow the key's level: read-only, "draft" (proposed changes wait for an administrator's approval in
 * the editor — the default) or "live" (applied at once, with a revision to roll back to).
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Mcp {

	private const PROTOCOL = '2025-06-18';

	/** Protocol versions this server can speak; the client's choice wins when it is one of them. */
	private const SUPPORTED = array( '2025-06-18', '2025-03-26', '2024-11-05' );

	private const MAX_EDITS = 500;

	/** Last outline: short ids (n12) to the selectors they stand for. */
	private const OUTLINE = 'sve_outline';

	private const TEMPLATES = 'sve_tpl_cache';

	/** While edit() runs, write() collects changes here so they are applied (and revisioned) once. */
	private ?array $batch = null;

	public function __construct( private VisualEdits $edits ) {}

	/* ------------------------------------------------------------------ JSON-RPC */

	/**
	 * @param array<string, mixed> $message One JSON-RPC message.
	 * @return array<string, mixed>|null Null for notifications.
	 */
	public function handle( array $message ): ?array {
		$id     = $message['id'] ?? null;
		$method = (string) ( $message['method'] ?? '' );
		$params = is_array( $message['params'] ?? null ) ? $message['params'] : array();

		if ( null === $id ) {
			return null; // Notifications (e.g. notifications/initialized) get no reply.
		}

		$reply = static fn ( array $result ): array => array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
		$fail  = static fn ( int $code, string $text ): array => array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $text ) );

		switch ( $method ) {
			case 'initialize':
				return $reply(
					array(
						'protocolVersion' => in_array( $params['protocolVersion'] ?? '', self::SUPPORTED, true ) ? (string) $params['protocolVersion'] : self::PROTOCOL,
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => array( 'name' => Api::server_name(), 'title' => get_bloginfo( 'name' ), 'version' => SVE_VERSION ),
						'instructions'    => $this->instructions(),
					)
				);
			case 'ping':
				return $reply( array() );
			case 'tools/list':
				$full = 'full' === Api::settings()['level'];

				return $reply( array( 'tools' => array_values( array_map( static fn ( array $tool ): array => array_diff_key( $tool, array( 'write' => 1 ) ), array_filter( $this->tools(), static fn ( array $tool ): bool => $full || 'upload_media' !== $tool['name'] ) ) ) ) );
			case 'tools/call':
				if ( ! in_array( (string) ( $params['name'] ?? '' ), array_column( $this->tools(), 'name' ), true ) ) {
					return $fail( -32602, 'Unknown tool: ' . (string) ( $params['name'] ?? '' ) );
				}

				return $reply( $this->call( (string) ( $params['name'] ?? '' ), is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array() ) );
			default:
				return $fail( -32601, 'Method not found: ' . $method );
		}
	}

	/**
	 * Whether the message needs write permission (used for rate limiting before the work is done).
	 *
	 * @param array<string, mixed> $message JSON-RPC message.
	 */
	public function is_write( array $message ): bool {
		if ( 'tools/call' !== ( $message['method'] ?? '' ) ) {
			return false;
		}
		$name = (string) ( $message['params']['name'] ?? '' );
		if ( 'wp' === $name ) {
			return ! in_array( strtoupper( (string) ( $message['params']['arguments']['method'] ?? 'GET' ) ), array( 'GET', 'OPTIONS' ), true );
		}
		foreach ( $this->tools() as $tool ) {
			if ( $tool['name'] === $name ) {
				return ! empty( $tool['write'] );
			}
		}

		return false;
	}

	private function instructions(): string {
		$level = Api::settings()['level'];
		$about = Api::settings()['about'];
		$mode  = array(
			'read'  => 'READ-ONLY.',
			'draft' => 'changes are PROPOSED as a draft the owner approves.',
			'live'  => 'changes go LIVE (each keeps a revision).',
			'full'  => 'FULL ACCESS as administrator through wp: deleting and user changes may be irreversible.',
		);

		return implode(
			"\n",
			array_filter(
				array(
					'ONE WordPress site: "' . get_bloginfo( 'name' ) . '" (' . Api::site() . '). Other WordPress MCP servers are other sites; never mix them up.',
					'' !== $about ? 'About: ' . $about : '',
					'Flow: site, then pages / outline, then edit (put many changes in ONE call). Scopes: page (one URL), template (every item a theme template renders, e.g. all products), site (everywhere). wp reaches the rest of WordPress; call it without path to find routes.',
					'This key: ' . ( $mode[ $level ] ?? $mode['draft'] ),
				)
			)
		);
	}

	/* ------------------------------------------------------------------ tools */

	/**
	 * Few tools with short descriptions: the list is sent to the AI at the start of every session.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function tools(): array {
		$object = static fn ( array $properties, array $required = array() ): array => array(
			'type'                 => 'object',
			'properties'           => (object) $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
		$string = static fn ( string $description = '' ): array => array_filter( array( 'type' => 'string', 'description' => $description ) );

		return array(
			array(
				'name'        => 'site',
				'description' => 'Overview of this site: theme, plugins, post types, templates, components, pending proposals. With url or template, also the changes already made there.',
				'inputSchema' => $object( array( 'url' => $string( 'Page URL or path' ), 'template' => $string( 'Template key from site' ) ) ),
			),
			array(
				'name'        => 'pages',
				'description' => 'Find pages, posts, products… by words. One line each: id, type, title, path.',
				'inputSchema' => $object( array( 'search' => $string(), 'type' => $string( 'Post type, e.g. page, product' ), 'page' => array( 'type' => 'integer' ) ) ),
			),
			array(
				'name'        => 'outline',
				'description' => 'Compact map of a page. Each line starts with an id (n12) that edit accepts as selector.',
				'inputSchema' => $object( array( 'url' => $string( 'Page URL or path' ), 'find' => $string( 'Only lines containing this text' ), 'max_nodes' => array( 'type' => 'integer', 'description' => 'Default 120, max 600' ) ), array( 'url' ) ),
			),
			array(
				'name'        => 'edit',
				'description' => 'Apply one or more changes in a single call (all or nothing). op style: selector, styles{prop:value}, device base|tablet|mobile, state normal|hover|focus|active. css: css, selector?. content: selector, text|html, attrs{}. insert: selector, position before|after|prepend|append, html. remove: selector. seo: title, description, image, canonical, noindex, nofollow, jsonld. component: name, html, css?, kind html|jsonld. discard: alone, drops the proposals. selector = nN id or CSS.',
				'write'       => true,
				'inputSchema' => $object(
					array(
						'scope'    => array( 'type' => 'string', 'enum' => array( 'page', 'template', 'site' ), 'description' => 'Default: page if url, template if template, else site' ),
						'url'      => $string(),
						'template' => $string(),
						'changes'  => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => (object) array( 'op' => array( 'type' => 'string', 'enum' => array( 'style', 'css', 'content', 'insert', 'remove', 'seo', 'component', 'discard' ) ) ),
								'required'   => array( 'op' ),
							),
						),
					),
					array( 'changes' )
				),
			),
			array(
				'name'        => 'wp',
				'description' => 'WordPress REST API as the key\'s administrator: content, media, menus, settings, users, plugins, WooCommerce. GET/OPTIONS at every level, changes need Full access. No path + search = find routes.',
				'inputSchema' => $object(
					array(
						'method' => array( 'type' => 'string', 'enum' => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ) ),
						'path'   => $string( 'e.g. /wp/v2/posts' ),
						'params' => array( 'type' => 'object' ),
						'fields' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Only these fields (saves tokens)' ),
						'search' => $string( 'With no path: word in the route' ),
					)
				),
			),
			array(
				'name'        => 'upload_media',
				'description' => 'Add a file to the media library from a public URL (Full access).',
				'write'       => true,
				'inputSchema' => $object( array( 'url' => $string(), 'title' => $string(), 'alt' => $string(), 'post_id' => array( 'type' => 'integer' ) ), array( 'url' ) ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array{content: array<int, array{type: string, text: string}>, isError?: bool}
	 */
	private function call( string $name, array $args ): array {
		try {
			$write = false;
			foreach ( $this->tools() as $tool ) {
				if ( $tool['name'] === $name ) {
					$write = ! empty( $tool['write'] );
				}
			}
			if ( $write && 'read' === Api::settings()['level'] ) {
				throw new \RuntimeException( 'This key is read-only. An administrator can change it in the editor settings.' );
			}

			$result = match ( $name ) {
				'site'         => $this->site( $args ),
				'pages'        => $this->pages( $args ),
				'outline'      => $this->outline( $args ),
				'edit'         => $this->edit( $args ),
				'wp'           => $this->wp( $args ),
				'upload_media' => $this->upload_media( $args ),
				default        => throw new \RuntimeException( 'Unknown tool: ' . $name ),
			};
			if ( $write ) {
				Api::log( $name . ' ' . (string) wp_json_encode( $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			}

			$text = is_string( $result ) ? $result : (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			return array( 'content' => array( array( 'type' => 'text', 'text' => $text ) ) );
		} catch ( \Throwable $error ) {
			return array( 'content' => array( array( 'type' => 'text', 'text' => $error->getMessage() ) ), 'isError' => true );
		}
	}

	/* ------------------------------------------------------------------ reading */

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function site( array $args ): array {
		$settings  = Api::settings();
		$draft     = $this->edits->draft();
		$templates = get_transient( self::TEMPLATES );
		if ( ! is_array( $templates ) ) {
			$templates = array();
			foreach ( Templates::listing() as $item ) {
				$templates[ $item['key'] ] = wp_make_link_relative( $item['url'] );
			}
			set_transient( self::TEMPLATES, $templates, 10 * MINUTE_IN_SECONDS );
		}
		$plugins = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			$plugins[] = (string) strtok( (string) $file, '/' );
		}

		$out = array_filter(
			array(
				'name'       => get_bloginfo( 'name' ),
				'url'        => home_url( '/' ),
				'about'      => $settings['about'],
				'level'      => $settings['level'],
				'pending'    => null === $draft ? 0 : self::difference( $this->edits->get(), $draft ),
				'theme'      => wp_get_theme()->get( 'Name' ),
				'plugins'    => array_slice( $plugins, 0, 40 ),
				'types'      => Pages::types(),
				'templates'  => $templates,
				'components' => array_map( static fn ( array $component ): string => $component['name'] . ' (' . $component['kind'] . ')', $this->working_doc()['components'] ),
			),
			static fn ( $value ): bool => ! in_array( $value, array( '', array(), 0 ), true )
		);
		if ( isset( $args['url'] ) || isset( $args['template'] ) ) {
			$out['changes'] = $this->changes( $args );
		}

		return $out;
	}

	/**
	 * What was already changed for a page or template (page, template and site-wide), without ids or bookkeeping.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function changes( array $args ): array {
		$doc  = $this->working_doc();
		$page = isset( $args['url'] ) ? VisualEdits::page_key_for_url( (string) $args['url'] ) : null;
		$tpl  = isset( $args['template'] ) ? (string) $args['template'] : '';

		$matches = static fn ( array $item ): bool => 'site' === $item['scope']
			|| ( 'page' === $item['scope'] && null !== $page && $item['page'] === $page )
			|| ( 'template' === $item['scope'] && '' !== $tpl && ( $item['tpl'] ?? '' ) === $tpl );

		return array_filter(
			array(
				'seo'   => array_filter(
					array(
						'page'     => null !== $page ? ( $doc['seo'][ $page ] ?? null ) : null,
						'template' => '' !== $tpl ? ( $doc['seo'][ 'tpl:' . $tpl ] ?? null ) : null,
					)
				),
				'edits' => array_map(
					static fn ( array $edit ): array => array_filter(
						array(
							'scope'    => $edit['scope'],
							'selector' => $edit['sel'],
							'styles'   => $edit['styles'] ?: null,
							'states'   => $edit['states'] ?? null,
							'css'      => '' !== $edit['css'] ? $edit['css'] : null,
							'text'     => $edit['text'] ?? null,
							'html'     => $edit['html'] ?? null,
							'attrs'    => $edit['attrs'] ?? null,
						),
						static fn ( $value ): bool => null !== $value
					),
					array_values( array_filter( $doc['edits'], $matches ) )
				),
				'ops'   => array_map(
					static fn ( array $op ): array => array_diff_key( $op, array( 'id' => 1, 'page' => 1, 'tpl' => 1 ) ),
					array_values( array_filter( $doc['ops'], $matches ) )
				),
			),
			static fn ( $value ): bool => array() !== $value
		);
	}

	/**
	 * One line per page: id, type, title, path.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	private function pages( array $args ): string {
		$found = Pages::search(
			array(
				'search'   => (string) ( $args['search'] ?? '' ),
				'type'     => (string) ( $args['type'] ?? '' ),
				'page'     => (int) ( $args['page'] ?? 1 ),
				'per_page' => 30,
			)
		);

		$lines = array( sprintf( '%d found, page %d of %d', $found['total'], max( 1, (int) ( $args['page'] ?? 1 ) ), $found['pages'] ) );
		foreach ( $found['items'] as $item ) {
			$lines[] = sprintf(
				'%d %s %s %s%s%s',
				$item['id'],
				$item['type'],
				(string) wp_json_encode( $item['title'], JSON_UNESCAPED_UNICODE ),
				wp_make_link_relative( $item['url'] ),
				'publish' === $item['status'] ? '' : ' (' . $item['status'] . ')',
				'' !== $item['role'] ? ' [' . $item['role'] . ']' : ''
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * A page as short lines ("n12 h2.title "Tours""), indented by depth. The ids stand for the long selectors,
	 * which stay on the server (10 minutes, last outline only) and are accepted by edit.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	private function outline( array $args ): string {
		$built = Outline::build( (string) ( $args['url'] ?? '' ), max( 20, min( 600, (int) ( $args['max_nodes'] ?? 120 ) ) ) );
		if ( is_wp_error( $built ) ) {
			throw new \RuntimeException( $built->get_error_message() );
		}

		$find  = strtolower( trim( (string) ( $args['find'] ?? '' ) ) );
		$map   = array();
		$lines = array();
		foreach ( array_values( $built['nodes'] ) as $index => $node ) {
			$id         = 'n' . ( $index + 1 );
			$map[ $id ] = $node['selector'];

			$text  = isset( $node['text'] ) ? ' "' . mb_substr( $node['text'], 0, 60 ) . '"' : '';
			$extra = '';
			foreach ( array( 'href', 'src', 'alt' ) as $attr ) {
				if ( isset( $node[ $attr ] ) ) {
					$extra .= ' ' . $attr . '=' . mb_substr( $node[ $attr ], 0, 60 );
				}
			}
			// Bare wrappers say nothing; they keep their number so the other ids stay stable.
			if ( in_array( $node['tag'], array( 'div', 'span' ), true ) && ! isset( $node['id'] ) && ! isset( $node['classes'] ) && '' === $text . $extra ) {
				continue;
			}

			$line = $id . ' ' . $node['tag'] . ( isset( $node['id'] ) ? '#' . $node['id'] : '' ) . ( isset( $node['classes'] ) ? '.' . implode( '.', array_slice( $node['classes'], 0, 4 ) ) : '' ) . $extra . $text;
			if ( '' !== $find && ! str_contains( strtolower( $line ), $find ) ) {
				continue;
			}
			$lines[] = str_repeat( ' ', min( 6, (int) $node['depth'] ) ) . $line;
		}
		set_transient( self::OUTLINE, array( 'map' => $map ), 10 * MINUTE_IN_SECONDS );

		array_unshift( $lines, '# ' . $built['title'] . ' — ' . count( $built['nodes'] ) . ' elements' . ( $built['truncated'] ? ' (cut off: raise max_nodes or use find)' : '' ) . '. Use the nN ids as selector in edit.' );

		return implode( "\n", $lines );
	}

	/* ------------------------------------------------------------------ writing */

	/**
	 * The document writes start from: the pending draft in "draft" mode, the live one otherwise.
	 *
	 * @return array<string, mixed>
	 */
	private function working_doc(): array {
		$draft = $this->edits->draft();

		return 'draft' === Api::settings()['level'] && null !== $draft ? $draft : $this->edits->get();
	}

	/**
	 * Applies a change to the working document and stores it where the key's level says.
	 *
	 * @param callable(array<string, mixed>): void $change Receives the document by reference.
	 * @return array<string, mixed>
	 */
	private function write( callable $change ): array {
		if ( null !== $this->batch ) {
			$this->batch[] = $change; // edit() applies them all at once.

			return array();
		}

		return $this->edits->locked(
			function () use ( $change ): array {
				$doc = $this->working_doc();
				$change( $doc );

				if ( count( $doc['edits'] ) > self::MAX_EDITS || count( $doc['ops'] ) > self::MAX_EDITS ) {
					throw new \RuntimeException( 'Too many changes on this site already. Remove some first.' );
				}

				// Trusted: the document's existing markup came from administrators, and the AI's own markup was
				// filtered with self::markup() before it got here. Re-filtering would strip their embeds.
				$clean = $this->edits->sanitize( $doc, true );
				if ( in_array( Api::settings()['level'], array( 'live', 'full' ), true ) ) {
					$this->edits->store( $clean );

					return array( 'site' => Api::site(), 'applied' => 'live', 'revision' => true );
				}

				$this->edits->set_draft( $clean );

				return array(
					'site'    => Api::site(),
					'applied' => 'draft',
					'pending' => self::difference( $this->edits->get(), $clean ),
				);
			}
		);
	}

	/** Markup from the AI: never scripts, event handlers or anything else posts may not contain. */
	private static function markup( mixed $value ): string {
		return wp_kses_post( is_string( $value ) ? $value : '' );
	}

	/**
	 * Where a change goes: page (one URL), template (every item of a theme template) or site.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array{scope: string, page: string, tpl: string}
	 */
	private function target( array $args ): array {
		$scope = (string) ( $args['scope'] ?? '' );
		if ( ! in_array( $scope, array( 'page', 'template', 'site' ), true ) ) {
			$scope = isset( $args['template'] ) ? 'template' : ( isset( $args['url'] ) ? 'page' : 'site' );
		}

		$page = '/';
		$tpl  = '';
		if ( 'page' === $scope ) {
			$key = VisualEdits::page_key_for_url( (string) ( $args['url'] ?? '' ) );
			if ( null === $key ) {
				throw new \RuntimeException( 'scope "page" needs the url of a page of this site.' );
			}
			$page = $key;
		} elseif ( 'template' === $scope ) {
			$tpl = (string) preg_replace( '/[^A-Za-z0-9:_\-.\/]/', '', (string) ( $args['template'] ?? '' ) );
			if ( '' === $tpl ) {
				throw new \RuntimeException( 'scope "template" needs a template key (see site).' );
			}
		}

		return array( 'scope' => $scope, 'page' => $page, 'tpl' => $tpl );
	}

	private function selector( mixed $value ): string {
		$key = is_string( $value ) ? trim( $value ) : '';
		if ( 1 === preg_match( '/^n\d+$/', $key ) ) {
			$saved = get_transient( self::OUTLINE );
			if ( ! is_array( $saved ) || ! isset( $saved['map'][ $key ] ) ) {
				throw new \RuntimeException( 'Unknown id ' . $key . ': call outline again (ids last 10 minutes and belong to the last outline).' );
			}

			return (string) $saved['map'][ $key ];
		}

		$sel = Guard::selector( $value );
		if ( null === $sel ) {
			throw new \RuntimeException( 'That selector is not allowed. Use plain CSS selectors without braces, semicolons or comments.' );
		}

		return $sel;
	}

	/**
	 * Finds the edit for (selector, target) or starts one, applies `$fill`, and compiles its CSS.
	 *
	 * @param array<string, mixed>               $doc    Document (by reference).
	 * @param array{scope: string, page: string, tpl: string} $target Where it applies.
	 * @param callable(array<string, mixed>): void $fill   Receives the edit by reference.
	 */
	private function upsert( array &$doc, array $target, string $sel, callable $fill, string $label = '' ): void {
		foreach ( $doc['edits'] as $index => $edit ) {
			if ( $edit['sel'] === $sel && $edit['scope'] === $target['scope'] && ( 'page' !== $target['scope'] || $edit['page'] === $target['page'] ) && ( 'template' !== $target['scope'] || $edit['tpl'] === $target['tpl'] ) ) {
				$fill( $edit );
				$edit['compiled']      = Compiler::compile( $edit );
				$doc['edits'][ $index ] = $edit;

				return;
			}
		}

		$edit = array(
			'id'     => wp_generate_password( 12, false ),
			'scope'  => $target['scope'],
			'page'   => $target['page'],
			'tpl'    => $target['tpl'],
			'sel'    => $sel,
			'label'  => '' !== $label ? $label : $sel,
			'styles' => array(),
			'css'    => '',
		);
		$fill( $edit );
		$edit['compiled'] = Compiler::compile( $edit );
		$doc['edits'][]   = $edit;
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function set_style( array $args ): array {
		$sel    = $this->selector( $args['selector'] ?? '' );
		$target = $this->target( $args );
		$device = in_array( $args['device'] ?? '', array( 'tablet', 'mobile' ), true ) ? (string) $args['device'] : 'base';
		$state  = in_array( $args['state'] ?? '', array( 'hover', 'focus', 'active' ), true ) ? (string) $args['state'] : 'normal';

		$styles = array();
		foreach ( (array) ( $args['styles'] ?? array() ) as $prop => $value ) {
			$prop = Guard::property( $prop );
			if ( null === $prop ) {
				throw new \RuntimeException( 'Unsupported CSS property name.' );
			}
			if ( '' === trim( (string) $value ) ) {
				$styles[ $prop ] = '';
				continue;
			}
			$clean = Guard::value( $value );
			if ( null === $clean ) {
				throw new \RuntimeException( 'The value for "' . $prop . '" is not allowed.' );
			}
			$styles[ $prop ] = $clean;
		}
		if ( array() === $styles ) {
			throw new \RuntimeException( 'styles is empty.' );
		}

		return $this->write(
			function ( array &$doc ) use ( $sel, $target, $device, $state, $styles ): void {
				$this->upsert(
					$doc,
					$target,
					$sel,
					static function ( array &$edit ) use ( $device, $state, $styles ): void {
						$bag = 'normal' === $state ? ( $edit['styles'][ $device ] ?? array() ) : ( $edit['states'][ $state ][ $device ] ?? array() );
						foreach ( $styles as $prop => $value ) {
							if ( '' === $value ) {
								unset( $bag[ $prop ] );
							} else {
								$bag[ $prop ] = $value;
							}
						}
						if ( 'normal' === $state ) {
							$edit['styles'][ $device ] = $bag;
						} else {
							$edit['states'][ $state ][ $device ] = $bag;
						}
					}
				);
			}
		);
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function add_css( array $args ): array {
		$css = Guard::css( $args['css'] ?? '' );
		if ( null === $css ) {
			throw new \RuntimeException( 'That CSS is not allowed (no @import, scripts or markup; at most 20,000 characters).' );
		}
		$target = $this->target( $args );
		$sel    = isset( $args['selector'] ) ? $this->selector( $args['selector'] ) : 'html';

		return $this->write(
			function ( array &$doc ) use ( $css, $target, $sel ): void {
				if ( 'site' === $target['scope'] && 'html' === $sel ) {
					$doc['css'] = trim( $doc['css'] . "\n" . $css );

					return;
				}
				$this->upsert(
					$doc,
					$target,
					$sel,
					static function ( array &$edit ) use ( $css ): void {
						$edit['css'] = trim( $edit['css'] . "\n" . $css );
					},
					'Custom CSS'
				);
			}
		);
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function set_content( array $args ): array {
		$sel    = $this->selector( $args['selector'] ?? '' );
		$target = $this->target( $args );

		$attrs = array();
		foreach ( (array) ( $args['attrs'] ?? array() ) as $name => $value ) {
			if ( ! Guard::attribute( (string) $name ) ) {
				throw new \RuntimeException( 'Attribute "' . $name . '" is not allowed.' );
			}
			$attrs[ strtolower( (string) $name ) ] = null === $value ? null : (string) $value;
		}
		$text = isset( $args['text'] ) ? (string) $args['text'] : null;
		$html = isset( $args['html'] ) ? self::markup( $args['html'] ) : null;
		if ( null === $text && null === $html && array() === $attrs ) {
			throw new \RuntimeException( 'Give text, html or attrs.' );
		}

		return $this->write(
			function ( array &$doc ) use ( $sel, $target, $text, $html, $attrs ): void {
				$this->upsert(
					$doc,
					$target,
					$sel,
					static function ( array &$edit ) use ( $text, $html, $attrs ): void {
						if ( null !== $html ) {
							$edit['html'] = $html;
							unset( $edit['text'] );
						} elseif ( null !== $text ) {
							$edit['text'] = $text;
							unset( $edit['html'] );
						}
						if ( array() !== $attrs ) {
							$edit['attrs'] = array_merge( (array) ( $edit['attrs'] ?? array() ), $attrs );
						}
					}
				);
			}
		);
	}

	/**
	 * @param array<string, mixed> $op Operation fields.
	 */
	private function push_op( array $op, array $target ): array {
		return $this->write(
			static function ( array &$doc ) use ( $op, $target ): void {
				$doc['ops'][] = array_merge(
					array(
						'id'    => wp_generate_password( 12, false ),
						'scope' => $target['scope'],
						'page'  => $target['page'],
						'tpl'   => $target['tpl'],
					),
					$op
				);
			}
		);
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function insert_html( array $args ): array {
		$position = (string) ( $args['position'] ?? '' );
		if ( ! in_array( $position, array( 'before', 'after', 'prepend', 'append' ), true ) ) {
			throw new \RuntimeException( 'position must be before, after, prepend or append.' );
		}
		$html = self::markup( $args['html'] ?? '' );
		if ( '' === trim( $html ) || strlen( $html ) > 50000 ) {
			throw new \RuntimeException( 'html is empty or too long.' );
		}

		return $this->push_op(
			array(
				'kind'  => 'insert',
				'sel'   => $this->selector( $args['selector'] ?? '' ),
				'pos'   => $position,
				'html'  => $html,
				'label' => 'AI: ' . mb_substr( wp_strip_all_tags( $html ), 0, 40 ),
			),
			$this->target( $args )
		);
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function remove_element( array $args ): array {
		$sel = $this->selector( $args['selector'] ?? '' );

		return $this->push_op( array( 'kind' => 'remove', 'sel' => $sel, 'label' => 'AI: remove ' . $sel ), $this->target( $args ) );
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function set_seo( array $args ): array {
		if ( isset( $args['template'] ) ) {
			$tpl = (string) preg_replace( '/[^A-Za-z0-9:_\-.\/]/', '', (string) $args['template'] );
			if ( '' === $tpl ) {
				throw new \RuntimeException( 'template is not a valid template key (see site).' );
			}
			$key = 'tpl:' . $tpl;
		} else {
			$page = VisualEdits::page_key_for_url( (string) ( $args['url'] ?? '' ) );
			if ( null === $page ) {
				throw new \RuntimeException( 'Give the url of a page of this site, or a template key.' );
			}
			$key = $page;
		}

		$fields = array_intersect_key( $args, array_flip( array( 'title', 'description', 'image', 'canonical', 'noindex', 'nofollow', 'jsonld' ) ) );
		if ( array() === $fields ) {
			throw new \RuntimeException( 'Give at least one SEO field.' );
		}
		if ( isset( $fields['jsonld'] ) && '' !== trim( (string) $fields['jsonld'] ) && ! is_array( json_decode( (string) $fields['jsonld'], true ) ) ) {
			throw new \RuntimeException( 'jsonld is not valid JSON.' );
		}

		return $this->write(
			static function ( array &$doc ) use ( $key, $fields ): void {
				$current = (array) ( $doc['seo'][ $key ] ?? array() );
				foreach ( $fields as $name => $value ) {
					if ( '' === $value || false === $value || null === $value ) {
						unset( $current[ $name ] );
					} else {
						$current[ $name ] = $value;
					}
				}
				$doc['seo'][ $key ] = $current;
			}
		);
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function save_component( array $args ): array {
		$name = trim( (string) ( $args['name'] ?? '' ) );
		$html = self::markup( $args['html'] ?? '' );
		if ( '' === $name || '' === trim( $html ) ) {
			throw new \RuntimeException( 'name and html are required.' );
		}
		$css = isset( $args['css'] ) ? Guard::css( $args['css'] ) : '';
		if ( null === $css ) {
			throw new \RuntimeException( 'That CSS is not allowed.' );
		}
		$kind = 'jsonld' === ( $args['kind'] ?? '' ) ? 'jsonld' : 'html'; // The API never creates "embed" components: they run scripts.

		return $this->write(
			static function ( array &$doc ) use ( $name, $html, $css, $kind ): void {
				foreach ( $doc['components'] as $index => $component ) {
					if ( strtolower( $component['name'] ) === strtolower( $name ) ) {
						$doc['components'][ $index ] = array_merge( $component, array( 'html' => $html, 'css' => $css, 'kind' => $kind ) );

						return;
					}
				}
				$doc['components'][] = array( 'id' => wp_generate_password( 12, false ), 'name' => $name, 'html' => $html, 'css' => $css, 'kind' => $kind );
			}
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function discard_draft(): array {
		$this->edits->set_draft( null );

		return array( 'discarded' => true );
	}

	/**
	 * Several changes in one call: all are checked first, then applied together (one lock, one revision).
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function edit( array $args ): array {
		$changes = is_array( $args['changes'] ?? null ) ? array_values( $args['changes'] ) : array();
		if ( array() === $changes || count( $changes ) > 40 ) {
			throw new \RuntimeException( 'Give 1 to 40 changes.' );
		}
		$ops = array(
			'style'     => 'set_style',
			'css'       => 'add_css',
			'content'   => 'set_content',
			'insert'    => 'insert_html',
			'remove'    => 'remove_element',
			'seo'       => 'set_seo',
			'component' => 'save_component',
		);
		if ( 'discard' === ( $changes[0]['op'] ?? '' ) ) {
			if ( count( $changes ) > 1 ) {
				throw new \RuntimeException( 'discard must be the only change.' );
			}

			return $this->discard_draft();
		}

		$top         = array_intersect_key( $args, array_flip( array( 'scope', 'url', 'template' ) ) );
		$this->batch = array();
		try {
			foreach ( $changes as $index => $change ) {
				$op = is_array( $change ) ? (string) ( $change['op'] ?? '' ) : '';
				if ( ! isset( $ops[ $op ] ) ) {
					throw new \RuntimeException( 'change ' . ( $index + 1 ) . ': op must be ' . implode( ', ', array_keys( $ops ) ) . ' (or discard, alone).' );
				}
				try {
					$this->{$ops[ $op ]}( $change + $top );
				} catch ( \RuntimeException $error ) {
					throw new \RuntimeException( 'change ' . ( $index + 1 ) . ' (' . $op . '): ' . $error->getMessage() . ' Nothing was applied.' );
				}
			}
			$queued = $this->batch;
		} finally {
			$this->batch = null;
		}

		return $this->write(
			static function ( array &$doc ) use ( $queued ): void {
				foreach ( $queued as $apply ) {
					$apply( $doc );
				}
			}
		) + array( 'changes' => count( $queued ) );
	}

	/* ------------------------------------------------------------------ REST bridge (Full access) */

	private function require_full(): void {
		if ( 'full' !== Api::settings()['level'] ) {
			throw new \RuntimeException( 'This needs the "Full access" level. An administrator can change it in the editor settings.' );
		}
	}

	/** Routes AI keys never reach: this plugin's own admin API (keys!) and per-user application passwords (they outlive the key). */
	public static function route_allowed( string $path ): bool {
		$path = '/' . trim( (string) preg_replace( '#/+#', '/', $path ), '/' );

		return 1 !== preg_match( '#^/sve/v1(/|$)|/application-passwords(/|$)#i', $path );
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function wp( array $args ): array {
		if ( '' === trim( (string) ( $args['path'] ?? '' ) ) ) {
			return $this->routes( $args );
		}
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ), true ) ) {
			throw new \RuntimeException( 'Unknown method.' );
		}
		$read = in_array( $method, array( 'GET', 'OPTIONS' ), true );
		if ( ! $read ) {
			$this->require_full();
		}

		$parts = (array) wp_parse_url( (string) ( $args['path'] ?? '' ) );
		$path  = '/' . trim( (string) preg_replace( '#^/?wp-json#', '', (string) ( $parts['path'] ?? '' ) ), '/' );
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		if ( '/' === $path || ! self::route_allowed( $path ) ) {
			throw new \RuntimeException( 'That route is not available to AI keys.' );
		}

		$params  = is_array( $args['params'] ?? null ) ? $args['params'] : array();
		$request = new \WP_REST_Request( $method, $path );
		$request->set_query_params( $read ? $query + $params : $query );
		if ( ! $read ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}
		if ( is_array( $args['fields'] ?? null ) && array() !== $args['fields'] ) {
			$request->set_param( '_fields', implode( ',', array_map( 'strval', $args['fields'] ) ) );
		}

		if ( ! $read ) {
			Api::log( 'wp ' . $method . ' ' . $path . ' ' . mb_substr( (string) wp_json_encode( $params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 0, 150 ) );
		}
		$response = rest_do_request( $request );
		$data     = self::strip_links( rest_get_server()->response_to_data( $response, false ) );
		if ( $response->is_error() ) {
			throw new \RuntimeException( ( is_array( $data ) ? (string) ( $data['code'] ?? 'error' ) . ': ' . (string) ( $data['message'] ?? '' ) : 'error' ) . ' (HTTP ' . $response->get_status() . ')' );
		}

		$json = (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( $json ) > 40000 ) {
			return array( 'truncated' => true, 'bytes' => strlen( $json ), 'hint' => 'Too large: use fields, per_page, or a narrower path.', 'head' => substr( $json, 0, 6000 ) );
		}
		$headers = $response->get_headers();

		return array_filter(
			array(
				'status' => $response->get_status(),
				'total'  => isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : null,
				'pages'  => isset( $headers['X-WP-TotalPages'] ) ? (int) $headers['X-WP-TotalPages'] : null,
				'data'   => $data,
			),
			static fn ( $value ): bool => null !== $value
		);
	}

	/** REST responses carry _links on every object: they cost tokens and say nothing new. */
	private static function strip_links( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		unset( $value['_links'], $value['_embedded'] );

		return array_map( array( self::class, 'strip_links' ), $value );
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function routes( array $args ): array {
		$needle = strtolower( (string) ( $args['search'] ?? '' ) );
		$out    = array();
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( '/' === $route || ! self::route_allowed( $route ) || ( '' !== $needle && ! str_contains( strtolower( $route ), $needle ) ) ) {
				continue;
			}
			$methods = array();
			foreach ( $handlers as $handler ) {
				$methods += array_fill_keys( array_keys( (array) ( $handler['methods'] ?? array() ) ), true );
			}
			$out[] = implode( ',', array_keys( $methods ) ) . ' ' . $route;
			if ( count( $out ) >= 80 ) {
				break;
			}
		}

		return array( 'routes' => $out, 'note' => count( $out ) >= 80 ? 'First 80 shown: add a more specific search.' : 'Call a route with wp; OPTIONS on it lists its arguments.' );
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function upload_media( array $args ): array {
		$this->require_full();
		$url = esc_url_raw( (string) ( $args['url'] ?? '' ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) || ! current_user_can( 'upload_files' ) ) {
			throw new \RuntimeException( 'Give a public URL (and the key\'s user must be allowed to upload).' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 ); // Refuses private addresses like wp_safe_remote_get.
		if ( is_wp_error( $tmp ) ) {
			throw new \RuntimeException( $tmp->get_error_message() );
		}
		$name = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$id   = media_handle_sideload( array( 'name' => '' !== $name ? $name : 'upload', 'tmp_name' => $tmp ), (int) ( $args['post_id'] ?? 0 ), sanitize_text_field( (string) ( $args['title'] ?? '' ) ) );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			throw new \RuntimeException( $id->get_error_message() );
		}
		if ( ! empty( $args['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt'] ) );
		}

		return array( 'site' => Api::site(), 'id' => $id, 'url' => (string) wp_get_attachment_url( $id ) );
	}

	/**
	 * How many edits, ops, components and SEO entries differ between two documents.
	 *
	 * @param array<string, mixed> $live  Live document.
	 * @param array<string, mixed> $draft Proposed document.
	 */
	public static function difference( array $live, array $draft ): int {
		$count = 0;
		foreach ( array( 'edits', 'ops', 'components' ) as $list ) {
			$before = array();
			foreach ( (array) ( $live[ $list ] ?? array() ) as $item ) {
				$before[ (string) $item['id'] ] = wp_json_encode( $item );
			}
			$after = array();
			foreach ( (array) ( $draft[ $list ] ?? array() ) as $item ) {
				$after[ (string) $item['id'] ] = wp_json_encode( $item );
			}
			$count += count( array_diff_assoc( $after, $before ) ) + count( array_diff_key( $before, $after ) );
		}
		$count += wp_json_encode( $live['seo'] ?? array() ) !== wp_json_encode( $draft['seo'] ?? array() ) ? 1 : 0;
		$count += (string) ( $live['css'] ?? '' ) !== (string) ( $draft['css'] ?? '' ) ? 1 : 0;

		return $count;
	}
}
