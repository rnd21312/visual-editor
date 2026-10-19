<?php
/**
 * Data that builder bindings can read: {{ site.name }}, {{ post.title }}, {{ post.image.large }},
 * {{ product.price_html }}, {{ term.name }}, {{ user.name }}, {{ menus.primary }}, {{ cart.count }}…
 * Everything is resolved lazily: only what a template uses is ever loaded.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor\Builder;

defined( 'ABSPATH' ) || exit;

/**
 * Markup produced by WordPress itself (post content, price HTML, menus…): printed as is in HTML context.
 */
final class SafeHtml implements \Stringable {

	public function __construct( public string $html ) {}

	public function __toString(): string {
		return $this->html;
	}
}

/**
 * A value whose fields are computed on first access.
 */
abstract class Node {

	/** @var array<string, mixed> */
	private array $resolved = array();

	abstract protected function field( string $name ): mixed;

	public function get( string $name ): mixed {
		if ( ! array_key_exists( $name, $this->resolved ) ) {
			$this->resolved[ $name ] = $this->field( $name );
		}

		return $this->resolved[ $name ];
	}

	/** Value printed when the node itself is used as text. */
	public function text(): string {
		return '';
	}
}

final class Data {

	/** Whether the template being rendered was written by a trusted author (may read any option / private meta). */
	public static bool $trusted = false;

	/**
	 * Global scope available everywhere.
	 *
	 * @return array<string, mixed>
	 */
	public static function root(): array {
		$scope = array(
			'site'    => new SiteNode(),
			'user'    => new UserNode(),
			'request' => new RequestNode(),
			'menus'   => new MenusNode(),
			'options' => new OptionsNode(),
			'query'   => new QueryNode(),
			'archive' => new ArchiveNode(),
			'now'     => new SafeHtml( esc_html( (string) wp_date( (string) get_option( 'date_format' ) ) ) ),
			'year'    => (string) wp_date( 'Y' ),
		);
		$post = is_singular() ? get_queried_object() : null;
		if ( $post instanceof \WP_Post ) {
			$scope['post'] = new PostNode( $post );
			if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
				$scope['product'] = $scope['post']->get( 'product' );
			}
		}
		$term = get_queried_object();
		if ( $term instanceof \WP_Term ) {
			$scope['term'] = new TermNode( $term );
		}
		if ( function_exists( 'WC' ) ) {
			$scope['cart'] = new CartNode();
			$scope['shop'] = array(
				'url'         => (string) get_permalink( (int) wc_get_page_id( 'shop' ) ),
				'cart_url'    => (string) wc_get_cart_url(),
				'checkout_url' => (string) wc_get_checkout_url(),
				'account_url' => (string) get_permalink( (int) wc_get_page_id( 'myaccount' ) ),
				'currency'    => (string) get_woocommerce_currency_symbol(),
			);
		}

		return $scope;
	}

	/**
	 * Reads one field of a value.
	 */
	public static function get( mixed $value, string $key ): mixed {
		if ( $value instanceof Node ) {
			return $value->get( $key );
		}
		if ( is_array( $value ) ) {
			if ( array_key_exists( $key, $value ) ) {
				return $value[ $key ];
			}
			if ( 'length' === $key || 'count' === $key ) {
				return count( $value );
			}
			if ( 'first' === $key ) {
				return $value[ array_key_first( $value ) ] ?? null;
			}
			if ( 'last' === $key ) {
				return $value[ array_key_last( $value ) ] ?? null;
			}
			return null;
		}
		if ( $value instanceof \ArrayAccess ) {
			return $value[ $key ] ?? null;
		}
		if ( is_object( $value ) && ! $value instanceof SafeHtml ) {
			return $value->$key ?? null;
		}

		return null;
	}

	/**
	 * Text form of a value (Nodes print their main field: post → title, image → url, term → name).
	 */
	public static function text( mixed $value ): string {
		if ( $value instanceof Node ) {
			return $value->text();
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( array( self::class, 'text' ), $value ) );
		}
		if ( is_scalar( $value ) || $value instanceof \Stringable ) {
			return (string) $value;
		}

		return '';
	}

	public static function truthy( mixed $value ): bool {
		if ( $value instanceof Node ) {
			return '' !== $value->text() || ! $value instanceof ImageNode;
		}
		if ( $value instanceof SafeHtml ) {
			return '' !== trim( $value->html );
		}
		if ( is_array( $value ) ) {
			return array() !== $value;
		}

		return ! empty( $value ) && '0' !== $value;
	}

	/**
	 * Posts of a query as nodes.
	 *
	 * @param array<int, \WP_Post|int> $posts Posts.
	 * @return array<int, PostNode>
	 */
	public static function posts( array $posts ): array {
		$out = array();
		foreach ( $posts as $post ) {
			$post = get_post( $post );
			if ( $post instanceof \WP_Post ) {
				$out[] = new PostNode( $post );
			}
		}

		return $out;
	}
}

final class PostNode extends Node {

	public function __construct( public \WP_Post $post ) {}

	public function text(): string {
		return (string) get_the_title( $this->post );
	}

	protected function field( string $name ): mixed {
		$p = $this->post;
		switch ( $name ) {
			case 'id':
			case 'ID':
				return $p->ID;
			case 'title':
				return get_the_title( $p );
			case 'slug':
				return $p->post_name;
			case 'type':
				return $p->post_type;
			case 'type_label':
				$object = get_post_type_object( $p->post_type );
				return $object ? (string) $object->labels->singular_name : $p->post_type;
			case 'status':
				return $p->post_status;
			case 'url':
			case 'link':
			case 'permalink':
				return (string) get_permalink( $p );
			case 'date':
				return (string) get_the_date( '', $p );
			case 'date_iso':
				return (string) get_the_date( 'c', $p );
			case 'modified':
				return (string) get_the_modified_date( '', $p );
			case 'timestamp':
				return (int) get_post_time( 'U', true, $p );
			case 'excerpt':
				return wp_strip_all_tags( (string) get_the_excerpt( $p ) );
			case 'content':
				return new SafeHtml( Renderer::post_content( $p ) );
			case 'author':
				$user = get_userdata( (int) $p->post_author );
				return $user ? new UserNode( $user ) : null;
			case 'image':
			case 'thumbnail':
			case 'featured_image':
				$id = (int) get_post_thumbnail_id( $p );
				return $id > 0 ? new ImageNode( $id ) : null;
			case 'has_image':
				return has_post_thumbnail( $p );
			case 'terms':
				return new TermsNode( $p );
			case 'categories':
				return ( new TermsNode( $p ) )->get( 'product' === $p->post_type ? 'product_cat' : 'category' );
			case 'tags':
				return ( new TermsNode( $p ) )->get( 'product' === $p->post_type ? 'product_tag' : 'post_tag' );
			case 'category':
				$cats = $this->get( 'categories' );
				return is_array( $cats ) && array() !== $cats ? $cats[0] : null;
			case 'meta':
				return new MetaNode( $p->ID );
			case 'acf':
				return new AcfNode( $p->ID );
			case 'comments':
			case 'comment_count':
				return (int) $p->comment_count;
			case 'reading_time':
				$words = count( preg_split( '/\s+/u', trim( wp_strip_all_tags( (string) $p->post_content ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
				return max( 1, (int) ceil( $words / 220 ) );
			case 'parent':
				return $p->post_parent > 0 ? new PostNode( get_post( $p->post_parent ) ) : null;
			case 'children':
				return Data::posts( get_children( array( 'post_parent' => $p->ID, 'post_type' => $p->post_type, 'post_status' => 'publish', 'orderby' => 'menu_order', 'order' => 'ASC' ) ) );
			case 'product':
				if ( 'product' !== $p->post_type || ! function_exists( 'wc_get_product' ) ) {
					return null;
				}
				$product = wc_get_product( $p->ID );
				return $product ? new ProductNode( $product ) : null;
			case 'edit_url':
				return current_user_can( 'edit_post', $p->ID ) ? (string) get_edit_post_link( $p->ID, 'raw' ) : '';
			case 'index':
				return 0;
		}
		// post.price, post.sku… on products are shortcuts for post.product.*.
		$product = $this->get( 'product' );
		if ( $product instanceof ProductNode ) {
			return $product->get( $name );
		}

		return null;
	}
}

final class ImageNode extends Node {

	public function __construct( public int $id ) {}

	public function text(): string {
		return (string) wp_get_attachment_image_url( $this->id, 'full' );
	}

	protected function field( string $name ): mixed {
		switch ( $name ) {
			case 'id':
				return $this->id;
			case 'url':
			case 'src':
				return (string) wp_get_attachment_image_url( $this->id, 'full' );
			case 'alt':
				return (string) get_post_meta( $this->id, '_wp_attachment_image_alt', true );
			case 'caption':
				return (string) wp_get_attachment_caption( $this->id );
			case 'title':
				return (string) get_the_title( $this->id );
			case 'width':
			case 'height':
				$meta = wp_get_attachment_metadata( $this->id );
				return (int) ( is_array( $meta ) ? ( $meta[ $name ] ?? 0 ) : 0 );
			case 'srcset':
				return (string) wp_get_attachment_image_srcset( $this->id, 'full' );
			case 'html':
			case 'tag':
				return new SafeHtml( (string) wp_get_attachment_image( $this->id, 'large' ) );
		}
		// Any registered size: image.thumbnail, image.medium, image.large, image.woocommerce_single…
		$url = wp_get_attachment_image_url( $this->id, $name );

		return false === $url ? null : (string) $url;
	}
}

final class TermsNode extends Node {

	public function __construct( private \WP_Post $post ) {}

	protected function field( string $name ): mixed {
		$terms = get_the_terms( $this->post, $name );

		return is_array( $terms ) ? array_map( static fn ( \WP_Term $t ): TermNode => new TermNode( $t ), $terms ) : array();
	}
}

final class TermNode extends Node {

	public function __construct( public \WP_Term $term ) {}

	public function text(): string {
		return $this->term->name;
	}

	protected function field( string $name ): mixed {
		$t = $this->term;
		switch ( $name ) {
			case 'id':
				return $t->term_id;
			case 'name':
			case 'title':
				return $t->name;
			case 'slug':
				return $t->slug;
			case 'url':
			case 'link':
				$link = get_term_link( $t );
				return is_string( $link ) ? $link : '';
			case 'description':
				return wp_strip_all_tags( $t->description );
			case 'count':
				return (int) $t->count;
			case 'taxonomy':
				return $t->taxonomy;
			case 'parent':
				$parent = $t->parent > 0 ? get_term( $t->parent, $t->taxonomy ) : null;
				return $parent instanceof \WP_Term ? new TermNode( $parent ) : null;
			case 'image':
				$id = (int) get_term_meta( $t->term_id, 'thumbnail_id', true );
				return $id > 0 ? new ImageNode( $id ) : null;
			case 'meta':
				return new MetaNode( $t->term_id, 'term' );
			case 'children':
				$children = get_terms( array( 'taxonomy' => $t->taxonomy, 'parent' => $t->term_id, 'hide_empty' => false ) );
				return is_array( $children ) ? array_map( static fn ( \WP_Term $c ): TermNode => new TermNode( $c ), $children ) : array();
		}

		return null;
	}
}

final class UserNode extends Node {

	private ?\WP_User $user;

	public function __construct( ?\WP_User $user = null ) {
		$current    = wp_get_current_user();
		$this->user = $user ?? ( $current->exists() ? $current : null );
	}

	public function text(): string {
		return null !== $this->user ? $this->user->display_name : '';
	}

	protected function field( string $name ): mixed {
		$u = $this->user;
		switch ( $name ) {
			case 'logged_in':
				return null !== $u && is_user_logged_in() && get_current_user_id() === $u->ID;
			case 'login_url':
				return wp_login_url( home_url( add_query_arg( array() ) ) );
			case 'logout_url':
				return wp_logout_url( home_url( '/' ) );
			case 'register_url':
				return wp_registration_url();
		}
		if ( null === $u ) {
			return null;
		}
		switch ( $name ) {
			case 'id':
				return $u->ID;
			case 'name':
			case 'display_name':
				return $u->display_name;
			case 'first_name':
				return $u->first_name;
			case 'url':
				return get_author_posts_url( $u->ID );
			case 'avatar':
				return (string) get_avatar_url( $u->ID, array( 'size' => 160 ) );
			case 'bio':
			case 'description':
				return wp_strip_all_tags( (string) $u->description );
			case 'account_url':
				return function_exists( 'wc_get_page_id' ) ? (string) get_permalink( (int) wc_get_page_id( 'myaccount' ) ) : admin_url( 'profile.php' );
		}

		return null;
	}
}

final class SiteNode extends Node {

	public function text(): string {
		return (string) get_bloginfo( 'name' );
	}

	protected function field( string $name ): mixed {
		switch ( $name ) {
			case 'name':
			case 'title':
				return (string) get_bloginfo( 'name' );
			case 'description':
			case 'tagline':
				return (string) get_bloginfo( 'description' );
			case 'url':
			case 'home':
				return home_url( '/' );
			case 'logo':
				$id = (int) get_theme_mod( 'custom_logo' );
				return $id > 0 ? new ImageNode( $id ) : null;
			case 'logo_html':
				return new SafeHtml( (string) get_custom_logo() );
			case 'icon':
				return (string) get_site_icon_url( 192 );
			case 'language':
				return (string) get_bloginfo( 'language' );
			case 'rtl':
				return is_rtl();
			case 'search_url':
				return home_url( '/' );
			case 'year':
				return (string) wp_date( 'Y' );
		}

		return null;
	}
}

final class RequestNode extends Node {

	protected function field( string $name ): mixed {
		switch ( $name ) {
			case 'url':
				return home_url( add_query_arg( array() ) );
			case 'search':
			case 's':
				return (string) get_search_query();
			case 'page':
			case 'paged':
				return max( 1, (int) get_query_var( 'paged' ) );
		}
		// Query string values (sanitised text).
		return isset( $_GET[ $name ] ) && is_scalar( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $name ] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}

final class ArchiveNode extends Node {

	public function text(): string {
		return (string) $this->get( 'title' );
	}

	protected function field( string $name ): mixed {
		switch ( $name ) {
			case 'title':
				if ( is_search() ) {
					/* translators: %s: search query */
					return sprintf( __( 'Search results for “%s”', 'visual-editor' ), get_search_query() );
				}
				if ( is_home() && ! is_front_page() ) {
					return (string) get_the_title( (int) get_option( 'page_for_posts' ) );
				}
				return wp_strip_all_tags( (string) get_the_archive_title() );
			case 'description':
				return wp_strip_all_tags( (string) get_the_archive_description() );
		}

		return null;
	}
}

final class QueryNode extends Node {

	protected function field( string $name ): mixed {
		global $wp_query;
		switch ( $name ) {
			case 'posts':
				return Data::posts( (array) ( $wp_query->posts ?? array() ) );
			case 'found':
			case 'total':
				return (int) ( $wp_query->found_posts ?? 0 );
			case 'pages':
				return (int) ( $wp_query->max_num_pages ?? 0 );
			case 'page':
				return max( 1, (int) get_query_var( 'paged' ) );
		}

		return null;
	}
}

final class MetaNode extends Node {

	public function __construct( private int $id, private string $kind = 'post' ) {}

	protected function field( string $name ): mixed {
		if ( str_starts_with( $name, '_' ) && ! Data::$trusted && ! in_array( $name, array( '_price', '_regular_price', '_sale_price', '_sku', '_stock_status' ), true ) ) {
			return null; // Private meta only for trusted templates.
		}
		$value = 'term' === $this->kind ? get_term_meta( $this->id, $name, true ) : get_post_meta( $this->id, $name, true );

		return is_scalar( $value ) || is_array( $value ) ? $value : null;
	}
}

final class AcfNode extends Node {

	public function __construct( private int $id ) {}

	protected function field( string $name ): mixed {
		if ( ! function_exists( 'get_field' ) ) {
			return get_post_meta( $this->id, $name, true );
		}
		$value = get_field( $name, $this->id );
		if ( is_array( $value ) && isset( $value['ID'], $value['url'] ) ) {
			return new ImageNode( (int) $value['ID'] );
		}
		if ( $value instanceof \WP_Post ) {
			return new PostNode( $value );
		}

		return $value;
	}
}

final class OptionsNode extends Node {

	private const PUBLIC = array( 'blogname', 'blogdescription', 'date_format', 'time_format', 'timezone_string', 'woocommerce_currency', 'posts_per_page', 'admin_email_public' );

	protected function field( string $name ): mixed {
		if ( ! Data::$trusted && ! in_array( $name, self::PUBLIC, true ) ) {
			return null;
		}
		$value = get_option( $name );

		return is_scalar( $value ) || is_array( $value ) ? $value : null;
	}
}

final class MenusNode extends Node {

	protected function field( string $name ): mixed {
		$locations = get_nav_menu_locations();
		$menu      = isset( $locations[ $name ] ) ? wp_get_nav_menu_object( $locations[ $name ] ) : wp_get_nav_menu_object( $name );
		if ( ! $menu ) {
			return array();
		}
		$items = wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) );
		if ( ! is_array( $items ) ) {
			return array();
		}
		_wp_menu_item_classes_by_context( $items );
		$by_parent = array();
		foreach ( $items as $item ) {
			$by_parent[ (int) $item->menu_item_parent ][] = $item;
		}
		$build = static function ( int $parent ) use ( &$build, $by_parent ): array {
			$out = array();
			foreach ( $by_parent[ $parent ] ?? array() as $item ) {
				$classes  = array_filter( (array) $item->classes );
				$children = $build( (int) $item->ID );
				$out[]    = array(
					'id'       => (int) $item->ID,
					'title'    => (string) $item->title,
					'url'      => (string) $item->url,
					'target'   => (string) $item->target,
					'classes'  => implode( ' ', $classes ),
					'active'   => in_array( 'current-menu-item', $classes, true ) || in_array( 'current-menu-ancestor', $classes, true ) || in_array( 'current_page_item', $classes, true ),
					'children' => $children,
					'has_children' => array() !== $children,
					'description' => (string) $item->description,
				);
			}
			return $out;
		};

		return $build( 0 );
	}
}

final class CartNode extends Node {

	protected function field( string $name ): mixed {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return null;
		}
		switch ( $name ) {
			case 'count':
				return (int) WC()->cart->get_cart_contents_count();
			case 'total':
				return new SafeHtml( (string) WC()->cart->get_cart_total() );
			case 'url':
				return (string) wc_get_cart_url();
			case 'checkout_url':
				return (string) wc_get_checkout_url();
			case 'empty':
				return WC()->cart->is_empty();
		}

		return null;
	}
}

final class ProductNode extends Node {

	public function __construct( public \WC_Product $product ) {}

	public function text(): string {
		return $this->product->get_name();
	}

	protected function field( string $name ): mixed {
		$p = $this->product;
		switch ( $name ) {
			case 'id':
				return $p->get_id();
			case 'name':
			case 'title':
				return $p->get_name();
			case 'price':
				return (string) $p->get_price();
			case 'regular_price':
				return (string) $p->get_regular_price();
			case 'sale_price':
				return (string) $p->get_sale_price();
			case 'price_html':
				return new SafeHtml( (string) $p->get_price_html() );
			case 'on_sale':
				return $p->is_on_sale();
			case 'discount':
				$regular = (float) $p->get_regular_price();
				$sale    = (float) $p->get_sale_price();
				return $regular > 0 && $sale > 0 ? (int) round( ( 1 - $sale / $regular ) * 100 ) : 0;
			case 'sku':
				return (string) $p->get_sku();
			case 'in_stock':
				return $p->is_in_stock();
			case 'stock_status':
				return (string) $p->get_stock_status();
			case 'stock_quantity':
				return $p->get_stock_quantity();
			case 'rating':
				return (float) $p->get_average_rating();
			case 'rating_count':
				return (int) $p->get_rating_count();
			case 'review_count':
				return (int) $p->get_review_count();
			case 'type':
				return $p->get_type();
			case 'featured':
				return $p->is_featured();
			case 'short_description':
				return new SafeHtml( (string) apply_filters( 'woocommerce_short_description', $p->get_short_description() ) );
			case 'description':
				return new SafeHtml( (string) wpautop( $p->get_description() ) );
			case 'add_to_cart_url':
				return (string) $p->add_to_cart_url();
			case 'add_to_cart_text':
				return (string) $p->add_to_cart_text();
			case 'add_to_cart':
				ob_start();
				$GLOBALS['product'] = $p; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				if ( is_singular( 'product' ) && get_queried_object_id() === $p->get_id() && function_exists( 'woocommerce_template_single_add_to_cart' ) ) {
					woocommerce_template_single_add_to_cart();
				} elseif ( function_exists( 'woocommerce_template_loop_add_to_cart' ) ) {
					woocommerce_template_loop_add_to_cart();
				}
				return new SafeHtml( (string) ob_get_clean() );
			case 'gallery':
				return array_map( static fn ( $id ): ImageNode => new ImageNode( (int) $id ), $p->get_gallery_image_ids() );
			case 'image':
				$id = (int) $p->get_image_id();
				return $id > 0 ? new ImageNode( $id ) : null;
			case 'attributes':
				$out = array();
				foreach ( $p->get_attributes() as $attribute ) {
					if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->get_visible() ) {
						continue;
					}
					$values = $attribute->is_taxonomy()
						? wc_get_product_terms( $p->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
						: $attribute->get_options();
					$out[]  = array(
						'name'   => wc_attribute_label( $attribute->get_name() ),
						'values' => array_map( 'strval', (array) $values ),
						'text'   => implode( ', ', array_map( 'strval', (array) $values ) ),
					);
				}
				return $out;
			case 'categories':
				$terms = get_the_terms( $p->get_id(), 'product_cat' );
				return is_array( $terms ) ? array_map( static fn ( \WP_Term $t ): TermNode => new TermNode( $t ), $terms ) : array();
			case 'url':
				return (string) $p->get_permalink();
			case 'related':
				return Data::posts( wc_get_related_products( $p->get_id(), 4 ) );
			case 'upsells':
				return Data::posts( $p->get_upsell_ids() );
		}

		return null;
	}
}
