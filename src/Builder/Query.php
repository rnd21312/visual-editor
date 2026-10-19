<?php
/**
 * Turns the friendly query of <sve-loop query='…'> into safe WP_Query / get_terms arguments.
 *
 * Accepted keys: post_type, posts_per_page (≤100), orderby (date|title|modified|menu_order|rand|comment_count|
 * price|popularity|rating|post__in|meta_value|meta_value_num), order, category, tag, taxonomy + terms,
 * product_cat, product_tag, ids, exclude, exclude_current, author, search, meta_key, meta_value, offset,
 * on_sale, featured, in_stock, current_term, parent.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor\Builder;

defined( 'ABSPATH' ) || exit;

final class Query {

	/**
	 * @param array<string, mixed> $in Friendly query.
	 * @return array<string, mixed>
	 */
	public static function args( array $in, ?\WP_Post $current, bool $paginate ): array {
		$public = array_values( get_post_types( array( 'public' => true ) ) );
		$types  = array_values( array_intersect( array_map( 'strval', (array) ( $in['post_type'] ?? 'post' ) ), $public ) );

		$args = array(
			'post_type'           => array() !== $types ? $types : array( 'post' ),
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, min( 100, (int) ( $in['posts_per_page'] ?? $in['limit'] ?? 6 ) ) ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => ! $paginate,
		);
		if ( -1 === (int) ( $in['posts_per_page'] ?? 0 ) ) {
			$args['posts_per_page'] = 100;
		}
		if ( $paginate ) {
			$args['paged'] = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		}

		$order           = strtoupper( (string) ( $in['order'] ?? 'DESC' ) );
		$args['order']   = 'ASC' === $order ? 'ASC' : 'DESC';
		$orderby         = (string) ( $in['orderby'] ?? 'date' );
		$args['orderby'] = match ( $orderby ) {
			'title', 'modified', 'menu_order', 'rand', 'comment_count', 'post__in', 'meta_value', 'meta_value_num', 'name', 'ID' => $orderby,
			default => 'date',
		};
		if ( 'price' === $orderby ) {
			$args['orderby']  = 'meta_value_num';
			$args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery
		} elseif ( 'popularity' === $orderby ) {
			$args['orderby']  = 'meta_value_num';
			$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery
		} elseif ( 'rating' === $orderby ) {
			$args['orderby']  = 'meta_value_num';
			$args['meta_key'] = '_wc_average_rating'; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( in_array( $args['orderby'], array( 'meta_value', 'meta_value_num' ), true ) && ! isset( $args['meta_key'] ) && ! empty( $in['meta_key'] ) ) {
			$args['meta_key'] = sanitize_key( (string) $in['meta_key'] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$tax = array();
		if ( ! empty( $in['category'] ) ) {
			$tax[] = self::tax( 'category', $in['category'] );
		}
		if ( ! empty( $in['tag'] ) ) {
			$tax[] = self::tax( 'post_tag', $in['tag'] );
		}
		foreach ( array( 'product_cat', 'product_tag' ) as $taxonomy ) {
			if ( ! empty( $in[ $taxonomy ] ) ) {
				$tax[] = self::tax( $taxonomy, $in[ $taxonomy ] );
			}
		}
		if ( ! empty( $in['taxonomy'] ) && ! empty( $in['terms'] ) && taxonomy_exists( (string) $in['taxonomy'] ) ) {
			$tax[] = self::tax( (string) $in['taxonomy'], $in['terms'] );
		}
		if ( ! empty( $in['current_term'] ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$tax[] = array( 'taxonomy' => $term->taxonomy, 'field' => 'term_id', 'terms' => array( $term->term_id ) );
			}
		}
		if ( ! empty( $in['featured'] ) && taxonomy_exists( 'product_visibility' ) ) {
			$tax[] = array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'featured' ) );
		}
		if ( array() !== $tax ) {
			$args['tax_query'] = array_merge( array( 'relation' => 'AND' ), $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		if ( ! empty( $in['ids'] ) ) {
			$args['post__in'] = array_map( 'intval', (array) $in['ids'] );
			if ( 'date' === $args['orderby'] ) {
				$args['orderby'] = 'post__in';
			}
		}
		$exclude = array_map( 'intval', (array) ( $in['exclude'] ?? array() ) );
		if ( ! empty( $in['exclude_current'] ) && null !== $current ) {
			$exclude[] = $current->ID;
		}
		if ( array() !== $exclude ) {
			$args['post__not_in'] = $exclude;
		}
		if ( ! empty( $in['on_sale'] ) && function_exists( 'wc_get_product_ids_on_sale' ) ) {
			$on_sale          = array_map( 'intval', wc_get_product_ids_on_sale() );
			$args['post__in'] = isset( $args['post__in'] ) ? array_values( array_intersect( $args['post__in'], $on_sale ) ) : $on_sale;
			$args['post__in'] = array() !== $args['post__in'] ? $args['post__in'] : array( 0 );
		}
		if ( ! empty( $in['in_stock'] ) ) {
			$args['meta_query'][] = array( 'key' => '_stock_status', 'value' => 'instock' ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( ! empty( $in['meta_key'] ) && isset( $in['meta_value'] ) ) {
			$args['meta_query'][] = array( 'key' => sanitize_key( (string) $in['meta_key'] ), 'value' => sanitize_text_field( (string) $in['meta_value'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( ! empty( $in['author'] ) ) {
			$args['author'] = (int) $in['author'];
		}
		if ( ! empty( $in['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $in['search'] );
		}
		if ( ! empty( $in['offset'] ) ) {
			$args['offset'] = max( 0, (int) $in['offset'] );
		}
		if ( isset( $in['parent'] ) ) {
			$args['post_parent'] = 'current' === $in['parent'] && null !== $current ? $current->ID : (int) $in['parent'];
		}

		return $args;
	}

	/**
	 * @param mixed $terms Slugs or ids.
	 * @return array<string, mixed>
	 */
	private static function tax( string $taxonomy, mixed $terms ): array {
		$terms = is_array( $terms ) ? $terms : explode( ',', (string) $terms );
		$terms = array_values( array_filter( array_map( static fn ( $t ): string => trim( (string) $t ), $terms ) ) );
		$ids   = array_filter( $terms, 'is_numeric' );

		return array(
			'taxonomy' => $taxonomy,
			'field'    => count( $ids ) === count( $terms ) ? 'term_id' : 'slug',
			'terms'    => count( $ids ) === count( $terms ) ? array_map( 'intval', $terms ) : array_map( 'sanitize_title', $terms ),
		);
	}

	/**
	 * @param array<string, mixed> $in Friendly query: taxonomy, number, hide_empty, parent, orderby, order, include.
	 * @return array<string, mixed>
	 */
	public static function terms_args( array $in ): array {
		$taxonomy = (string) ( $in['taxonomy'] ?? 'category' );
		$args     = array(
			'taxonomy'   => taxonomy_exists( $taxonomy ) ? $taxonomy : 'category',
			'number'     => max( 1, min( 200, (int) ( $in['number'] ?? $in['limit'] ?? 12 ) ) ),
			'hide_empty' => ! isset( $in['hide_empty'] ) || ! empty( $in['hide_empty'] ),
			'orderby'    => in_array( $in['orderby'] ?? '', array( 'name', 'count', 'slug', 'term_order', 'include', 'id' ), true ) ? (string) $in['orderby'] : 'name',
			'order'      => 'DESC' === strtoupper( (string) ( $in['order'] ?? 'ASC' ) ) ? 'DESC' : 'ASC',
		);
		if ( isset( $in['parent'] ) ) {
			$args['parent'] = (int) $in['parent'];
		}
		if ( ! empty( $in['include'] ) ) {
			$args['include'] = array_map( 'intval', (array) $in['include'] );
		}

		return $args;
	}

	/**
	 * Posts sharing a category / tag (or product category) with a post.
	 *
	 * @return array<int, int>
	 */
	public static function related( \WP_Post $post, int $count ): array {
		if ( 'product' === $post->post_type && function_exists( 'wc_get_related_products' ) ) {
			return array_map( 'intval', wc_get_related_products( $post->ID, $count ) );
		}
		$terms = wp_get_post_terms( $post->ID, array( 'category', 'post_tag' ), array( 'fields' => 'ids' ) );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return array();
		}
		$query = new \WP_Query(
			array(
				'post_type'           => $post->post_type,
				'post_status'         => 'publish',
				'posts_per_page'      => max( 1, min( 24, $count ) ),
				'post__not_in'        => array( $post->ID ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'fields'              => 'ids',
				'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array( 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $terms ),
					array( 'taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => $terms ),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}
}
