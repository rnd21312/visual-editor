<?php
/**
 * How the AI "sees" the site without a browser: fetches a page of this site (with the saved edits applied),
 * and answers structural questions — outline of the page, find "the Book now button", inspect one element
 * with the CSS rules that style it. When an editor window is open, LiveBridge adds what only a browser knows
 * (computed styles, size and position, screenshots).
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Inspector {

	public const PROBE_ARG = 'sve_probe';

	private const SKIP = array( 'script', 'style', 'noscript', 'template', 'link', 'meta', 'head', 'path', 'g', 'defs', 'use', 'symbol', 'clippath', 'mask', 'lineargradient', 'radialgradient', 'stop', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'br', 'wbr' );

	private const STATE_CLASS = '/^(active|current|open|show|is-|has-|focus|hover|selected|current-menu|current_page|menu-item-\d|page-item-\d|wp-image-\d|post-\d|sve-|swiper-slide-(active|next|prev))/';

	/** @var array<string, mixed> */
	private static array $docs = array();

	/**
	 * Signature that lets the inspector's own request see the page as rendered for visitors, plus its context.
	 */
	public static function probe_token(): string {
		return substr( hash_hmac( 'sha256', 'sve-probe|' . gmdate( 'YmdH' ), wp_salt( 'nonce' ) ), 0, 20 );
	}

	public static function is_probe(): bool {
		$token = isset( $_GET[ self::PROBE_ARG ] ) ? (string) $_GET[ self::PROBE_ARG ] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		if ( '' === $token ) {
			return false;
		}
		$previous = substr( hash_hmac( 'sha256', 'sve-probe|' . gmdate( 'YmdH', time() - HOUR_IN_SECONDS ), wp_salt( 'nonce' ) ), 0, 20 );

		return hash_equals( self::probe_token(), $token ) || hash_equals( $previous, $token );
	}

	/**
	 * Fetches a page of this site. Only this site's host and port, no redirects to elsewhere.
	 *
	 * @return array{html: string, url: string}|WP_Error
	 */
	public static function fetch( string $url, bool $as_user = false ) {
		$url  = trim( $url );
		$home = wp_parse_url( home_url() );
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			$url = home_url( $url );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || strtolower( (string) ( $parts['host'] ?? '' ) ) !== strtolower( (string) ( $home['host'] ?? '' ) ) || ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
			return new WP_Error( 'sve_outline_host', 'Only pages of this site can be read (same host and port). Give a full URL of this site or a path like "/about/".' );
		}

		$cookies = array();
		if ( $as_user && is_user_logged_in() ) {
			$expire    = time() + 120;
			$cookies[] = new \WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( get_current_user_id(), $expire, 'logged_in' ) ) );
		}

		$target = add_query_arg( self::PROBE_ARG, self::probe_token(), $url );
		for ( $hop = 0; $hop < 4; $hop++ ) {
			$response = wp_remote_get(
				$target,
				array(
					'timeout'     => 20,
					'redirection' => 0,
					'user-agent'  => 'VisualEditor/' . SVE_VERSION . ' (inspector)',
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
					'cookies'     => $cookies,
					'headers'     => array( 'Accept' => 'text/html' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'sve_outline_fetch', 'The page could not be loaded: ' . $response->get_error_message() . ' (the server must be able to request its own pages).' );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 300 && $code < 400 ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				$next     = wp_parse_url( $location );
				if ( '' === $location || ( isset( $next['host'] ) && strtolower( (string) $next['host'] ) !== strtolower( (string) $home['host'] ) ) ) {
					return new WP_Error( 'sve_outline_redirect', 'The page redirects to another site: ' . $location );
				}
				$target = add_query_arg( self::PROBE_ARG, self::probe_token(), str_starts_with( $location, '/' ) ? home_url( $location ) : $location );
				$url    = remove_query_arg( self::PROBE_ARG, $target );
				continue;
			}
			if ( 200 !== $code && 404 !== $code ) {
				return new WP_Error( 'sve_outline_fetch', 'The page answered with HTTP ' . $code . '.' );
			}

			return array(
				'html' => (string) wp_remote_retrieve_body( $response ),
				'url'  => $url,
			);
		}

		return new WP_Error( 'sve_outline_fetch', 'Too many redirects.' );
	}

	/**
	 * Parsed page (HTML5 parser with CSS selectors on PHP 8.4+, libxml otherwise).
	 *
	 * @return array{doc: object, modern: bool, url: string, context: array<string, mixed>}|WP_Error
	 */
	private static function load( string $url, bool $as_user = false ) {
		$key = $url . '|' . ( $as_user ? 1 : 0 );
		if ( isset( self::$docs[ $key ] ) ) {
			return self::$docs[ $key ];
		}
		$page = self::fetch( $url, $as_user );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		if ( class_exists( '\Dom\HTMLDocument' ) ) {
			$doc    = \Dom\HTMLDocument::createFromString( $page['html'], LIBXML_NOERROR );
			$modern = true;
		} else {
			$doc      = new \DOMDocument();
			$previous = libxml_use_internal_errors( true );
			$doc->loadHTML( '<?xml encoding="utf-8"?>' . $page['html'] );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			$modern = false;
		}
		$context = array();
		$meta    = self::query( $doc, $modern, 'meta[name="sve-context"]' );
		if ( array() !== $meta ) {
			$context = (array) json_decode( (string) $meta[0]->getAttribute( 'content' ), true );
		}
		self::$docs[ $key ] = array(
			'doc'     => $doc,
			'modern'  => $modern,
			'url'     => $page['url'],
			'context' => $context,
		);

		return self::$docs[ $key ];
	}

	/**
	 * CSS query on either DOM flavour (libxml: a small selector subset converted to XPath).
	 *
	 * @return array<int, object>
	 */
	private static function query( object $doc, bool $modern, string $selector ): array {
		try {
			if ( $modern ) {
				return iterator_to_array( $doc->querySelectorAll( $selector ) );
			}
			$xpath = new \DOMXPath( $doc );
			$list  = $xpath->query( self::to_xpath( $selector ) );

			return false === $list ? array() : iterator_to_array( $list );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Very small CSS → XPath (tag, #id, .class, [attr], [attr="v"], descendant and child combinators).
	 */
	private static function to_xpath( string $selector ): string {
		$out = array();
		foreach ( Compiler::split( $selector ) as $one ) {
			$path  = '';
			$parts = preg_split( '/\s*(>)\s*|\s+/', trim( $one ), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) ?: array();
			$axis  = '//';
			foreach ( $parts as $part ) {
				if ( '>' === $part ) {
					$axis = '/';
					continue;
				}
				preg_match( '/^([a-zA-Z0-9*-]*)/', $part, $m );
				$tag   = '' !== ( $m[1] ?? '' ) ? $m[1] : '*';
				$conds = array();
				preg_match_all( '/#([\w-]+)|\.([\w-]+)|\[([\w-]+)(?:([~*^$]?=)["\']?([^"\'\]]*)["\']?)?\]/', $part, $all, PREG_SET_ORDER );
				foreach ( $all as $c ) {
					if ( '' !== ( $c[1] ?? '' ) ) {
						$conds[] = '@id="' . $c[1] . '"';
					} elseif ( '' !== ( $c[2] ?? '' ) ) {
						$conds[] = 'contains(concat(" ",normalize-space(@class)," ")," ' . $c[2] . ' ")';
					} elseif ( '' !== ( $c[3] ?? '' ) ) {
						$conds[] = isset( $c[5] ) ? '@' . $c[3] . '="' . $c[5] . '"' : '@' . $c[3];
					}
				}
				$path .= $axis . $tag . ( array() !== $conds ? '[' . implode( ' and ', $conds ) . ']' : '' );
				$axis  = '//';
			}
			$out[] = $path;
		}

		return implode( ' | ', $out );
	}

	/* ------------------------------------------------------------------ selectors */

	/**
	 * Short stable selector of an element: builder id, unique id, unique class combination, else a path.
	 */
	public static function selector( object $el, object $doc, bool $modern ): string {
		$sid = (string) $el->getAttribute( 'data-sid' );
		if ( '' !== $sid ) {
			return '[data-sid="' . $sid . '"]';
		}
		$parts = array();
		$node  = $el;
		while ( is_object( $node ) && 1 === $node->nodeType ) {
			$name = strtolower( (string) $node->localName );
			if ( 'html' === $name || 'body' === $name ) {
				array_unshift( $parts, $name );
				break;
			}
			$unique = self::unique_part( $node, $doc, $modern );
			if ( null !== $unique ) {
				array_unshift( $parts, $unique );
				break;
			}
			$parent = $node->parentNode;
			if ( ! is_object( $parent ) || 1 !== $parent->nodeType ) {
				break;
			}
			$index = 0;
			foreach ( $parent->childNodes as $sibling ) {
				if ( 1 === $sibling->nodeType && ! $sibling->hasAttribute( 'data-ve-id' ) ) {
					++$index;
				}
				if ( $sibling === $node ) {
					break;
				}
			}
			array_unshift( $parts, $name . ':nth-child(' . $index . ' of :not([data-ve-id]))' );
			$node = $parent;
		}

		return implode( ' > ', $parts );
	}

	private static function unique_part( object $el, object $doc, bool $modern ): ?string {
		$name = strtolower( (string) $el->localName );
		$sid  = (string) $el->getAttribute( 'data-sid' );
		if ( '' !== $sid ) {
			return '[data-sid="' . $sid . '"]';
		}
		$id = (string) $el->getAttribute( 'id' );
		if ( '' !== $id && 1 === preg_match( '/^[A-Za-z][\w-]*$/', $id ) && 1 !== preg_match( '/\d{3,}|^(radix|headlessui|react|ember|ui-id)/i', $id ) && 1 === count( self::query( $doc, $modern, '#' . $id ) ) ) {
			return '#' . $id;
		}
		if ( in_array( $name, array( 'header', 'footer', 'main', 'nav' ), true ) && 1 === count( self::query( $doc, $modern, $name ) ) ) {
			return $name;
		}
		$classes = array_values(
			array_filter(
				preg_split( '/\s+/', trim( (string) $el->getAttribute( 'class' ) ) ) ?: array(),
				static fn ( string $c ): bool => '' !== $c && 1 === preg_match( '/^[A-Za-z_-][\w-]*$/', $c ) && 1 !== preg_match( self::STATE_CLASS, $c )
			)
		);
		foreach ( array_slice( $classes, 0, 6 ) as $class ) {
			if ( 1 === count( self::query( $doc, $modern, '.' . $class ) ) ) {
				return '.' . $class;
			}
			if ( 1 === count( self::query( $doc, $modern, $name . '.' . $class ) ) ) {
				return $name . '.' . $class;
			}
		}
		if ( count( $classes ) >= 2 ) {
			$pair = $name . '.' . $classes[0] . '.' . $classes[1];
			if ( 1 === count( self::query( $doc, $modern, $pair ) ) ) {
				return $pair;
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------ outline / find / inspect */

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function outline( string $url, int $max = 300, string $root = '', bool $as_user = false ) {
		$page = self::load( $url, $as_user );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$doc   = $page['doc'];
		$start = '' !== $root ? ( self::query( $doc, $page['modern'], $root )[0] ?? null ) : ( self::query( $doc, $page['modern'], 'body' )[0] ?? null );
		if ( null === $start ) {
			return new WP_Error( 'sve_outline_root', 'No element matches root "' . $root . '".' );
		}

		$nodes     = array();
		$truncated = false;
		$walk      = static function ( object $el, int $depth ) use ( &$walk, &$nodes, &$truncated, $max, $doc, $page ): void {
			foreach ( $el->childNodes as $child ) {
				if ( 1 !== $child->nodeType || in_array( strtolower( (string) $child->localName ), self::SKIP, true ) ) {
					continue;
				}
				if ( count( $nodes ) >= $max ) {
					$truncated = true;
					return;
				}
				$nodes[] = self::describe( $child, $depth, $doc, $page['modern'], false );
				if ( 'svg' !== strtolower( (string) $child->localName ) ) {
					$walk( $child, $depth + 1 );
				}
			}
		};
		$walk( $start, 0 );

		return array(
			'url'       => $page['url'],
			'title'     => trim( (string) ( self::query( $doc, $page['modern'], 'title' )[0]->textContent ?? '' ) ),
			'context'   => $page['context'],
			'nodes'     => $nodes,
			'truncated' => $truncated,
			'note'      => $truncated ? 'Truncated: call again with root (a selector) to zoom into one part, or a larger max_nodes.' : '',
		);
	}

	/**
	 * Finds elements by visible text, tag, role or selector (e.g. text "Book now", role "button").
	 *
	 * @param array<string, mixed> $q text, tag, role, selector, limit.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function find( string $url, array $q, bool $as_user = false ) {
		$page = self::load( $url, $as_user );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$doc  = $page['doc'];
		$text = mb_strtolower( trim( (string) ( $q['text'] ?? '' ) ) );
		$role = strtolower( (string) ( $q['role'] ?? '' ) );
		$tag  = strtolower( (string) ( $q['tag'] ?? '' ) );
		$sel  = (string) ( $q['selector'] ?? '' );

		$candidates = '' !== $sel ? self::query( $doc, $page['modern'], $sel ) : self::query( $doc, $page['modern'], '' !== $tag ? $tag : 'body *' );
		$roles      = array(
			'button'  => array( 'button', 'a', 'input' ),
			'link'    => array( 'a' ),
			'heading' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ),
			'image'   => array( 'img', 'svg', 'picture' ),
			'input'   => array( 'input', 'textarea', 'select' ),
			'section' => array( 'section', 'header', 'footer', 'main', 'aside', 'nav', 'article' ),
			'form'    => array( 'form' ),
		);

		$found = array();
		foreach ( $candidates as $el ) {
			$name = strtolower( (string) $el->localName );
			if ( in_array( $name, self::SKIP, true ) ) {
				continue;
			}
			if ( '' !== $role ) {
				$explicit = strtolower( (string) $el->getAttribute( 'role' ) );
				$is_btn   = 'button' === $role && ( 'a' === $name ? 1 === preg_match( '/\b(btn|button|wp-block-button__link|cta)\b/i', (string) $el->getAttribute( 'class' ) ) : true );
				if ( $explicit !== $role && ( ! in_array( $name, $roles[ $role ] ?? array(), true ) || ( 'button' === $role && ! $is_btn ) ) ) {
					continue;
				}
			}
			$score = 0;
			if ( '' !== $text ) {
				$own   = mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', (string) $el->textContent ) ) );
				$label = mb_strtolower( (string) $el->getAttribute( 'aria-label' ) . ' ' . (string) $el->getAttribute( 'alt' ) . ' ' . (string) $el->getAttribute( 'title' ) . ' ' . (string) $el->getAttribute( 'placeholder' ) . ' ' . (string) $el->getAttribute( 'value' ) );
				if ( $own === $text ) {
					$score = 100;
				} elseif ( str_contains( $label, $text ) ) {
					$score = 80;
				} elseif ( '' !== $own && str_contains( $own, $text ) && mb_strlen( $own ) < mb_strlen( $text ) * 4 + 40 ) {
					$score = 60 - min( 40, (int) ( mb_strlen( $own ) / 10 ) );
				} else {
					continue;
				}
			} else {
				$score = 10;
			}
			$found[] = array( 'el' => $el, 'score' => $score + ( in_array( $name, array( 'a', 'button', 'h1', 'h2', 'h3', 'img', 'input' ), true ) ? 5 : 0 ) );
		}
		// Keep the innermost of nested matches (the <a>, not every wrapper around it).
		usort( $found, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );
		$out   = array();
		$limit = max( 1, min( 30, (int) ( $q['limit'] ?? 10 ) ) );
		foreach ( $found as $item ) {
			foreach ( $out as $kept ) {
				if ( self::contains( $item['el'], $kept['_el'] ) ) {
					continue 2;
				}
			}
			$described        = self::describe( $item['el'], 0, $doc, $page['modern'], true );
			$described['_el'] = $item['el'];
			$described['match'] = $item['score'];
			$out[]            = $described;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return array(
			'url'     => $page['url'],
			'context' => $page['context'],
			'matches' => array_map( static function ( array $m ): array {
				unset( $m['_el'] );
				return $m;
			}, $out ),
		);
	}

	/**
	 * Everything about one element: markup, attributes, ancestors, children, the CSS rules that style it
	 * (PHP 8.4+) and the editor changes that target it.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function inspect( string $url, string $selector, bool $as_user = false ) {
		$page = self::load( $url, $as_user );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$doc   = $page['doc'];
		$found = self::query( $doc, $page['modern'], $selector );
		if ( array() === $found ) {
			return new WP_Error( 'sve_no_match', 'No element matches "' . $selector . '" on ' . $page['url'] . '. Use find_elements or get_outline to get a valid selector.' );
		}
		$el   = $found[0];
		$info = self::describe( $el, 0, $doc, $page['modern'], true );

		$info['matches_count'] = count( $found );
		$html                  = (string) ( $page['modern'] ? $doc->saveHtml( $el ) : $doc->saveHTML( $el ) );
		$info['html']          = mb_strlen( $html ) > 6000 ? mb_substr( $html, 0, 6000 ) . '…' : $html;
		$attrs                 = array();
		foreach ( $el->attributes as $attr ) {
			$attrs[ $attr->name ] = mb_substr( (string) $attr->value, 0, 300 );
		}
		$info['attributes'] = $attrs;

		$ancestors = array();
		for ( $node = $el->parentNode; is_object( $node ) && 1 === $node->nodeType && count( $ancestors ) < 8; $node = $node->parentNode ) {
			$ancestors[] = array(
				'tag'      => strtolower( (string) $node->localName ),
				'selector' => self::selector( $node, $doc, $page['modern'] ),
				'classes'  => trim( (string) $node->getAttribute( 'class' ) ),
			);
		}
		$info['ancestors'] = $ancestors;

		$children = array();
		foreach ( $el->childNodes as $child ) {
			if ( 1 === $child->nodeType && count( $children ) < 40 && ! in_array( strtolower( (string) $child->localName ), self::SKIP, true ) ) {
				$children[] = self::describe( $child, 1, $doc, $page['modern'], false );
			}
		}
		$info['children'] = $children;

		if ( $page['modern'] ) {
			$info['css_rules'] = self::matched_rules( $el, $doc );
		} else {
			$info['css_rules_note'] = 'Matching CSS rules need PHP 8.4+ on the server. Open the editor for computed styles (live bridge).';
		}
		$info['editor_changes'] = self::changes_for( $el, $page['modern'] );

		return $info;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function describe( object $el, int $depth, object $doc, bool $modern, bool $rich ): array {
		$name = strtolower( (string) $el->localName );
		$own  = '';
		foreach ( $el->childNodes as $part ) {
			if ( 3 === $part->nodeType ) {
				$own .= ' ' . $part->nodeValue;
			}
		}
		$own     = trim( (string) preg_replace( '/\s+/u', ' ', $own ) );
		$classes = trim( (string) $el->getAttribute( 'class' ) );
		$node    = array(
			'selector' => self::selector( $el, $doc, $modern ),
			'tag'      => $name,
		);
		if ( $depth > 0 || ! $rich ) {
			$node['depth'] = $depth;
		}
		foreach ( array( 'id', 'data-sid', 'role', 'aria-label' ) as $attr ) {
			if ( '' !== (string) $el->getAttribute( $attr ) ) {
				$node[ $attr ] = (string) $el->getAttribute( $attr );
			}
		}
		if ( '' !== $classes ) {
			$node['classes'] = mb_substr( $classes, 0, 200 );
		}
		if ( '' !== $own ) {
			$node['text'] = mb_substr( $own, 0, $rich ? 300 : 100 );
		} elseif ( $rich ) {
			$all = trim( (string) preg_replace( '/\s+/u', ' ', (string) $el->textContent ) );
			if ( '' !== $all ) {
				$node['text'] = mb_substr( $all, 0, 300 );
			}
		}
		foreach ( array( 'href', 'src', 'alt', 'type', 'name', 'placeholder' ) as $attr ) {
			if ( '' !== (string) $el->getAttribute( $attr ) ) {
				$node[ $attr ] = mb_substr( (string) $el->getAttribute( $attr ), 0, 160 );
			}
		}
		if ( '' !== (string) $el->getAttribute( 'style' ) ) {
			$node['inline_style'] = mb_substr( (string) $el->getAttribute( 'style' ), 0, 300 );
		}
		if ( $el->hasAttribute( 'data-ve-id' ) ) {
			$node['inserted_by_editor'] = true;
		}
		if ( $rich ) {
			// Context: nearest section heading.
			for ( $up = $el->parentNode; is_object( $up ) && 1 === $up->nodeType; $up = $up->parentNode ) {
				if ( in_array( strtolower( (string) $up->localName ), array( 'section', 'header', 'footer', 'article', 'aside', 'nav', 'main' ), true ) ) {
					$heading = self::query( $doc, $modern, 'h1,h2,h3' );
					foreach ( $heading as $h ) {
						if ( self::contains( $up, $h ) ) {
							$node['in_section'] = array(
								'tag'      => strtolower( (string) $up->localName ),
								'selector' => self::selector( $up, $doc, $modern ),
								'heading'  => mb_substr( trim( (string) $h->textContent ), 0, 120 ),
							);
							break 2;
						}
					}
					$node['in_section'] = array( 'tag' => strtolower( (string) $up->localName ), 'selector' => self::selector( $up, $doc, $modern ) );
					break;
				}
			}
		}

		return $node;
	}

	/**
	 * Editor changes whose selector matches the element.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function changes_for( object $el, bool $modern ): array {
		$out = array();
		if ( ! $modern ) {
			return $out;
		}
		foreach ( Store::get()['edits'] as $edit ) {
			try {
				if ( $el->matches( (string) $edit['sel'] ) ) {
					$out[] = array_intersect_key( $edit, array_flip( array( 'id', 'scope', 'page', 'tpl', 'sel', 'styles', 'states', 'hide', 'text', 'attrs', 'motion', 'css' ) ) );
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			}
		}

		return $out;
	}

	/**
	 * Node::contains() exists only from PHP 8.3.
	 */
	private static function contains( object $ancestor, object $node ): bool {
		for ( $n = $node; is_object( $n ); $n = $n->parentNode ) {
			if ( $n === $ancestor ) {
				return true;
			}
		}

		return false;
	}

	/* ------------------------------------------------------------------ CSS */

	/**
	 * CSS rules of the page's stylesheets (this site's files and inline <style>) that match an element, in
	 * cascade order (later wins at equal specificity).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function matched_rules( object $el, object $doc ): array {
		$sheets = array();
		$host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$budget = 15;
		foreach ( $doc->querySelectorAll( 'link[rel~="stylesheet"][href], style' ) as $node ) {
			if ( 'style' === strtolower( $node->localName ) ) {
				$sheets[] = array( 'source' => '<style' . ( $node->getAttribute( 'id' ) ? '#' . $node->getAttribute( 'id' ) : '' ) . '>', 'css' => (string) $node->textContent );
				continue;
			}
			$href = (string) $node->getAttribute( 'href' );
			$h    = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
			if ( '' !== $h && $h !== $host ) {
				continue;
			}
			if ( --$budget < 0 ) {
				break;
			}
			$css = self::stylesheet( $href );
			if ( '' !== $css ) {
				$sheets[] = array( 'source' => (string) preg_replace( '#^https?://[^/]+#', '', strtok( $href, '?' ) ?: $href ), 'css' => $css );
			}
		}

		$out = array();
		foreach ( $sheets as $order => $sheet ) {
			foreach ( self::rules( $sheet['css'] ) as $rule ) {
				foreach ( Compiler::split( $rule['selector'] ) as $one ) {
					$states = array();
					$test   = (string) preg_replace_callback(
						'/::?(before|after|placeholder|marker|selection|first-line|first-letter)\b|:(hover|focus-visible|focus-within|focus|active|visited)\b/',
						static function ( array $m ) use ( &$states ): string {
							$states[] = $m[0];
							return '';
						},
						$one
					);
					try {
						if ( '' !== trim( $test ) && $el->matches( $test ) ) {
							$out[] = array(
								'selector' => $one,
								'state'    => implode( '', array_unique( $states ) ),
								'media'    => $rule['media'],
								'css'      => mb_substr( $rule['body'], 0, 600 ),
								'source'   => $sheet['source'],
							);
							break;
						}
					} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
					}
				}
				if ( count( $out ) > 80 ) {
					break 2;
				}
			}
		}

		return $out;
	}

	private static function stylesheet( string $href ): string {
		$url  = str_starts_with( $href, '//' ) ? set_url_scheme( 'http:' . $href ) : ( str_starts_with( $href, '/' ) ? home_url( $href ) : $href );
		$file = null;
		// Read local files from disk instead of over HTTP.
		$content_url = content_url();
		$path        = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( str_starts_with( $path, (string) wp_parse_url( $content_url, PHP_URL_PATH ) ) ) {
			$file = WP_CONTENT_DIR . substr( $path, strlen( (string) wp_parse_url( $content_url, PHP_URL_PATH ) ) );
		} elseif ( str_starts_with( $path, (string) wp_parse_url( includes_url(), PHP_URL_PATH ) ) ) {
			$file = ABSPATH . WPINC . substr( $path, strlen( (string) wp_parse_url( includes_url(), PHP_URL_PATH ) ) );
		}
		if ( null !== $file && is_readable( $file ) && str_ends_with( $file, '.css' ) ) {
			return (string) file_get_contents( $file, false, null, 0, 800000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return '';
	}

	/**
	 * Flat list of style rules with their media condition.
	 *
	 * @return array<int, array{selector: string, body: string, media: string}>
	 */
	private static function rules( string $css, string $media = '' ): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$out = array();
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			$open = strpos( $css, '{', $i );
			if ( false === $open ) {
				break;
			}
			$prelude = trim( substr( $css, $i, $open - $i ) );
			// Find the matching closing brace.
			$depth = 1;
			$j     = $open + 1;
			while ( $j < $len && $depth > 0 ) {
				if ( '{' === $css[ $j ] ) {
					++$depth;
				} elseif ( '}' === $css[ $j ] ) {
					--$depth;
				}
				++$j;
			}
			$body = substr( $css, $open + 1, $j - $open - 2 );
			$i    = $j;
			if ( str_starts_with( $prelude, '@media' ) ) {
				$out = array_merge( $out, self::rules( $body, trim( substr( $prelude, 6 ) ) ) );
			} elseif ( str_starts_with( $prelude, '@supports' ) || str_starts_with( $prelude, '@layer' ) || str_starts_with( $prelude, '@container' ) ) {
				$out = array_merge( $out, self::rules( $body, $media ) );
			} elseif ( str_starts_with( $prelude, '@' ) ) {
				continue;
			} elseif ( '' !== $prelude ) {
				// Statements before the rule (e.g. @charset …;) end with ";".
				$prelude = trim( (string) substr( $prelude, (int) strrpos( ';' . $prelude, ';' ) ) );
				$out[]   = array( 'selector' => $prelude, 'body' => trim( $body ), 'media' => $media );
			}
		}

		return $out;
	}
}
