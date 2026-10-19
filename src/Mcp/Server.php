<?php
/**
 * Model Context Protocol server (JSON-RPC 2.0 over Streamable HTTP, stateless): tools, resources and prompts.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor\Mcp;

use VisualEditor\Api;

defined( 'ABSPATH' ) || exit;

final class Server {

	private const PROTOCOLS = array( '2025-06-18', '2025-03-26', '2024-11-05' );

	/**
	 * @param array<string, mixed> $message One JSON-RPC message.
	 * @return array<string, mixed>|null Null for notifications.
	 */
	public static function handle( array $message ): ?array {
		$id     = $message['id'] ?? null;
		$method = (string) ( $message['method'] ?? '' );
		$params = is_array( $message['params'] ?? null ) ? $message['params'] : array();

		if ( ! array_key_exists( 'id', $message ) || null === $id ) {
			return null; // Notifications (notifications/initialized, cancelled…) get no reply.
		}

		$reply = static fn ( array $result ): array => array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $result );
		$fail  = static fn ( int $code, string $text ): array => array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $text ) );

		switch ( $method ) {
			case 'initialize':
				$asked = (string) ( $params['protocolVersion'] ?? '' );
				return $reply(
					array(
						'protocolVersion' => in_array( $asked, self::PROTOCOLS, true ) ? $asked : self::PROTOCOLS[0],
						'capabilities'    => array(
							'tools'     => array( 'listChanged' => false ),
							'resources' => array( 'listChanged' => false, 'subscribe' => false ),
							'prompts'   => array( 'listChanged' => false ),
							'logging'   => new \stdClass(),
						),
						'serverInfo'      => array( 'name' => Api::server_name(), 'title' => 'Visual Editor — ' . get_bloginfo( 'name' ), 'version' => SVE_VERSION ),
						'instructions'    => Guide::instructions(),
					)
				);
			case 'ping':
			case 'logging/setLevel':
				return $reply( array() );
			case 'tools/list':
				return $reply( array( 'tools' => Tools::listing() ) );
			case 'tools/call':
				return $reply( self::call( (string) ( $params['name'] ?? '' ), is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array() ) );
			case 'resources/list':
				return $reply( array( 'resources' => self::resources() ) );
			case 'resources/templates/list':
				return $reply( array( 'resourceTemplates' => array() ) );
			case 'resources/read':
				$uri  = (string) ( $params['uri'] ?? '' );
				$text = self::resource( $uri );
				return null === $text ? $fail( -32002, 'Resource not found: ' . $uri ) : $reply( array( 'contents' => array( array( 'uri' => $uri, 'mimeType' => 'text/markdown', 'text' => $text ) ) ) );
			case 'prompts/list':
				return $reply( array( 'prompts' => self::prompts() ) );
			case 'prompts/get':
				$prompt = self::prompt( (string) ( $params['name'] ?? '' ), (array) ( $params['arguments'] ?? array() ) );
				return null === $prompt ? $fail( -32602, 'Unknown prompt.' ) : $reply( $prompt );
			default:
				return $fail( -32601, 'Method not found: ' . $method );
		}
	}

	public static function is_write( array $message ): bool {
		return 'tools/call' === ( $message['method'] ?? '' ) && Tools::is_write( (string) ( $message['params']['name'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private static function call( string $name, array $args ): array {
		try {
			$result  = Tools::call( $name, $args );
			$image   = (string) ( $result['_image'] ?? '' );
			unset( $result['_image'] );
			$content = array( array( 'type' => 'text', 'text' => (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) );
			if ( '' !== $image && 1 === preg_match( '#^data:(image/[a-z]+);base64,(.+)$#s', $image, $m ) ) {
				$content[] = array( 'type' => 'image', 'mimeType' => $m[1], 'data' => $m[2] );
			}

			return array( 'content' => $content );
		} catch ( \Throwable $error ) {
			return array(
				'content' => array( array( 'type' => 'text', 'text' => $error->getMessage() ) ),
				'isError' => true,
			);
		}
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private static function resources(): array {
		$out = array();
		foreach ( Guide::topics() as $key => $topic ) {
			$out[] = array(
				'uri'         => 'sve://guide/' . $key,
				'name'        => 'guide-' . $key,
				'title'       => $topic['title'],
				'description' => 'Visual Editor guide: ' . $topic['title'],
				'mimeType'    => 'text/markdown',
			);
		}
		$out[] = array( 'uri' => 'sve://site/overview', 'name' => 'site-overview', 'title' => 'Site overview', 'description' => 'Theme, plugins, content types, templates, components, tokens.', 'mimeType' => 'text/markdown' );

		return $out;
	}

	private static function resource( string $uri ): ?string {
		if ( str_starts_with( $uri, 'sve://guide/' ) ) {
			$topic = substr( $uri, 12 );
			return isset( Guide::topics()[ $topic ] ) ? Guide::text( $topic ) : null;
		}
		if ( 'sve://site/overview' === $uri ) {
			return (string) wp_json_encode( Tools::overview(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		return null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function prompts(): array {
		return array(
			array(
				'name'        => 'build_site',
				'title'       => 'Build a complete site',
				'description' => 'Plan and build a full site: design system, header/footer, pages, templates, menus, SEO.',
				'arguments'   => array(
					array( 'name' => 'brief', 'description' => 'What the business is and what the site needs', 'required' => true ),
					array( 'name' => 'style', 'description' => 'Visual style / brand notes', 'required' => false ),
				),
			),
			array(
				'name'        => 'redesign_page',
				'title'       => 'Redesign a page',
				'description' => 'Review a page and improve its design, UX and responsiveness.',
				'arguments'   => array(
					array( 'name' => 'url', 'description' => 'Page URL', 'required' => true ),
					array( 'name' => 'goal', 'description' => 'What should improve', 'required' => false ),
				),
			),
			array(
				'name'        => 'audit_site',
				'title'       => 'UX / SEO / accessibility audit',
				'description' => 'Inspect key pages and report concrete problems with fixes.',
				'arguments'   => array( array( 'name' => 'focus', 'description' => 'ux, seo, accessibility, speed or all', 'required' => false ) ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|null
	 */
	private static function prompt( string $name, array $args ): ?array {
		$text = match ( $name ) {
			'build_site'    => "Build this WordPress site with the Visual Editor tools.\nBrief: " . (string) ( $args['brief'] ?? '' ) . "\nStyle: " . (string) ( $args['style'] ?? 'modern, clean, fast' ) . "\n\nSteps: 1) get_site_overview and get_guide(all). 2) Propose a sitemap, design tokens and section list to the person and wait for OK. 3) set_design_tokens (colors, fonts, radius, spacing). 4) Create reusable components (buttons, cards, section headers). 5) create_template header and footer parts. 6) Create pages with builder layouts (mode canvas), products/posts templates. 7) save_menu. 8) set_seo per page. 9) Check each page on mobile with screenshot/inspect_element and fix. Report progress after each step.",
			'redesign_page' => 'Redesign ' . (string) ( $args['url'] ?? '' ) . '. Goal: ' . (string) ( $args['goal'] ?? 'clearer hierarchy, better conversion, great on mobile' ) . ".\nFirst get_page_outline and screenshot (desktop + mobile), list the problems you see, propose changes, then apply them step by step (set_style per device, edit_layout or insert_html, set_motion sparingly) and verify each with inspect_element / screenshot.",
			'audit_site'    => 'Audit the site (' . (string) ( $args['focus'] ?? 'all' ) . "). Use get_site_overview, then for the home page and 3–5 key pages: get_page_outline, find_elements(role heading/image/button), inspect_element on CTAs, screenshot on mobile. Report problems ranked by impact with the exact fix (tool + arguments), and apply them only after the person agrees.",
			default         => null,
		};
		if ( null === $text ) {
			return null;
		}

		return array(
			'description' => $name,
			'messages'    => array( array( 'role' => 'user', 'content' => array( 'type' => 'text', 'text' => $text ) ) ),
		);
	}

	public static function level(): string {
		return Api::current_level();
	}
}
