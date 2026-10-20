<?php
/**
 * Every tool the AI can call. Each declares the minimum key level it needs; write tools on a "draft" key store
 * proposals instead of changing the live site (see Api for the levels).
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor\Mcp;

use RuntimeException;
use VisualEditor\Api;
use VisualEditor\Builder\Renderer;
use VisualEditor\Builder\Router;
use VisualEditor\Builder\SourceEditor;
use VisualEditor\Compiler;
use VisualEditor\Content;
use VisualEditor\Forms;
use VisualEditor\Guard;
use VisualEditor\Inspector;
use VisualEditor\Islands;
use VisualEditor\Libraries;
use VisualEditor\LiveBridge;
use VisualEditor\PageKey;
use VisualEditor\Sanitizer;
use VisualEditor\Ssr;
use VisualEditor\Store;
use VisualEditor\Templates;

defined( 'ABSPATH' ) || exit;

final class Tools {

	/** @var array<string, array<string, mixed>>|null */
	private static ?array $tools = null;

	/* ------------------------------------------------------------------ schema helpers */

	/**
	 * @param array<string, mixed> $props    Properties.
	 * @param array<int, string>   $required Required names.
	 * @return array<string, mixed>
	 */
	private static function obj( array $props, array $required = array() ): array {
		return array(
			'type'       => 'object',
			'properties' => (object) $props,
			'required'   => $required,
		);
	}

	/** @return array<string, string> */
	private static function str( string $d ): array {
		return array( 'type' => 'string', 'description' => $d );
	}

	/** @return array<string, string> */
	private static function int( string $d ): array {
		return array( 'type' => 'integer', 'description' => $d );
	}

	/** @return array<string, string> */
	private static function bool( string $d ): array {
		return array( 'type' => 'boolean', 'description' => $d );
	}

	/** @return array<string, string> */
	private static function any( string $d, string $type = 'object' ): array {
		return array( 'type' => $type, 'description' => $d );
	}

	/**
	 * @param array<int, string> $values Values.
	 * @return array<string, mixed>
	 */
	private static function enum( array $values, string $d ): array {
		return array( 'type' => 'string', 'enum' => $values, 'description' => $d );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function scope(): array {
		return array(
			'scope'    => self::enum( array( 'page', 'template', 'site' ), 'Where the change applies. Default: "page" when url/post_id is given, "template" when template is given, otherwise "site".' ),
			'url'      => self::str( 'Page URL of this site (or path like /about/) for scope "page".' ),
			'post_id'  => self::int( 'Alternative to url: id of the page/post/product.' ),
			'template' => self::str( 'Theme template key from list_templates, e.g. "single:product", for scope "template".' ),
		);
	}

	/* ------------------------------------------------------------------ registry */

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null !== self::$tools ) {
			return self::$tools;
		}
		$t = array();

		$add = static function ( string $name, string $level, string $description, array $schema, callable $run ) use ( &$t ): void {
			$t[ $name ] = array(
				'name'        => $name,
				'level'       => $level,
				'description' => $description,
				'inputSchema' => $schema,
				'run'         => $run,
			);
		};

		/* ---- orientation ---- */
		$add( 'get_site_overview', 'read', 'Start here: the site, theme, plugins, post types, taxonomies, menus, templates, builder templates, components, libraries, design tokens, your permission level and whether a live editor is open.', self::obj( array() ), array( self::class, 'overview' ) );
		$add( 'get_guide', 'read', 'How to build with this editor: workflow, editing, builder syntax (bindings, loops, components), components & React islands, design/motion, SEO, admin. Read topic "all" once.', self::obj( array( 'topic' => self::enum( array( 'all', 'workflow', 'editing', 'syntax', 'components', 'design', 'seo', 'admin' ), 'Topic' ) ) ), static fn ( array $a ): array => array( 'guide' => Guide::text( (string) ( $a['topic'] ?? 'all' ) ) ) );
		$add( 'get_activity', 'read', 'Recent changes by people and AI keys, and the changes waiting in the AI draft.', self::obj( array() ), array( self::class, 'activity' ) );

		/* ---- seeing ---- */
		$add( 'list_content', 'read', 'Find pages, posts, products, builder templates (type "sve_template") or any public post type.', self::obj( array( 'type' => self::str( 'Post type, e.g. page, post, product, sve_template' ), 'search' => self::str( 'Words' ), 'status' => self::str( 'publish, draft, pending, private' ), 'page' => self::int( 'Result page' ), 'per_page' => self::int( '1–100' ) ) ), static fn ( array $a ): array => Content::list_posts( $a ) );
		$add( 'get_content', 'read', 'Everything about one page/post/product/template: fields, terms, meta, SEO, product data and — for builder content — builder.source and builder.outline (element ids for edit_layout).', self::obj( array( 'id' => self::int( 'Post id' ) ), array( 'id' ) ), array( self::class, 'get_content' ) );
		$add( 'list_templates', 'read', 'Theme templates (single product, single post, archives, search, 404…) with a sample URL each, plus builder templates and header/footer parts.', self::obj( array() ), static fn (): array => array( 'theme_templates' => Templates::listing(), 'builder_templates' => Router::templates(), 'hint' => 'Use scope "template" with a theme template key to change every page it renders; create_template to replace it with a builder layout.' ) );
		$add( 'get_page_outline', 'read', 'Structure of a page as visitors get it (saved edits applied): every element with a stable selector, tag, classes, text, links. Use root to zoom into one part.', self::obj( array( 'url' => self::str( 'Page URL or path' ), 'root' => self::str( 'Optional selector to start from' ), 'find' => self::str( 'Keep only lines containing this text' ), 'max_nodes' => self::int( 'Default 150, max 600' ), 'as_user' => self::bool( 'Load as the key user (to see drafts/private pages)' ) ), array( 'url' ) ), array( self::class, 'outline' ) );
		$add( 'find_elements', 'read', 'Find elements by visible text, role (button, link, heading, image, input, section, form), tag or selector — e.g. text "Book now", role "button". Returns selectors, text, section context.', self::obj( array( 'url' => self::str( 'Page URL or path' ), 'text' => self::str( 'Visible text / label' ), 'role' => self::str( 'button | link | heading | image | input | section | form' ), 'tag' => self::str( 'Tag name' ), 'selector' => self::str( 'CSS selector' ), 'limit' => self::int( 'Max results (10)' ), 'as_user' => self::bool( 'Load as the key user' ) ), array( 'url' ) ), array( self::class, 'find' ) );
		$add( 'inspect_element', 'read', 'Look closely at one element: markup, attributes, ancestors, children, the CSS rules that style it, editor changes on it — and, when the editor is open, computed styles, size/position and a screenshot of the element.', self::obj( array( 'url' => self::str( 'Page URL or path' ), 'selector' => self::str( 'CSS selector (from find_elements / get_page_outline)' ), 'device' => self::enum( array( 'desktop', 'tablet', 'mobile' ), 'Viewport for the live view' ), 'live' => self::bool( 'Use the open editor (default true)' ), 'screenshot' => self::bool( 'Include an image (default true)' ) ), array( 'url', 'selector' ) ), array( self::class, 'inspect' ) );
		$add( 'screenshot', 'read', 'Picture of a page or one element on desktop, tablet or mobile (needs the editor open).', self::obj( array( 'url' => self::str( 'Page URL or path' ), 'selector' => self::str( 'Optional element' ), 'device' => self::enum( array( 'desktop', 'tablet', 'mobile' ), 'Viewport' ), 'full_page' => self::bool( 'Whole page instead of the first screen' ) ), array( 'url' ) ), array( self::class, 'screenshot' ) );
		$page   = array( 'url' => self::str( 'Page URL or path (default: home page)' ), 'post_id' => self::int( 'Alternative to url' ), 'as_user' => self::bool( 'As the logged-in administrator instead of a visitor' ) );
		$window = array( 'offset' => self::int( 'Start of the window (from "next" of the previous answer)' ), 'length' => self::int( 'Window size in characters (default 60000, max 120000)' ) );
		$add( 'get_page_source', 'read', 'The raw HTML a visitor gets for a page (head, body or both), in windows of up to 120k characters. Use it when the outline is not enough: theme markup, inline scripts, data attributes, exact classes.', self::obj( array_merge( $page, $window, array( 'part' => self::enum( array( 'full', 'head', 'body' ), 'Default full' ) ) ) ), array( Explore::class, 'source' ) );
		$add( 'get_page_assets', 'read', 'Every stylesheet, inline style, script, font and image a page loads, and where each comes from (theme:<name>, plugin:<name>, uploads, core, external). include_css returns the CSS text itself (match keeps only files containing a class name, e.g. "ig-app").', self::obj( array_merge( $page, array( 'include_css' => self::bool( 'Also return the CSS text' ), 'match' => self::str( 'With include_css: only CSS containing this text' ) ) ) ), array( Explore::class, 'assets' ) );
		$add( 'export_page', 'read', 'A page as a portable bundle to rebuild on another site: part "html" (body markup without scripts) or part "css" (all its CSS with absolute url()), plus scripts, images and fonts. Read long parts in windows with offset.', self::obj( array_merge( $page, $window, array( 'part' => self::enum( array( 'html', 'css' ), 'Default html' ), 'start_at' => self::str( 'Optional: start the html at the first occurrence of this text, e.g. class="ig-app"' ) ) ) ), array( Explore::class, 'export' ) );
		$add( 'list_files', 'admin', 'Folders and files of themes, plugins, mu-plugins and uploads (read-only). path "" lists the roots and the active theme.', self::obj( array( 'path' => self::str( 'e.g. themes/twentytwentyfive or mu-plugins' ), 'depth' => self::int( 'Sub-folder levels to include (0-3)' ) ) ), array( Explore::class, 'list_files' ) );
		$add( 'read_file', 'admin', 'Read a text file of a theme, plugin, mu-plugin or uploads (php, css, js, json, html…) in windows.', self::obj( array_merge( array( 'path' => self::str( 'e.g. themes/my-theme/style.css' ) ), $window ), array( 'path' ) ), array( Explore::class, 'read_file' ) );
		$add( 'get_page_changes', 'read', 'All editor changes that apply to a URL or template: style/content edits (with ids), inserted/moved/removed elements, motions, SEO.', self::obj( array( 'url' => self::str( 'Page URL' ), 'template' => self::str( 'Template key' ) ) ), array( self::class, 'page_changes' ) );
		$add( 'list_components', 'read', 'Saved reusable components (name, kind, props, markup, CSS).', self::obj( array() ), static fn (): array => array( 'components' => array_map( array( self::class, 'component_summary' ), self::doc()['components'] ) ) );
		$add( 'list_libraries', 'read', 'Front-end libraries (GSAP, Swiper, Alpine, Lenis, Lottie, Chart.js, icons…) with usage notes, and which are enabled.', self::obj( array() ), static fn (): array => array( 'catalog' => array_values( array_map( static fn ( array $l ): array => array_intersect_key( $l, array_flip( array( 'id', 'name', 'category', 'version', 'deps', 'usage' ) ) ), Libraries::catalog() ) ), 'enabled' => self::doc()['libraries'] ) );
		$add( 'get_design_tokens', 'read', 'Design tokens (CSS variables, fonts), global CSS and responsive breakpoints.', self::obj( array() ), static fn (): array => array( 'tokens' => self::doc()['tokens'], 'global_css' => self::doc()['css'], 'settings' => self::doc()['settings'], 'tailwind' => array( 'enabled' => self::doc()['tailwind']['enabled'] ) ) );
		$add( 'list_media', 'read', 'Media library (images, videos, files).', self::obj( array( 'search' => self::str( 'Words' ), 'page' => self::int( 'Page' ), 'type' => self::str( 'image | video | audio | application' ) ) ), static fn ( array $a ): array => array( 'items' => Content::list_media( (string) ( $a['search'] ?? '' ), (int) ( $a['page'] ?? 1 ), (string) ( $a['type'] ?? 'image' ) ) ) );
		$add( 'list_menus', 'read', 'Navigation menus with items, and the theme\'s menu locations.', self::obj( array() ), static fn (): array => Content::menus() );
		$add( 'list_terms', 'read', 'Categories, tags, product categories or terms of any taxonomy.', self::obj( array( 'taxonomy' => self::str( 'category, post_tag, product_cat…' ), 'search' => self::str( 'Words' ) ), array( 'taxonomy' ) ), static fn ( array $a ): mixed => Content::list_terms( (string) $a['taxonomy'], (string) ( $a['search'] ?? '' ) ) );
		$add( 'list_revisions', 'read', 'Saved versions of the editor document (styles, elements, components, SEO) to roll back to.', self::obj( array() ), static fn (): array => array( 'revisions' => Store::revisions() ) );
		$add( 'list_form_submissions', 'read', 'What visitors sent through builder forms.', self::obj( array( 'form' => self::str( 'Form name (data-sve-form)' ), 'limit' => self::int( 'Max (50)' ) ) ), static fn ( array $a ): array => array( 'submissions' => Forms::list( (string) ( $a['form'] ?? '' ), (int) ( $a['limit'] ?? 50 ) ) ) );
		$add( 'list_plugins', 'read', 'Installed plugins and whether they are active.', self::obj( array() ), static fn (): array => array( 'plugins' => Content::plugins() ) );
		$add( 'get_settings', 'read', 'Site settings (title, tagline, front page, reading, permalinks, WooCommerce currency…).', self::obj( array( 'names' => self::any( 'Option names (admin keys may read others)', 'array' ) ) ), static fn ( array $a ): array => Content::options( (array) ( $a['names'] ?? array() ) ) );
		$add( 'render_preview', 'read', 'Render builder source on the server (bindings, loops, components) to check it before saving. Optionally in the context of a post.', self::obj( array( 'source' => self::str( 'Builder source' ), 'post_id' => self::int( 'Post to use as {{ post }}' ) ), array( 'source' ) ), array( self::class, 'render_preview' ) );

		/* ---- content ---- */
		$content = array(
			'type'           => self::str( 'page, post, product, any public post type (create only)' ),
			'title'          => self::str( 'Title' ),
			'status'         => self::enum( array( 'publish', 'draft', 'pending', 'private', 'future' ), 'Status' ),
			'slug'           => self::str( 'URL slug' ),
			'parent'         => self::int( 'Parent page id' ),
			'menu_order'     => self::int( 'Order' ),
			'content'        => self::str( 'Classic/Gutenberg content (not needed for builder pages)' ),
			'excerpt'        => self::str( 'Excerpt' ),
			'builder'        => self::any( '{source: builder markup, mode: "theme"|"canvas"|"blank"} — the visual layout. source null removes it.' ),
			'featured_image' => self::any( 'Attachment id or image URL (downloaded into the library)', 'string' ),
			'terms'          => self::any( '{taxonomy: [names or ids]}, e.g. {"category":["News"]}' ),
			'meta'           => self::any( '{key: value} custom fields' ),
			'acf'            => self::any( '{field: value} Advanced Custom Fields' ),
			'seo'            => self::any( '{title, description, image, canonical, noindex, nofollow, jsonld}' ),
			'product'        => self::any( 'WooCommerce: {type, regular_price, sale_price, sku, stock_status, manage_stock, stock_quantity, short_description, description, gallery[], attributes[{name, options[], variation}], variations[{attributes{}, regular_price}]}' ),
			'template'       => self::str( 'Theme page template file' ),
			'front_page'     => self::bool( 'Make this page the home page' ),
		);
		$add( 'create_content', 'draft', 'Create a page, post, product or any content — optionally with a visual builder layout (builder.source).', self::obj( $content, array( 'type', 'title' ) ), static fn ( array $a ): mixed => self::save_content( $a, true ) );
		$add( 'update_content', 'draft', 'Change any field of existing content (title, status, layout, product data, terms, SEO…).', self::obj( array_merge( array( 'id' => self::int( 'Post id' ) ), $content ), array( 'id' ) ), static fn ( array $a ): mixed => self::save_content( $a, false ) );
		$add( 'delete_content', 'live', 'Move content to the trash (force: delete permanently).', self::obj( array( 'id' => self::int( 'Post id' ), 'force' => self::bool( 'Delete permanently' ) ), array( 'id' ) ), static fn ( array $a ): mixed => self::logged( Content::delete_post( (int) $a['id'], ! empty( $a['force'] ) ), 'delete_content', 'Deleted #' . (int) $a['id'] ) );
		$add( 'duplicate_content', 'draft', 'Copy a page/post/product with its layout (as a draft).', self::obj( array( 'id' => self::int( 'Post id' ) ), array( 'id' ) ), static fn ( array $a ): mixed => self::logged( Content::duplicate_post( (int) $a['id'] ), 'duplicate_content', 'Duplicated #' . (int) $a['id'] ) );
		$add( 'set_page_layout', 'draft', 'Replace the whole builder layout of a page/post/product/template.', self::obj( array( 'id' => self::int( 'Post id' ), 'source' => self::str( 'Builder markup' ), 'mode' => self::enum( Router::MODES, 'theme | canvas | blank' ) ), array( 'id', 'source' ) ), static fn ( array $a ): mixed => self::save_content( array( 'id' => $a['id'], 'builder' => array( 'source' => $a['source'], 'mode' => $a['mode'] ?? null ) ), false ) );
		$add( 'edit_layout', 'draft', 'Precise change of one element of a builder layout by its data-sid: replace, remove, insert_before, insert_after, prepend, append, set_inner, set_text, set_attrs, wrap, duplicate, move_before/move_after/move_into (target = another sid).', self::obj( array( 'id' => self::int( 'Post / template id' ), 'sid' => self::str( 'data-sid of the element' ), 'action' => self::enum( array( 'replace', 'remove', 'insert_before', 'insert_after', 'prepend', 'append', 'set_inner', 'set_text', 'set_attrs', 'wrap', 'duplicate', 'move_before', 'move_after', 'move_into' ), 'What to do' ), 'html' => self::str( 'Markup for replace/insert/prepend/append/set_inner' ), 'text' => self::str( 'Plain text for set_text' ), 'attrs' => self::any( '{name: value|null} for set_attrs (class, href, src, alt, style…)' ), 'wrapper' => self::str( 'Opening tag for wrap, e.g. <div class="grid">' ), 'target' => self::str( 'Target sid for move_*' ) ), array( 'id', 'sid', 'action' ) ), array( self::class, 'edit_layout' ) );
		$add( 'create_template', 'draft', 'Create a builder template that replaces a theme template for many URLs (target single:product, single:post, archive:post, tax:product_cat, search, 404, single:*, archive:*), or a header/footer part.', self::obj( array( 'title' => self::str( 'Name' ), 'target' => self::str( 'Template target' ), 'source' => self::str( 'Builder markup' ), 'mode' => self::enum( Router::MODES, 'theme (inside theme header/footer) | canvas | blank' ), 'active' => self::bool( 'Use it on the site now (default true; draft keys: false)' ), 'conditions' => self::any( '{include:[post/term ids], exclude:[ids]}' ), 'replace' => self::str( 'header/footer parts: selector of the theme header/footer to hide and replace on theme pages' ), 'priority' => self::int( 'Higher wins' ) ), array( 'title', 'target', 'source' ) ), static fn ( array $a ): mixed => self::save_template( $a ) );
		$add( 'update_template', 'draft', 'Change a builder template (source, target, mode, active, conditions…).', self::obj( array( 'id' => self::int( 'Template id' ), 'title' => self::str( 'Name' ), 'target' => self::str( 'Target' ), 'source' => self::str( 'Builder markup' ), 'mode' => self::enum( Router::MODES, 'Mode' ), 'active' => self::bool( 'Active' ), 'conditions' => self::any( '{include, exclude}' ), 'replace' => self::str( 'Theme selector to replace (parts)' ), 'priority' => self::int( 'Priority' ) ), array( 'id' ) ), static fn ( array $a ): mixed => self::save_template( $a ) );
		$add( 'delete_template', 'live', 'Delete a builder template (the theme template is used again).', self::obj( array( 'id' => self::int( 'Template id' ) ), array( 'id' ) ), static fn ( array $a ): mixed => self::logged( Content::delete_post( (int) $a['id'], true ), 'delete_template', 'Deleted template #' . (int) $a['id'] ) );
		$add( 'upload_media', 'draft', 'Add an image/file to the media library from a URL or base64 data.', self::obj( array( 'url' => self::str( 'File URL' ), 'data' => self::str( 'Base64 data (data: URL allowed)' ), 'filename' => self::str( 'File name' ), 'alt' => self::str( 'Alt text' ), 'title' => self::str( 'Title' ) ) ), static fn ( array $a ): mixed => self::logged( Content::upload( $a ), 'upload_media', 'Uploaded ' . (string) ( $a['filename'] ?? $a['url'] ?? 'file' ) ) );
		$add( 'save_menu', 'live', 'Create or replace a navigation menu and assign it to a theme location.', self::obj( array( 'id' => self::int( 'Menu id (to replace)' ), 'name' => self::str( 'Menu name' ), 'location' => self::str( 'Theme location (list_menus)' ), 'items' => self::any( '[{title, url | page_id | post_id | term_id+taxonomy, target, classes, children:[…]}]', 'array' ) ), array( 'name' ) ), static fn ( array $a ): mixed => self::logged( Content::save_menu( $a ), 'save_menu', 'Menu ' . (string) $a['name'] ) );
		$add( 'save_term', 'live', 'Create or update a category / tag / product category / any term.', self::obj( array( 'taxonomy' => self::str( 'Taxonomy' ), 'id' => self::int( 'Term id (update)' ), 'name' => self::str( 'Name' ), 'slug' => self::str( 'Slug' ), 'parent' => self::int( 'Parent id' ), 'description' => self::str( 'Description' ), 'image' => self::any( 'Image id or URL (product categories)', 'string' ) ), array( 'taxonomy' ) ), static fn ( array $a ): mixed => self::logged( Content::save_term( $a ), 'save_term', 'Term ' . (string) ( $a['name'] ?? $a['id'] ?? '' ) ) );
		$add( 'delete_term', 'live', 'Delete a term.', self::obj( array( 'taxonomy' => self::str( 'Taxonomy' ), 'id' => self::int( 'Term id' ) ), array( 'taxonomy', 'id' ) ), static fn ( array $a ): mixed => Content::delete_term( (string) $a['taxonomy'], (int) $a['id'] ) );

		/* ---- styles & elements (selector based) ---- */
		$add( 'set_style', 'draft', 'Set CSS properties on every element matching a selector, per device and state.', self::obj( array_merge( self::scope(), array( 'selector' => self::str( 'CSS selector' ), 'styles' => self::any( 'Property → value, e.g. {"color":"#c00","border-radius":"12px"}. "" removes a property.' ), 'device' => self::enum( array( 'base', 'tablet', 'mobile' ), 'base = all sizes' ), 'state' => self::enum( array( 'normal', 'hover', 'focus', 'active', 'focus-visible', 'before', 'after', 'placeholder' ), 'State' ) ) ), array( 'selector', 'styles' ) ), array( self::class, 'set_style' ) );
		$add( 'add_css', 'draft', 'Add free CSS (rules, media queries, keyframes). With a selector, "selector"/"&" in the CSS stands for it.', self::obj( array_merge( self::scope(), array( 'css' => self::str( 'CSS' ), 'selector' => self::str( 'Optional selector' ) ) ), array( 'css' ) ), array( self::class, 'add_css' ) );
		$add( 'set_content', 'draft', 'Change text, inner HTML or attributes (href, src, alt, class, target, aria-*, data-*) of elements matching a selector.', self::obj( array_merge( self::scope(), array( 'selector' => self::str( 'CSS selector' ), 'text' => self::str( 'Plain text' ), 'html' => self::str( 'Inner HTML' ), 'attrs' => self::any( 'Attribute → value, null removes' ) ) ), array( 'selector' ) ), array( self::class, 'set_content' ) );
		$add( 'insert_html', 'draft', 'Insert markup or a component before/after/inside the element matching a selector.', self::obj( array_merge( self::scope(), array( 'selector' => self::str( 'Anchor element' ), 'position' => self::enum( array( 'before', 'after', 'prepend', 'append' ), 'Where' ), 'html' => self::str( 'Markup' ), 'component' => self::str( 'Component id or name instead of html' ), 'props' => self::any( 'Component props' ) ) ), array( 'selector', 'position' ) ), array( self::class, 'insert_html' ) );
		$add( 'move_element', 'draft', 'Move an element before/after/into another one.', self::obj( array_merge( self::scope(), array( 'selector' => self::str( 'Element to move' ), 'to' => self::str( 'Target element' ), 'position' => self::enum( array( 'before', 'after', 'prepend', 'append' ), 'Where' ) ) ), array( 'selector', 'to', 'position' ) ), array( self::class, 'move_element' ) );
		$add( 'remove_element', 'draft', 'Remove an element (an inert placeholder keeps the page structure stable).', self::obj( array_merge( self::scope(), array( 'selector' => self::str( 'CSS selector' ) ) ), array( 'selector' ) ), array( self::class, 'remove_element' ) );
		$add( 'change_tag', 'draft', 'Change an element\'s tag (e.g. div → section, h3 → h2).', self::obj( array_merge( self::scope(), array( 'selector' => self::str( 'CSS selector' ), 'tag' => self::str( 'New tag' ) ) ), array( 'selector', 'tag' ) ), array( self::class, 'change_tag' ) );
		$add( 'hide_element', 'draft', 'Show or hide an element per device (responsive visibility).', self::obj( array_merge( self::scope(), array( 'selector' => self::str( 'CSS selector' ), 'desktop' => self::bool( 'Hide on desktop' ), 'tablet' => self::bool( 'Hide on tablet' ), 'mobile' => self::bool( 'Hide on mobile' ) ) ), array( 'selector' ) ), array( self::class, 'hide_element' ) );
		$add( 'set_motion', 'draft', 'Animate an element with GSAP: entrance on scroll/load, hover, click, scroll-scrubbed parallax, staggered children. preset "none" removes.', self::obj( array_merge( self::scope(), array( 'selector' => self::str( 'CSS selector' ), 'preset' => self::str( 'fade | fade-up | fade-down | fade-left | fade-right | zoom-in | zoom-out | flip-up | rotate-in | blur-in | slide-up | slide-down | slide-left | slide-right | parallax | float | pulse | spin | stagger-children | reveal-text | custom | none' ), 'trigger' => self::enum( array( 'scroll', 'load', 'hover', 'click', 'scrub' ), 'When' ), 'duration' => array( 'type' => 'number' ), 'delay' => array( 'type' => 'number' ), 'stagger' => array( 'type' => 'number' ), 'distance' => array( 'type' => 'number', 'description' => 'px for slides/parallax' ), 'ease' => self::str( 'GSAP ease, e.g. power3.out, back.out(1.7), elastic.out(1,0.4)' ), 'repeat' => self::int( '-1 = forever' ), 'yoyo' => self::bool( 'Back and forth' ), 'from' => self::any( 'custom preset: GSAP from vars' ), 'to' => self::any( 'custom preset: GSAP to vars' ) ) ), array( 'selector', 'preset' ) ), array( self::class, 'set_motion' ) );
		$add( 'delete_change', 'draft', 'Undo one editor change by id (ids from get_page_changes).', self::obj( array( 'id' => self::str( 'Change id' ) ), array( 'id' ) ), array( self::class, 'delete_change' ) );

		/* ---- design system ---- */
		$add( 'set_design_tokens', 'draft', 'Set design tokens: CSS variables (colors, radius, spacing, shadows…) and fonts (Google/Bunny fonts with roles body/heading). Merged with existing ones; value "" removes a variable.', self::obj( array( 'vars' => self::any( '{"--color-primary":"#0f766e"}' ), 'fonts' => self::any( '[{family, source:"google"|"bunny"|"system", weights:[400,700], role:"body"|"heading"|"mono"|"accent"}] (replaces the font list)', 'array' ), 'breakpoints' => self::any( '{tablet:1023, mobile:767}' ) ) ), array( self::class, 'set_tokens' ) );
		$add( 'set_global_css', 'draft', 'Site-wide CSS.', self::obj( array( 'css' => self::str( 'CSS' ), 'mode' => self::enum( array( 'append', 'replace' ), 'Default append' ) ), array( 'css' ) ), array( self::class, 'set_global_css' ) );
		$add( 'save_component', 'draft', 'Create or update a reusable component (kind html | section | jsonld; react / embed / js need an admin key). Props: [{name, type, default, label}].', self::obj( array( 'id' => self::str( 'Existing id (or match by name)' ), 'name' => self::str( 'Name' ), 'kind' => self::enum( array( 'html', 'section', 'jsonld', 'react', 'embed' ), 'Kind' ), 'html' => self::str( 'Builder markup (react: server-rendered fallback)' ), 'css' => self::str( 'CSS' ), 'js' => self::str( 'JavaScript run per instance with `root` (admin)' ), 'module' => self::str( 'react: ES module with default export (bare imports via esm.sh)' ), 'jsx' => self::str( 'react: JSX source (compiled by the open editor)' ), 'props' => self::any( '[{name, type: text|html|url|image|number|bool|color|select|json|list, default, label}]', 'array' ), 'category' => self::str( 'Category' ) ), array( 'name' ) ), array( self::class, 'save_component' ) );
		$add( 'delete_component', 'live', 'Delete a component.', self::obj( array( 'id' => self::str( 'Component id or name' ) ), array( 'id' ) ), array( self::class, 'delete_component' ) );
		$add( 'set_library', 'draft', 'Enable/disable a front-end library on the site, a page or a template. Custom https libraries (url) need an admin key.', self::obj( array( 'id' => self::str( 'Catalog id or new custom id' ), 'enabled' => self::bool( 'On/off (default on)' ), 'scope' => self::enum( array( 'site', 'page', 'template' ), 'Where' ), 'pages' => self::any( 'Page URLs for scope page', 'array' ), 'templates' => self::any( 'Template keys for scope template', 'array' ), 'url' => self::str( 'Custom library URL (https)' ), 'type' => self::enum( array( 'script', 'module', 'style' ), 'Custom library type' ), 'name' => self::str( 'Custom library name' ) ), array( 'id' ) ), array( self::class, 'set_library' ) );
		$add( 'add_script', 'admin', 'Add custom JavaScript to the site, a page or a template (admin keys).', self::obj( array_merge( self::scope(), array( 'id' => self::str( 'Existing id to replace' ), 'label' => self::str( 'Name' ), 'code' => self::str( 'JavaScript' ), 'where' => self::enum( array( 'head', 'footer' ), 'Default footer' ), 'module' => self::bool( 'type="module"' ), 'enabled' => self::bool( 'Default true' ) ) ), array( 'code' ) ), array( self::class, 'add_script' ) );
		$add( 'set_seo', 'draft', 'SEO of a page, post or template: title, description, share image, canonical, robots, JSON-LD.', self::obj( array( 'url' => self::str( 'Page URL' ), 'post_id' => self::int( 'Post id' ), 'template' => self::str( 'Template key (titles may use %title% %site% %sep%)' ), 'title' => self::str( 'SEO title' ), 'description' => self::str( 'Meta description' ), 'image' => self::str( 'Share image URL' ), 'canonical' => self::str( 'Canonical URL' ), 'noindex' => self::bool( 'noindex' ), 'nofollow' => self::bool( 'nofollow' ), 'jsonld' => self::str( 'JSON-LD (JSON string)' ), 'ogTitle' => self::str( 'Open Graph title' ), 'ogDescription' => self::str( 'Open Graph description' ) ) ), array( self::class, 'set_seo' ) );

		/* ---- history ---- */
		$add( 'restore_revision', 'live', 'Roll the editor document back to a revision (list_revisions).', self::obj( array( 'id' => self::str( 'Revision id' ) ), array( 'id' ) ), array( self::class, 'restore_revision' ) );
		$add( 'publish_draft', 'admin', 'Publish the pending AI draft (merged into the current site).', self::obj( array() ), array( self::class, 'publish_draft' ) );
		$add( 'discard_draft', 'draft', 'Throw away the pending AI draft.', self::obj( array() ), static function (): array {
			Store::set_draft( null );
			Store::log( 'discard_draft', 'Discarded the AI draft' );
			return array( 'discarded' => true );
		} );

		/* ---- settings, plugins, any API ---- */
		$add( 'update_settings', 'live', 'Change site settings (title, tagline, front page, reading, permalinks, timezone, WooCommerce currency…). Admin keys may change other options too.', self::obj( array( 'values' => self::any( '{option: value}' ) ), array( 'values' ) ), static fn ( array $a ): mixed => self::logged( Content::update_options( (array) $a['values'], Api::allows( 'admin' ) ), 'update_settings', 'Settings: ' . implode( ', ', array_keys( (array) $a['values'] ) ) ) );
		$add( 'manage_plugin', 'admin', 'Install (from wordpress.org), activate or deactivate a plugin.', self::obj( array( 'action' => self::enum( array( 'install', 'activate', 'deactivate' ), 'Action' ), 'plugin' => self::str( 'Slug (install) or file / folder (activate, deactivate)' ) ), array( 'action', 'plugin' ) ), static fn ( array $a ): mixed => self::logged( Content::plugin_action( (string) $a['action'], (string) $a['plugin'] ), 'manage_plugin', (string) $a['action'] . ' ' . (string) $a['plugin'] ) );
		$parts = self::any( 'Parts: design, pages, layouts, menus, posts, products, snippets, settings, theme_files. Default: design, pages, layouts, menus (the look, not the content). All of them = the whole site except orders and users.', 'array' );
		$add( 'export_site', 'admin', 'Export this site — all of it or the parts you choose — to a private link valid for a day (a zip when theme_files is included). Give the link to import_site on the other site; the file never passes through you.', self::obj( array( 'groups' => $parts, 'pages' => self::any( 'Only these page ids (default: all pages)', 'array' ) ) ), static fn ( array $a ): array => \VisualEditor\SiteTransfer::export_link( (array) ( $a['groups'] ?? array() ), array_map( 'intval', (array) ( $a['pages'] ?? array() ) ) ) );
		$add( 'import_site', 'admin', 'Import a package made by export_site on another site into THIS site. First call with url + preview:true and tell the person what will be replaced. Then call with url (and groups to import only some parts): it starts a job and works ~20 s; while the answer has done:false, call again with {job} until done. Content not in the package (e.g. this site\'s own products when only the look is imported) stays.', self::obj( array( 'url' => self::str( 'The link export_site returned' ), 'job' => self::str( 'Continue this job' ), 'preview' => self::bool( 'Only describe the package' ), 'groups' => $parts, 'media' => self::bool( 'Copy images into this media library (default true)' ), 'max_media' => self::int( 'Max files to copy (default 2000)' ), 'theme' => self::bool( 'Switch to the exported theme (default true)' ), 'replace' => self::bool( 'Replace pages/products/templates with the same address (default true)' ) ) ), array( self::class, 'import_site' ) );
		$add( 'wp_rest', 'read', 'Call any WordPress or plugin REST route as the key\'s user (GET for every key; POST/PUT/PATCH/DELETE need an admin key). E.g. GET /wp/v2/types, GET /wc/v3/products, POST /wp/v2/comments.', self::obj( array( 'method' => self::enum( array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), 'HTTP method' ), 'route' => self::str( 'Route, e.g. /wp/v2/pages' ), 'params' => self::any( 'Query parameters' ), 'body' => self::any( 'JSON body' ) ), array( 'route' ) ), array( self::class, 'wp_rest' ) );

		/* ---- collaboration ---- */
		$add( 'show_in_editor', 'read', 'Point something out to the person in their open editor: open a page and highlight an element with a message.', self::obj( array( 'url' => self::str( 'Page URL' ), 'selector' => self::str( 'Element to highlight' ), 'message' => self::str( 'Short note shown next to it' ) ) ), array( self::class, 'show_in_editor' ) );

		self::$tools = $t;

		return $t;
	}

	/**
	 * Tool list for tools/list (only what this key may use is marked usable in the description).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function listing(): array {
		return self::facade();
	}

	/**
	 * The full catalogue (every operation with its schema), for clients that want it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function full_listing(): array {
		$out = array();
		foreach ( self::all() as $tool ) {
			$description = $tool['description'];
			if ( ! Api::allows( $tool['level'] ) ) {
				$description .= ' [Needs a "' . $tool['level'] . '" key — this key is "' . Api::current_level() . '".]';
			}
			$out[] = array(
				'name'        => $tool['name'],
				'description' => $description,
				'inputSchema' => $tool['inputSchema'],
				'annotations' => array(
					'readOnlyHint'    => 'read' === $tool['level'] && 'wp_rest' !== $tool['name'],
					'destructiveHint' => in_array( $tool['name'], array( 'delete_content', 'delete_template', 'delete_term', 'delete_component', 'manage_plugin', 'restore_revision', 'wp_rest' ), true ),
				),
			);
		}

		return $out;
	}

	public static function exists( string $name ): bool {
		return in_array( $name, self::FACADE, true ) || isset( self::all()[ $name ] );
	}

	public static function is_write( string $name, array $args = array() ): bool {
		if ( 'wp' === $name ) {
			return 'GET' !== strtoupper( (string) ( $args['method'] ?? 'GET' ) ) && '' !== (string) ( $args['route'] ?? '' );
		}
		if ( 'do' === $name ) {
			if ( ! empty( $args['help'] ) ) {
				return false;
			}
			if ( 'batch' === ( $args['tool'] ?? '' ) ) {
				foreach ( (array) ( $args['args']['steps'] ?? array() ) as $step ) {
					if ( is_array( $step ) && self::is_write( (string) ( $step['tool'] ?? '' ), (array) ( $step['args'] ?? array() ) ) ) {
						return true;
					}
				}

				return false;
			}

			return self::is_write( (string) ( $args['tool'] ?? '' ), (array) ( $args['args'] ?? array() ) );
		}
		$tool = self::all()[ $name ] ?? null;

		return null !== $tool && 'read' !== $tool['level'];
	}

	/* ------------------------------------------------------------------ compact surface */

	private const FACADE = array( 'site', 'do', 'wp' );

	/**
	 * Three tools instead of sixty: the list the AI receives at the start of every session stays around 600
	 * tokens, and the operations it may use are listed (one line each) by `site`. Schemas come on demand.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function facade(): array {
		return array(
			array(
				'name'        => 'site',
				'description' => 'START HERE. This site (name, url, what it is, theme, plugins, content types, templates, components) and the operations you may run with `do`. With several WordPress sites connected, check `site.url` matches the site you mean.',
				'inputSchema' => self::obj( array( 'guide' => self::enum( array( 'all', 'workflow', 'editing', 'syntax', 'components', 'design', 'seo', 'admin' ), 'Also return a how-to topic ("all" once per session)' ) ) ),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			array(
				'name'        => 'do',
				'description' => 'Run one operation listed by `site` (look at pages, edit styles/layouts, build pages and templates, content, SEO, components, revisions…). Pass help:true to get an operation\'s argument schema first.',
				'inputSchema' => self::obj(
					array(
						'tool' => self::str( 'Operation name' ),
						'args' => self::any( 'Its arguments' ),
						'help' => self::bool( 'Return the schema instead of running' ),
					),
					array( 'tool' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
			array(
				'name'        => 'wp',
				'description' => 'Any WordPress / plugin REST route as the key\'s administrator (GET for every key; changes need an admin key). Without `route`, `search` finds routes. Use _fields to keep answers small.',
				'inputSchema' => self::obj(
					array(
						'route'  => self::str( 'e.g. /wp/v2/posts' ),
						'method' => self::enum( array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), 'Default GET' ),
						'params' => self::any( 'Query parameters' ),
						'body'   => self::any( 'JSON body' ),
						'search' => self::str( 'Find routes containing this text' ),
					)
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
			),
		);
	}

	/**
	 * One line per operation this key may run.
	 *
	 * @return array<int, string>
	 */
	private static function operations(): array {
		$out = array( 'batch — Several operations as one step (args.steps:[{tool,args}]): one revision, document rolled back if one fails' );
		foreach ( self::all() as $tool ) {
			if ( in_array( $tool['name'], array( 'wp_rest', 'get_site_overview', 'get_guide' ), true ) || ! Api::allows( $tool['level'] ) ) {
				continue;
			}
			$first = (string) preg_replace( '/(?<=[.!?])\s.*$/s', '', $tool['description'] );
			$out[] = $tool['name'] . ' — ' . ( mb_strlen( $first ) > 72 ? mb_substr( $first, 0, 69 ) . '…' : $first );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	private static function site_tool( array $a ): array {
		$out = array_merge(
			array( 'server' => Api::server_name(), 'about' => Api::settings()['about'], 'level' => Api::current_level() ),
			self::overview()
		);
		if ( ! empty( $a['guide'] ) ) {
			$out['guide'] = Guide::text( (string) $a['guide'] );
		}
		unset( $out['next'] );
		$out['operations'] = self::operations();
		$out['how']        = 'do{tool,args}; do{tool,help:true} shows the schema. Sites are told apart by site.url.';

		return $out;
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	private static function do_tool( array $a ): array {
		$name = (string) ( $a['tool'] ?? '' );
		if ( in_array( $name, self::FACADE, true ) || 'wp_rest' === $name ) {
			throw new RuntimeException( 'Use the ' . ( 'wp_rest' === $name ? 'wp' : $name ) . ' tool directly.' );
		}
		if ( 'batch' === $name ) {
			return self::batch( is_array( $a['args']['steps'] ?? null ) ? $a['args']['steps'] : array() );
		}
		$tool = self::all()[ $name ] ?? null;
		if ( null === $tool ) {
			throw new RuntimeException( 'Unknown operation "' . $name . '". `site` lists them.' );
		}
		if ( ! empty( $a['help'] ) ) {
			return array( 'tool' => $name, 'needs' => $tool['level'], 'description' => $tool['description'], 'inputSchema' => $tool['inputSchema'] );
		}

		return self::call( $name, is_array( $a['args'] ?? null ) ? $a['args'] : array() );
	}

	/**
	 * Several operations as one step: every step is checked first (known, allowed), then they run under one lock
	 * and make ONE revision; if any fails, the editor document and the AI draft are put back. Writes to posts
	 * and media are not rolled back, so keep those out of a batch you may need to undo.
	 *
	 * @param array<int, mixed> $steps [{tool, args}…]
	 * @return array<string, mixed>
	 */
	private static function batch( array $steps ): array {
		if ( array() === $steps || count( $steps ) > 25 ) {
			throw new RuntimeException( 'batch needs args.steps: 1 to 25 items of {tool, args}.' );
		}
		foreach ( $steps as $i => $step ) {
			$name = is_array( $step ) ? (string) ( $step['tool'] ?? '' ) : '';
			$tool = self::all()[ $name ] ?? null;
			if ( null === $tool || 'wp_rest' === $name ) {
				throw new RuntimeException( 'Step ' . ( $i + 1 ) . ': unknown operation "' . $name . '". Nothing was applied.' );
			}
			if ( ! Api::allows( $tool['level'] ) ) {
				throw new RuntimeException( 'Step ' . ( $i + 1 ) . ': ' . $name . ' needs a "' . $tool['level'] . '" key. Nothing was applied.' );
			}
		}

		return Store::batch(
			static function () use ( $steps ): array {
				$results = array();
				foreach ( $steps as $i => $step ) {
					try {
						$results[] = array( 'tool' => $step['tool'], 'result' => self::call( (string) $step['tool'], is_array( $step['args'] ?? null ) ? $step['args'] : array() ) );
					} catch ( \Throwable $error ) {
						throw new RuntimeException( 'Step ' . ( $i + 1 ) . ' (' . $step['tool'] . ') failed: ' . $error->getMessage() . '. Document changes were rolled back.', 0, $error );
					}
				}

				return array( 'steps' => count( $results ), 'results' => $results );
			}
		);
	}

	/**
	 * Routes of this site that match a search (name and methods only).
	 *
	 * @return array<string, mixed>
	 */
	private static function find_routes( string $search ): array {
		$found = array();
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( ! self::route_allowed( $route ) || ( '' !== $search && false === stripos( $route, $search ) ) ) {
				continue;
			}
			$methods = array();
			foreach ( $handlers as $handler ) {
				$methods = array_merge( $methods, array_keys( (array) ( $handler['methods'] ?? array() ) ) );
			}
			$found[] = $route . ' [' . implode( ',', array_unique( $methods ) ) . ']';
		}

		return array( 'routes' => array_slice( $found, 0, 60 ), 'total' => count( $found ), 'hint' => 'Narrow with search; call with route.' );
	}

	/**
	 * Routes the AI must never reach through `wp`: this plugin's own admin API (keys), the MCP endpoint and
	 * application passwords (they would outlive a revoked key). Any spelling of the path is normalised first.
	 */
	public static function route_allowed( string $route ): bool {
		$path = strtolower( '/' . trim( (string) preg_replace( '#/+#', '/', rawurldecode( $route ) ), '/' ) );

		return ! str_starts_with( $path, '/sve/' ) && ! str_contains( $path, 'application-passwords' );
	}

	/**
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	public static function call( string $name, array $args ): array {
		if ( 'site' === $name ) {
			return self::site_tool( $args );
		}
		if ( 'do' === $name ) {
			return self::do_tool( $args );
		}
		if ( 'wp' === $name ) {
			return '' === (string) ( $args['route'] ?? '' ) ? self::find_routes( (string) ( $args['search'] ?? '' ) ) : self::wp_rest( $args );
		}
		$tool = self::all()[ $name ] ?? null;
		if ( null === $tool ) {
			throw new RuntimeException( 'Unknown tool: ' . $name . '. Call tools/list.' );
		}
		if ( ! Api::allows( $tool['level'] ) ) {
			throw new RuntimeException( 'This key is "' . Api::current_level() . '"; ' . $name . ' needs "' . $tool['level'] . '". An administrator can change the key level on the AI (MCP) page of the dashboard.' );
		}
		$result = ( $tool['run'] )( self::resolve_ids( $args ) );
		if ( $result instanceof \WP_Error ) {
			throw new RuntimeException( $result->get_error_message() );
		}

		return is_array( $result ) ? $result : array( 'result' => $result );
	}

	/* ------------------------------------------------------------------ helpers */

	/**
	 * @return array<string, mixed>
	 */
	private static function doc(): array {
		return 'draft' === Api::current_level() ? Store::working_draft() : Store::get();
	}

	private static function trusted(): bool {
		return 'admin' === Api::current_level();
	}

	private static function draft_mode(): bool {
		return 'draft' === Api::current_level();
	}

	/**
	 * @return mixed
	 */
	private static function logged( mixed $result, string $action, string $summary ): mixed {
		if ( ! $result instanceof \WP_Error ) {
			Store::log( $action, $summary );
		}

		return $result;
	}

	/**
	 * Applies a change to the editor document — to the draft for draft keys, live otherwise.
	 *
	 * @param callable(array<string, mixed>): void $change Receives the document by reference.
	 * @return array<string, mixed>
	 */
	private static function write( callable $change, string $action, string $summary ): array {
		$doc = self::doc();
		$change( $doc );
		$prev      = self::draft_mode() ? self::doc() : Store::get();
		$sanitizer = new Sanitizer( self::trusted(), $prev );
		$clean     = $sanitizer->document( $doc );
		$warnings  = $sanitizer->warnings();

		if ( self::draft_mode() ) {
			$clean['rev'] = (int) Store::get()['rev'];
			Store::set_draft( $clean );
			Store::log( $action, $summary . ' (draft)' );

			return array_filter(
				array(
					'applied'  => 'draft',
					'pending'  => Store::difference( Store::get(), $clean ),
					'note'     => 'Saved as a draft. A person reviews and publishes it in the visual editor.',
					'warnings' => $warnings,
				)
			);
		}

		$stored = Store::store( $clean, '', $summary );
		Store::log( $action, $summary, array( 'rev' => $stored['rev'] ) );

		return array_filter(
			array(
				'applied'  => 'live',
				'rev'      => $stored['rev'],
				'note'     => 'Live now. The previous version is kept as a revision.',
				'warnings' => $warnings,
			)
		);
	}

	/**
	 * Where a selector change goes.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array{scope: string, page: string, tpl: string}
	 */
	private static function target( array $args ): array {
		if ( ! empty( $args['post_id'] ) && empty( $args['url'] ) ) {
			$link = get_permalink( (int) $args['post_id'] );
			if ( false === $link ) {
				throw new RuntimeException( 'No post with id ' . (int) $args['post_id'] . '.' );
			}
			$args['url'] = $link;
		}
		$scope = (string) ( $args['scope'] ?? '' );
		if ( ! in_array( $scope, array( 'page', 'template', 'site' ), true ) ) {
			$scope = ! empty( $args['template'] ) ? 'template' : ( ! empty( $args['url'] ) ? 'page' : 'site' );
		}
		$page = '/';
		$tpl  = '';
		if ( 'page' === $scope ) {
			$key = PageKey::for_url( (string) ( $args['url'] ?? '' ) );
			if ( null === $key ) {
				throw new RuntimeException( 'scope "page" needs the url (or post_id) of a page of this site.' );
			}
			$page = $key;
		} elseif ( 'template' === $scope ) {
			$tpl = Sanitizer::tpl( $args['template'] ?? '' );
			if ( '' === $tpl ) {
				throw new RuntimeException( 'scope "template" needs a template key (see list_templates).' );
			}
		}

		return array( 'scope' => $scope, 'page' => $page, 'tpl' => $tpl );
	}

	private static function selector( mixed $value ): string {
		$sel = Guard::selector( $value );
		if ( null === $sel ) {
			throw new RuntimeException( 'That selector is not allowed (no braces, semicolons, comments or "<").' );
		}

		return $sel;
	}

	/**
	 * Finds the edit for (selector, target) or starts one, then applies $fill.
	 *
	 * @param array<string, mixed>                 $doc    Document (by reference).
	 * @param array{scope: string, page: string, tpl: string} $target Target.
	 * @param callable(array<string, mixed>): void $fill   Receives the edit by reference.
	 */
	private static function upsert( array &$doc, array $target, string $sel, callable $fill, string $label = '' ): string {
		foreach ( $doc['edits'] as $index => $edit ) {
			if ( $edit['sel'] === $sel && $edit['scope'] === $target['scope'] && ( 'page' !== $target['scope'] || $edit['page'] === $target['page'] ) && ( 'template' !== $target['scope'] || $edit['tpl'] === $target['tpl'] ) ) {
				$fill( $edit );
				$doc['edits'][ $index ] = $edit;
				return (string) $edit['id'];
			}
		}
		$edit = array(
			'id'     => Sanitizer::new_id(),
			'scope'  => $target['scope'],
			'page'   => $target['page'],
			'tpl'    => $target['tpl'],
			'sel'    => $sel,
			'label'  => '' !== $label ? $label : 'AI: ' . $sel,
			'styles' => array(),
			'css'    => '',
		);
		$fill( $edit );
		$doc['edits'][] = $edit;

		return (string) $edit['id'];
	}

	/**
	 * @param array<string, mixed> $op     Op fields.
	 * @param array<string, mixed> $target Target.
	 * @return array<string, mixed>
	 */
	private static function push_op( array $op, array $target, string $summary ): array {
		$op['id'] = Sanitizer::new_id();
		$result   = self::write(
			static function ( array &$doc ) use ( $op, $target ): void {
				$doc['ops'][] = array_merge( $op, $target );
			},
			'op_' . $op['kind'],
			$summary
		);

		return array( 'id' => $op['id'] ) + $result;
	}

	/* ------------------------------------------------------------------ orientation */

	/**
	 * @return array<string, mixed>
	 */
	public static function overview(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$doc    = self::doc();
		$theme  = wp_get_theme();
		$active = array();
		foreach ( get_plugins() as $file => $plugin ) {
			if ( is_plugin_active( $file ) ) {
				$active[] = $plugin['Name'] . ' ' . $plugin['Version'];
			}
		}
		$routes = array_keys( rest_get_server()->get_namespaces() );

		return array(
			'site'        => array(
				'name'        => get_bloginfo( 'name' ),
				'description' => get_bloginfo( 'description' ),
				'url'         => home_url( '/' ),
				'language'    => get_bloginfo( 'language' ),
				'rtl'         => is_rtl(),
				'timezone'    => wp_timezone_string(),
				'front_page'  => 'page' === get_option( 'show_on_front' ) ? array( 'id' => (int) get_option( 'page_on_front' ), 'title' => get_the_title( (int) get_option( 'page_on_front' ) ) ) : 'latest posts',
				'posts_page'  => (int) get_option( 'page_for_posts' ),
				'permalinks'  => (string) get_option( 'permalink_structure' ) ?: 'plain',
				'wordpress'   => get_bloginfo( 'version' ),
				'php'         => PHP_VERSION,
				'server_side_edits' => Ssr::available(),
			),
			'theme'       => array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ), 'block_theme' => wp_is_block_theme(), 'parent' => $theme->parent() ? $theme->parent()->get( 'Name' ) : null ),
			'plugins'     => $active,
			'woocommerce' => function_exists( 'WC' ) ? array( 'currency' => get_woocommerce_currency(), 'shop' => (string) get_permalink( (int) wc_get_page_id( 'shop' ) ), 'products' => (int) ( ( (array) wp_count_posts( 'product' ) )['publish'] ?? 0 ) ) : null,
			'post_types'  => array_values( array_map( static fn ( array $t ): array => array_intersect_key( $t, array_flip( array( 'name', 'label', 'taxonomies', 'archive' ) ) ) + array( 'count' => (int) ( ( (array) wp_count_posts( $t['name'] ) )['publish'] ?? 0 ) ), Content::post_types() ) ),
			'taxonomies'  => Content::taxonomies(),
			'menus'       => array_map( static fn ( array $m ): array => array( 'id' => $m['id'], 'name' => $m['name'], 'locations' => $m['locations'], 'items' => count( $m['items'] ) ), Content::menus()['menus'] ),
			'menu_locations' => get_registered_nav_menus(),
			'theme_templates' => array_map( static fn ( array $t ): string => $t['key'], Templates::listing() ),
			'builder_templates' => Router::templates(),
			'components'  => array_map( static fn ( array $c ): string => $c['name'] . ' (' . $c['kind'] . ')', $doc['components'] ),
			'libraries'   => array_map( static fn ( array $l ): string => $l['id'] . ( empty( $l['enabled'] ) ? ' (off)' : '' ), $doc['libraries'] ),
			'design'      => array( 'tokens' => $doc['tokens'], 'breakpoints' => $doc['settings']['breakpoints'], 'tailwind' => (bool) $doc['tailwind']['enabled'] ),
			'editor'      => array( 'edits' => count( $doc['edits'] ), 'element_changes' => count( $doc['ops'] ), 'revision' => (int) Store::get()['rev'], 'pending_draft_changes' => null !== Store::draft() ? Store::difference( Store::get(), (array) Store::draft() ) : 0 ),
			'rest_namespaces' => $routes,
			'you'         => array( 'level' => Api::current_level(), 'acting_as' => wp_get_current_user()->display_name, 'roles' => wp_get_current_user()->roles, 'live_editor_open' => LiveBridge::status()['online'] ),
			'next'        => 'Read get_guide (topic "all") once, then use get_page_outline / find_elements on the page the person talks about.',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function activity(): array {
		$draft = Store::draft();

		return array(
			'recent'        => array_slice( array_reverse( Store::activity() ), 0, 40 ),
			'draft_changes' => null !== $draft ? Store::changes( Store::get(), $draft ) : array(),
		);
	}

	/* ------------------------------------------------------------------ seeing */

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_content( array $a ) {
		$data = Content::get_post( (int) $a['id'] );
		if ( is_array( $data ) && is_array( $data['builder'] ) ) {
			$data['builder']['outline'] = SourceEditor::outline( (string) $data['builder']['source'] );
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function import_site( array $a ): array {
		if ( '' !== (string) ( $a['job'] ?? '' ) ) {
			return \VisualEditor\TransferJob::load( (string) $a['job'] )->step();
		}
		$url = (string) ( $a['url'] ?? '' );
		if ( ! empty( $a['preview'] ) ) {
			return \VisualEditor\TransferJob::preview_url( $url );
		}
		$status = \VisualEditor\TransferJob::start_from_url( $url, array_map( 'strval', (array) ( $a['groups'] ?? array() ) ), $a );

		return \VisualEditor\TransferJob::load( (string) $status['job'] )->step() + array( 'summary' => $status['summary'] );
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function outline( array $a ) {
		$out = Inspector::outline( self::url( $a ), max( 20, min( 600, (int) ( $a['max_nodes'] ?? 150 ) ) ), (string) ( $a['root'] ?? '' ), ! empty( $a['as_user'] ), (string) ( $a['find'] ?? '' ) );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		// One short line per element ("n12 h2.title "Tours"") instead of an object with a long selector: roughly
		// a tenth of the tokens. The ids stand for the selectors, valid for ten minutes, in set_style & co.
		$ids   = array();
		$lines = array();
		foreach ( $out['nodes'] as $i => $node ) {
			$id         = 'n' . ( $i + 1 );
			$ids[ $id ] = $node['selector'];
			$attrs      = '';
			foreach ( array( 'id' => '#', 'role' => ' role=', 'href' => ' href=', 'src' => ' src=', 'alt' => ' alt=', 'type' => ' type=', 'placeholder' => ' ph=' ) as $name => $glue ) {
				if ( isset( $node[ $name ] ) ) {
					$attrs .= $glue . mb_substr( (string) $node[ $name ], 0, 80 );
				}
			}
			$classes = isset( $node['classes'] ) ? '.' . implode( '.', array_slice( preg_split( '/\s+/', (string) $node['classes'] ) ?: array(), 0, 3 ) ) : '';
			$lines[] = str_repeat( ' ', min( 12, (int) ( $node['depth'] ?? 0 ) ) ) . $id . ' ' . $node['tag'] . $classes . $attrs . ( isset( $node['text'] ) ? ' "' . mb_substr( (string) $node['text'], 0, 60 ) . '"' : '' ) . ( isset( $node['data-sid'] ) ? ' sid=' . $node['data-sid'] : '' );
		}
		set_transient( self::ids_key(), $ids, 10 * MINUTE_IN_SECONDS );

		return array(
			'url'       => $out['url'],
			'title'     => $out['title'],
			'outline'   => implode( "\n", $lines ),
			'truncated' => $out['truncated'],
			'note'      => $out['truncated'] ? 'Truncated: pass root (an id like n12 or a selector) to zoom in, find to filter, or a larger max_nodes.' : 'Use the ids (n12) as `selector` in other operations.',
		);
	}

	private static function ids_key(): string {
		return 'sve_ids_' . md5( (string) ( Api::current_key()['id'] ?? 'x' ) );
	}

	/**
	 * Replaces short outline ids ("n12") in selector arguments with the selectors they stand for.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private static function resolve_ids( array $args ): array {
		$map = null;
		foreach ( array( 'selector', 'root', 'target' ) as $name ) {
			if ( isset( $args[ $name ] ) && is_string( $args[ $name ] ) && 1 === preg_match( '/^n\d{1,4}$/', $args[ $name ] ) ) {
				$map ??= get_transient( self::ids_key() );
				if ( ! is_array( $map ) || ! isset( $map[ $args[ $name ] ] ) ) {
					throw new RuntimeException( 'Unknown or expired id "' . $args[ $name ] . '". Call get_page_outline again' );
				}
				$args[ $name ] = $map[ $args[ $name ] ];
			}
		}

		return $args;
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function find( array $a ) {
		return Inspector::find( self::url( $a ), $a, ! empty( $a['as_user'] ) );
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 */
	private static function url( array $a ): string {
		$url = trim( (string) ( $a['url'] ?? '' ) );
		if ( '' === $url && ! empty( $a['post_id'] ) ) {
			$url = (string) get_permalink( (int) $a['post_id'] );
		}
		if ( '' === $url ) {
			throw new RuntimeException( 'Give the url (or path) of a page of this site.' );
		}

		return str_starts_with( $url, 'http' ) ? $url : home_url( '/' . ltrim( $url, '/' ) );
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function inspect( array $a ) {
		$url  = self::url( $a );
		$info = Inspector::inspect( $url, (string) $a['selector'], ! empty( $a['as_user'] ) );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( ! isset( $a['live'] ) || ! empty( $a['live'] ) ) {
			$live = LiveBridge::run(
				'inspect',
				array(
					'url'        => $url,
					'selector'   => (string) $a['selector'],
					'device'     => (string) ( $a['device'] ?? 'desktop' ),
					'screenshot' => ! isset( $a['screenshot'] ) || ! empty( $a['screenshot'] ),
				),
				40
			);
			if ( null === $live ) {
				$info['live'] = 'No editor window is open: computed styles and screenshots are not available. Ask the person to open the visual editor for those.';
			} else {
				if ( ! empty( $live['image'] ) ) {
					$info['_image'] = (string) $live['image'];
					unset( $live['image'] );
				}
				$info['live'] = $live;
			}
		}

		return $info;
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function screenshot( array $a ): array {
		$live = LiveBridge::run(
			'screenshot',
			array(
				'url'      => self::url( $a ),
				'selector' => (string) ( $a['selector'] ?? '' ),
				'device'   => (string) ( $a['device'] ?? 'desktop' ),
				'full'     => ! empty( $a['full_page'] ),
			),
			40
		);
		if ( null === $live ) {
			throw new RuntimeException( 'Screenshots need the visual editor open in a browser (the editor takes the picture). Ask the person to open it — or use get_page_outline / inspect_element meanwhile.' );
		}
		if ( ! empty( $live['error'] ) ) {
			throw new RuntimeException( (string) $live['error'] );
		}
		$image = (string) ( $live['image'] ?? '' );
		unset( $live['image'] );

		return $live + array( '_image' => $image );
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function page_changes( array $a ): array {
		$doc  = self::doc();
		$page = ! empty( $a['url'] ) ? PageKey::for_url( (string) $a['url'] ) : null;
		$tpl  = (string) ( $a['template'] ?? '' );

		$matches = static fn ( array $item ): bool => 'site' === $item['scope']
			|| ( 'page' === $item['scope'] && null !== $page && $item['page'] === $page )
			|| ( 'template' === $item['scope'] && ( '' === $tpl || ( $item['tpl'] ?? '' ) === $tpl ) && ( null === $page || '' !== $tpl ) );

		return array(
			'page'     => $page,
			'template' => '' !== $tpl ? $tpl : null,
			'edits'    => array_values(
				array_map(
					static fn ( array $e ): array => array_diff_key( $e, array_flip( array( 'compiled', 'trusted' ) ) ),
					array_filter( $doc['edits'], $matches )
				)
			),
			'ops'      => array_values( array_filter( $doc['ops'], $matches ) ),
			'seo'      => array(
				'page'     => null !== $page ? ( $doc['seo'][ $page ] ?? null ) : null,
				'template' => '' !== $tpl ? ( $doc['seo'][ 'tpl:' . $tpl ] ?? null ) : null,
			),
			'note'     => self::draft_mode() ? 'Showing your pending draft.' : 'Showing the live document.',
		);
	}

	/**
	 * @param array<string, mixed> $c Component.
	 * @return array<string, mixed>
	 */
	public static function component_summary( array $c ): array {
		return array_filter(
			array(
				'id'       => $c['id'],
				'name'     => $c['name'],
				'kind'     => $c['kind'],
				'category' => $c['category'] ?? '',
				'props'    => $c['props'] ?? array(),
				'html'     => mb_substr( (string) $c['html'], 0, 4000 ),
				'css'      => mb_substr( (string) $c['css'], 0, 2000 ),
				'module'   => isset( $c['compiled'] ) ? mb_substr( (string) $c['compiled'], 0, 4000 ) : null,
			),
			static fn ( $v ): bool => null !== $v && '' !== $v && array() !== $v
		);
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function render_preview( array $a ): array {
		$vars = array();
		if ( ! empty( $a['post_id'] ) ) {
			$post = get_post( (int) $a['post_id'] );
			if ( $post instanceof \WP_Post ) {
				$vars['post'] = new \VisualEditor\Builder\PostNode( $post );
			}
		}
		$html = Renderer::render( SourceEditor::ids( (string) $a['source'] ), $vars, self::trusted() );

		return array( 'html' => mb_strlen( $html ) > 30000 ? mb_substr( $html, 0, 30000 ) . '…' : $html );
	}

	/* ------------------------------------------------------------------ content */

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function save_content( array $a, bool $create ) {
		if ( $create ) {
			unset( $a['id'] );
		}
		if ( isset( $a['builder']['source'] ) && is_string( $a['builder']['source'] ) ) {
			$a['builder']['source'] = SourceEditor::ids( $a['builder']['source'] );
		}
		$result = Content::save_post( $a, self::trusted(), self::draft_mode() );
		if ( is_array( $result ) ) {
			Store::log( $create ? 'create_content' : 'update_content', ( $create ? 'Created ' : 'Updated ' ) . (string) ( $a['type'] ?? get_post_type( (int) ( $a['id'] ?? 0 ) ) ) . ' "' . (string) ( $a['title'] ?? get_the_title( (int) $result['id'] ) ) . '"', array( 'post' => (int) $result['id'] ) );
			LiveBridge::run( 'reload', array( 'post' => (int) $result['id'] ), 0, false );
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function edit_layout( array $a ) {
		$id     = (int) $a['id'];
		$source = Router::source( $id );
		if ( null === $source ) {
			return new \WP_Error( 'sve_no_layout', 'That content has no builder layout. Use set_page_layout to give it one, or the selector-based tools for theme pages.' );
		}
		$base = $source['source'];
		if ( self::draft_mode() ) {
			$pending = (string) get_post_meta( $id, '_sve_source_draft', true );
			$base    = '' !== $pending ? $pending : $base;
		}
		$new = SourceEditor::apply( $base, (string) $a['sid'], (string) $a['action'], $a );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$result = self::save_content( array( 'id' => $id, 'builder' => array( 'source' => $new ) ), false );
		if ( is_array( $result ) ) {
			$result['outline'] = array_slice( SourceEditor::outline( $new ), 0, 120 );
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function save_template( array $a ) {
		$fields = array(
			'type'    => Router::POST_TYPE,
			'title'   => $a['title'] ?? null,
			'builder' => isset( $a['source'] ) || isset( $a['mode'] ) ? array_filter( array( 'source' => isset( $a['source'] ) ? SourceEditor::ids( (string) $a['source'] ) : null, 'mode' => $a['mode'] ?? null ), static fn ( $v ): bool => null !== $v ) : null,
		);
		if ( ! empty( $a['id'] ) ) {
			$fields['id'] = (int) $a['id'];
		}
		if ( isset( $a['target'] ) ) {
			$fields['template_target'] = (string) $a['target'];
		}
		if ( isset( $a['conditions'] ) ) {
			$fields['conditions'] = (array) $a['conditions'];
		}
		if ( isset( $a['replace'] ) ) {
			$fields['replace'] = (string) $a['replace'];
		}
		if ( isset( $a['priority'] ) ) {
			$fields['menu_order'] = (int) $a['priority'];
		}
		if ( isset( $a['active'] ) || empty( $a['id'] ) ) {
			$active           = ! isset( $a['active'] ) || ! empty( $a['active'] );
			$fields['status'] = $active && ! self::draft_mode() ? 'publish' : 'draft';
		}
		$fields = array_filter( $fields, static fn ( $v ): bool => null !== $v );
		$result = Content::save_post( $fields, self::trusted(), false );
		if ( is_array( $result ) ) {
			Store::log( 'save_template', 'Template "' . (string) ( $a['title'] ?? $a['id'] ) . '" → ' . (string) ( $a['target'] ?? '' ) );
		}

		return $result;
	}

	/* ------------------------------------------------------------------ styles & elements */

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function set_style( array $a ): array {
		$sel    = self::selector( $a['selector'] ?? '' );
		$target = self::target( $a );
		$device = in_array( $a['device'] ?? '', array( 'tablet', 'mobile' ), true ) ? (string) $a['device'] : 'base';
		$state  = (string) ( $a['state'] ?? 'normal' );
		$state  = in_array( $state, array( 'hover', 'focus', 'active', 'focus-visible', 'focus-within', 'before', 'after', 'placeholder' ), true ) ? $state : 'normal';

		$styles = array();
		foreach ( (array) ( $a['styles'] ?? array() ) as $prop => $value ) {
			$prop = Guard::property( $prop );
			if ( null === $prop ) {
				throw new RuntimeException( 'Unsupported CSS property name.' );
			}
			if ( '' === trim( is_scalar( $value ) ? (string) $value : '' ) ) {
				$styles[ $prop ] = '';
				continue;
			}
			$clean = Guard::value( $value );
			if ( null === $clean ) {
				throw new RuntimeException( 'The value for "' . $prop . '" is not allowed (no ; { } < > or comments; url() only https/relative/data:image).' );
			}
			$styles[ $prop ] = $clean;
		}
		if ( array() === $styles ) {
			throw new RuntimeException( 'styles is empty.' );
		}

		$id     = '';
		$result = self::write(
			static function ( array &$doc ) use ( $sel, $target, $device, $state, $styles, &$id ): void {
				$id = self::upsert(
					$doc,
					$target,
					$sel,
					static function ( array &$edit ) use ( $device, $state, $styles ): void {
						$bag = 'normal' === $state ? ( $edit['styles'][ $device ] ?? array() ) : ( $edit['states'][ $state ][ $device ] ?? array() );
						foreach ( $styles as $prop => $value ) {
							if ( '' === $value ) {
								unset( $bag[ $prop ] );
							} else {
								$bag[ $prop ] = $value;
							}
						}
						if ( 'normal' === $state ) {
							$edit['styles'][ $device ] = $bag;
						} else {
							$edit['states'][ $state ][ $device ] = $bag;
						}
					}
				);
			},
			'set_style',
			'Style ' . $sel . ' (' . $target['scope'] . ', ' . $device . ( 'normal' !== $state ? ', ' . $state : '' ) . ')'
		);
		LiveBridge::run( 'reload', array(), 0, false );

		return array( 'id' => $id ) + $result;
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function add_css( array $a ): array {
		$css = self::trusted() ? (string) ( $a['css'] ?? '' ) : Guard::css( $a['css'] ?? '' );
		if ( null === $css || '' === trim( $css ) ) {
			throw new RuntimeException( 'That CSS is not allowed (no @import, scripts or markup; at most 100,000 characters).' );
		}
		$target = self::target( $a );
		$sel    = isset( $a['selector'] ) ? self::selector( $a['selector'] ) : '';

		return self::write(
			static function ( array &$doc ) use ( $css, $target, $sel ): void {
				if ( '' === $sel && 'site' === $target['scope'] ) {
					$doc['css'] = trim( $doc['css'] . "\n" . $css );
					return;
				}
				self::upsert(
					$doc,
					$target,
					'' !== $sel ? $sel : 'html',
					static function ( array &$edit ) use ( $css ): void {
						$edit['css'] = trim( $edit['css'] . "\n" . $css );
					},
					'Custom CSS'
				);
			},
			'add_css',
			'CSS ' . ( '' !== $sel ? $sel : 'global' )
		);
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function set_content( array $a ): array {
		$sel    = self::selector( $a['selector'] ?? '' );
		$target = self::target( $a );
		$attrs  = array();
		foreach ( (array) ( $a['attrs'] ?? array() ) as $name => $value ) {
			if ( ! Guard::attribute( (string) $name ) ) {
				throw new RuntimeException( 'Attribute "' . $name . '" is not allowed (no event handlers, style or srcdoc — use set_style for styles).' );
			}
			$attrs[ strtolower( (string) $name ) ] = null === $value ? null : ( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );
		}
		$text = isset( $a['text'] ) ? (string) $a['text'] : null;
		$html = isset( $a['html'] ) ? (string) $a['html'] : null;
		if ( null === $text && null === $html && array() === $attrs ) {
			throw new RuntimeException( 'Give text, html or attrs.' );
		}
		if ( ! self::trusted() && Guard::protected_target( $sel ) ) {
			throw new RuntimeException( 'The content of scripts, styles, iframes and meta tags can only be changed with an admin key.' );
		}

		$id     = '';
		$result = self::write(
			static function ( array &$doc ) use ( $sel, $target, $text, $html, $attrs, &$id ): void {
				$id = self::upsert(
					$doc,
					$target,
					$sel,
					static function ( array &$edit ) use ( $text, $html, $attrs ): void {
						if ( null !== $html ) {
							$edit['html'] = $html;
							unset( $edit['text'] );
						} elseif ( null !== $text ) {
							$edit['text'] = $text;
							unset( $edit['html'] );
						}
						if ( array() !== $attrs ) {
							$edit['attrs'] = array_merge( (array) ( $edit['attrs'] ?? array() ), $attrs );
						}
					}
				);
			},
			'set_content',
			'Content of ' . $sel
		);
		LiveBridge::run( 'reload', array(), 0, false );

		return array( 'id' => $id ) + $result;
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function insert_html( array $a ): array {
		$position = (string) ( $a['position'] ?? '' );
		if ( ! in_array( $position, array( 'before', 'after', 'prepend', 'append' ), true ) ) {
			throw new RuntimeException( 'position must be before, after, prepend or append.' );
		}
		$op = array(
			'kind' => 'insert',
			'sel'  => self::selector( $a['selector'] ?? '' ),
			'pos'  => $position,
			'html' => (string) ( $a['html'] ?? '' ),
		);
		if ( ! empty( $a['component'] ) ) {
			$component = Renderer::find_component( (string) $a['component'] );
			if ( null === $component ) {
				throw new RuntimeException( 'No component "' . (string) $a['component'] . '" (see list_components).' );
			}
			$op['comp']  = $component['id'];
			$op['props'] = (array) ( $a['props'] ?? array() );
			$op['label'] = 'AI: ' . $component['name'];
		} else {
			if ( '' === trim( $op['html'] ) || strlen( $op['html'] ) > 100000 ) {
				throw new RuntimeException( 'html is empty or too long.' );
			}
			$op['label'] = 'AI: ' . mb_substr( wp_strip_all_tags( $op['html'] ), 0, 40 );
		}
		$result = self::push_op( $op, self::target( $a ), 'Inserted ' . $op['label'] . ' ' . $position . ' ' . $op['sel'] );
		LiveBridge::run( 'reload', array(), 0, false );

		return $result;
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function move_element( array $a ): array {
		$position = (string) ( $a['position'] ?? 'after' );
		if ( ! in_array( $position, array( 'before', 'after', 'prepend', 'append' ), true ) ) {
			throw new RuntimeException( 'position must be before, after, prepend or append.' );
		}
		$sel = self::selector( $a['selector'] ?? '' );

		return self::push_op( array( 'kind' => 'move', 'sel' => $sel, 'to' => self::selector( $a['to'] ?? '' ), 'pos' => $position, 'label' => 'AI: move ' . $sel ), self::target( $a ), 'Moved ' . $sel );
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function remove_element( array $a ): array {
		$sel = self::selector( $a['selector'] ?? '' );

		return self::push_op( array( 'kind' => 'remove', 'sel' => $sel, 'label' => 'AI: remove ' . $sel ), self::target( $a ), 'Removed ' . $sel );
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function change_tag( array $a ): array {
		$sel = self::selector( $a['selector'] ?? '' );

		return self::push_op( array( 'kind' => 'tag', 'sel' => $sel, 'tag' => strtolower( (string) ( $a['tag'] ?? '' ) ), 'label' => 'AI: <' . (string) ( $a['tag'] ?? '' ) . '> ' . $sel ), self::target( $a ), 'Changed tag of ' . $sel );
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function hide_element( array $a ): array {
		$sel    = self::selector( $a['selector'] ?? '' );
		$target = self::target( $a );
		$hide   = array_filter(
			array(
				'desktop' => ! empty( $a['desktop'] ),
				'tablet'  => ! empty( $a['tablet'] ),
				'mobile'  => ! empty( $a['mobile'] ),
			)
		);

		return self::write(
			static function ( array &$doc ) use ( $sel, $target, $hide ): void {
				self::upsert(
					$doc,
					$target,
					$sel,
					static function ( array &$edit ) use ( $hide ): void {
						if ( array() === $hide ) {
							unset( $edit['hide'] );
						} else {
							$edit['hide'] = $hide;
						}
					}
				);
			},
			'hide_element',
			( array() === $hide ? 'Show ' : 'Hide ' ) . $sel . ( array() !== $hide ? ' on ' . implode( ', ', array_keys( $hide ) ) : '' )
		);
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function set_motion( array $a ): array {
		$sel    = self::selector( $a['selector'] ?? '' );
		$target = self::target( $a );
		$none   = 'none' === ( $a['preset'] ?? '' );
		$motion = $none ? null : ( new Sanitizer( false ) )->motion( $a );
		if ( ! $none && null === $motion ) {
			throw new RuntimeException( 'Unknown preset. Use one listed in the tool description.' );
		}

		return self::write(
			static function ( array &$doc ) use ( $sel, $target, $motion ): void {
				self::upsert(
					$doc,
					$target,
					$sel,
					static function ( array &$edit ) use ( $motion ): void {
						if ( null === $motion ) {
							unset( $edit['motion'] );
						} else {
							$edit['motion'] = $motion;
						}
					}
				);
			},
			'set_motion',
			( $none ? 'Removed motion of ' : 'Motion ' . (string) $a['preset'] . ' on ' ) . $sel
		);
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function delete_change( array $a ): array {
		$id    = (string) $a['id'];
		$found = false;

		$result = self::write(
			static function ( array &$doc ) use ( $id, &$found ): void {
				foreach ( array( 'edits', 'ops', 'scripts' ) as $list ) {
					foreach ( $doc[ $list ] as $i => $item ) {
						if ( (string) $item['id'] === $id ) {
							unset( $doc[ $list ][ $i ] );
							$doc[ $list ] = array_values( $doc[ $list ] );
							$found        = true;
							return;
						}
					}
				}
			},
			'delete_change',
			'Removed change ' . $id
		);
		if ( ! $found ) {
			throw new RuntimeException( 'No change with id "' . $id . '" (see get_page_changes).' );
		}

		return $result;
	}

	/* ------------------------------------------------------------------ design system */

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function set_tokens( array $a ): array {
		return self::write(
			static function ( array &$doc ) use ( $a ): void {
				$vars = (array) ( $doc['tokens']['vars'] ?? array() );
				foreach ( (array) ( $a['vars'] ?? array() ) as $name => $value ) {
					$name = str_starts_with( (string) $name, '--' ) ? (string) $name : '--' . $name;
					if ( '' === (string) $value ) {
						unset( $vars[ $name ] );
					} else {
						$vars[ $name ] = (string) $value;
					}
				}
				$doc['tokens']['vars'] = $vars;
				if ( isset( $a['fonts'] ) && is_array( $a['fonts'] ) ) {
					$doc['tokens']['fonts'] = $a['fonts'];
				}
				if ( isset( $a['breakpoints'] ) && is_array( $a['breakpoints'] ) ) {
					$doc['settings']['breakpoints'] = array_merge( (array) $doc['settings']['breakpoints'], $a['breakpoints'] );
				}
			},
			'set_design_tokens',
			'Design tokens: ' . implode( ', ', array_keys( (array) ( $a['vars'] ?? array() ) ) ) . ( isset( $a['fonts'] ) ? ' + fonts' : '' )
		);
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function set_global_css( array $a ): array {
		$css = self::trusted() ? (string) $a['css'] : Guard::css( $a['css'] ?? '' );
		if ( null === $css ) {
			throw new RuntimeException( 'That CSS is not allowed (no @import, scripts or markup).' );
		}

		return self::write(
			static function ( array &$doc ) use ( $css, $a ): void {
				$doc['css'] = 'replace' === ( $a['mode'] ?? 'append' ) ? $css : trim( $doc['css'] . "\n" . $css );
			},
			'set_global_css',
			'Global CSS (' . ( $a['mode'] ?? 'append' ) . ')'
		);
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function save_component( array $a ): array {
		$kind = (string) ( $a['kind'] ?? 'html' );
		if ( in_array( $kind, array( 'react', 'embed' ), true ) && ! self::trusted() ) {
			throw new RuntimeException( ucfirst( $kind ) . ' components run code in visitors\' browsers: they need an admin key.' );
		}
		$compiled = '';
		if ( 'react' === $kind ) {
			if ( ! empty( $a['module'] ) ) {
				$compiled = Islands::resolve_imports( (string) $a['module'] );
			} elseif ( ! empty( $a['jsx'] ) ) {
				$out = LiveBridge::run( 'compile', array( 'jsx' => (string) $a['jsx'] ), 30 );
				if ( null === $out || empty( $out['code'] ) ) {
					throw new RuntimeException( null === $out ? 'JSX is compiled by the open editor, and no editor is open. Send "module" (plain ES module, e.g. with htm) instead.' : 'JSX error: ' . (string) ( $out['error'] ?? 'unknown' ) );
				}
				$compiled = (string) $out['code'];
			} else {
				throw new RuntimeException( 'A react component needs "module" (ES module) or "jsx".' );
			}
		}
		$name = trim( (string) $a['name'] );
		$id   = '';

		$result = self::write(
			static function ( array &$doc ) use ( $a, $kind, $compiled, $name, &$id ): void {
				$fields = array_filter(
					array(
						'name'     => $name,
						'kind'     => $kind,
						'html'     => $a['html'] ?? null,
						'css'      => $a['css'] ?? null,
						'js'       => $a['js'] ?? null,
						'props'    => $a['props'] ?? null,
						'category' => $a['category'] ?? null,
						'jsx'      => $a['jsx'] ?? ( '' !== $compiled ? (string) ( $a['module'] ?? '' ) : null ),
						'compiled' => '' !== $compiled ? $compiled : null,
					),
					static fn ( $v ): bool => null !== $v
				);
				if ( isset( $fields['html'] ) ) {
					$fields['html'] = SourceEditor::ids( (string) $fields['html'] );
				}
				foreach ( $doc['components'] as $i => $component ) {
					if ( ( ! empty( $a['id'] ) && $component['id'] === $a['id'] ) || 0 === strcasecmp( (string) $component['name'], $name ) ) {
						$doc['components'][ $i ] = array_merge( $component, $fields );
						$id                      = (string) $component['id'];
						return;
					}
				}
				$id                  = Sanitizer::new_id();
				$doc['components'][] = array_merge( array( 'id' => $id, 'html' => '', 'css' => '', 'props' => array() ), $fields );
			},
			'save_component',
			'Component "' . $name . '" (' . $kind . ')'
		);

		return array( 'id' => $id, 'use' => '<sve-component ref="' . $name . '"></sve-component>' ) + $result;
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function delete_component( array $a ): array {
		$ref = (string) $a['id'];

		return self::write(
			static function ( array &$doc ) use ( $ref ): void {
				$doc['components'] = array_values( array_filter( $doc['components'], static fn ( array $c ): bool => $c['id'] !== $ref && 0 !== strcasecmp( (string) $c['name'], $ref ) ) );
			},
			'delete_component',
			'Deleted component ' . $ref
		);
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function set_library( array $a ): array {
		$id = strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $a['id'] ) );
		if ( ! isset( Libraries::catalog()[ $id ] ) && empty( $a['url'] ) ) {
			throw new RuntimeException( 'Unknown library "' . $id . '". See list_libraries, or give url (admin keys) for a custom one.' );
		}
		if ( ! empty( $a['url'] ) && ! self::trusted() ) {
			throw new RuntimeException( 'Custom library URLs need an admin key.' );
		}
		$lib = array(
			'id'      => $id,
			'enabled' => ! isset( $a['enabled'] ) || ! empty( $a['enabled'] ),
			'scope'   => (string) ( $a['scope'] ?? 'site' ),
			'pages'   => array_values( array_filter( array_map( static fn ( $u ): ?string => PageKey::for_url( (string) $u ), (array) ( $a['pages'] ?? array() ) ) ) ),
			'tpls'    => (array) ( $a['templates'] ?? array() ),
		);
		if ( ! empty( $a['url'] ) ) {
			$lib += array( 'url' => (string) $a['url'], 'type' => (string) ( $a['type'] ?? 'script' ), 'name' => (string) ( $a['name'] ?? $id ) );
		}

		return self::write(
			static function ( array &$doc ) use ( $lib ): void {
				foreach ( $doc['libraries'] as $i => $existing ) {
					if ( $existing['id'] === $lib['id'] ) {
						$doc['libraries'][ $i ] = array_merge( $existing, $lib );
						return;
					}
				}
				$doc['libraries'][] = $lib;
			},
			'set_library',
			( $lib['enabled'] ? 'Enabled ' : 'Disabled ' ) . $id
		);
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function add_script( array $a ): array {
		$target = self::target( $a );
		$script = array(
			'id'      => ! empty( $a['id'] ) ? (string) $a['id'] : Sanitizer::new_id(),
			'label'   => (string) ( $a['label'] ?? 'AI script' ),
			'code'    => (string) $a['code'],
			'where'   => (string) ( $a['where'] ?? 'footer' ),
			'module'  => ! empty( $a['module'] ),
			'enabled' => ! isset( $a['enabled'] ) || ! empty( $a['enabled'] ),
		) + $target;

		$result = self::write(
			static function ( array &$doc ) use ( $script ): void {
				foreach ( $doc['scripts'] as $i => $existing ) {
					if ( $existing['id'] === $script['id'] ) {
						$doc['scripts'][ $i ] = $script;
						return;
					}
				}
				$doc['scripts'][] = $script;
			},
			'add_script',
			'Script "' . $script['label'] . '"'
		);

		return array( 'id' => $script['id'] ) + $result;
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function set_seo( array $a ) {
		$fields = array_intersect_key( $a, array_flip( array( 'title', 'description', 'image', 'canonical', 'noindex', 'nofollow', 'jsonld', 'ogTitle', 'ogDescription', 'keywords' ) ) );
		if ( array() === $fields ) {
			throw new RuntimeException( 'Give at least one SEO field.' );
		}
		if ( isset( $fields['jsonld'] ) && '' !== trim( (string) $fields['jsonld'] ) && ! is_array( json_decode( (string) $fields['jsonld'], true ) ) ) {
			throw new RuntimeException( 'jsonld is not valid JSON.' );
		}
		if ( ! empty( $a['post_id'] ) && empty( $a['url'] ) && empty( $a['template'] ) ) {
			return self::save_content( array( 'id' => (int) $a['post_id'], 'seo' => $fields ), false );
		}
		if ( isset( $a['template'] ) ) {
			if ( '' === Sanitizer::tpl( $a['template'] ) ) {
				throw new RuntimeException( 'template is empty. Use a template key from list_templates, or url / post_id.' );
			}
			$key = 'tpl:' . Sanitizer::tpl( $a['template'] );
		} else {
			$page = PageKey::for_url( (string) ( $a['url'] ?? '' ) );
			if ( null === $page ) {
				throw new RuntimeException( 'Give url, post_id or template.' );
			}
			$key = $page;
		}

		return self::write(
			static function ( array &$doc ) use ( $key, $fields ): void {
				$current = (array) ( $doc['seo'][ $key ] ?? array() );
				foreach ( $fields as $name => $value ) {
					if ( '' === $value || false === $value || null === $value ) {
						unset( $current[ $name ] );
					} else {
						$current[ $name ] = $value;
					}
				}
				$doc['seo'][ $key ] = $current;
			},
			'set_seo',
			'SEO ' . $key
		);
	}

	/* ------------------------------------------------------------------ history, api, collaboration */

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function restore_revision( array $a ): array {
		$doc = Store::restore( (string) $a['id'] );
		if ( null === $doc ) {
			throw new RuntimeException( 'No revision with that id (see list_revisions).' );
		}
		Store::log( 'restore_revision', 'Restored revision ' . (string) $a['id'] );

		return array( 'restored' => (string) $a['id'], 'rev' => $doc['rev'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function publish_draft(): array {
		$doc = Store::publish_draft();
		if ( null === $doc ) {
			throw new RuntimeException( 'There is no pending draft.' );
		}
		Store::log( 'publish_draft', 'Published the AI draft' );

		return array( 'published' => true, 'rev' => $doc['rev'] );
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function wp_rest( array $a ): array {
		$method = strtoupper( (string) ( $a['method'] ?? 'GET' ) );
		$route  = '/' . ltrim( (string) $a['route'], '/' );
		if ( 'GET' !== $method && ! Api::allows( 'admin' ) ) {
			throw new RuntimeException( $method . ' requests need an admin key (this key may only read).' );
		}
		if ( ! self::route_allowed( $route ) ) {
			throw new RuntimeException( 'That route is not reachable through the AI (editor admin API, MCP endpoint, application passwords).' );
		}
		$request = new \WP_REST_Request( $method, $route );
		foreach ( (array) ( $a['params'] ?? array() ) as $key => $value ) {
			$request->set_param( (string) $key, $value );
		}
		if ( isset( $a['body'] ) && is_array( $a['body'] ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $a['body'] ) );
			foreach ( $a['body'] as $key => $value ) {
				$request->set_param( (string) $key, $value );
			}
		}
		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );
		if ( 'GET' !== $method ) {
			Store::log( 'wp_rest', $method . ' ' . $route );
		}
		$json = (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return array(
			'status'  => $response->get_status(),
			'headers' => array_intersect_key( $response->get_headers(), array_flip( array( 'X-WP-Total', 'X-WP-TotalPages' ) ) ),
			'data'    => strlen( $json ) > 40000 ? array( 'truncated' => true, 'hint' => 'Too long: add _fields, per_page or a narrower route.', 'json' => substr( $json, 0, 40000 ) ) : $data,
		);
	}

	/**
	 * @param array<string, mixed> $a Arguments.
	 * @return array<string, mixed>
	 */
	public static function show_in_editor( array $a ): array {
		if ( ! LiveBridge::status()['online'] ) {
			throw new RuntimeException( 'No editor window is open.' );
		}
		LiveBridge::run(
			'highlight',
			array(
				'url'      => isset( $a['url'] ) ? self::url( $a ) : '',
				'selector' => (string) ( $a['selector'] ?? '' ),
				'message'  => mb_substr( (string) ( $a['message'] ?? '' ), 0, 300 ),
			),
			0,
			false
		);

		return array( 'shown' => true );
	}

	/**
	 * Compiler access for tests and tools.
	 *
	 * @param array<string, mixed> $edit Edit.
	 */
	public static function compile( array $edit ): string {
		return Compiler::compile( $edit );
	}
}
