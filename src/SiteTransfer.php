<?php
/**
 * Moves a site — all of it or the parts you pick — to another site: design (Visual Editor document, theme
 * customizer and theme / page-builder options, widgets, front page), pages, layouts (builder templates, Elementor,
 * block theme templates, theme layouts), menus, blog posts, products, Code Snippets, other settings and the theme
 * files. The package is a JSON file, or a zip when theme files are included. Importing runs in steps
 * (see TransferJob) so large sites do not time out on shared hosting.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class SiteTransfer {

	public const FORMAT = 'sve-site';

	/** What can be exported, in import order. */
	public const GROUPS = array( 'design', 'pages', 'layouts', 'menus', 'posts', 'products', 'snippets', 'settings', 'theme_files' );

	/** Picked when nothing is chosen: the look of the site, not its content. */
	public const DEFAULT_GROUPS = array( 'design', 'pages', 'layouts', 'menus' );

	private const LINKS = 'sve_transfer_links';

	private const LINK_TTL = DAY_IN_SECONDS;

	/** Post types that are data, never exported as layout. */
	private const DATA_TYPES = array( 'post', 'page', 'product', 'product_variation', 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_coupon', 'shop_subscription', 'scheduled-action', 'sve_submission', 'wp_font_face', 'wp_font_family', 'patterns_ai_data', 'flamingo_contact', 'flamingo_inbound', 'mc4wp-form', 'acf-field', 'acf-field-group' );

	/** Post meta that only means something on the source site. */
	private const SKIP_META = '/^(_edit_lock|_edit_last|_wp_old_slug|_wp_old_date|_wp_trash_meta_.*|_elementor_css|_elementor_page_assets|_elementor_element_cache|_wp_attached_file|_wp_attachment_metadata|_encloseme|_pingme|_sve_working.*|_oembed_.*|total_sales|_wc_review_count|_wc_rating_count|_wc_average_rating)$/';

	/** Layout options besides the theme's own ("<theme>_…", "xts-<theme>-…") and the theme mods. */
	private const DESIGN_OPTIONS = '/^(sidebars_widgets|widget_.+|elementor_(active_kit|cpt_support|disable_color_schemes|disable_typography_schemes|container_width|space_between_widgets|stretched_section_container|page_title_selector|viewport_lg|viewport_md|default_generic_fonts|global_image_lightbox|experiment-.+|font_display|load_fa4_shim|unfiltered_files_upload|css_print_method|google_font|optimized_.+)|woocommerce_(shop|cart|checkout|myaccount|terms)_page_id|wp_page_for_privacy_policy)$/';

	/** Never copied by "settings": the site's identity, accounts, secrets, plugin state. */
	private const PRIVATE_OPTIONS = '/^(siteurl|home|admin_email|new_admin_email|active_plugins|active_sitewide_plugins|recently_activated|uninstall_plugins|cron|rewrite_rules|.*user_roles|db_version|db_upgraded|initial_db_version|fresh_site|auth_.*|secure_auth_.*|logged_in_.*|nonce_.*|.*_salt|.*_key|.*_secret|.*_token|.*password.*|.*license.*|.*api_?key.*|sve_.*|can_compress_scripts|upload_path|upload_url_path|template|stylesheet|current_theme|theme_switched|finished_.*|wp_force_deactivated_plugins|recovery_.*|.*_db_version|.*_version|woocommerce_(admin_notices|onboarding.*|task_list.*|meta_box_errors|queue_flush_rewrite_rules|maxmind.*)|wordfence.*|wf.*|litespeed.*|duplicator.*|action_scheduler.*|jetpack.*)$/i';

	/* ------------------------------------------------------------------ export */

	/**
	 * @param array<int, string> $groups   Parts to export (GROUPS).
	 * @param array<int, int>    $page_ids Only these pages (empty: all pages).
	 * @return array<string, mixed>
	 */
	public static function export( array $groups = self::DEFAULT_GROUPS, array $page_ids = array() ): array {
		$groups = self::groups( $groups );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		wp_raise_memory_limit( 'admin' );

		$stylesheet = get_stylesheet();
		$template   = get_template();
		$data       = array(
			'format'  => self::FORMAT,
			'version' => 2,
			'plugin'  => SVE_VERSION,
			'created' => gmdate( 'c' ),
			'source'  => untrailingslashit( home_url() ),
			'uploads' => untrailingslashit( (string) wp_get_upload_dir()['baseurl'] ),
			'groups'  => $groups,
			'theme'   => array( 'stylesheet' => $stylesheet, 'template' => $template, 'name' => (string) wp_get_theme()->get( 'Name' ) ),
			'posts'   => array(),
			'terms'   => array(),
		);

		if ( in_array( 'design', $groups, true ) ) {
			$data['document'] = Store::live();
			$data['reading']  = array(
				'show_on_front' => (string) get_option( 'show_on_front' ),
				'front'         => (int) get_option( 'page_on_front' ),
				'posts'         => (int) get_option( 'page_for_posts' ),
			);
			$data['siteIcon'] = (int) get_option( 'site_icon' );
			$data['options']  = array();
			foreach ( self::design_options( $stylesheet, $template ) as $name ) {
				$data['options'][ $name ] = get_option( $name );
			}
		}
		if ( in_array( 'settings', $groups, true ) ) {
			$data['settings'] = self::settings( array_keys( $data['options'] ?? array() ) );
		}

		$types = array();
		if ( in_array( 'pages', $groups, true ) ) {
			$types[] = 'page';
		}
		if ( in_array( 'layouts', $groups, true ) ) {
			$types = array_merge( $types, self::layout_types() );
		}
		if ( in_array( 'posts', $groups, true ) ) {
			$types[] = 'post';
		}
		if ( in_array( 'products', $groups, true ) && post_type_exists( 'product' ) ) {
			$types[]            = 'product';
			$types[]            = 'product_variation';
			$data['attributes'] = self::attributes();
		}
		if ( array() !== $types ) {
			$data['posts'] = self::posts( $types, array_map( 'intval', $page_ids ) );
			$data['terms'] = self::terms( $data['posts'] );
		}
		if ( in_array( 'menus', $groups, true ) ) {
			$data['menus'] = self::menus();
		}
		if ( in_array( 'snippets', $groups, true ) ) {
			$data['snippets'] = self::snippets();
		}
		$data['media'] = self::media_index( $data );

		return $data;
	}

	/**
	 * @param array<int, mixed> $groups Requested parts.
	 * @return array<int, string>
	 */
	public static function groups( array $groups ): array {
		$groups = array_values( array_intersect( self::GROUPS, array_map( 'strval', $groups ) ) );

		return array() === $groups ? self::DEFAULT_GROUPS : $groups;
	}

	/**
	 * Post types that hold layout: builder templates and every registered type that is not content or data.
	 *
	 * @return array<int, string>
	 */
	private static function layout_types(): array {
		$types = array( 'sve_template' );
		foreach ( get_post_types( array(), 'objects' ) as $type => $object ) {
			if ( in_array( $type, self::DATA_TYPES, true ) || str_contains( $type, 'order' ) || str_starts_with( $type, 'shop_' ) ) {
				continue;
			}
			// Public content types (portfolio, events…) are content, not layout.
			if ( $object->public && $object->publicly_queryable && ! in_array( $type, array( 'elementor_library', 'sve_template', 'wp_block', 'cms_block', 'woodmart_layout' ), true ) ) {
				continue;
			}
			$types[] = (string) $type;
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * @param array<int, string> $types    Post types.
	 * @param array<int, int>    $page_ids Only these pages (empty: all).
	 * @return array<int, array<string, mixed>>
	 */
	private static function posts( array $types, array $page_ids ): array {
		global $wpdb;
		$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status IN ('publish','private','draft','future','pending','inherit') ORDER BY post_parent ASC, ID ASC", $types ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = array();
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( null === $post || ( 'inherit' === $post->post_status && 'product_variation' !== $post->post_type ) ) {
				continue;
			}
			if ( 'page' === $post->post_type && array() !== $page_ids && ! in_array( $post->ID, $page_ids, true ) ) {
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
				$list = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_array( $list ) && array() !== $list ) {
					$terms[ $taxonomy ] = array_map( 'intval', $list );
				}
			}
			$out[] = array(
				'id'      => $post->ID,
				'type'    => $post->post_type,
				'slug'    => $post->post_name,
				'title'   => $post->post_title,
				'content' => $post->post_content,
				'excerpt' => $post->post_excerpt,
				'status'  => $post->post_status,
				'date'    => $post->post_date,
				'parent'  => $post->post_parent,
				'order'   => $post->menu_order,
				'mime'    => $post->post_mime_type,
				'comment' => $post->comment_status,
				'meta'    => $meta,
				'terms'   => $terms,
			);
		}

		return $out;
	}

	/**
	 * Every term the exported posts use, with its ancestors, by taxonomy.
	 *
	 * @param array<int, array<string, mixed>> $posts Exported posts.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function terms( array $posts ): array {
		$wanted = array();
		foreach ( $posts as $post ) {
			foreach ( $post['terms'] as $taxonomy => $ids ) {
				foreach ( $ids as $id ) {
					$wanted[ $taxonomy ][ $id ] = true;
				}
			}
		}
		$out = array();
		foreach ( $wanted as $taxonomy => $ids ) {
			$queue = array_keys( $ids );
			$seen  = array();
			while ( array() !== $queue ) {
				$id = (int) array_shift( $queue );
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$term = get_term( $id, (string) $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$seen[ $id ] = true;
				if ( $term->parent > 0 ) {
					$queue[] = $term->parent;
				}
				$meta = array();
				foreach ( (array) get_term_meta( $term->term_id ) as $key => $values ) {
					$meta[ $key ] = array_map( 'maybe_unserialize', (array) $values );
				}
				$out[ $taxonomy ][] = array( 'id' => $term->term_id, 'slug' => $term->slug, 'name' => $term->name, 'description' => $term->description, 'parent' => $term->parent, 'meta' => $meta );
			}
		}

		return $out;
	}

	/**
	 * WooCommerce product attributes (pa_*).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function attributes(): array {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return array();
		}

		return array_values(
			array_map(
				static fn ( object $a ): array => array( 'name' => $a->attribute_name, 'label' => $a->attribute_label, 'type' => $a->attribute_type, 'orderby' => $a->attribute_orderby, 'public' => (int) $a->attribute_public ),
				wc_get_attribute_taxonomies()
			)
		);
	}

	/**
	 * @return array<int, string>
	 */
	private static function design_options( string $stylesheet, string $template ): array {
		$names  = array( 'theme_mods_' . $stylesheet, 'theme_mods_' . $template );
		$prefix = array_unique( array( strtolower( $stylesheet ), strtolower( $template ) ) );
		foreach ( self::option_names() as $name ) {
			$low = strtolower( $name );
			if ( 1 === preg_match( self::DESIGN_OPTIONS, $name ) ) {
				$names[] = $name;
				continue;
			}
			foreach ( $prefix as $slug ) {
				if ( str_starts_with( $low, $slug . '_' ) || str_starts_with( $low, $slug . '-' ) || str_starts_with( $low, 'xts-' . $slug ) ) {
					$names[] = $name;
				}
			}
		}

		return array_values( array_unique( array_filter( $names, static fn ( string $n ): bool => false !== get_option( $n, false ) ) ) );
	}

	/**
	 * Other settings: everything in the options table except the site's identity, secrets, accounts and the
	 * state of security / cache plugins.
	 *
	 * @param array<int, string> $skip Already exported as design.
	 * @return array<string, mixed>
	 */
	private static function settings( array $skip ): array {
		$out = array();
		foreach ( self::option_names() as $name ) {
			if ( in_array( $name, $skip, true ) || 1 === preg_match( self::PRIVATE_OPTIONS, $name ) || str_starts_with( $name, 'theme_mods_' ) ) {
				continue;
			}
			$out[ $name ] = get_option( $name );
		}

		return $out;
	}

	/**
	 * @return array<int, string>
	 */
	private static function option_names(): array {
		global $wpdb;

		return array_map( 'strval', (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_%' AND option_name NOT LIKE '%transient%' AND option_name NOT LIKE '%cache%'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @return array{menus: array<int, array<string, mixed>>, locations: array<string, string>}
	 */
	private static function menus(): array {
		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = array();
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) ) as $item ) {
				$term    = 'taxonomy' === $item->type ? get_term( (int) $item->object_id, (string) $item->object ) : null;
				$post    = 'post_type' === $item->type ? get_post( (int) $item->object_id ) : null;
				$meta    = array();
				foreach ( (array) get_post_meta( (int) $item->ID ) as $key => $values ) {
					if ( ! str_starts_with( (string) $key, '_menu_item_' ) && ! str_starts_with( (string) $key, '_edit' ) ) {
						$meta[ $key ] = maybe_unserialize( $values[0] ?? '' );
					}
				}
				$items[] = array(
					'id'       => (int) $item->ID,
					'parent'   => (int) $item->menu_item_parent,
					'title'    => (string) $item->post_title,
					'url'      => (string) $item->url,
					'type'     => (string) $item->type,
					'object'   => (string) $item->object,
					'objectId' => (int) $item->object_id,
					'slug'     => $term instanceof \WP_Term ? $term->slug : ( $post instanceof \WP_Post ? $post->post_name : '' ),
					'target'   => (string) $item->target,
					'attr'     => (string) $item->attr_title,
					'classes'  => array_values( array_filter( (array) $item->classes ) ),
					'xfn'      => (string) $item->xfn,
					'desc'     => (string) $item->description,
					'order'    => (int) $item->menu_order,
					'meta'     => $meta,
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
	 * Code Snippets rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function snippets(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'snippets';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return array();
		}
		$rows = (array) $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static function ( array $row ): array {
				unset( $row['id'] );
				return $row;
			},
			$rows
		);
	}

	/**
	 * Every upload the export uses, by URL, with the attachment id when it is known (thumbnails, galleries,
	 * logo, icon, term thumbnails, Elementor "id" next to "url").
	 *
	 * @param array<string, mixed> $data Export.
	 * @return array<int, array{id: int, url: string}>
	 */
	private static function media_index( array $data ): array {
		$ids = array( (int) ( $data['siteIcon'] ?? 0 ) );
		foreach ( $data['posts'] as $post ) {
			$ids[] = (int) ( $post['meta']['_thumbnail_id'][0] ?? 0 );
			foreach ( explode( ',', (string) ( $post['meta']['_product_image_gallery'][0] ?? '' ) ) as $id ) {
				$ids[] = (int) $id;
			}
		}
		foreach ( $data['terms'] as $terms ) {
			foreach ( $terms as $term ) {
				$ids[] = (int) ( $term['meta']['thumbnail_id'][0] ?? 0 );
			}
		}
		foreach ( (array) ( $data['options'] ?? array() ) as $name => $value ) {
			if ( str_starts_with( (string) $name, 'theme_mods_' ) && is_array( $value ) ) {
				$ids[] = (int) ( $value['custom_logo'] ?? 0 );
			}
		}

		$media = array();
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			if ( 'attachment' === get_post_type( $id ) ) {
				$url = (string) wp_get_attachment_url( $id );
				if ( '' !== $url ) {
					$media[ $url ] = $id;
				}
			}
		}

		$json = (string) wp_json_encode( array( $data['posts'], $data['options'] ?? null, $data['document'] ?? null, $data['menus'] ?? null, $data['settings'] ?? null, $data['terms'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$json = str_replace( array( '\\\\/', '\\/' ), '/', $json );
		preg_match_all( '#' . preg_quote( (string) $data['uploads'], '#' ) . '/[^"\'\s\)\\\\<>]+?\.(?:jpe?g|png|gif|webp|avif|svg|ico|mp4|webm|pdf|woff2?|ttf|otf|json|lottie)#i', $json, $m );
		foreach ( array_unique( $m[0] ) as $url ) {
			$url = TransferJob::original( $url );
			if ( ! isset( $media[ $url ] ) ) {
				$media[ $url ] = 0;
			}
		}
		// Elementor and theme options keep "id" next to the image url: remember that id too.
		preg_match_all( '#"url":"([^"]+)","id":"?(\d+)|"id":"?(\d+)"?,"url":"([^"]+)"#', $json, $pairs, PREG_SET_ORDER );
		foreach ( $pairs as $pair ) {
			$url = TransferJob::original( '' !== $pair[1] ? $pair[1] : (string) ( $pair[4] ?? '' ) );
			$id  = (int) ( '' !== $pair[2] ? $pair[2] : ( $pair[3] ?? 0 ) );
			if ( isset( $media[ $url ] ) && 0 === $media[ $url ] && $id > 0 ) {
				$media[ $url ] = $id;
			}
		}

		$out = array();
		foreach ( $media as $url => $id ) {
			$out[] = array( 'id' => (int) $id, 'url' => (string) $url );
		}

		return $out;
	}

	/* ------------------------------------------------------------------ packages */

	public static function folder(): string {
		$dir = (string) wp_get_upload_dir()['basedir'] . '/sve-transfer';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_file( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', '<?php // Silence.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dir;
	}

	/**
	 * Writes a package: JSON, or a zip with site.json and the theme folders when theme files are wanted.
	 *
	 * @param array<int, string> $groups   Parts.
	 * @param array<int, int>    $page_ids Only these pages.
	 * @return array{file: string, name: string, data: array<string, mixed>}
	 */
	public static function package( array $groups, array $page_ids = array() ): array {
		$groups = self::groups( $groups );
		$data   = self::export( $groups, $page_ids );
		$json   = (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		$base   = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Ymd-His' );
		$token  = bin2hex( random_bytes( 24 ) );

		if ( in_array( 'theme_files', $groups, true ) ) {
			if ( ! class_exists( '\ZipArchive' ) ) {
				throw new RuntimeException( __( 'Theme files need the PHP zip extension, which this server does not have.', 'visual-editor' ) );
			}
			$file = self::folder() . '/' . $token . '.zip';
			$zip  = new \ZipArchive();
			if ( true !== $zip->open( $file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				throw new RuntimeException( 'Could not create the zip file.' );
			}
			$zip->addFromString( 'site.json', $json );
			foreach ( array_unique( array( get_stylesheet(), get_template() ) ) as $theme ) {
				self::zip_dir( $zip, get_theme_root() . '/' . $theme, 'themes/' . $theme );
			}
			$zip->close();

			return array( 'file' => $file, 'name' => $base . '-site.zip', 'data' => $data );
		}

		$file = self::folder() . '/' . $token . '.json';
		file_put_contents( $file, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return array( 'file' => $file, 'name' => $base . '-site.json', 'data' => $data );
	}

	private static function zip_dir( \ZipArchive $zip, string $dir, string $prefix ): void {
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::LEAVES_ONLY );
		foreach ( $items as $item ) {
			$path = wp_normalize_path( (string) $item->getPathname() );
			if ( $item->isFile() && ! str_contains( $path, '/node_modules/' ) && ! str_contains( $path, '/.git/' ) ) {
				$zip->addFile( $path, $prefix . substr( $path, strlen( wp_normalize_path( $dir ) ) ) );
			}
		}
	}

	/**
	 * Package behind a secret link valid for a day: another site's import_site downloads it from there, so the
	 * large file never passes through the AI.
	 *
	 * @param array<int, string> $groups   Parts.
	 * @param array<int, int>    $page_ids Only these pages.
	 * @return array<string, mixed>
	 */
	public static function export_link( array $groups = array(), array $page_ids = array() ): array {
		$links = (array) get_option( self::LINKS, array() );
		foreach ( $links as $token => $link ) {
			if ( ! is_array( $link ) || (int) ( $link['expires'] ?? 0 ) < time() ) {
				if ( is_array( $link ) ) {
					wp_delete_file( (string) ( $link['file'] ?? '' ) );
				}
				unset( $links[ $token ] );
			}
		}
		$package         = self::package( $groups, $page_ids );
		$token           = bin2hex( random_bytes( 24 ) );
		$links[ $token ] = array( 'file' => $package['file'], 'name' => $package['name'], 'expires' => time() + self::LINK_TTL );
		update_option( self::LINKS, $links, false );
		Store::log( 'export', 'Exported ' . implode( ', ', $package['data']['groups'] ) . ' (link valid for a day)' );

		return array(
			'link'    => rest_url( 'sve/v1/transfer/' . $token ),
			'expires' => gmdate( 'c', time() + self::LINK_TTL ),
			'size'    => size_format( (int) filesize( $package['file'] ) ),
		) + self::summary( $package['data'] );
	}

	public static function hooks(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route( 'sve/v1', '/transfer/(?P<token>[a-f0-9]{48})', array( 'methods' => 'GET', 'callback' => array( self::class, 'serve' ), 'permission_callback' => '__return_true', 'show_in_index' => false ) );
			}
		);
	}

	public static function serve( \WP_REST_Request $request ): \WP_REST_Response {
		$link = ( (array) get_option( self::LINKS, array() ) )[ (string) $request['token'] ] ?? null;
		if ( ! is_array( $link ) || (int) $link['expires'] < time() || ! is_file( (string) $link['file'] ) ) {
			return new \WP_REST_Response( array( 'error' => 'This export link expired or does not exist. Export again.' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: ' . ( str_ends_with( (string) $link['file'], '.zip' ) ? 'application/zip' : 'application/json; charset=utf-8' ) );
		header( 'Content-Disposition: attachment; filename="' . $link['name'] . '"' );
		header( 'Content-Length: ' . (int) filesize( (string) $link['file'] ) );
		readfile( (string) $link['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * What a package holds, in numbers.
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
			'source'   => $data['source'],
			'groups'   => $data['groups'] ?? self::DEFAULT_GROUPS,
			'theme'    => $data['theme'],
			'posts'    => $types,
			'pages'    => array_values( array_map( static fn ( array $p ): string => (string) $p['slug'], array_filter( (array) $data['posts'], static fn ( array $p ): bool => 'page' === $p['type'] ) ) ),
			'menus'    => array_map( static fn ( array $m ): string => $m['name'] . ' (' . count( $m['items'] ) . ')', (array) ( $data['menus']['menus'] ?? array() ) ),
			'options'  => count( (array) ( $data['options'] ?? array() ) ),
			'settings' => count( (array) ( $data['settings'] ?? array() ) ),
			'snippets' => array_map( static fn ( array $s ): string => (string) ( $s['name'] ?? '' ), (array) ( $data['snippets'] ?? array() ) ),
			'media'    => count( (array) ( $data['media'] ?? array() ) ),
		);
	}

	/**
	 * Human labels of the groups.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function labels(): array {
		return array(
			'design'      => array( __( 'Design', 'visual-editor' ), __( 'Visual Editor styles, elements, components and tokens; theme customizer and theme options; Elementor kit settings; widgets; front page and site icon.', 'visual-editor' ) ),
			'pages'       => array( __( 'Pages', 'visual-editor' ), __( 'All pages (or the ones you pick) with their builder / Elementor content.', 'visual-editor' ) ),
			'layouts'     => array( __( 'Templates and layouts', 'visual-editor' ), __( 'Builder templates, headers and footers, Elementor templates, block theme templates, reusable blocks and theme layouts / HTML blocks.', 'visual-editor' ) ),
			'menus'       => array( __( 'Menus', 'visual-editor' ), __( 'Navigation menus and their locations.', 'visual-editor' ) ),
			'posts'       => array( __( 'Blog posts', 'visual-editor' ), __( 'Posts with their categories, tags and images.', 'visual-editor' ) ),
			'products'    => array( __( 'Products', 'visual-editor' ), __( 'Products and variations with prices, stock, attributes, categories, tags, brands and images.', 'visual-editor' ) ),
			'snippets'    => array( __( 'Code snippets', 'visual-editor' ), __( 'Every snippet of the Code Snippets plugin, active or not as on this site.', 'visual-editor' ) ),
			'settings'    => array( __( 'Other settings', 'visual-editor' ), __( 'Plugin and WooCommerce settings (currency, shipping…), except addresses, accounts, licences, API keys and security / cache plugins.', 'visual-editor' ) ),
			'theme_files' => array( __( 'Theme files', 'visual-editor' ), __( 'The theme folder (and its parent theme), so the other site gets exactly the same theme. Makes a zip file.', 'visual-editor' ) ),
		);
	}
}
