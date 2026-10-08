<?php
/**
 * Model Context Protocol server (JSON-RPC 2.0 over HTTP) so AI assistants can use the visual editor: find
 * pages and templates, read how a page is built, restyle it, change content, insert and remove elements,
 * and manage SEO — all through the same validation as the editor itself.
 *
 * Writes follow the key's level: read-only, "draft" (proposed changes wait for an administrator's approval in
 * the editor — the default) or "live" (applied at once, with a revision to roll back to).
 *
 * @package Suntourz\VisualEditor
 */

declare(strict_types=1);

namespace Suntourz\VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Mcp {

	private const PROTOCOL = '2025-03-26';

	private const MAX_EDITS = 500;

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
						'protocolVersion' => (string) ( $params['protocolVersion'] ?? self::PROTOCOL ),
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => array( 'name' => 'suntourz-visual-editor', 'version' => SVE_VERSION ),
						'instructions'    => $this->instructions(),
					)
				);
			case 'ping':
				return $reply( array() );
			case 'tools/list':
				return $reply( array( 'tools' => array_map( static fn ( array $tool ): array => array_diff_key( $tool, array( 'write' => 1 ) ), $this->tools() ) ) );
			case 'tools/call':
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
		foreach ( $this->tools() as $tool ) {
			if ( $tool['name'] === $name ) {
				return ! empty( $tool['write'] );
			}
		}

		return false;
	}

	private function instructions(): string {
		$level = Api::settings()['level'];

		return implode(
			"\n",
			array(
				'You edit the WordPress site "' . get_bloginfo( 'name' ) . '" through its visual editor.',
				'Workflow: list_pages / list_templates → get_page or get_outline (to learn selectors) → set_style, set_content, insert_html, add_css, set_seo.',
				'Scopes: "page" = one URL, "template" = every item rendered by one theme template (a product page, a blog post, a category…) — use it instead of editing each product or post, "site" = everywhere (header, footer, global styles).',
				'Prefer stable selectors from get_outline or short class selectors (.btn) over long paths. Responsive: device base|tablet|mobile. States: normal|hover|focus|active.',
				'This key is: ' . ( 'read' === $level ? 'READ-ONLY.' : ( 'draft' === $level ? 'allowed to PROPOSE changes: they are saved as a draft that an administrator reviews and publishes in the editor.' : 'allowed to edit LIVE (every change keeps a revision).' ) ),
			)
		);
	}

	/* ------------------------------------------------------------------ tools */

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function tools(): array {
		$scope = array(
			'scope'    => array( 'type' => 'string', 'enum' => array( 'page', 'template', 'site' ), 'description' => 'Where the change applies. Default: "page" when url is given, "template" when template is given, otherwise "site".' ),
			'url'      => array( 'type' => 'string', 'description' => 'Page URL (for scope "page").' ),
			'template' => array( 'type' => 'string', 'description' => 'Template key from list_templates, e.g. "single:product" (for scope "template").' ),
		);
		$object = static fn ( array $properties, array $required = array() ): array => array(
			'type'                 => 'object',
			'properties'           => (object) $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
		$string = static fn ( string $description ): array => array( 'type' => 'string', 'description' => $description );

		return array(
			array( 'name' => 'status', 'description' => 'Permissions of this key, request limits and the number of changes waiting for approval.', 'inputSchema' => $object( array() ) ),
			array( 'name' => 'list_pages', 'description' => 'Find pages, posts, products… (search, type filter, paging).', 'inputSchema' => $object( array( 'search' => $string( 'Words to search for' ), 'type' => $string( 'Post type slug, e.g. page, post, product' ), 'page' => array( 'type' => 'integer' ) ) ) ),
			array( 'name' => 'list_templates', 'description' => 'Theme templates (single product, single post, category archive…) with one sample URL each. Edit these instead of every product or post.', 'inputSchema' => $object( array() ) ),
			array( 'name' => 'get_page', 'description' => 'Everything the editor changed for a URL: edits, inserted/moved/removed elements and SEO (page, template and site-wide).', 'inputSchema' => $object( array( 'url' => $string( 'Page URL' ), 'template' => $string( 'Template key, to read the template-level changes' ) ) ) ),
			array( 'name' => 'get_outline', 'description' => 'Map of a page: every visible tag with its CSS selector, classes and text. Built from the server-rendered HTML.', 'inputSchema' => $object( array( 'url' => $string( 'Page URL' ), 'max_nodes' => array( 'type' => 'integer', 'description' => 'Default 250, maximum 600' ) ), array( 'url' ) ) ),
			array( 'name' => 'list_components', 'description' => 'Saved reusable components.', 'inputSchema' => $object( array() ) ),
			array(
				'name'        => 'set_style',
				'description' => 'Set CSS properties on every element matching a selector.',
				'write'       => true,
				'inputSchema' => $object(
					array_merge(
						$scope,
						array(
							'selector' => $string( 'CSS selector' ),
							'styles'   => array( 'type' => 'object', 'description' => 'Property → value, e.g. {"color":"#c00","font-size":"20px"}. Empty string removes a property.' ),
							'device'   => array( 'type' => 'string', 'enum' => array( 'base', 'tablet', 'mobile' ), 'description' => 'base = all widths; tablet ≤1023px; mobile ≤767px' ),
							'state'    => array( 'type' => 'string', 'enum' => array( 'normal', 'hover', 'focus', 'active' ) ),
						)
					),
					array( 'selector', 'styles' )
				),
			),
			array(
				'name'        => 'add_css',
				'description' => 'Add free CSS. With a selector, the word "selector" inside the CSS stands for it. Without one, the CSS is global for the scope.',
				'write'       => true,
				'inputSchema' => $object( array_merge( $scope, array( 'css' => $string( 'CSS rules' ), 'selector' => $string( 'Optional selector' ) ) ), array( 'css' ) ),
			),
			array(
				'name'        => 'set_content',
				'description' => 'Change the text, inner HTML or attributes (href, src, alt, class …) of elements matching a selector.',
				'write'       => true,
				'inputSchema' => $object( array_merge( $scope, array( 'selector' => $string( 'CSS selector' ), 'text' => $string( 'Plain text' ), 'html' => $string( 'Inner HTML (sanitised; scripts are removed)' ), 'attrs' => array( 'type' => 'object', 'description' => 'Attribute → value, null removes it' ) ) ), array( 'selector' ) ),
			),
			array(
				'name'        => 'insert_html',
				'description' => 'Insert markup before/after/inside the element matching a selector.',
				'write'       => true,
				'inputSchema' => $object( array_merge( $scope, array( 'selector' => $string( 'Anchor element' ), 'position' => array( 'type' => 'string', 'enum' => array( 'before', 'after', 'prepend', 'append' ) ), 'html' => $string( 'Markup (sanitised; scripts are removed)' ) ) ), array( 'selector', 'position', 'html' ) ),
			),
			array(
				'name'        => 'remove_element',
				'description' => 'Remove the element matching a selector (it is replaced by an inert placeholder, so nothing else shifts).',
				'write'       => true,
				'inputSchema' => $object( array_merge( $scope, array( 'selector' => $string( 'CSS selector' ) ) ), array( 'selector' ) ),
			),
			array(
				'name'        => 'set_seo',
				'description' => 'Title, meta description, share image, canonical, robots and JSON-LD of a page or a template. Template titles may use %title% and %site%.',
				'write'       => true,
				'inputSchema' => $object(
					array(
						'url'         => $string( 'Page URL (for one page)' ),
						'template'    => $string( 'Template key (for all items of a template)' ),
						'title'       => $string( 'SEO title' ),
						'description' => $string( 'Meta description' ),
						'image'       => $string( 'Share image URL' ),
						'canonical'   => $string( 'Canonical URL' ),
						'noindex'     => array( 'type' => 'boolean' ),
						'nofollow'    => array( 'type' => 'boolean' ),
						'jsonld'      => $string( 'JSON-LD as a JSON string' ),
					)
				),
			),
			array(
				'name'        => 'save_component',
				'description' => 'Create or replace a reusable component.',
				'write'       => true,
				'inputSchema' => $object( array( 'name' => $string( 'Component name' ), 'html' => $string( 'Markup' ), 'css' => $string( 'Optional CSS' ), 'kind' => array( 'type' => 'string', 'enum' => array( 'html', 'jsonld' ) ) ), array( 'name', 'html' ) ),
			),
			array(
				'name'        => 'discard_draft',
				'description' => 'Throw away the changes proposed so far (only affects the draft, never the live site).',
				'write'       => true,
				'inputSchema' => $object( array() ),
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
				'status'          => $this->status(),
				'list_pages'      => $this->list_pages( $args ),
				'list_templates'  => $this->list_templates(),
				'get_page'        => $this->get_page( $args ),
				'get_outline'     => $this->get_outline( $args ),
				'list_components' => $this->list_components(),
				'set_style'       => $this->set_style( $args ),
				'add_css'         => $this->add_css( $args ),
				'set_content'     => $this->set_content( $args ),
				'insert_html'     => $this->insert_html( $args ),
				'remove_element'  => $this->remove_element( $args ),
				'set_seo'         => $this->set_seo( $args ),
				'save_component'  => $this->save_component( $args ),
				'discard_draft'   => $this->discard_draft(),
				default           => throw new \RuntimeException( 'Unknown tool: ' . $name ),
			};

			return array( 'content' => array( array( 'type' => 'text', 'text' => (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) ) );
		} catch ( \Throwable $error ) {
			return array( 'content' => array( array( 'type' => 'text', 'text' => $error->getMessage() ) ), 'isError' => true );
		}
	}

	/* ------------------------------------------------------------------ reading */

	/**
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$draft = $this->edits->draft();

		return array(
			'site'         => get_bloginfo( 'name' ),
			'level'        => Api::settings()['level'],
			'limits'       => Api::status()['limits'],
			'pendingDraft' => null === $draft ? 0 : self::difference( $this->edits->get(), $draft ),
		);
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function list_pages( array $args ): array {
		$found = Pages::search(
			array(
				'search'   => (string) ( $args['search'] ?? '' ),
				'type'     => (string) ( $args['type'] ?? '' ),
				'page'     => (int) ( $args['page'] ?? 1 ),
				'per_page' => 30,
			)
		);

		return array(
			'total' => $found['total'],
			'pages' => $found['pages'],
			'types' => $found['types'],
			'items' => array_map(
				static fn ( array $item ): array => array_intersect_key( $item, array_flip( array( 'id', 'title', 'url', 'type', 'status', 'modified', 'role' ) ) ),
				$found['items']
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function list_templates(): array {
		return array(
			'templates' => array_map(
				static fn ( array $item ): array => array_intersect_key( $item, array_flip( array( 'key', 'label', 'url', 'count' ) ) ),
				Templates::listing()
			),
			'hint'      => 'Use scope "template" with one of these keys to change every item rendered by that template.',
		);
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function get_page( array $args ): array {
		$doc  = $this->working_doc();
		$page = isset( $args['url'] ) ? VisualEdits::page_key_for_url( (string) $args['url'] ) : null;
		$tpl  = isset( $args['template'] ) ? (string) $args['template'] : '';

		$matches = static fn ( array $item ): bool => 'site' === $item['scope']
			|| ( 'page' === $item['scope'] && null !== $page && $item['page'] === $page )
			|| ( 'template' === $item['scope'] && '' !== $tpl && ( $item['tpl'] ?? '' ) === $tpl );

		$edits = array_values( array_filter( $doc['edits'], $matches ) );

		return array(
			'page'     => $page,
			'template' => '' !== $tpl ? $tpl : null,
			'seo'      => array(
				'page'     => null !== $page ? ( $doc['seo'][ $page ] ?? null ) : null,
				'template' => '' !== $tpl ? ( $doc['seo'][ 'tpl:' . $tpl ] ?? null ) : null,
			),
			'edits'    => array_map(
				static fn ( array $edit ): array => array_filter(
					array(
						'id'       => $edit['id'],
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
				$edits
			),
			'ops'      => array_values( array_filter( $doc['ops'], $matches ) ),
			'note'     => 'Showing the proposed draft where one exists, otherwise the live document.',
		);
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private function get_outline( array $args ): array {
		$outline = Outline::build( (string) ( $args['url'] ?? '' ), max( 20, min( 600, (int) ( $args['max_nodes'] ?? 250 ) ) ) );
		if ( is_wp_error( $outline ) ) {
			throw new \RuntimeException( $outline->get_error_message() );
		}

		return $outline;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function list_components(): array {
		return array( 'components' => $this->working_doc()['components'] );
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
		$doc = $this->working_doc();
		$change( $doc );

		if ( count( $doc['edits'] ) > self::MAX_EDITS || count( $doc['ops'] ) > self::MAX_EDITS ) {
			throw new \RuntimeException( 'Too many changes on this site already. Remove some first.' );
		}

		$clean = $this->edits->sanitize( $doc );
		$mode  = Api::settings()['level'];
		if ( 'live' === $mode ) {
			$this->edits->store( $clean );

			return array( 'applied' => 'live', 'note' => 'The change is live. The previous version is kept as a revision.' );
		}

		$this->edits->set_draft( $clean );

		return array(
			'applied' => 'draft',
			'pending' => self::difference( $this->edits->get(), $clean ),
			'note'    => 'Saved as a draft. An administrator reviews and publishes it in the visual editor.',
		);
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
				throw new \RuntimeException( 'scope "template" needs a template key (see list_templates).' );
			}
		}

		return array( 'scope' => $scope, 'page' => $page, 'tpl' => $tpl );
	}

	private function selector( mixed $value ): string {
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
		$html = isset( $args['html'] ) ? (string) $args['html'] : null;
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
		$html = (string) ( $args['html'] ?? '' );
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
			$key = 'tpl:' . preg_replace( '/[^A-Za-z0-9:_\-.\/]/', '', (string) $args['template'] );
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
		$html = (string) ( $args['html'] ?? '' );
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
