<?php
/**
 * Renders builder source (see Parser) to HTML on the server: bindings, blocks, loops over WordPress queries,
 * reusable components with props and slots, React islands, menus, shortcodes, Gutenberg blocks.
 *
 * Output escaping depends on where a binding stands: text in HTML is escaped, attribute values are attribute-
 * escaped, URL attributes go through esc_url. {{{ triple }}} prints markup: as is for trusted authors, filtered
 * through the post HTML rules otherwise. WordPress-generated markup (post content, prices, menus) is printed as is.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor\Builder;

use VisualEditor\Frontend;
use VisualEditor\Html;
use VisualEditor\Islands;
use VisualEditor\Store;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	private const MAX_DEPTH = 12;

	/** @var array<int, array<string, mixed>> */
	private array $scopes;

	private bool $trusted;

	private int $depth;

	/** @var array<string, bool> */
	private static array $css_printed = array();

	/** @var array<string, string> */
	private static array $scripts = array();

	/** @var array<int, bool> Posts being rendered (stops a post from including itself). */
	private static array $rendering = array();

	private static ?\WP_Query $last_query = null;

	/**
	 * @param array<int, array<string, mixed>> $scopes Scope stack (innermost last).
	 */
	private function __construct( array $scopes, bool $trusted, int $depth ) {
		$this->scopes  = $scopes;
		$this->trusted = $trusted;
		$this->depth   = $depth;
	}

	/**
	 * Renders builder source.
	 *
	 * @param array<string, mixed> $vars Extra variables on top of the global scope (post, props, item…).
	 */
	public static function render( string $source, array $vars = array(), bool $trusted = false, int $depth = 0 ): string {
		if ( $depth > self::MAX_DEPTH ) {
			return '<!-- sve: components nested too deep -->';
		}
		$previous      = Data::$trusted;
		Data::$trusted = $trusted;
		$renderer      = new self( array( Data::root(), $vars ), $trusted, $depth );
		$html          = $renderer->nodes( Parser::parse( $source ) );
		Data::$trusted = $previous;

		return $html;
	}

	/**
	 * Content of a post: its builder source when it has one, otherwise the regular content filters.
	 */
	public static function post_content( \WP_Post $post ): string {
		if ( isset( self::$rendering[ $post->ID ] ) ) {
			return '';
		}
		self::$rendering[ $post->ID ] = true;
		try {
			$source = Router::source( $post->ID );
			if ( null !== $source ) {
				return self::render( $source['source'], array( 'post' => new PostNode( $post ) ), $source['trusted'] );
			}
			$previous        = $GLOBALS['post'] ?? null;
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $post );
			$html = (string) apply_filters( 'the_content', get_the_content( null, false, $post ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			if ( $previous instanceof \WP_Post ) {
				setup_postdata( $previous );
			}

			return $html;
		} finally {
			unset( self::$rendering[ $post->ID ] );
		}
	}

	/**
	 * Markup of a component with props (used by structural edits on theme pages and by <sve-component>).
	 *
	 * @param array<string, mixed> $component Component.
	 * @param array<string, mixed> $props     Prop values.
	 */
	public static function component_html( array $component, array $props, string $slot = '', int $depth = 0, bool $inline_css = false ): string {
		$values = array();
		foreach ( (array) ( $component['props'] ?? array() ) as $prop ) {
			$values[ $prop['name'] ] = $prop['default'] ?? '';
		}
		$values  = array_merge( $values, $props );
		$trusted = ! empty( $component['trusted'] );
		$kind    = (string) ( $component['kind'] ?? 'html' );

		if ( 'react' === $kind ) {
			$fallback = '' !== trim( (string) $component['html'] ) ? self::render( (string) $component['html'], array( 'props' => $values, 'slot' => new SafeHtml( $slot ) ), $trusted, $depth + 1 ) : $slot;
			return self::css_once( $component, $inline_css ) . Islands::markup( $component, $values, $fallback );
		}
		if ( 'embed' === $kind ) {
			return self::css_once( $component, $inline_css ) . ( $trusted ? (string) $component['html'] : Html::clean( (string) $component['html'], false ) );
		}
		if ( 'jsonld' === $kind ) {
			$json = self::render( (string) $component['html'], array( 'props' => $values ), $trusted, $depth + 1 );
			$data = json_decode( $json, true );
			return is_array( $data ) ? '<script type="application/ld+json">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' : '';
		}

		$html = self::render( (string) $component['html'], array( 'props' => $values, 'slot' => new SafeHtml( $slot ) ), $trusted, $depth + 1 );
		$html = (string) preg_replace( '/^(\s*<[a-zA-Z][\w-]*)/', '$1 data-sve-c="' . esc_attr( (string) $component['id'] ) . '"', $html, 1 );
		if ( ! empty( $component['js'] ) && $trusted ) {
			self::$scripts[ (string) $component['id'] ] = (string) $component['js'];
		}

		return self::css_once( $component, $inline_css ) . $html;
	}

	/**
	 * @param array<string, mixed> $component Component.
	 */
	private static function css_once( array $component, bool $inline ): string {
		$id = (string) $component['id'];
		if ( ! $inline || isset( self::$css_printed[ $id ] ) || '' === trim( (string) ( $component['css'] ?? '' ) ) ) {
			return '';
		}
		self::$css_printed[ $id ] = true;

		return '<style data-sve-comp="' . esc_attr( $id ) . '">' . str_replace( '<', '', (string) $component['css'] ) . '</style>';
	}

	/**
	 * Component scripts: each runs once per instance with `root` = the instance element.
	 */
	public static function print_component_scripts(): void {
		foreach ( self::$scripts as $id => $code ) {
			printf(
				"<script data-sve-comp-js=\"%1\$s\">document.querySelectorAll('[data-sve-c=\"%1\$s\"]').forEach(function(root){try{%2\$s\n}catch(e){console.error('[sve component %1\$s]',e)}});</script>\n",
				esc_attr( $id ),
				$code // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- administrators only.
			);
		}
		self::$scripts = array();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find_component( string $ref ): ?array {
		$ref = trim( $ref );
		foreach ( Store::get()['components'] as $component ) {
			if ( $component['id'] === $ref || 0 === strcasecmp( (string) $component['name'], $ref ) ) {
				return $component;
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------ tree walking */

	/**
	 * @param array<int, array<string, mixed>> $nodes Nodes.
	 */
	private function nodes( array $nodes ): string {
		$out = '';
		foreach ( $nodes as $node ) {
			$out .= match ( $node['type'] ) {
				'text'  => $node['value'],
				'var'   => $this->variable( $node ),
				'block' => $this->block( $node ),
				'el'    => $this->element( $node ),
				default => '',
			};
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $node Variable node.
	 */
	private function variable( array $node ): string {
		$value = $this->expression( (string) $node['expr'] );
		$ctx   = (string) $node['ctx'];

		if ( 'url' === $ctx ) {
			return esc_url( Data::text( $value ) );
		}
		if ( 'attr' === $ctx ) {
			return esc_attr( $value instanceof SafeHtml ? wp_strip_all_tags( $value->html ) : Data::text( $value ) );
		}
		if ( $value instanceof SafeHtml ) {
			return $value->html;
		}
		if ( $value instanceof ImageNode && ! $node['raw'] ) {
			return esc_url( $value->text() );
		}
		if ( $node['raw'] ) {
			$text = Data::text( $value );
			return $this->trusted ? $text : Html::clean( $text, false );
		}

		return esc_html( Data::text( $value ) );
	}

	/**
	 * @param array<string, mixed> $node Block node.
	 */
	private function block( array $node ): string {
		$arg = (string) $node['arg'];
		switch ( $node['kind'] ) {
			case 'each':
				$alias = 'item';
				if ( 1 === preg_match( '/^(.+?)\s+as\s+([A-Za-z_]\w*)$/', $arg, $m ) ) {
					$arg   = $m[1];
					$alias = $m[2];
				} elseif ( 1 === preg_match( '/^([A-Za-z_]\w*)\s+in\s+(.+)$/', $arg, $m ) ) {
					$alias = $m[1];
					$arg   = $m[2];
				}
				$list = $this->expression( $arg );
				if ( $list instanceof Node || ! is_iterable( $list ) ) {
					$list = array();
				}
				$list = is_array( $list ) ? $list : iterator_to_array( $list );
				if ( array() === $list ) {
					return $this->nodes( $node['else'] );
				}
				return $this->iterate( $list, $alias, $node['children'] );
			case 'if':
				return $this->condition( $arg ) ? $this->nodes( $node['children'] ) : $this->nodes( $node['else'] );
			case 'unless':
				return ! $this->condition( $arg ) ? $this->nodes( $node['children'] ) : $this->nodes( $node['else'] );
			case 'with':
				$value = $this->expression( $arg );
				if ( ! Data::truthy( $value ) ) {
					return $this->nodes( $node['else'] );
				}
				return $this->scoped( array( 'this' => $value, 'it' => $value ), $node['children'] );
		}

		return $this->nodes( $node['children'] );
	}

	/**
	 * @param array<int|string, mixed>         $list     Items.
	 * @param array<int, array<string, mixed>> $children Template.
	 */
	private function iterate( array $list, string $alias, array $children, int $total = 0 ): string {
		$out   = '';
		$count = count( $list );
		$i     = 0;
		$saved = $GLOBALS['post'] ?? null;
		foreach ( $list as $key => $item ) {
			if ( $item instanceof PostNode ) {
				$GLOBALS['post'] = $item->post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- shortcodes and template tags inside loops need it.
				setup_postdata( $item->post );
			}
			$vars = array(
				$alias   => $item,
				'this'   => $item,
				'@index' => $i,
				'@key'   => $key,
				'loop'   => array(
					'index'  => $i,
					'number' => $i + 1,
					'first'  => 0 === $i,
					'last'   => $i === $count - 1,
					'odd'    => 1 === $i % 2,
					'even'   => 0 === $i % 2,
					'count'  => $count,
					'total'  => $total > 0 ? $total : $count,
				),
			);
			if ( $item instanceof PostNode ) {
				$vars['post'] = $item;
				$product      = $item->get( 'product' );
				if ( null !== $product ) {
					$vars['product'] = $product;
				}
			}
			$out .= $this->scoped( $vars, $children );
			++$i;
		}
		if ( $saved instanceof \WP_Post ) {
			$GLOBALS['post'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $saved );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>             $vars  Variables.
	 * @param array<int, array<string, mixed>> $nodes Nodes.
	 */
	private function scoped( array $vars, array $nodes ): string {
		$this->scopes[] = $vars;
		$out            = $this->nodes( $nodes );
		array_pop( $this->scopes );

		return $out;
	}

	/* ------------------------------------------------------------------ builder tags */

	/**
	 * @param array<string, mixed> $node Element node.
	 */
	private function element( array $node ): string {
		$html = $this->element_inner( $node );
		$sid  = (string) ( $node['attrs']['data-sid'] ?? '' );
		// In the editor's preview, builder tags become selectable boxes (layout-neutral).
		if ( '' !== $sid && Frontend::is_preview() && ! in_array( $node['name'], array( 'component', 'empty', 'slot', 'if' ), true ) ) {
			return '<div data-sid="' . esc_attr( $sid ) . '" data-sve-tag="' . esc_attr( (string) $node['name'] ) . '" style="display:contents">' . $html . '</div>';
		}

		return $html;
	}

	/**
	 * @param array<string, mixed> $node Element node.
	 */
	private function element_inner( array $node ): string {
		$attrs = array();
		foreach ( (array) $node['attrs'] as $name => $value ) {
			$attrs[ $name ] = str_contains( (string) $value, '{{' ) ? $this->interpolate( (string) $value ) : (string) $value;
		}
		$children = (array) $node['children'];

		switch ( $node['name'] ) {
			case 'loop':
				return $this->loop( $attrs, $children );
			case 'empty':
				return ''; // Rendered by the loop when it has no results.
			case 'component':
				$component = self::find_component( (string) ( $attrs['ref'] ?? '' ) );
				if ( null === $component ) {
					return Frontend::is_preview() ? '<div class="sve-missing">Missing component: ' . esc_html( (string) ( $attrs['ref'] ?? '' ) ) . '</div>' : '';
				}
				$props = json_decode( (string) ( $attrs['props'] ?? '' ), true );
				$props = is_array( $props ) ? $props : array();
				foreach ( $attrs as $name => $value ) {
					if ( str_starts_with( $name, 'prop-' ) || str_starts_with( $name, ':' ) ) {
						$props[ str_replace( '-', '_', (string) preg_replace( '/^(prop-|:)/', '', $name ) ) ] = $value;
					}
				}
				$slot = $this->nodes( $children );
				$html = self::component_html( $component, $props, $slot, $this->depth + 1, true );
				return isset( $attrs['data-sid'] ) && Frontend::is_preview()
					? '<div data-sid="' . esc_attr( $attrs['data-sid'] ) . '" data-sve-instance="' . esc_attr( (string) $component['id'] ) . '" style="display:contents">' . $html . '</div>'
					: $html;
			case 'island':
				$component = self::find_component( (string) ( $attrs['ref'] ?? '' ) );
				if ( null === $component || 'react' !== $component['kind'] ) {
					return '';
				}
				$props = json_decode( (string) ( $attrs['props'] ?? '' ), true );
				return Islands::markup( $component, is_array( $props ) ? $props : array(), $this->nodes( $children ) );
			case 'content':
				$post = $this->lookup( 'post' );
				if ( $post instanceof PostNode ) {
					return self::post_content( $post->post );
				}
				return '';
			case 'shortcode':
				$text = $this->nodes( $children );
				return do_shortcode( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
			case 'block':
				$block_attrs = json_decode( (string) ( $attrs['attrs'] ?? '' ), true );
				return render_block(
					array(
						'blockName'    => (string) ( $attrs['name'] ?? '' ),
						'attrs'        => is_array( $block_attrs ) ? $block_attrs : array(),
						'innerHTML'    => $this->nodes( $children ),
						'innerContent' => array( $this->nodes( $children ) ),
						'innerBlocks'  => array(),
					)
				);
			case 'menu':
				$args = array(
					'echo'        => false,
					'container'   => false,
					'fallback_cb' => false,
					'menu_class'  => (string) ( $attrs['class'] ?? 'sve-menu' ),
					'depth'       => (int) ( $attrs['depth'] ?? 0 ),
				);
				if ( ! empty( $attrs['location'] ) ) {
					$args['theme_location'] = (string) $attrs['location'];
				}
				if ( ! empty( $attrs['menu'] ) ) {
					$args['menu'] = (string) $attrs['menu'];
				}
				return (string) wp_nav_menu( $args );
			case 'if':
				$ok = $this->condition( (string) ( $attrs['test'] ?? '' ) );
				return ( isset( $attrs['not'] ) ? ! $ok : $ok ) ? $this->nodes( $children ) : '';
			case 'slot':
				$slot = $this->lookup( 'slot' );
				return $slot instanceof SafeHtml && '' !== trim( $slot->html ) ? $slot->html : $this->nodes( $children );
			case 'pagination':
				return $this->pagination();
			case 'search':
				return sprintf(
					'<form role="search" method="get" class="sve-search" action="%1$s"><input type="search" name="s" value="%2$s" placeholder="%3$s" aria-label="%3$s"><button type="submit">%4$s</button></form>',
					esc_url( home_url( '/' ) ),
					esc_attr( (string) get_search_query() ),
					esc_attr( (string) ( $attrs['placeholder'] ?? __( 'Search…', 'visual-editor' ) ) ),
					esc_html( (string) ( $attrs['button'] ?? __( 'Search', 'visual-editor' ) ) )
				);
			case 'breadcrumbs':
				return self::breadcrumbs();
		}

		return $this->nodes( $children );
	}

	/**
	 * <sve-loop query='{"post_type":"product","posts_per_page":8}' as="item"> … <sve-empty>…</sve-empty></sve-loop>
	 * Sources: query (default), main (the page's own query), related, terms, items="path".
	 *
	 * @param array<string, string>            $attrs    Attributes.
	 * @param array<int, array<string, mixed>> $children Template.
	 */
	private function loop( array $attrs, array $children ): string {
		$alias  = (string) preg_replace( '/[^A-Za-z_0-9]/', '', (string) ( $attrs['as'] ?? 'item' ) );
		$alias  = '' !== $alias ? $alias : 'item';
		$source = (string) ( $attrs['source'] ?? ( isset( $attrs['items'] ) ? 'items' : 'query' ) );
		$args   = json_decode( (string) ( $attrs['query'] ?? '' ), true );
		$args   = is_array( $args ) ? $args : array();
		$empty  = array_values( array_filter( $children, static fn ( array $n ): bool => 'el' === $n['type'] && 'empty' === $n['name'] ) );
		$total  = 0;

		switch ( $source ) {
			case 'items':
				$list = $this->expression( (string) $attrs['items'] );
				$list = is_array( $list ) ? $list : array();
				break;
			case 'main':
				global $wp_query;
				self::$last_query = $wp_query;
				$list             = Data::posts( (array) $wp_query->posts );
				$total            = (int) $wp_query->found_posts;
				break;
			case 'terms':
				$terms = get_terms( Query::terms_args( $args ) );
				$list  = is_array( $terms ) ? array_map( static fn ( \WP_Term $t ): TermNode => new TermNode( $t ), $terms ) : array();
				break;
			case 'related':
				$post = $this->lookup( 'post' );
				$list = $post instanceof PostNode ? Data::posts( Query::related( $post->post, (int) ( $args['posts_per_page'] ?? 4 ) ) ) : array();
				break;
			default:
				$current = $this->lookup( 'post' );
				$query   = new \WP_Query( Query::args( $args, $current instanceof PostNode ? $current->post : null, isset( $attrs['paginate'] ) ) );
				if ( isset( $attrs['paginate'] ) ) {
					self::$last_query = $query;
				}
				$list  = Data::posts( $query->posts );
				$total = (int) $query->found_posts;
		}

		if ( array() === $list ) {
			if ( array() !== $empty ) {
				return $this->nodes( (array) $empty[0]['children'] );
			}
			return isset( $attrs['empty'] ) ? '<p class="sve-empty">' . esc_html( (string) $attrs['empty'] ) . '</p>' : '';
		}

		return $this->iterate( $list, $alias, $children, $total );
	}

	private function pagination(): string {
		global $wp_query;
		$query = self::$last_query ?? $wp_query;
		if ( ! $query instanceof \WP_Query || (int) $query->max_num_pages < 2 ) {
			return '';
		}
		$links = paginate_links(
			array(
				'total'     => (int) $query->max_num_pages,
				'current'   => max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) ),
				'type'      => 'list',
				'prev_text' => is_rtl() ? '→' : '←',
				'next_text' => is_rtl() ? '←' : '→',
			)
		);

		return is_string( $links ) ? '<nav class="sve-pagination" aria-label="' . esc_attr__( 'Pages', 'visual-editor' ) . '">' . $links . '</nav>' : '';
	}

	private static function breadcrumbs(): string {
		$items = array( array( (string) get_bloginfo( 'name' ), home_url( '/' ) ) );
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				if ( 'product' === $post->post_type && function_exists( 'wc_get_page_id' ) ) {
					$items[] = array( get_the_title( (int) wc_get_page_id( 'shop' ) ), (string) get_permalink( (int) wc_get_page_id( 'shop' ) ) );
				} elseif ( 'post' === $post->post_type && get_option( 'page_for_posts' ) ) {
					$items[] = array( get_the_title( (int) get_option( 'page_for_posts' ) ), (string) get_permalink( (int) get_option( 'page_for_posts' ) ) );
				}
				foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor ) {
					$items[] = array( get_the_title( $ancestor ), (string) get_permalink( $ancestor ) );
				}
				$items[] = array( get_the_title( $post ), '' );
			}
		} elseif ( is_archive() || is_search() || is_home() ) {
			$items[] = array( wp_strip_all_tags( is_search() ? (string) get_search_query() : (string) get_the_archive_title() ), '' );
		}

		$html = '<nav class="sve-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'visual-editor' ) . '"><ol itemscope itemtype="https://schema.org/BreadcrumbList">';
		foreach ( $items as $i => $item ) {
			$html .= '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
			$html .= '' !== $item[1] ? '<a itemprop="item" href="' . esc_url( $item[1] ) . '"><span itemprop="name">' . esc_html( $item[0] ) . '</span></a>' : '<span itemprop="name" aria-current="page">' . esc_html( $item[0] ) . '</span>';
			$html .= '<meta itemprop="position" content="' . ( $i + 1 ) . '"></li>';
		}

		return $html . '</ol></nav>';
	}

	/* ------------------------------------------------------------------ expressions */

	/**
	 * Bindings inside builder-tag attribute values (no escaping: the value is data for the tag, e.g. JSON).
	 */
	private function interpolate( string $value ): string {
		return (string) preg_replace_callback(
			'/\{\{\{?\s*(.*?)\s*\}?\}\}/s',
			function ( array $m ): string {
				$v = $this->expression( $m[1] );
				return $v instanceof SafeHtml ? $v->html : Data::text( $v );
			},
			$value
		);
	}

	private function lookup( string $name ): mixed {
		for ( $i = count( $this->scopes ) - 1; $i >= 0; $i-- ) {
			if ( array_key_exists( $name, $this->scopes[ $i ] ) ) {
				return $this->scopes[ $i ][ $name ];
			}
		}

		return null;
	}

	/**
	 * `path | filter:arg | filter`.
	 */
	public function expression( string $expr ): mixed {
		$parts = self::split( $expr, '|' );
		$value = $this->operand( trim( (string) array_shift( $parts ) ) );
		foreach ( $parts as $filter ) {
			$args  = self::split( trim( $filter ), ':' );
			$name  = strtolower( trim( (string) array_shift( $args ) ) );
			$args  = array_map( fn ( string $a ): mixed => $this->operand( trim( $a ) ), $args );
			$value = Filters::apply( $name, $value, $args, $this->trusted );
		}

		return $value;
	}

	/**
	 * A literal or a path (a.b.c, a[0], a["key"], @index).
	 */
	private function operand( string $token ): mixed {
		if ( '' === $token ) {
			return null;
		}
		if ( 1 === preg_match( '/^"(.*)"$|^\'(.*)\'$/s', $token, $m ) ) {
			return stripcslashes( '' !== ( $m[1] ?? '' ) ? $m[1] : ( $m[2] ?? '' ) );
		}
		if ( is_numeric( $token ) ) {
			return $token + 0;
		}
		$lower = strtolower( $token );
		if ( 'true' === $lower || 'false' === $lower ) {
			return 'true' === $lower;
		}
		if ( 'null' === $lower ) {
			return null;
		}

		$segments = preg_split( '/\.|\[["\']?|["\']?\]/', $token, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $segments || array() === $segments ) {
			return null;
		}
		$value = $this->lookup( (string) array_shift( $segments ) );
		foreach ( $segments as $segment ) {
			if ( null === $value ) {
				return null;
			}
			$value = Data::get( $value, (string) $segment );
		}

		return $value;
	}

	/**
	 * Conditions of {{#if}}: a, !a, a == b, a != b, a > b, a >= b, a < b, a <= b, combined with && and ||.
	 */
	private function condition( string $expr ): bool {
		$expr = trim( $expr );
		if ( '' === $expr ) {
			return false;
		}
		foreach ( self::split( $expr, '||' ) as $any ) {
			$all = true;
			foreach ( self::split( $any, '&&' ) as $term ) {
				if ( ! $this->compare( trim( $term ) ) ) {
					$all = false;
					break;
				}
			}
			if ( $all ) {
				return true;
			}
		}

		return false;
	}

	private function compare( string $term ): bool {
		if ( str_starts_with( $term, '!' ) && ! str_starts_with( $term, '!=' ) ) {
			return ! $this->compare( trim( substr( $term, 1 ) ) );
		}
		foreach ( array( '===', '!==', '==', '!=', '>=', '<=', '>', '<', ' contains ' ) as $op ) {
			$parts = self::split( $term, $op );
			if ( 2 === count( $parts ) ) {
				$a = $this->expression( trim( $parts[0] ) );
				$b = $this->expression( trim( $parts[1] ) );
				$x = Data::text( $a );
				$y = Data::text( $b );
				return match ( $op ) {
					'===', '==' => $x === $y,
					'!==', '!=' => $x !== $y,
					'>='        => (float) $x >= (float) $y,
					'<='        => (float) $x <= (float) $y,
					'>'         => (float) $x > (float) $y,
					'<'         => (float) $x < (float) $y,
					default     => is_array( $a ) ? in_array( $y, array_map( array( Data::class, 'text' ), $a ), true ) : str_contains( $x, $y ),
				};
			}
		}

		return Data::truthy( $this->expression( $term ) );
	}

	/**
	 * Splits at a separator outside quotes and parentheses.
	 *
	 * @return array<int, string>
	 */
	public static function split( string $text, string $sep ): array {
		$out   = array();
		$buf   = '';
		$quote = '';
		$depth = 0;
		$len   = strlen( $text );
		$slen  = strlen( $sep );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $text[ $i ];
			if ( '' !== $quote ) {
				$buf .= $c;
				if ( '\\' === $c && $i + 1 < $len ) {
					$buf .= $text[ ++$i ];
				} elseif ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '(' === $c ) {
				++$depth;
			} elseif ( ')' === $c ) {
				--$depth;
			} elseif ( 0 === $depth && substr( $text, $i, $slen ) === $sep ) {
				// "||" must not split on "|" filters and ">=" not on ">".
				if ( '|' === $sep && ( '|' === ( $text[ $i + 1 ] ?? '' ) || '|' === ( $text[ $i - 1 ] ?? '' ) ) ) {
					$buf .= $c;
					continue;
				}
				if ( in_array( $sep, array( '>', '<', '!=', '==' ), true ) && '=' === ( $text[ $i + $slen ] ?? '' ) ) {
					$buf .= $c;
					continue;
				}
				if ( in_array( $sep, array( '>', '<' ), true ) && in_array( $text[ $i - 1 ] ?? '', array( '=', '!' ), true ) ) {
					$buf .= $c;
					continue;
				}
				if ( '==' === $sep && in_array( $text[ $i - 1 ] ?? '', array( '=', '!' ), true ) ) {
					$buf .= $c;
					continue;
				}
				$out[] = $buf;
				$buf   = '';
				$i    += $slen - 1;
				continue;
			}
			$buf .= $c;
		}
		$out[] = $buf;

		return $out;
	}
}
