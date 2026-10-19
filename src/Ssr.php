<?php
/**
 * Server-side application of content and structural edits (PHP 8.4+, which ships a standards-compliant HTML5
 * parser with CSS selectors). With it, changed texts, removed elements and inserted sections are already in the
 * HTML search engines, social previews and visitors without JavaScript receive — and nothing flashes.
 *
 * The browser runtime still runs afterwards: it skips everything marked as applied and covers content that
 * appears later (sliders, AJAX). Any problem returns the untouched page.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

defined( 'ABSPATH' ) || exit;

final class Ssr {

	private const PROTECTED_TAGS = array( 'script', 'style', 'base', 'meta', 'link', 'noscript', 'template', 'iframe', 'object', 'embed', 'head', 'html', 'title' );

	private static bool $started = false;

	public static function available(): bool {
		return class_exists( '\Dom\HTMLDocument' );
	}

	/**
	 * Whether this request is rendered with server-side edits.
	 */
	public static function active(): bool {
		return self::$started;
	}

	public static function hooks(): void {
		add_action( 'template_redirect', array( self::class, 'start' ), PHP_INT_MAX );
	}

	public static function start(): void {
		if ( is_admin() || Frontend::is_preview() || wp_doing_ajax() || is_feed() || is_robots() || is_trackback() || ! self::available() ) {
			return;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		$mode = (string) ( Store::get()['settings']['ssr'] ?? 'auto' );
		if ( 'off' === $mode && ! Inspector::is_probe() ) {
			return;
		}
		$items = Frontend::page_items();
		if ( array() === $items['content'] && array() === $items['ops'] ) {
			return;
		}
		self::$started = true;
		ob_start( array( self::class, 'filter' ) );
	}

	/**
	 * Output-buffer callback.
	 */
	public static function filter( string $html ): string {
		if ( '' === $html || ! str_contains( substr( $html, 0, 2000 ), '<' ) || ! preg_match( '/<html[\s>]/i', substr( $html, 0, 5000 ) ) ) {
			return $html; // Not an HTML page (JSON, redirect body, file…).
		}
		try {
			$doc = \Dom\HTMLDocument::createFromString( $html, LIBXML_NOERROR | LIBXML_COMPACT );
			self::apply( $doc, Frontend::page_items() );
			$out = $doc->saveHtml();

			return is_string( $out ) && '' !== $out ? $out : $html;
		} catch ( \Throwable $e ) {
			return $html;
		}
	}

	/**
	 * @param \Dom\HTMLDocument                    $doc   Document.
	 * @param array<string, array<int, mixed>> $items Page items.
	 */
	public static function apply( \Dom\HTMLDocument $doc, array $items ): void {
		foreach ( $items['ops'] as $op ) {
			try {
				self::op( $doc, $op );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- one bad selector must not stop the others.
			}
		}
		foreach ( $items['content'] as $edit ) {
			try {
				foreach ( $doc->querySelectorAll( (string) $edit['sel'] ) as $el ) {
					if ( empty( $edit['t'] ) && in_array( strtolower( $el->localName ), self::PROTECTED_TAGS, true ) ) {
						continue;
					}
					if ( isset( $edit['html'] ) ) {
						$el->innerHTML = (string) $edit['html'];
					} elseif ( isset( $edit['text'] ) ) {
						$el->textContent = (string) $edit['text'];
					}
					foreach ( (array) ( $edit['attrs'] ?? array() ) as $name => $value ) {
						if ( null === $value ) {
							$el->removeAttribute( (string) $name );
						} else {
							$el->setAttribute( (string) $name, (string) $value );
						}
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			}
		}
	}

	/**
	 * @param array<string, mixed> $op Structural edit.
	 */
	private static function op( \Dom\HTMLDocument $doc, array $op ): void {
		$id = (string) $op['id'];
		if ( null !== $doc->querySelector( '[data-ve-ops~="' . $id . '"]' ) ) {
			return;
		}
		$target = $doc->querySelector( (string) $op['sel'] );
		if ( null === $target ) {
			return;
		}

		switch ( $op['kind'] ) {
			case 'insert':
				// Scripts inserted server-side run natively, so embeds need nothing more.
				$node = self::fragment( $doc, (string) ( $op['html'] ?? '' ) );
				self::mark( $node, $id );
				self::place( $node, $target, (string) ( $op['pos'] ?? 'after' ) );
				break;
			case 'move':
				$to = $doc->querySelector( (string) $op['to'] );
				if ( null === $to || $to === $target || $target->contains( $to ) ) {
					return;
				}
				if ( $target->hasAttribute( 'data-ve-id' ) ) {
					$target->remove();
				} else {
					$target->replaceWith( self::placeholder( $doc, $id ) );
				}
				self::mark( $target, $id );
				self::place( $target, $to, (string) ( $op['pos'] ?? 'after' ) );
				break;
			case 'remove':
				if ( $target->hasAttribute( 'data-ve-id' ) ) {
					$target->remove();
				} else {
					$placeholder = self::placeholder( $doc, $id );
					$placeholder->setAttribute( 'data-ve-ops', $id );
					$target->replaceWith( $placeholder );
				}
				break;
			case 'tag':
				$tag = (string) ( $op['tag'] ?? '' );
				if ( ! preg_match( '/^[a-z][a-z0-9-]*$/', $tag ) || strtolower( $target->localName ) === $tag ) {
					return;
				}
				$new = $doc->createElement( $tag );
				foreach ( iterator_to_array( $target->attributes ) as $attr ) {
					if ( 'data-ve-ops' !== $attr->name ) {
						$new->setAttribute( $attr->name, $attr->value );
					}
				}
				while ( null !== $target->firstChild ) {
					$new->appendChild( $target->firstChild );
				}
				if ( $target->hasAttribute( 'data-ve-id' ) ) {
					$target->replaceWith( $new );
				} else {
					$target->replaceWith( self::placeholder( $doc, $id ), $new );
				}
				self::mark( $new, $id );
				break;
		}
	}

	/**
	 * Parses inserted markup: one element stays as it is, several nodes get a display:contents wrapper.
	 */
	private static function fragment( \Dom\HTMLDocument $doc, string $html ): \Dom\Element {
		$box            = $doc->createElement( 'div' );
		$box->innerHTML = $html;
		$nodes          = array();
		foreach ( iterator_to_array( $box->childNodes ) as $child ) {
			if ( $child instanceof \Dom\Element || '' !== trim( (string) $child->textContent ) ) {
				$nodes[] = $child;
			}
		}
		if ( 1 === count( $nodes ) && $nodes[0] instanceof \Dom\Element ) {
			return $nodes[0];
		}
		$box->setAttribute( 'style', 'display:contents' );

		return $box;
	}

	private static function placeholder( \Dom\HTMLDocument $doc, string $id ): \Dom\Element {
		$el = $doc->createElement( 'template' );
		$el->setAttribute( 'data-ve-ph', $id );

		return $el;
	}

	private static function mark( \Dom\Element $el, string $id ): void {
		$el->setAttribute( 'data-ve-ops', trim( ( $el->getAttribute( 'data-ve-ops' ) ?? '' ) . ' ' . $id ) );
		if ( ! $el->hasAttribute( 'data-ve-id' ) ) {
			$el->setAttribute( 'data-ve-id', $id );
		}
	}

	private static function place( \Dom\Element $node, \Dom\Element $target, string $pos ): void {
		match ( $pos ) {
			'before'  => $target->before( $node ),
			'prepend' => $target->prepend( $node ),
			'append'  => $target->append( $node ),
			default   => $target->after( $node ),
		};
	}
}
