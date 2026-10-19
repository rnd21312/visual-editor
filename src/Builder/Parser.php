<?php
/**
 * Parses builder source: ordinary HTML plus
 *  - builder tags:   <sve-loop>, <sve-component>, <sve-island>, <sve-content>, <sve-menu>, <sve-if>, …
 *  - bindings:       {{ post.title }}  {{ post.date | date:"j F Y" }}  {{{ raw }}}
 *  - blocks:         {{#each posts as item}} … {{else}} … {{/each}}   {{#if a == "x"}} … {{/if}}  {{#unless}}
 *
 * Plain HTML is kept byte for byte (it is never re-serialized), so whatever the author wrote is what visitors get.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor\Builder;

defined( 'ABSPATH' ) || exit;

final class Parser {

	private const TOKEN = '/(<\/?sve-[a-z][a-z0-9-]*(?:\s(?:[^>"\']|"[^"]*"|\'[^\']*\')*)?\/?>|\{\{\{[\s\S]*?\}\}\}|\{\{[\s\S]*?\}\})/i';

	/** Attributes whose bindings are URLs (escaped with esc_url). */
	private const URL_ATTRS = array( 'href', 'src', 'action', 'poster', 'srcset', 'data-src', 'data-href', 'data-bg', 'formaction', 'xlink:href', 'cite' );

	/** @var array<string, array<int, array<string, mixed>>> */
	private static array $cache = array();

	/** @var array<int, array<string, mixed>> */
	private array $tokens = array();

	private int $pos = 0;

	/**
	 * @return array<int, array<string, mixed>> Tree of nodes.
	 */
	public static function parse( string $source ): array {
		$key = md5( $source );
		if ( ! isset( self::$cache[ $key ] ) ) {
			$parser              = new self();
			$parser->tokens      = self::tokenize( $source );
			self::$cache[ $key ] = $parser->nodes( array() )['nodes'];
		}

		return self::$cache[ $key ];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function tokenize( string $source ): array {
		$out   = array();
		$tail  = '';
		$parts = preg_split( self::TOKEN, $source, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		foreach ( false === $parts ? array( $source ) : $parts as $part ) {
			if ( str_starts_with( $part, '{{' ) && str_ends_with( $part, '}}' ) ) {
				$raw   = str_starts_with( $part, '{{{' ) && str_ends_with( $part, '}}}' );
				$inner = trim( $raw ? substr( $part, 3, -3 ) : substr( $part, 2, -2 ) );
				if ( '' === $inner || str_starts_with( $inner, '!' ) ) {
					continue;
				}
				if ( ! $raw && str_starts_with( $inner, '#' ) ) {
					$bits  = preg_split( '/\s+/', substr( $inner, 1 ), 2 );
					$out[] = array( 't' => 'open', 'kind' => strtolower( (string) ( $bits[0] ?? '' ) ), 'arg' => trim( (string) ( $bits[1] ?? '' ) ) );
				} elseif ( ! $raw && ( 'else' === $inner || str_starts_with( $inner, 'else if ' ) ) ) {
					$out[] = array( 't' => 'else', 'arg' => 'else' === $inner ? '' : trim( substr( $inner, 8 ) ) );
				} elseif ( ! $raw && str_starts_with( $inner, '/' ) ) {
					$out[] = array( 't' => 'close', 'kind' => strtolower( trim( substr( $inner, 1 ) ) ) );
				} else {
					$out[] = array( 't' => 'var', 'expr' => $inner, 'raw' => $raw, 'ctx' => self::context( $tail ) );
				}
				continue;
			}
			if ( 1 === preg_match( '/^<(\/?)sve-([a-z][a-z0-9-]*)/i', $part, $m ) ) {
				if ( '/' === $m[1] ) {
					$out[] = array( 't' => 'end', 'name' => strtolower( $m[2] ) );
				} else {
					$out[] = array( 't' => 'tag', 'name' => strtolower( $m[2] ), 'attrs' => self::attributes( $part ), 'self' => str_ends_with( $part, '/>' ) );
				}
				continue;
			}
			$out[] = array( 't' => 'text', 'value' => $part );
			$tail  = substr( $tail . $part, -400 );
		}

		return $out;
	}

	/**
	 * Reads nodes until one of the stop tokens.
	 *
	 * @param array<int, string> $stop Token signatures that end this list ("close:each", "end:loop", "else").
	 * @return array{nodes: array<int, array<string, mixed>>, stopped: string}
	 */
	private function nodes( array $stop ): array {
		$nodes = array();
		while ( $this->pos < count( $this->tokens ) ) {
			$tok = $this->tokens[ $this->pos ];
			$sig = match ( $tok['t'] ) {
				'close' => 'close:' . $tok['kind'],
				'end'   => 'end:' . $tok['name'],
				'else'  => 'else',
				default => '',
			};
			if ( '' !== $sig && in_array( $sig, $stop, true ) ) {
				return array( 'nodes' => $nodes, 'stopped' => $sig );
			}
			++$this->pos;

			switch ( $tok['t'] ) {
				case 'text':
					$nodes[] = array( 'type' => 'text', 'value' => $tok['value'] );
					break;
				case 'var':
					$nodes[] = array( 'type' => 'var', 'expr' => $tok['expr'], 'raw' => $tok['raw'], 'ctx' => $tok['ctx'] );
					break;
				case 'open':
					$nodes[] = $this->block( $tok['kind'], $tok['arg'] );
					break;
				case 'tag':
					$el = array( 'type' => 'el', 'name' => $tok['name'], 'attrs' => $tok['attrs'], 'children' => array(), 'else' => array() );
					if ( ! $tok['self'] ) {
						$inner = $this->nodes( array( 'end:' . $tok['name'] ) );
						$el['children'] = $inner['nodes'];
						if ( 'end:' . $tok['name'] === $inner['stopped'] ) {
							++$this->pos;
						}
					}
					$nodes[] = $el;
					break;
				default:
					// Stray closing tokens are ignored.
					break;
			}
		}

		return array( 'nodes' => $nodes, 'stopped' => '' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function block( string $kind, string $arg ): array {
		$block = array( 'type' => 'block', 'kind' => $kind, 'arg' => $arg, 'children' => array(), 'else' => array() );
		$stop  = array( 'close:' . $kind, 'else' );
		$first = $this->nodes( $stop );

		$block['children'] = $first['nodes'];
		if ( 'else' === $first['stopped'] ) {
			$else_arg = (string) $this->tokens[ $this->pos ]['arg'];
			++$this->pos;
			if ( '' !== $else_arg && 'if' === $kind ) {
				// {{else if x}} … : a nested if that shares this block's closing tag.
				$nested         = $this->block( 'if', $else_arg );
				$block['else']  = array( $nested );
				return $block;
			}
			$second        = $this->nodes( array( 'close:' . $kind ) );
			$block['else'] = $second['nodes'];
			if ( 'close:' . $kind === $second['stopped'] ) {
				++$this->pos;
			}
			return $block;
		}
		if ( 'close:' . $kind === $first['stopped'] ) {
			++$this->pos;
		}

		return $block;
	}

	/**
	 * html | attr | url — where a binding is printed.
	 */
	private static function context( string $tail ): string {
		$lt = strrpos( $tail, '<' );
		$gt = strrpos( $tail, '>' );
		if ( false === $lt || ( false !== $gt && $gt > $lt ) ) {
			return 'html';
		}
		if ( 1 === preg_match( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*["\']?[^"\'=<>]*$/', substr( $tail, $lt ), $m ) ) {
			return in_array( strtolower( $m[1] ), self::URL_ATTRS, true ) ? 'url' : 'attr';
		}

		return 'attr';
	}

	/**
	 * @return array<string, string>
	 */
	public static function attributes( string $tag ): array {
		$out  = array();
		$body = (string) preg_replace( '/^<\/?sve-[a-z0-9-]+|\/?>$/i', '', $tag );
		preg_match_all( '/([a-zA-Z_:@][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>\/=`]+)))?/', $body, $matches, PREG_SET_ORDER );
		foreach ( $matches as $m ) {
			$value = '';
			foreach ( array( 2, 3, 4 ) as $i ) {
				if ( isset( $m[ $i ] ) && '' !== $m[ $i ] ) {
					$value = $m[ $i ];
					break;
				}
			}
			$out[ strtolower( $m[1] ) ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		return $out;
	}
}
