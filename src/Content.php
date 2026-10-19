<?php
/**
 * Site content for the editor and the AI API: pages, posts, products (WooCommerce) and any public post type,
 * terms, menus, media, builder templates, site settings and plugins. Every call checks the capabilities of the
 * acting user (the logged-in person, or the user an AI key acts as).
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

use VisualEditor\Builder\Router;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Content {

	/** Site settings anyone who may manage options can change through the builder. */
	private const OPTIONS = array( 'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'date_format', 'time_format', 'timezone_string', 'start_of_week', 'site_icon', 'permalink_structure', 'default_comment_status', 'blog_public', 'WPLANG', 'woocommerce_currency', 'woocommerce_currency_pos', 'woocommerce_price_num_decimals', 'woocommerce_shop_page_id' );

	/** Options never changed through the API (they could lock everyone out or move the site). */
	private const PROTECTED_OPTIONS = array( 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role', 'active_plugins', 'template', 'stylesheet', 'auth_key', 'auth_salt', 'secure_auth_key', 'logged_in_key', 'nonce_key', 'db_version', 'initial_db_version', 'wp_user_roles', 'sve_api', 'cron', 'recovery_keys', 'upload_path', 'upload_url_path' );

	/* ------------------------------------------------------------------ posts */

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function post_types(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$out[ $type->name ] = array(
				'name'         => $type->name,
				'label'        => (string) $type->labels->name,
				'singular'     => (string) $type->labels->singular_name,
				'hierarchical' => (bool) $type->hierarchical,
				'taxonomies'   => array_values( get_object_taxonomies( $type->name ) ),
				'archive'      => (string) ( get_post_type_archive_link( $type->name ) ?: '' ),
				'canCreate'    => current_user_can( $type->cap->create_posts ?? 'edit_posts' ),
			);
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $args search, type, status, page, per_page, parent.
	 * @return array<string, mixed>
	 */
	public static function list_posts( array $args ): array {
		$types  = self::post_types();
		$type   = (string) ( $args['type'] ?? '' );
		$status = (string) ( $args['status'] ?? '' );
		$query  = array(
			'post_type'           => isset( $types[ $type ] ) || Router::POST_TYPE === $type ? $type : array_keys( $types ),
			'post_status'         => in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ), true ) ? $status : array( 'publish', 'draft', 'pending', 'private', 'future' ),
			's'                   => (string) ( $args['search'] ?? '' ),
			'posts_per_page'      => max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) ),
			'paged'               => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'orderby'             => '' !== (string) ( $args['search'] ?? '' ) ? 'relevance' : 'modified',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'perm'                => 'readable',
		);
		if ( isset( $args['parent'] ) ) {
			$query['post_parent'] = (int) $args['parent'];
		}
		$found = new \WP_Query( $query );
		$front = (int) get_option( 'page_on_front' );
		$blog  = (int) get_option( 'page_for_posts' );
		$shop  = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;

		return array(
			'items' => array_map(
				static function ( \WP_Post $post ) use ( $front, $blog, $shop ): array {
					$builder = Router::source( $post->ID );
					return array(
						'id'       => $post->ID,
						'title'    => '' !== get_the_title( $post ) ? html_entity_decode( get_the_title( $post ), ENT_QUOTES ) : __( '(no title)', 'visual-editor' ),
						'type'     => $post->post_type,
						'status'   => $post->post_status,
						'url'      => (string) get_permalink( $post ),
						'modified' => mysql2date( 'c', $post->post_modified_gmt, false ),
						'thumb'    => (string) get_the_post_thumbnail_url( $post, 'medium' ),
						'parent'   => $post->post_parent,
						'builder'  => null !== $builder ? $builder['mode'] : '',
						'role'     => $front === $post->ID ? 'front' : ( $blog === $post->ID ? 'blog' : ( $shop === $post->ID ? 'shop' : '' ) ),
						'canEdit'  => current_user_can( 'edit_post', $post->ID ),
					);
				},
				$found->posts
			),
			'total' => (int) $found->found_posts,
			'pages' => (int) $found->max_num_pages,
			'types' => array_map( static fn ( array $t ): string => $t['label'], $types ),
		);
	}

	/**
	 * Everything about one post.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_post( int $id ) {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return new WP_Error( 'sve_not_found', 'No post with that id.' );
		}
		if ( ! current_user_can( 'read_post', $id ) ) {
			return new WP_Error( 'sve_forbidden', 'You may not read this post.' );
		}
		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$list = get_the_terms( $post, $taxonomy );
			if ( is_array( $list ) ) {
				$terms[ $taxonomy ] = array_map( static fn ( \WP_Term $t ): array => array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug ), $list );
			}
		}
		$meta = array();
		foreach ( (array) get_post_meta( $id ) as $key => $values ) {
			if ( ! is_protected_meta( (string) $key, 'post' ) ) {
				$meta[ $key ] = maybe_unserialize( $values[0] ?? '' );
			}
		}
		$builder = Router::source( $id );
		$working = get_post_meta( $id, '_sve_source_edit', true );
		$out     = array(
			'id'             => $post->ID,
			'type'           => $post->post_type,
			'status'         => $post->post_status,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'url'            => (string) get_permalink( $post ),
			'parent'         => $post->post_parent,
			'menu_order'     => $post->menu_order,
			'excerpt'        => $post->post_excerpt,
			'content'        => null === $builder ? $post->post_content : '(built with the visual builder — see "builder.source")',
			'date'           => $post->post_date,
			'modified'       => $post->post_modified,
			'author'         => (int) $post->post_author,
			'template'       => (string) get_page_template_slug( $post ),
			'featured_image' => (int) get_post_thumbnail_id( $post ),
			'featured_url'   => (string) get_the_post_thumbnail_url( $post, 'large' ),
			'terms'          => $terms,
			'meta'           => $meta,
			'seo'            => get_post_meta( $id, '_sve_seo', true ) ?: new \stdClass(),
			'builder'        => null !== $builder ? array( 'mode' => $builder['mode'], 'source' => $builder['source'], 'draft' => (string) get_post_meta( $id, '_sve_source_draft', true ), 'working' => is_array( $working ) && (int) ( $working['user'] ?? 0 ) === get_current_user_id() ? $working : null ) : null,
		);
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$out['product'] = self::product_data( $id );
		}
		if ( Router::POST_TYPE === $post->post_type ) {
			$out['template_info'] = Router::template_info( $post );
		}

		return $out;
	}

	/**
	 * Creates or updates a post of any public type (or a builder template).
	 *
	 * Fields: type, title, slug, status, content, excerpt, parent, menu_order, date, template (page template),
	 * featured_image (attachment id or URL), terms {taxonomy: [names|ids]}, meta {key: value},
	 * builder {source, mode}, seo {…}, product {…} (WooCommerce), template_target / conditions (builder templates).
	 *
	 * @param array<string, mixed> $f       Fields.
	 * @param bool                 $trusted Author may post unfiltered HTML.
	 * @param bool                 $draft_only Keep everything unpublished (AI "draft" level).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_post( array $f, bool $trusted, bool $draft_only = false ) {
		$id     = (int) ( $f['id'] ?? 0 );
		$exists = $id > 0 ? get_post( $id ) : null;
		if ( $id > 0 && ! $exists instanceof \WP_Post ) {
			return new WP_Error( 'sve_not_found', 'No post with that id.' );
		}
		$type = $exists instanceof \WP_Post ? $exists->post_type : sanitize_key( (string) ( $f['type'] ?? 'page' ) );
		if ( ! post_type_exists( $type ) ) {
			return new WP_Error( 'sve_bad_type', 'Unknown post type "' . $type . '". Use one of: ' . implode( ', ', array_keys( self::post_types() ) ) . '.' );
		}
		$object = get_post_type_object( $type );
		if ( $exists instanceof \WP_Post ? ! current_user_can( 'edit_post', $id ) : ! current_user_can( $object->cap->create_posts ?? 'edit_posts' ) ) {
			return new WP_Error( 'sve_forbidden', 'You may not edit this content.' );
		}

		$published = $exists instanceof \WP_Post && in_array( $exists->post_status, array( 'publish', 'private', 'future' ), true );
		if ( $draft_only && $published ) {
			// A draft-level key cannot change what visitors see: builder changes become a pending draft.
			if ( isset( $f['builder']['source'] ) ) {
				update_post_meta( $id, '_sve_source_draft', wp_slash( Html::clean( (string) $f['builder']['source'], false, 2000000 ) ) );
				return array( 'id' => $id, 'pending' => true, 'note' => 'This page is published: the new layout was saved as a draft for an administrator to publish in the editor.' );
			}
			return new WP_Error( 'sve_draft_level', 'This key may only propose changes: published content can not be edited directly (builder layouts are saved as drafts). Ask for a "live" key to edit it.' );
		}

		$data = array( 'post_type' => $type );
		if ( $exists instanceof \WP_Post ) {
			$data['ID'] = $id;
		}
		foreach ( array( 'title' => 'post_title', 'excerpt' => 'post_excerpt', 'slug' => 'post_name', 'date' => 'post_date' ) as $in => $key ) {
			if ( isset( $f[ $in ] ) ) {
				$data[ $key ] = 'slug' === $in ? sanitize_title( (string) $f[ $in ] ) : (string) $f[ $in ];
			}
		}
		if ( isset( $f['content'] ) ) {
			$data['post_content'] = $trusted ? (string) $f['content'] : wp_kses_post( (string) $f['content'] );
		}
		if ( isset( $f['parent'] ) ) {
			$data['post_parent'] = (int) $f['parent'];
		}
		if ( isset( $f['menu_order'] ) ) {
			$data['menu_order'] = (int) $f['menu_order'];
		}
		$status = (string) ( $f['status'] ?? ( $exists instanceof \WP_Post ? '' : 'draft' ) );
		if ( '' !== $status ) {
			if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ) {
				return new WP_Error( 'sve_bad_status', 'status must be publish, draft, pending, private or future.' );
			}
			if ( 'publish' === $status && ! current_user_can( $object->cap->publish_posts ?? 'publish_posts' ) ) {
				$status = 'pending';
			}
			$data['post_status'] = $draft_only ? ( 'publish' === $status ? 'pending' : $status ) : $status;
		}
		if ( ! $exists instanceof \WP_Post && ! isset( $data['post_title'] ) ) {
			$data['post_title'] = __( 'Untitled', 'visual-editor' );
		}

		$result = $exists instanceof \WP_Post ? wp_update_post( wp_slash( $data ), true ) : wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id = (int) $result;

		if ( isset( $f['template'] ) ) {
			update_post_meta( $id, '_wp_page_template', sanitize_text_field( (string) $f['template'] ) );
		}
		if ( array_key_exists( 'featured_image', $f ) ) {
			$image = self::attachment_id( $f['featured_image'], $id );
			if ( $image > 0 ) {
				set_post_thumbnail( $id, $image );
			} elseif ( empty( $f['featured_image'] ) ) {
				delete_post_thumbnail( $id );
			}
		}
		foreach ( (array) ( $f['terms'] ?? array() ) as $taxonomy => $terms ) {
			if ( taxonomy_exists( (string) $taxonomy ) && is_object_in_taxonomy( $type, (string) $taxonomy ) ) {
				wp_set_object_terms( $id, self::term_ids( (string) $taxonomy, (array) $terms ), (string) $taxonomy );
			}
		}
		foreach ( (array) ( $f['meta'] ?? array() ) as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || ( is_protected_meta( $key, 'post' ) && ! $trusted ) || str_starts_with( $key, '_sve_' ) ) {
				continue;
			}
			if ( null === $value ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, $key, wp_slash( $value ) );
			}
		}
		if ( isset( $f['acf'] ) && is_array( $f['acf'] ) && function_exists( 'update_field' ) ) {
			foreach ( $f['acf'] as $key => $value ) {
				update_field( (string) $key, $value, $id );
			}
		}
		if ( isset( $f['seo'] ) && is_array( $f['seo'] ) ) {
			$current = get_post_meta( $id, '_sve_seo', true );
			$merged  = array_merge( is_array( $current ) ? $current : array(), $f['seo'] );
			update_post_meta( $id, '_sve_seo', Sanitizer::seo_entry( array_filter( $merged, static fn ( $v ): bool => null !== $v && '' !== $v && false !== $v ) ) );
		}
		if ( isset( $f['builder'] ) && is_array( $f['builder'] ) ) {
			if ( array_key_exists( 'source', $f['builder'] ) && null === $f['builder']['source'] ) {
				Router::remove_source( $id );
			} elseif ( isset( $f['builder']['source'] ) ) {
				Router::set_source( $id, (string) $f['builder']['source'], isset( $f['builder']['mode'] ) ? (string) $f['builder']['mode'] : null, $trusted );
				delete_post_meta( $id, '_sve_source_draft' );
				delete_post_meta( $id, '_sve_source_edit' );
			} elseif ( isset( $f['builder']['mode'] ) && in_array( $f['builder']['mode'], Router::MODES, true ) ) {
				update_post_meta( $id, '_sve_mode', (string) $f['builder']['mode'] );
			}
		}
		if ( Router::POST_TYPE === $type ) {
			if ( isset( $f['template_target'] ) ) {
				update_post_meta( $id, '_sve_target', Sanitizer::tpl( $f['template_target'] ) );
			}
			if ( isset( $f['conditions'] ) && is_array( $f['conditions'] ) ) {
				update_post_meta(
					$id,
					'_sve_conditions',
					array(
						'include' => array_map( 'intval', (array) ( $f['conditions']['include'] ?? array() ) ),
						'exclude' => array_map( 'intval', (array) ( $f['conditions']['exclude'] ?? array() ) ),
					)
				);
			}
			if ( isset( $f['replace'] ) ) {
				update_post_meta( $id, '_sve_replace', Sanitizer::selector( $f['replace'] ) );
			}
		}
		if ( 'product' === $type && isset( $f['product'] ) && is_array( $f['product'] ) ) {
			$saved = self::save_product( $id, $f['product'] );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}
		if ( ! empty( $f['front_page'] ) && 'page' === $type && current_user_can( 'manage_options' ) && ! $draft_only ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $id );
		}
		Cache::purge();

		return array(
			'id'     => $id,
			'url'    => (string) get_permalink( $id ),
			'status' => (string) get_post_status( $id ),
		);
	}

	/**
	 * Moves a post to the trash (or deletes it for good with $force).
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_post( int $id, bool $force ) {
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'sve_forbidden', 'You may not delete this content.' );
		}
		$result = $force ? wp_delete_post( $id, true ) : wp_trash_post( $id );
		if ( ! $result ) {
			return new WP_Error( 'sve_delete_failed', 'The post could not be deleted.' );
		}
		Cache::purge();

		return array( 'id' => $id, 'deleted' => $force ? 'permanently' : 'trash' );
	}

	/**
	 * Copies a post with its builder layout, terms and meta (as a draft).
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function duplicate_post( int $id ) {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'sve_forbidden', 'You may not copy this content.' );
		}
		$new = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $post->post_type,
					'post_title'   => $post->post_title . ' ' . __( '(copy)', 'visual-editor' ),
					'post_content' => $post->post_content,
					'post_excerpt' => $post->post_excerpt,
					'post_parent'  => $post->post_parent,
					'menu_order'   => $post->menu_order,
					'post_status'  => 'draft',
				)
			),
			true
		);
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		foreach ( (array) get_post_meta( $id ) as $key => $values ) {
			if ( in_array( $key, array( '_edit_lock', '_edit_last', '_wp_old_slug' ), true ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_post_meta( (int) $new, (string) $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_array( $terms ) ) {
				wp_set_object_terms( (int) $new, $terms, $taxonomy );
			}
		}

		return array( 'id' => (int) $new, 'url' => (string) get_permalink( (int) $new ) );
	}

	/* ------------------------------------------------------------------ WooCommerce */

	/**
	 * @return array<string, mixed>|null
	 */
	public static function product_data( int $id ): ?array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$p = wc_get_product( $id );
		if ( ! $p ) {
			return null;
		}
		$attributes = array();
		foreach ( $p->get_attributes() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute ) {
				$attributes[] = array(
					'name'      => wc_attribute_label( $attribute->get_name() ),
					'options'   => $attribute->is_taxonomy() ? wc_get_product_terms( $id, $attribute->get_name(), array( 'fields' => 'names' ) ) : $attribute->get_options(),
					'variation' => $attribute->get_variation(),
					'visible'   => $attribute->get_visible(),
				);
			}
		}
		$out = array(
			'type'              => $p->get_type(),
			'regular_price'     => $p->get_regular_price(),
			'sale_price'        => $p->get_sale_price(),
			'price'             => $p->get_price(),
			'sku'               => $p->get_sku(),
			'manage_stock'      => $p->get_manage_stock(),
			'stock_quantity'    => $p->get_stock_quantity(),
			'stock_status'      => $p->get_stock_status(),
			'virtual'           => $p->get_virtual(),
			'downloadable'      => $p->get_downloadable(),
			'featured'          => $p->get_featured(),
			'short_description' => $p->get_short_description(),
			'description'       => $p->get_description(),
			'gallery'           => array_map( 'intval', $p->get_gallery_image_ids() ),
			'weight'            => $p->get_weight(),
			'attributes'        => $attributes,
		);
		if ( $p->is_type( 'variable' ) ) {
			$out['variations'] = array();
			foreach ( $p->get_children() as $child_id ) {
				$v = wc_get_product( $child_id );
				if ( $v instanceof \WC_Product_Variation ) {
					$out['variations'][] = array(
						'id'             => $v->get_id(),
						'attributes'     => $v->get_attributes(),
						'regular_price'  => $v->get_regular_price(),
						'sale_price'     => $v->get_sale_price(),
						'sku'            => $v->get_sku(),
						'stock_quantity' => $v->get_stock_quantity(),
						'stock_status'   => $v->get_stock_status(),
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Product fields: type (simple|variable|external), regular_price, sale_price, sku, manage_stock,
	 * stock_quantity, stock_status, virtual, downloadable, featured, short_description, description, gallery
	 * (ids or URLs), weight, external_url, button_text, attributes [{name, options[], variation, visible}],
	 * variations [{attributes {name: option}, regular_price, sale_price, sku, stock_quantity}].
	 *
	 * @param array<string, mixed> $f Fields.
	 * @return true|WP_Error
	 */
	public static function save_product( int $id, array $f ) {
		if ( ! function_exists( 'wc_get_product_object' ) ) {
			return new WP_Error( 'sve_no_woocommerce', 'WooCommerce is not active.' );
		}
		$type    = in_array( $f['type'] ?? '', array( 'simple', 'variable', 'external', 'grouped' ), true ) ? (string) $f['type'] : ( wc_get_product( $id ) ? wc_get_product( $id )->get_type() : 'simple' );
		$product = wc_get_product_object( $type, $id );
		if ( ! $product ) {
			return new WP_Error( 'sve_product', 'The product could not be loaded.' );
		}
		$setters = array(
			'regular_price'     => 'set_regular_price',
			'sale_price'        => 'set_sale_price',
			'sku'               => 'set_sku',
			'manage_stock'      => 'set_manage_stock',
			'stock_quantity'    => 'set_stock_quantity',
			'stock_status'      => 'set_stock_status',
			'virtual'           => 'set_virtual',
			'downloadable'      => 'set_downloadable',
			'featured'          => 'set_featured',
			'short_description' => 'set_short_description',
			'description'       => 'set_description',
			'weight'            => 'set_weight',
			'external_url'      => 'set_product_url',
			'button_text'       => 'set_button_text',
			'catalog_visibility' => 'set_catalog_visibility',
		);
		try {
			foreach ( $setters as $key => $setter ) {
				if ( array_key_exists( $key, $f ) && method_exists( $product, $setter ) ) {
					$value = $f[ $key ];
					if ( in_array( $key, array( 'short_description', 'description' ), true ) ) {
						$value = wp_kses_post( (string) $value );
					}
					$product->$setter( $value );
				}
			}
			if ( isset( $f['gallery'] ) ) {
				$product->set_gallery_image_ids( array_values( array_filter( array_map( static fn ( $g ): int => self::attachment_id( $g, $id ), (array) $f['gallery'] ) ) ) );
			}
			if ( isset( $f['attributes'] ) && is_array( $f['attributes'] ) ) {
				$attributes = array();
				foreach ( $f['attributes'] as $i => $raw ) {
					if ( ! is_array( $raw ) || empty( $raw['name'] ) ) {
						continue;
					}
					$attribute = new \WC_Product_Attribute();
					$attribute->set_name( sanitize_text_field( (string) $raw['name'] ) );
					$attribute->set_options( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $raw['options'] ?? array() ) ) ) );
					$attribute->set_position( (int) $i );
					$attribute->set_visible( ! isset( $raw['visible'] ) || ! empty( $raw['visible'] ) );
					$attribute->set_variation( ! empty( $raw['variation'] ) );
					$attributes[] = $attribute;
				}
				$product->set_attributes( $attributes );
			}
			$product->save();

			if ( 'variable' === $type && isset( $f['variations'] ) && is_array( $f['variations'] ) ) {
				foreach ( $product->get_children() as $child ) {
					wp_delete_post( (int) $child, true );
				}
				foreach ( $f['variations'] as $raw ) {
					if ( ! is_array( $raw ) ) {
						continue;
					}
					$variation = new \WC_Product_Variation();
					$variation->set_parent_id( $id );
					$attrs = array();
					foreach ( (array) ( $raw['attributes'] ?? array() ) as $name => $option ) {
						$attrs[ sanitize_title( (string) $name ) ] = (string) $option;
					}
					$variation->set_attributes( $attrs );
					foreach ( array( 'regular_price', 'sale_price', 'sku', 'stock_quantity', 'manage_stock', 'stock_status' ) as $key ) {
						if ( isset( $raw[ $key ] ) ) {
							$variation->{'set_' . $key}( $raw[ $key ] );
						}
					}
					if ( isset( $raw['image'] ) ) {
						$variation->set_image_id( self::attachment_id( $raw['image'], $id ) );
					}
					$variation->save();
				}
				\WC_Product_Variable::sync( $id );
			}
		} catch ( \Throwable $e ) {
			return new WP_Error( 'sve_product', $e->getMessage() );
		}

		return true;
	}

	/* ------------------------------------------------------------------ terms */

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function taxonomies(): array {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$out[] = array(
				'name'         => $taxonomy->name,
				'label'        => (string) $taxonomy->labels->name,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'types'        => array_values( (array) $taxonomy->object_type ),
			);
		}

		return $out;
	}

	/**
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function list_terms( string $taxonomy, string $search = '' ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'sve_bad_taxonomy', 'Unknown taxonomy.' );
		}
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 300, 'search' => $search ) );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		return array_map(
			static fn ( \WP_Term $t ): array => array(
				'id'          => $t->term_id,
				'name'        => $t->name,
				'slug'        => $t->slug,
				'parent'      => $t->parent,
				'count'       => $t->count,
				'description' => $t->description,
				'url'         => is_string( get_term_link( $t ) ) ? get_term_link( $t ) : '',
				'image'       => (int) get_term_meta( $t->term_id, 'thumbnail_id', true ),
			),
			$terms
		);
	}

	/**
	 * @param array<string, mixed> $f taxonomy, id?, name, slug, parent, description, image.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_term( array $f ) {
		$taxonomy = (string) ( $f['taxonomy'] ?? '' );
		$object   = get_taxonomy( $taxonomy );
		if ( ! $object ) {
			return new WP_Error( 'sve_bad_taxonomy', 'Unknown taxonomy.' );
		}
		if ( ! current_user_can( $object->cap->manage_terms ) ) {
			return new WP_Error( 'sve_forbidden', 'You may not manage these terms.' );
		}
		$args = array();
		foreach ( array( 'slug', 'description' ) as $key ) {
			if ( isset( $f[ $key ] ) ) {
				$args[ $key ] = sanitize_text_field( (string) $f[ $key ] );
			}
		}
		if ( isset( $f['parent'] ) ) {
			$args['parent'] = (int) $f['parent'];
		}
		$id = (int) ( $f['id'] ?? 0 );
		if ( $id > 0 ) {
			if ( isset( $f['name'] ) ) {
				$args['name'] = sanitize_text_field( (string) $f['name'] );
			}
			$result = wp_update_term( $id, $taxonomy, $args );
		} else {
			$result = wp_insert_term( sanitize_text_field( (string) ( $f['name'] ?? '' ) ), $taxonomy, $args );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$term_id = (int) $result['term_id'];
		if ( isset( $f['image'] ) ) {
			update_term_meta( $term_id, 'thumbnail_id', self::attachment_id( $f['image'], 0 ) );
		}

		return array( 'id' => $term_id, 'url' => is_string( get_term_link( $term_id, $taxonomy ) ) ? get_term_link( $term_id, $taxonomy ) : '' );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_term( string $taxonomy, int $id ) {
		$object = get_taxonomy( $taxonomy );
		if ( ! $object || ! current_user_can( $object->cap->delete_terms ) ) {
			return new WP_Error( 'sve_forbidden', 'You may not delete these terms.' );
		}
		$result = wp_delete_term( $id, $taxonomy );

		return true === $result ? array( 'deleted' => $id ) : new WP_Error( 'sve_delete_failed', 'The term could not be deleted.' );
	}

	/**
	 * Term ids from names, slugs or ids (missing names are created when allowed).
	 *
	 * @param array<int, mixed> $terms Terms.
	 * @return array<int, int>
	 */
	private static function term_ids( string $taxonomy, array $terms ): array {
		$ids = array();
		foreach ( $terms as $term ) {
			if ( is_numeric( $term ) ) {
				$ids[] = (int) $term;
				continue;
			}
			$found = get_term_by( 'slug', sanitize_title( (string) $term ), $taxonomy ) ?: get_term_by( 'name', (string) $term, $taxonomy );
			if ( $found instanceof \WP_Term ) {
				$ids[] = $found->term_id;
			} elseif ( current_user_can( get_taxonomy( $taxonomy )->cap->manage_terms ?? 'manage_categories' ) ) {
				$created = wp_insert_term( sanitize_text_field( (string) $term ), $taxonomy );
				if ( ! is_wp_error( $created ) ) {
					$ids[] = (int) $created['term_id'];
				}
			}
		}

		return $ids;
	}

	/* ------------------------------------------------------------------ menus */

	/**
	 * @return array<string, mixed>
	 */
	public static function menus(): array {
		$locations = get_nav_menu_locations();
		$menus     = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$items   = wp_get_nav_menu_items( $menu->term_id ) ?: array();
			$menus[] = array(
				'id'        => $menu->term_id,
				'name'      => $menu->name,
				'locations' => array_keys( array_filter( $locations, static fn ( $id ): bool => (int) $id === $menu->term_id ) ),
				'items'     => array_map(
					static fn ( $item ): array => array(
						'id'     => (int) $item->ID,
						'title'  => (string) $item->title,
						'url'    => (string) $item->url,
						'parent' => (int) $item->menu_item_parent,
						'object' => (string) $item->object,
						'object_id' => (int) $item->object_id,
						'target' => (string) $item->target,
						'classes' => implode( ' ', array_filter( (array) $item->classes ) ),
					),
					$items
				),
			);
		}

		return array(
			'menus'     => $menus,
			'locations' => get_registered_nav_menus(),
		);
	}

	/**
	 * Creates or replaces a whole menu.
	 *
	 * @param array<string, mixed> $f name, id?, location?, items [{title, url | page_id | post_id | term_id+taxonomy, target, classes, children[]}].
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_menu( array $f ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error( 'sve_forbidden', 'You may not edit menus.' );
		}
		$id = (int) ( $f['id'] ?? 0 );
		if ( $id <= 0 ) {
			$existing = wp_get_nav_menu_object( (string) ( $f['name'] ?? '' ) );
			$id       = $existing ? (int) $existing->term_id : (int) wp_create_nav_menu( sanitize_text_field( (string) ( $f['name'] ?? 'Menu' ) ) );
		} elseif ( isset( $f['name'] ) ) {
			wp_update_nav_menu_object( $id, array( 'menu-name' => sanitize_text_field( (string) $f['name'] ) ) );
		}
		if ( $id <= 0 ) {
			return new WP_Error( 'sve_menu', 'The menu could not be created.' );
		}
		if ( isset( $f['items'] ) && is_array( $f['items'] ) ) {
			foreach ( wp_get_nav_menu_items( $id ) ?: array() as $old ) {
				wp_delete_post( (int) $old->ID, true );
			}
			$add = static function ( array $items, int $parent ) use ( &$add, $id ): void {
				foreach ( $items as $position => $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$args = array(
						'menu-item-title'     => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
						'menu-item-status'    => 'publish',
						'menu-item-parent-id' => $parent,
						'menu-item-position'  => (int) $position + 1,
						'menu-item-target'    => '_blank' === ( $item['target'] ?? '' ) ? '_blank' : '',
						'menu-item-classes'   => sanitize_text_field( (string) ( $item['classes'] ?? '' ) ),
					);
					$object = (int) ( $item['page_id'] ?? $item['post_id'] ?? 0 );
					if ( $object > 0 && get_post( $object ) ) {
						$args['menu-item-type']      = 'post_type';
						$args['menu-item-object']    = (string) get_post_type( $object );
						$args['menu-item-object-id'] = $object;
					} elseif ( ! empty( $item['term_id'] ) && ! empty( $item['taxonomy'] ) ) {
						$args['menu-item-type']      = 'taxonomy';
						$args['menu-item-object']    = (string) $item['taxonomy'];
						$args['menu-item-object-id'] = (int) $item['term_id'];
					} else {
						$args['menu-item-type'] = 'custom';
						$args['menu-item-url']  = esc_url_raw( (string) ( $item['url'] ?? '#' ) );
					}
					$item_id = wp_update_nav_menu_item( $id, 0, $args );
					if ( ! is_wp_error( $item_id ) && ! empty( $item['children'] ) && is_array( $item['children'] ) ) {
						$add( $item['children'], (int) $item_id );
					}
				}
			};
			$add( $f['items'], 0 );
		}
		if ( ! empty( $f['location'] ) ) {
			$locations                                   = get_nav_menu_locations();
			$locations[ sanitize_key( (string) $f['location'] ) ] = $id;
			set_theme_mod( 'nav_menu_locations', $locations );
		}
		Cache::purge();

		return array( 'id' => $id );
	}

	/* ------------------------------------------------------------------ media */

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_media( string $search = '', int $page = 1, string $type = 'image' ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => in_array( $type, array( 'image', 'video', 'audio', 'application' ), true ) ? $type : '',
				's'              => $search,
				'posts_per_page' => 40,
				'paged'          => max( 1, $page ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		return array_map(
			static fn ( \WP_Post $a ): array => array(
				'id'     => $a->ID,
				'title'  => $a->post_title,
				'url'    => (string) wp_get_attachment_url( $a->ID ),
				'thumb'  => (string) ( wp_get_attachment_image_url( $a->ID, 'medium' ) ?: wp_get_attachment_url( $a->ID ) ),
				'alt'    => (string) get_post_meta( $a->ID, '_wp_attachment_image_alt', true ),
				'mime'   => $a->post_mime_type,
				'width'  => (int) ( wp_get_attachment_metadata( $a->ID )['width'] ?? 0 ),
				'height' => (int) ( wp_get_attachment_metadata( $a->ID )['height'] ?? 0 ),
			),
			$query->posts
		);
	}

	/**
	 * Adds a file to the media library from a URL or base64 data.
	 *
	 * @param array<string, mixed> $f url | data (base64) + filename, alt, title, post (attach to).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function upload( array $f ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'sve_forbidden', 'You may not upload files.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$post = (int) ( $f['post'] ?? 0 );
		if ( ! empty( $f['url'] ) ) {
			$url = esc_url_raw( (string) $f['url'] );
			if ( ! wp_http_validate_url( $url ) ) {
				return new WP_Error( 'sve_bad_url', 'That URL can not be downloaded.' );
			}
			$tmp = download_url( $url, 30 );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
			$name = sanitize_file_name( (string) ( $f['filename'] ?? basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		} elseif ( ! empty( $f['data'] ) ) {
			$data = base64_decode( (string) preg_replace( '/^data:[^,]+,/', '', (string) $f['data'] ), true );
			if ( false === $data || strlen( $data ) > 20 * MB_IN_BYTES ) {
				return new WP_Error( 'sve_bad_data', 'data must be base64 (at most 20 MB).' );
			}
			$tmp = wp_tempnam( 'sve-upload' );
			file_put_contents( $tmp, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$name = sanitize_file_name( (string) ( $f['filename'] ?? 'upload.png' ) );
		} else {
			return new WP_Error( 'sve_no_file', 'Give url or data.' );
		}
		if ( '' === pathinfo( $name, PATHINFO_EXTENSION ) ) {
			$name .= '.jpg';
		}

		$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $post, isset( $f['title'] ) ? sanitize_text_field( (string) $f['title'] ) : null );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}
		if ( isset( $f['alt'] ) ) {
			update_post_meta( (int) $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $f['alt'] ) );
		}

		return array(
			'id'    => (int) $id,
			'url'   => (string) wp_get_attachment_url( (int) $id ),
			'sizes' => array_map( static fn ( $s ): string => (string) wp_get_attachment_image_url( (int) $id, $s ), array( 'thumbnail' => 'thumbnail', 'medium' => 'medium', 'large' => 'large' ) ),
		);
	}

	/**
	 * Attachment id from an id or a URL (downloads external URLs into the library).
	 */
	public static function attachment_id( mixed $value, int $post ): int {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		$url = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $url ) {
			return 0;
		}
		$local = attachment_url_to_postid( $url );
		if ( $local > 0 ) {
			return $local;
		}
		$uploaded = self::upload( array( 'url' => $url, 'post' => $post ) );

		return is_array( $uploaded ) ? (int) $uploaded['id'] : 0;
	}

	/* ------------------------------------------------------------------ settings & plugins */

	/**
	 * @return array<string, mixed>
	 */
	public static function options( array $names = array() ): array {
		$names = array() !== $names ? $names : self::OPTIONS;
		$out   = array();
		foreach ( $names as $name ) {
			$name = (string) $name;
			if ( in_array( $name, self::PROTECTED_OPTIONS, true ) || ( ! in_array( $name, self::OPTIONS, true ) && ! Api::allows( 'admin' ) && ! current_user_can( 'manage_options' ) ) ) {
				continue;
			}
			$out[ $name ] = get_option( $name );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $values Option → value.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_options( array $values, bool $any ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'sve_forbidden', 'You may not change site settings.' );
		}
		$done    = array();
		$skipped = array();
		foreach ( $values as $name => $value ) {
			$name = (string) $name;
			if ( in_array( $name, self::PROTECTED_OPTIONS, true ) || str_starts_with( $name, '_' ) || ( ! $any && ! in_array( $name, self::OPTIONS, true ) ) ) {
				$skipped[] = $name;
				continue;
			}
			update_option( $name, is_string( $value ) ? sanitize_option( $name, $value ) : $value );
			$done[] = $name;
			if ( 'permalink_structure' === $name ) {
				flush_rewrite_rules( false );
			}
		}
		Cache::purge();

		return array( 'updated' => $done, 'skipped' => $skipped );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function plugins(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$out = array();
		foreach ( get_plugins() as $file => $plugin ) {
			$out[] = array(
				'file'        => $file,
				'name'        => $plugin['Name'],
				'version'     => $plugin['Version'],
				'active'      => is_plugin_active( $file ),
				'description' => wp_strip_all_tags( (string) $plugin['Description'] ),
			);
		}

		return $out;
	}

	/**
	 * Installs (from wordpress.org), activates or deactivates a plugin.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function plugin_action( string $action, string $plugin ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( 'install' === $action ) {
			if ( ! current_user_can( 'install_plugins' ) ) {
				return new WP_Error( 'sve_forbidden', 'You may not install plugins.' );
			}
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$api = plugins_api( 'plugin_information', array( 'slug' => sanitize_key( $plugin ), 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $api ) ) {
				return $api;
			}
			$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
			$result   = $upgrader->install( $api->download_link );
			if ( true !== $result ) {
				return is_wp_error( $result ) ? $result : new WP_Error( 'sve_install', 'The plugin could not be installed.' );
			}
			$file = (string) $upgrader->plugin_info();
			return array( 'installed' => $file, 'name' => $api->name, 'version' => $api->version );
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'sve_forbidden', 'You may not manage plugins.' );
		}
		if ( 'visual-editor/visual-editor.php' === $plugin || str_starts_with( $plugin, 'visual-editor/' ) ) {
			return new WP_Error( 'sve_self', 'This plugin can not switch itself off through its own API.' );
		}
		$files = array_keys( get_plugins() );
		if ( ! in_array( $plugin, $files, true ) ) {
			$match = array_values( array_filter( $files, static fn ( string $f ): bool => str_starts_with( $f, $plugin . '/' ) ) );
			if ( array() === $match ) {
				return new WP_Error( 'sve_no_plugin', 'Plugin not found. Use the "file" from list_plugins (e.g. woocommerce/woocommerce.php).' );
			}
			$plugin = $match[0];
		}
		if ( 'activate' === $action ) {
			$result = activate_plugin( $plugin );
			return is_wp_error( $result ) ? $result : array( 'activated' => $plugin );
		}
		if ( 'deactivate' === $action ) {
			deactivate_plugins( $plugin );
			return array( 'deactivated' => $plugin );
		}

		return new WP_Error( 'sve_bad_action', 'action must be install, activate or deactivate.' );
	}
}
