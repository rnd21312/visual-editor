<?php
/**
 * A compact map of a page for the AI API: every visible tag with the CSS selector the editor would use for
 * it, so a tool can point at "the second section's heading" exactly. Built from the page's server-rendered
 * HTML (pages that only exist after JavaScript runs show their fallback markup).
 *
 * @package Suntourz\VisualEditor
 */

declare(strict_types=1);

namespace Suntourz\VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Outline {

	private const SKIP = array( 'script', 'style', 'noscript', 'template', 'link', 'meta', 'svg', 'path', 'head' );

	/**
	 * @return array{url: string, title: string, nodes: array<int, array<string, mixed>>, truncated: bool}|\WP_Error
	 */
	public static function build( string $url, int $max = 250 ) {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( (string) wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
			return new \WP_Error( 'sve_outline_host', 'Only pages of this site can be read.' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'user-agent'  => 'SuntourzVisualEditor/' . SVE_VERSION,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'sve_outline_fetch', 'The page could not be loaded.' );
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . wp_remote_retrieve_body( $response ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new \DOMXPath( $dom );
		$body  = $dom->getElementsByTagName( 'body' )->item( 0 );
		$title = trim( (string) $xpath->evaluate( 'string(//title)' ) );
		if ( ! $body instanceof \DOMElement ) {
			return array( 'url' => $url, 'title' => $title, 'nodes' => array(), 'truncated' => false );
		}

		$nodes     = array();
		$truncated = false;
		$walk      = static function ( \DOMElement $el, int $depth ) use ( &$walk, &$nodes, &$truncated, $max, $xpath ): void {
			foreach ( $el->childNodes as $child ) {
				if ( ! $child instanceof \DOMElement || in_array( strtolower( $child->localName ), self::SKIP, true ) ) {
					continue;
				}
				if ( count( $nodes ) >= $max ) {
					$truncated = true;
					return;
				}

				$text = '';
				foreach ( $child->childNodes as $part ) {
					if ( XML_TEXT_NODE === $part->nodeType ) {
						$text .= ' ' . $part->nodeValue;
					}
				}
				$text    = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
				$classes = array_slice( array_values( array_filter( preg_split( '/\s+/', $child->getAttribute( 'class' ) ) ?: array() ) ), 0, 8 );

				$node = array(
					'selector' => self::selector( $child, $xpath ),
					'tag'      => strtolower( $child->localName ),
					'depth'    => $depth,
				);
				if ( '' !== $child->getAttribute( 'id' ) ) {
					$node['id'] = $child->getAttribute( 'id' );
				}
				if ( array() !== $classes ) {
					$node['classes'] = $classes;
				}
				if ( '' !== $text ) {
					$node['text'] = mb_substr( $text, 0, 90 );
				}
				foreach ( array( 'href', 'src', 'alt' ) as $attr ) {
					if ( '' !== $child->getAttribute( $attr ) ) {
						$node[ $attr ] = mb_substr( $child->getAttribute( $attr ), 0, 120 );
					}
				}
				$nodes[] = $node;

				$walk( $child, $depth + 1 );
			}
		};
		$walk( $body, 0 );

		return array(
			'url'       => $url,
			'title'     => $title,
			'nodes'     => $nodes,
			'truncated' => $truncated,
		);
	}

	/**
	 * Same path rules as the editor (frontend/src/editor/selector.ts): a unique id, a lone header/footer,
	 * or body as anchor, then `tag:nth-child(k of :not([data-ve-id]))` down to the element.
	 */
	private static function selector( \DOMElement $el, \DOMXPath $xpath ): string {
		$parts = array();
		$node  = $el;

		while ( $node instanceof \DOMElement ) {
			$name = strtolower( $node->localName );
			if ( 'html' === $name || 'body' === $name ) {
				array_unshift( $parts, $name );
				break;
			}
			$id = $node->getAttribute( 'id' );
			if ( '' !== $id && 1 === preg_match( '/^[A-Za-z][\w-]*$/', $id ) && 1 !== preg_match( '/\d{3,}|^(radix|headlessui|react)/i', $id ) && 1 === (int) $xpath->evaluate( 'count(//*[@id="' . $id . '"])' ) ) {
				array_unshift( $parts, '#' . $id );
				break;
			}
			if ( ( 'header' === $name || 'footer' === $name ) && 1 === (int) $xpath->evaluate( 'count(//' . $name . ')' ) ) {
				array_unshift( $parts, $name );
				break;
			}

			$parent = $node->parentNode;
			if ( ! $parent instanceof \DOMElement ) {
				break;
			}
			$index = 0;
			foreach ( $parent->childNodes as $sibling ) {
				if ( $sibling instanceof \DOMElement ) {
					++$index;
					if ( $sibling->isSameNode( $node ) ) {
						break;
					}
				}
			}
			array_unshift( $parts, $name . ':nth-child(' . $index . ' of :not([data-ve-id]))' );
			$node = $parent;
		}

		return implode( ' > ', $parts );
	}
}
