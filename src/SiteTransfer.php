<?php
/**
 * Moves the whole front end of a site to another one in a single JSON file: the Visual Editor document (styles,
 * elements, components, tokens, libraries, scripts, SEO of pages), builder templates, pages and every layout post
 * type (Elementor, block theme templates, theme blocks…) with their meta, menus and their locations, widgets,
 * the theme with its customizer settings and theme/page-builder options, the front page, and — on import — the
 * images they use. Products, blog posts, orders, users and other site data stay where they are.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class SiteTransfer {

	public const FORMAT = 'sve-site';

	private const MAX_MEDIA = 400;

	/** Post types that are content or data, never layout. */
	private const SKIP_TYPES = array( 'post', 'product', 'product_variation', 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_coupon', 'shop_subscription', 'scheduled-action', 'wpcf7_contact_form', 'sve_submission', 'wp_font_face', 'wp_font_family', 'patterns_ai_data', 'rank_math_schema', 'mc4wp-form', 'acf-field', 'acf-field-group' );

	/** Post meta that only means something on the source site. */
	private const SKIP_META = '/^(_edit_lock|_edit_last|_wp_old_slug|_wp_old_date|_wp_trash_meta_.*|_elementor_css|_elementor_page_assets|_elementor_element_cache|_wp_attached_file|_wp_attachment_metadata|_encloseme|_pingme|_sve_working.*|_oembed_.*)$/';

	/** Options that hold layout / theme settings, besides the theme's own ("<theme>_…", "xts-<theme>-…"). */
	private const OPTIONS = '/^(sidebars_widgets|widget_.+|elementor_(active_kit|cpt_support|disable_color_schemes|disable_typography_schemes|container_width|space_between_widgets|stretched_section_container|page_title_selector|viewport_lg|viewport_md|default_generic_fonts|global_image_lightbox|experiment-.+|font_display|load_fa4_shim|unfiltered_files_upload|css_print_method|google_font|optimized_.+)|woocommerce_(shop|cart|checkout|myaccount|terms)_page_id)$/';

	/* ------------------------------------------------------------------ export */

	/**
	 * @return array<string, mixed>
	 */
	public static function export(): array {
		$stylesheet = get_stylesheet();
		$template   = get_template();
		$types      = self::layout_types();

		$posts = array();
		$ids   = get_posts( array( 'post_type' => $types, 'post_status' => array( 'publish', 'private', 'draft', 'future' ), 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ) );
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( null === $post ) {
				continue;
			}
			$meta = array();
			foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
				if ( 1 !== preg_match( self::SKIP_META, (string) $key ) ) {
					$meta[ $key ] = array_map( 'maybe_unserialize', (array) $values );
				}
			}
			$terms = array();
			foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
				if ( in_array( $taxonomy, array( 'product_cat', 'product_tag', 'category', 'post_tag' ), true ) ) {
					continue;
				}
				$names = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );
				if ( is_array( $names ) && array() !== $names ) {
					$terms[ $taxonomy ] = $names;
				}
			}
			$posts[] = array(
				'id'       => $post->ID,
				'type'     => $post->post_type,
				'slug'     => $post->post_name,
				'title'    => $post->post_title,
				'content'  => $post->post_content,
				'excerpt'  => $post->post_excerpt,
				'status'   => $post->post_status,
				'parent'   => $post->post_parent,
				'order'    => $post->menu_order,
				'mime'     => $post->post_mime_type,
				'meta'     => $meta,
				'terms'    => $terms,
			);
		}

		$options = array();
		foreach ( self::option_names( $stylesheet, $template ) as $name ) {
			$options[ $name ] = get_option( $name );
		}

		$data = array(
			'format'   => self::FORMAT,
			'version'  => 1,
			'plugin'   => SVE_VERSION,
			'created'  => gmdate( 'c' ),
			'source'   => untrailingslashit( home_url() ),
			'uploads'  => untrailingslashit( (string) wp_get_upload_dir()['baseurl'] ),
			'theme'    => array( 'stylesheet' => $stylesheet, 'template' => $template, 'name' => (string) wp_get_theme()->get( 'Name' ) ),
			'reading'  => array(
				'show_on_front' => (string) get_option( 'show_on_front' ),
				'front'         => (int) get_option( 'page_on_front' ),
				'posts'         => (int) get_option( 'page_for_posts' ),
			),
			'siteIcon' => (int) get_option( 'site_icon' ),
			'document' => Store::live(),
			'posts'    => $posts,
			'menus'    => self::menus(),
			'options'  => $options,
		);
		$data['media'] = self::media_index( $data );

		return $data;
	}

	/**
	 * Post types that hold layout: pages, builder templates and every registered type that is not content or data.
	 *
	 * @return array<int, string>
	 */
	private static function layout_types(): array {
		$types = array();
		foreach ( get_post_types( array(), 'names' ) as $type ) {
			if ( in_array( $type, self::SKIP_TYPES, true ) || str_contains( $type, 'order' ) || str_starts_with( $type, 'shop_' ) ) {
				continue;
			}
			$object = get_post_type_object( $type );
			// Public content types other than pages (portfolio, events…) are content, not layout.
			if ( 'page' !== $type && null !== $object && $object->public && $object->publicly_queryable && ! in_array( $type, array( 'elementor_library', 'sve_template', 'wp_block' ), true ) ) {
				continue;
			}
			$types[] = $type;
		}

		return array_values( array_unique( array_merge( array( 'page', 'sve_template' ), $types ) ) );
	}

	/**
	 * @return array<int, string>
	 */
	private static function option_names( string $stylesheet, string $template ): array {
		global $wpdb;
		$names   = array( 'theme_mods_' . $stylesheet, 'theme_mods_' . $template );
		$prefix  = array_unique( array( $stylesheet, $template ) );
		$all     = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_%' AND option_name NOT LIKE '%transient%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $all as $name ) {
			$name = (string) $name;
			if ( 1 === preg_match( self::OPTIONS, $name ) ) {
				$names[] = $name;
				continue;
			}
			foreach ( $prefix as $slug ) {
				$slug = strtolower( $slug );
				$low  = strtolower( $name );
				if ( str_starts_with( $low, $slug . '_' ) || str_starts_with( $low, $slug . '-' ) || str_starts_with( $low, 'xts-' . $slug ) ) {
					$names[] = $name;
				}
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * @return array{menus: array<int, array<string, mixed>>, locations: array<string, string>}
	 */
	private static function menus(): array {
		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = array();
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) ) as $item ) {
				$object = '';
				if ( 'taxonomy' === $item->type ) {
					$term   = get_term( (int) $item->object_id, (string) $item->object );
					$object = $term instanceof \WP_Term ? $term->slug : '';
				}
				$items[] = array(
					'id'       => (int) $item->ID,
					'parent'   => (int) $item->menu_item_parent,
					'title'    => (string) $item->title,
					'url'      => (string) $item->url,
					'type'     => (string) $item->type,
					'object'   => (string) $item->object,
					'objectId' => (int) $item->object_id,
					'term'     => $object,
					'target'   => (string) $item->target,
					'attr'     => (string) $item->attr_title,
					'classes'  => array_values( array_filter( (array) $item->classes ) ),
					'xfn'      => (string) $item->xfn,
					'desc'     => (string) $item->description,
					'order'    => (int) $item->menu_order,
				);
			}
			$menus[] = array( 'id' => $menu->term_id, 'name' => $menu->name, 'slug' => $menu->slug, 'items' => $items );
		}
		$locations = array();
		foreach ( get_nav_menu_locations() as $location => $id ) {
			$term = get_term( (int) $id, 'nav_menu' );
			if ( $term instanceof \WP_Term ) {
				$locations[ $location ] = $term->slug;
			}
		}

		return array( 'menus' => $menus, 'locations' => $locations );
	}

	/**
	 * Every upload the export uses, by URL, with the attachment id when it is known (thumbnails, logo, icon,
	 * Elementor "id" next to "url").
	 *
	 * @param array<string, mixed> $data Export.
	 * @return array<int, array{id: int, url: string}>
	 */
	private static function media_index( array $data ): array {
		$json = (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$json = str_replace( '\\/', '/', str_replace( '\\\\/', '/', $json ) );
		$base = preg_quote( $data['uploads'], '#' );
		preg_match_all( '#' . $base . '/[^"\'\s\)\\\\<>]+?\.(?:jpe?g|png|gif|webp|avif|svg|ico|mp4|webm|pdf|woff2?|ttf|otf|json)#i', $json, $m );

		$media = array();
		foreach ( array_unique( $m[0] ) as $url ) {
			$media[ self::original( $url ) ] = 0;
		}
		$ids = array( (int) $data['siteIcon'] );
		foreach ( $data['posts'] as $post ) {
			$ids[] = (int) ( $post['meta']['_thumbnail_id'][0] ?? 0 );
		}
		foreach ( $data['options'] as $name => $value ) {
			if ( str_starts_with( (string) $name, 'theme_mods_' ) && is_array( $value ) ) {
				$ids[] = (int) ( $value['custom_logo'] ?? 0 );
			}
		}
		preg_match_all( '#"id":"?(\d+)"?#', $json, $found );
		foreach ( array_merge( $ids, array_map( 'intval', $found[1] ) ) as $id ) {
			if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
				$url = (string) wp_get_attachment_url( $id );
				if ( '' !== $url && ( isset( $media[ $url ] ) || in_array( $id, $ids, true ) ) ) {
					$media[ $url ] = $id;
				}
			}
		}
		$out = array();
		foreach ( $media as $url => $id ) {
			$out[] = array( 'id' => $id, 'url' => $url );
		}

		return $out;
	}

	/** "photo-300x200.jpg" → "photo.jpg", "photo-scaled.jpg" → "photo.jpg". */
	private static function original( string $url ): string {
		return (string) preg_replace( '#-(?:\d+x\d+|scaled|rotated)(\.[a-z0-9]+)$#i', '$1', $url );
	}

	/* ------------------------------------------------------------------ export link (for AI tools) */

	private const LINKS = 'sve_transfer_links';

	private const LINK_TTL = DAY_IN_SECONDS;

	public static function hooks(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route( 'sve/v1', '/transfer/(?P<token>[a-f0-9]{48})', array( 'methods' => 'GET', 'callback' => array( self::class, 'serve' ), 'permission_callback' => '__return_true', 'show_in_index' => false ) );
			}
		);
	}

	private static function folder(): string {
		$dir = (string) wp_get_upload_dir()['basedir'] . '/sve-transfer';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', '<?php // Silence.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dir;
	}

	/**
	 * Writes the export to a private file and returns a secret link that works for a day: another site's
	 * import_site downloads it from there, so the large file never passes through the AI.
	 *
	 * @return array<string, mixed>
	 */
	public static function export_link(): array {
		$links = (array) get_option( self::LINKS, array() );
		foreach ( $links as $token => $expires ) {
			if ( (int) $expires < time() ) {
				wp_delete_file( self::folder() . '/' . $token . '.json' );
				unset( $links[ $token ] );
			}
		}

		$data  = self::export();
		$token = bin2hex( random_bytes( 24 ) );
		$json  = (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		file_put_contents( self::folder() . '/' . $token . '.json', $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$links[ $token ] = time() + self::LINK_TTL;
		update_option( self::LINKS, $links, false );
		Store::log( 'export', 'Exported the front end (link valid for a day)' );

		return array(
			'link'    => rest_url( 'sve/v1/transfer/' . $token ),
			'expires' => gmdate( 'c', time() + self::LINK_TTL ),
			'size'    => size_format( strlen( $json ) ),
		) + self::summary( $data );
	}

	public static function serve( \WP_REST_Request $request ): \WP_REST_Response {
		$token   = (string) $request['token'];
		$expires = (int) ( ( (array) get_option( self::LINKS, array() ) )[ $token ] ?? 0 );
		$file    = self::folder() . '/' . $token . '.json';
		if ( $expires < time() || ! is_file( $file ) ) {
			return new \WP_REST_Response( array( 'error' => 'This export link expired or does not exist. Export again.' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * What an export holds, in numbers.
	 *
	 * @param array<string, mixed> $data Export.
	 * @return array<string, mixed>
	 */
	public static function summary( array $data ): array {
		$types = array();
		foreach ( (array) $data['posts'] as $post ) {
			$types[ $post['type'] ] = ( $types[ $post['type'] ] ?? 0 ) + 1;
		}

		return array(
			'source'  => $data['source'],
			'theme'   => $data['theme'],
			'posts'   => $types,
			'pages'   => array_values( array_map( static fn ( array $p ): string => $p['slug'], array_filter( (array) $data['posts'], static fn ( array $p ): bool => 'page' === $p['type'] ) ) ),
			'menus'   => array_map( static fn ( array $m ): string => $m['name'] . ' (' . count( $m['items'] ) . ')', (array) ( $data['menus']['menus'] ?? array() ) ),
			'options' => count( (array) $data['options'] ),
			'media'   => count( (array) ( $data['media'] ?? array() ) ),
		);
	}

	/**
	 * Downloads an export (a link from export_site on another site) and imports it, or only describes it.
	 *
	 * @param array<string, mixed> $a url, preview, media, theme, replace.
	 * @return array<string, mixed>
	 */
	public static function import_link( array $a ): array {
		$url = trim( (string) ( $a['url'] ?? '' ) );
		if ( ! wp_http_validate_url( $url ) ) {
			throw new RuntimeException( 'Give url: the link export_site returned on the other site.' );
		}
		$response = wp_remote_get( $url, array( 'timeout' => 120, 'limit_response_size' => 200 * MB_IN_BYTES ) );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Could not download the export: ' . $response->get_error_message() );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) ) {
			throw new RuntimeException( 'The link did not return an export (HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . '). It may have expired: export again.' );
		}
		if ( untrailingslashit( (string) ( $data['source'] ?? '' ) ) === untrailingslashit( home_url() ) ) {
			throw new RuntimeException( 'This export comes from this same site.' );
		}
		if ( ! empty( $a['preview'] ) ) {
			return array( 'preview' => true, 'target' => home_url(), 'installedTheme' => wp_get_theme( (string) ( $data['theme']['stylesheet'] ?? '' ) )->exists() ) + self::summary( $data );
		}

		return self::import(
			$data,
			array(
				'media'     => ! array_key_exists( 'media', $a ) || ! empty( $a['media'] ),
				'theme'     => ! array_key_exists( 'theme', $a ) || ! empty( $a['theme'] ),
				'replace'   => ! array_key_exists( 'replace', $a ) || ! empty( $a['replace'] ),
				'max_media' => isset( $a['max_media'] ) ? max( 0, (int) $a['max_media'] ) : self::MAX_MEDIA,
			)
		);
	}

	/* ------------------------------------------------------------------ import */

	/**
	 * @param array<string, mixed> $data    Export file.
	 * @param array<string, mixed> $options media (copy images), max_media, theme (switch theme), replace (update pages with the same slug).
	 * @return array{done: array<int, string>, warnings: array<int, string>}
	 */
	public static function import( array $data, array $options ): array {
		if ( self::FORMAT !== ( $data['format'] ?? '' ) || ! isset( $data['source'], $data['posts'], $data['document'] ) ) {
			throw new RuntimeException( 'This is not a Visual Editor site export.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		wp_raise_memory_limit( 'admin' );
		kses_remove_filters(); // Layouts carry scripts, iframes and data attributes; only administrators import.

		$done     = array();
		$warnings = array();
		$target   = untrailingslashit( home_url() );

		// 1. Theme.
		$theme = (array) $data['theme'];
		if ( ! empty( $options['theme'] ) && get_stylesheet() !== $theme['stylesheet'] ) {
			if ( wp_get_theme( (string) $theme['stylesheet'] )->exists() && wp_get_theme( (string) $theme['template'] )->exists() ) {
				switch_theme( (string) $theme['stylesheet'] );
				$done[] = 'Theme switched to ' . $theme['name'] . '.';
			} else {
				$warnings[] = 'Theme "' . $theme['name'] . '" (' . $theme['stylesheet'] . ') is not installed here: install it (and its parent "' . $theme['template'] . '") and import again for its settings to apply.';
			}
		}

		// 2. Images: copied first so every reference can point at the new files.
		$urls     = array();
		$id_map   = array();
		$uploads  = (string) ( $data['uploads'] ?? $data['source'] . '/wp-content/uploads' );
		if ( ! empty( $options['media'] ) ) {
			list( $urls, $id_map, $media_warnings ) = self::copy_media( (array) ( $data['media'] ?? array() ), (int) ( $options['max_media'] ?? self::MAX_MEDIA ) );
			$warnings = array_merge( $warnings, $media_warnings );
			$done[]   = count( $urls ) . ' images and files copied.';
		}

		// 3. Addresses: copied files first, then any other address of the old site.
		$data = self::rewrite( $data, $urls, $uploads, (string) $data['source'], $target );

		// 4. Posts (pages, templates, layouts).
		$post_map = array();
		$created  = 0;
		$updated  = 0;
		foreach ( (array) $data['posts'] as $post ) {
			$existing = ! empty( $options['replace'] ) && '' !== (string) $post['slug'] ? get_page_by_path( (string) $post['slug'], OBJECT, (string) $post['type'] ) : null;
			$args     = array(
				'post_type'      => (string) $post['type'],
				'post_name'      => (string) $post['slug'],
				'post_title'     => (string) $post['title'],
				'post_content'   => (string) $post['content'],
				'post_excerpt'   => (string) $post['excerpt'],
				'post_status'    => (string) $post['status'],
				'menu_order'     => (int) $post['order'],
				'post_mime_type' => (string) ( $post['mime'] ?? '' ),
			);
			if ( null !== $existing ) {
				$args['ID'] = $existing->ID;
			}
			$id = wp_insert_post( wp_slash( $args ), true );
			if ( is_wp_error( $id ) ) {
				$warnings[] = 'Could not import ' . $post['type'] . ' "' . $post['title'] . '": ' . $id->get_error_message();
				continue;
			}
			$post_map[ (int) $post['id'] ] = (int) $id;
			null !== $existing ? ++$updated : ++$created;
		}
		foreach ( (array) $data['posts'] as $post ) {
			$id = $post_map[ (int) $post['id'] ] ?? 0;
			if ( 0 === $id ) {
				continue;
			}
			if ( (int) $post['parent'] > 0 && isset( $post_map[ (int) $post['parent'] ] ) ) {
				wp_update_post( array( 'ID' => $id, 'post_parent' => $post_map[ (int) $post['parent'] ] ) );
			}
			foreach ( (array) $post['meta'] as $key => $values ) {
				delete_post_meta( $id, (string) $key );
				foreach ( (array) $values as $value ) {
					$value = self::map_ids( (string) $key, $value, $id_map, $post_map );
					add_post_meta( $id, (string) $key, wp_slash( $value ) );
				}
			}
			foreach ( (array) $post['terms'] as $taxonomy => $slugs ) {
				if ( taxonomy_exists( (string) $taxonomy ) ) {
					wp_set_object_terms( $id, array_map( 'strval', (array) $slugs ), (string) $taxonomy );
				}
			}
		}
		$done[] = $created . ' pages/templates/layouts created, ' . $updated . ' updated.';

		// 5. Menus.
		$menu_map = self::import_menus( (array) ( $data['menus']['menus'] ?? array() ), $post_map );
		$done[]   = count( $menu_map ) . ' menus imported.';

		// 6. Options: widgets, customizer, theme and page-builder settings.
		foreach ( (array) $data['options'] as $name => $value ) {
			$name = (string) $name;
			if ( str_starts_with( $name, 'theme_mods_' ) && is_array( $value ) ) {
				if ( isset( $value['custom_logo'] ) ) {
					$value['custom_logo'] = $id_map[ (int) $value['custom_logo'] ] ?? 0;
				}
				if ( isset( $value['custom_css_post_id'] ) ) {
					$value['custom_css_post_id'] = $post_map[ (int) $value['custom_css_post_id'] ] ?? -1;
				}
				unset( $value['nav_menu_locations'] );
				$value = array_merge( (array) get_option( $name, array() ), $value );
			}
			if ( 'elementor_active_kit' === $name ) {
				$value = $post_map[ (int) $value ] ?? $value;
			}
			if ( str_starts_with( $name, 'woocommerce_' ) && str_ends_with( $name, '_page_id' ) ) {
				$value = $post_map[ (int) $value ] ?? get_option( $name );
			}
			update_option( $name, $value );
		}
		$locations = array();
		foreach ( (array) ( $data['menus']['locations'] ?? array() ) as $location => $slug ) {
			if ( isset( $menu_map[ $slug ] ) ) {
				$locations[ $location ] = $menu_map[ $slug ];
			}
		}
		set_theme_mod( 'nav_menu_locations', $locations );
		$done[] = count( (array) $data['options'] ) . ' theme, widget and builder settings applied.';

		// 7. Front page and site icon.
		$reading = (array) $data['reading'];
		update_option( 'show_on_front', 'page' === $reading['show_on_front'] ? 'page' : 'posts' );
		if ( isset( $post_map[ (int) $reading['front'] ] ) ) {
			update_option( 'page_on_front', $post_map[ (int) $reading['front'] ] );
		}
		if ( isset( $post_map[ (int) $reading['posts'] ] ) ) {
			update_option( 'page_for_posts', $post_map[ (int) $reading['posts'] ] );
		}
		if ( isset( $id_map[ (int) ( $data['siteIcon'] ?? 0 ) ] ) ) {
			update_option( 'site_icon', $id_map[ (int) $data['siteIcon'] ] );
		}

		// 8. Visual Editor document (kept as a revision, so it can be rolled back from the editor's history).
		$saved = Store::save( (array) $data['document'], true, null, null, 'site import from ' . wp_parse_url( (string) $data['source'], PHP_URL_HOST ) );
		$done[] = 'Visual Editor styles, elements, components, tokens and libraries imported.';
		foreach ( $saved['warnings'] as $warning ) {
			$warnings[] = 'Editor: ' . $warning;
		}

		// 9. Caches.
		delete_post_meta_by_key( '_elementor_css' );
		delete_option( '_elementor_global_css' );
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		flush_rewrite_rules();
		kses_init_filters();

		Store::log( 'import', 'Imported the front end of ' . $data['source'] );

		return array( 'done' => $done, 'warnings' => $warnings );
	}

	/**
	 * Downloads every file of the old site once (originals, not resized copies) into this media library.
	 *
	 * @param array<int, array{id: int, url: string}> $media Media index of the export.
	 * @return array{0: array<string, string>, 1: array<int, int>, 2: array<int, string>}
	 */
	private static function copy_media( array $media, int $max = self::MAX_MEDIA ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$urls     = array();
		$ids      = array();
		$warnings = array();
		foreach ( array_slice( $media, 0, $max ) as $item ) {
			$url = (string) $item['url'];
			if ( '' === $url ) {
				continue;
			}
			$tmp = download_url( $url, 30 );
			if ( is_wp_error( $tmp ) ) {
				$warnings[] = 'Image not copied (' . $tmp->get_error_message() . '): ' . $url;
				continue;
			}
			$file = array( 'name' => rawurldecode( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ), 'tmp_name' => $tmp );
			$id   = media_handle_sideload( $file, 0 );
			if ( is_wp_error( $id ) ) {
				wp_delete_file( $tmp );
				$warnings[] = 'Image not copied (' . $id->get_error_message() . '): ' . $url;
				continue;
			}
			$urls[ $url ] = (string) wp_get_attachment_url( (int) $id );
			if ( (int) $item['id'] > 0 ) {
				$ids[ (int) $item['id'] ] = (int) $id;
			}
		}
		if ( count( $media ) > $max ) {
			$warnings[] = 'Only the first ' . $max . ' of ' . count( $media ) . ' files were copied; the others still load from the old site.';
		}

		return array( $urls, $ids, $warnings );
	}

	/**
	 * Replaces addresses in every string of the export: copied files (and their resized copies) first, then the
	 * old site's address. Also the "https:\/\/…" form used inside JSON strings (Elementor data).
	 *
	 * @param mixed                 $data    Export or a part of it.
	 * @param array<string, string> $urls    Old file URL → new file URL.
	 * @return mixed
	 */
	private static function rewrite( mixed $data, array $urls, string $uploads, string $source, string $target ): mixed {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::rewrite( $value, $urls, $uploads, $source, $target );
			}

			return $data;
		}
		if ( ! is_string( $data ) || ( ! str_contains( $data, wp_parse_url( $source, PHP_URL_HOST ) ?? '' ) ) ) {
			return $data;
		}
		foreach ( array( false, true ) as $escaped ) {
			$e = static fn ( string $s ): string => $escaped ? str_replace( '/', '\\/', $s ) : $s;
			// Files that were not copied keep loading from the old site: hide them from the address swap below.
			$data = (string) preg_replace_callback(
				'#' . preg_quote( $e( $uploads ), '#' ) . '[^"\'\s\)<>]+?\.[a-z0-9]{2,5}#i',
				static function ( array $m ) use ( $urls, $e, $escaped, $source ): string {
					$plain = $escaped ? str_replace( '\\/', '/', $m[0] ) : $m[0];
					$new   = $urls[ $plain ] ?? $urls[ self::original( $plain ) ] ?? null;

					return null === $new ? str_replace( $e( $source ), "\x00SVE\x00", $m[0] ) : $e( $new );
				},
				$data
			);
			$data = str_replace( array( $e( $source ), "\x00SVE\x00" ), array( $e( $target ), $e( $source ) ), $data );
		}

		return $data;
	}

	/**
	 * Attachment and post ids inside meta: thumbnails, and Elementor "id" next to a copied image.
	 *
	 * @param array<int, int> $media Old attachment id → new id.
	 * @param array<int, int> $posts Old post id → new id.
	 */
	private static function map_ids( string $key, mixed $value, array $media, array $posts ): mixed {
		if ( '_thumbnail_id' === $key ) {
			return $media[ (int) $value ] ?? '';
		}
		if ( '_elementor_data' === $key && is_string( $value ) && array() !== $media ) {
			return (string) preg_replace_callback(
				'#"id":(\d+)#',
				static fn ( array $m ): string => isset( $media[ (int) $m[1] ] ) ? '"id":' . $media[ (int) $m[1] ] : $m[0],
				$value
			);
		}
		if ( in_array( $key, array( '_elementor_template_id', '_sve_page' ), true ) && is_numeric( $value ) ) {
			return $posts[ (int) $value ] ?? $value;
		}

		return $value;
	}

	/**
	 * @param array<int, array<string, mixed>> $menus Menus of the export.
	 * @param array<int, int>                  $posts Old post id → new id.
	 * @return array<string, int> Menu slug → new menu id.
	 */
	private static function import_menus( array $menus, array $posts ): array {
		$map = array();
		foreach ( $menus as $menu ) {
			$existing = wp_get_nav_menu_object( (string) $menu['slug'] );
			$id       = $existing ? (int) $existing->term_id : (int) wp_create_nav_menu( (string) $menu['name'] );
			if ( $id <= 0 ) {
				continue;
			}
			foreach ( (array) wp_get_nav_menu_items( $id, array( 'post_status' => 'any' ) ) as $old ) {
				wp_delete_post( (int) $old->ID, true );
			}
			$items = array();
			foreach ( (array) $menu['items'] as $item ) {
				$args = array(
					'menu-item-title'       => (string) $item['title'],
					'menu-item-url'         => (string) $item['url'],
					'menu-item-target'      => (string) $item['target'],
					'menu-item-attr-title'  => (string) $item['attr'],
					'menu-item-classes'     => implode( ' ', (array) $item['classes'] ),
					'menu-item-xfn'         => (string) $item['xfn'],
					'menu-item-description' => (string) $item['desc'],
					'menu-item-position'    => (int) $item['order'],
					'menu-item-status'      => 'publish',
					'menu-item-parent-id'   => (int) ( $items[ (int) $item['parent'] ] ?? 0 ),
					'menu-item-type'        => 'custom',
				);
				if ( 'post_type' === $item['type'] && isset( $posts[ (int) $item['objectId'] ] ) ) {
					$args['menu-item-type']      = 'post_type';
					$args['menu-item-object']    = (string) $item['object'];
					$args['menu-item-object-id'] = $posts[ (int) $item['objectId'] ];
				} elseif ( 'taxonomy' === $item['type'] && '' !== $item['term'] ) {
					$term = get_term_by( 'slug', (string) $item['term'], (string) $item['object'] );
					if ( $term instanceof \WP_Term ) {
						$args['menu-item-type']      = 'taxonomy';
						$args['menu-item-object']    = (string) $item['object'];
						$args['menu-item-object-id'] = $term->term_id;
					}
				} elseif ( 'post_type_archive' === $item['type'] ) {
					$args['menu-item-type']   = 'post_type_archive';
					$args['menu-item-object'] = (string) $item['object'];
				}
				$new = wp_update_nav_menu_item( $id, 0, wp_slash( $args ) );
				if ( ! is_wp_error( $new ) ) {
					$items[ (int) $item['id'] ] = (int) $new;
				}
			}
			$map[ (string) $menu['slug'] ] = $id;
		}

		return $map;
	}
}
