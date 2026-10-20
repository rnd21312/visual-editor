<?php
/**
 * What an AI assistant needs to know to build and edit this site well. Sent as MCP server instructions, as
 * resources (sve://guide/…) and through the get_guide tool for clients without resources.
 *
 * @package VisualEditor
 */

declare(strict_types=1);

namespace VisualEditor\Mcp;

use VisualEditor\Api;
use VisualEditor\Builder\Filters;
use VisualEditor\LiveBridge;

defined( 'ABSPATH' ) || exit;

final class Guide {

	/**
	 * Short instructions sent on initialize.
	 */
	public static function instructions(): string {
		$level = Api::current_level();
		$live  = LiveBridge::status()['online'];
		$what  = array(
			'read'  => 'READ-ONLY: you can look at everything but not change anything.',
			'draft' => 'DRAFT level: your changes to styles/elements/SEO are saved as a draft that a person reviews and publishes in the editor; new pages/posts are created as drafts; published content can not be changed directly (layout changes become pending drafts).',
			'live'  => 'LIVE level: your changes go live immediately (every change keeps a revision you can roll back with restore_revision). Markup is filtered (no scripts).',
			'admin' => 'ADMIN level: full control like the site administrator — scripts, plugins, any setting, any WordPress/plugin REST route (`wp` tool). Be careful and explain risky actions to the person first.',
		);

		return implode(
			"\n",
			array(
				'You are connected to the WordPress site "' . get_bloginfo( 'name' ) . '" (' . home_url( '/' ) . ') through the Visual Editor. You can see, design and build this site together with the person you talk to: pages, posts, products, templates, headers/footers, reusable components, React islands, motion, design tokens, menus, media, SEO, forms, settings and plugins.',
				'' !== Api::settings()['about'] ? 'About this site: ' . Api::settings()['about'] : '',
				'This key: ' . ( $what[ $level ] ?? $what['draft'] ),
				'Live editor: ' . ( $live ? 'an editor window is OPEN right now — inspect_element and screenshot return real computed styles and images, and show_in_editor can point things out to the person.' : 'no editor window is open — you see pages through server HTML (get_page_outline, find_elements, inspect_element). Ask the person to open the visual editor for screenshots and computed styles.' ),
				'',
				'TOOLS: only three — `site` (start here; also lists every operation you may run), `do` (run an operation: {tool, args}; {tool, help:true} gives its schema) and `wp` (any REST route). Tool names below (set_style, edit_layout…) are operations for `do`.',
				'START with `site` (add guide:"all" once for the builder syntax). Several WordPress sites may be connected to you: this one is ' . Api::site() . ' — never apply a change meant for another site here.',
				'TO CHANGE SOMETHING THE PERSON POINTS AT ("make the Book now button rounded"): find_elements(url, text) → inspect_element(url, selector) → change it (set_style / set_content / edit_layout) → verify with inspect_element or screenshot.',
				'TWO KINDS OF PAGES: (1) theme pages — change them with selector-based tools (set_style, set_content, insert_html, move_element, remove_element, hide_element, set_motion); (2) builder pages/templates (get_content shows builder.source) — edit their layout with edit_layout (by data-sid) or set_page_layout, and style elements with set_style using selector [data-sid="…"].',
				'SCOPES of style/element changes: "page" (one URL), "template" (every product / post / category page rendered by that theme template — use instead of repeating per page), "site" (everywhere: header, footer, buttons…).',
				'RESPONSIVE: set_style has device base | tablet (≤' . '1023px) | mobile (≤767px) and state normal | hover | focus | active | before | after. hide_element hides per device.',
				'REUSE: save repeated blocks as components (save_component with props) and place them with <sve-component ref="Name" props=\'{"title":"…"}\'> — changing the component updates every page (like React components).',
				'Prefer design tokens (set_design_tokens → var(--color-primary)) over hard-coded colors so the whole site stays consistent.',
				'Work in small verified steps, tell the person what you changed, and use list_revisions / restore_revision to undo.',
			)
		);
	}

	/**
	 * @return array<string, array{title: string, text: string}>
	 */
	public static function topics(): array {
		$filters = array();
		foreach ( Filters::HELP as $name => $help ) {
			$filters[] = '- ' . $name . ': ' . $help;
		}

		return array(
			'workflow'   => array(
				'title' => 'Workflow and tools',
				'text'  => <<<'TXT'
WORKFLOW
1. get_site_overview — theme, plugins, post types, menus, templates, components, tokens, your permission level.
2. Find what to change: list_content / get_content (pages, posts, products, builder templates), list_templates,
   get_page_outline(url) for the structure of any page, find_elements(url, text|role) to locate "that button".
   Deeper view: get_page_source (raw HTML), get_page_assets (every CSS/JS file and where it comes from — theme, plugin,
   uploads; include_css + match "some-class" finds the CSS that styles a class), list_files / read_file (theme, plugin and
   mu-plugin files, admin keys). To move a page to another site: export_page part "html" and part "css" here, then on the
   other site upload_media for the images, create_content with that html as builder.source and add_css (scope page).
   To move the WHOLE front end (all pages, templates, menus, theme settings, editor styles) to another connected site:
   export_site here → import_site(url, preview:true) on the other site → tell the person → import_site(url).
3. Look closely: inspect_element(url, selector) → markup, attributes, ancestors, matching CSS rules, existing
   editor changes, and (when the editor is open) computed styles, size and a screenshot. screenshot(url) for the page.
4. Change: see "editing" below. 5. Verify with inspect_element/screenshot. 6. Report to the person.

BUILDING A NEW PAGE
create_content({type:"page", title, status:"draft"|"publish", builder:{mode:"theme"|"canvas"|"blank", source:"<section …>…"}})
 - mode theme: inside the theme's header/footer. canvas: full width page with builder header/footer parts
   (create_template target "header"/"footer"). blank: nothing but your markup (landing pages).
 - Set front_page:true to make it the home page. Add it to a menu with save_menu.
BUILDING A TEMPLATE (all products, all posts, a category archive, search, 404…)
create_template({title, target:"single:product"|"single:post"|"archive:post"|"tax:product_cat"|"search"|"404"|"single:*"|"archive:*"|"header"|"footer", mode, source})
 - inside, use bindings ({{ post.title }}, {{ product.price_html }}, <sve-content></sve-content> for the post body).
 - header/footer parts: used by canvas pages; set "replace" to the theme header's selector to also replace it on theme pages.
PRODUCTS (WooCommerce): create_content({type:"product", title, status, product:{regular_price, sale_price, sku,
 stock_status, short_description, description, gallery:[urls|ids], attributes:[{name, options[], variation}],
 type:"simple"|"variable", variations:[{attributes:{Size:"L"}, regular_price}]}, terms:{product_cat:["Tours"]}, featured_image:url})
MEDIA: upload_media({url}) → attachment id/url (featured_image and gallery also accept URLs directly).
TXT
			),
			'editing'    => array(
				'title' => 'Editing existing pages and elements',
				'text'  => <<<'TXT'
SELECTOR-BASED TOOLS (work on any page, also theme-made ones — the theme files are never touched)
- set_style({url|template|scope, selector, styles:{"background":"var(--color-primary)","border-radius":"999px"}, device, state})
  Empty string removes a property. All CSS properties and custom properties are allowed.
- add_css({css, selector?}) — free CSS; inside it, "selector" or a leading "&" stands for the selector.
- set_content({selector, text | html | attrs:{href, src, alt, target, class, aria-label, data-*}})
- insert_html({selector, position:"before"|"after"|"prepend"|"append", html | component + props})
- move_element({selector, to, position}), remove_element({selector}), change_tag({selector, tag})
- hide_element({selector, desktop, tablet, mobile}) — responsive visibility.
- set_motion({selector, preset, trigger:"scroll"|"load"|"hover"|"click"|"scrub", duration, delay, stagger, ease})
- delete_change({id}) — undo one change (ids from get_page_changes).
Selectors: prefer what get_page_outline / find_elements return. Stable choices: [data-sid="…"] (builder),
#id, a unique class (.hero__title). BEM (--), Tailwind (md\:flex) and attribute selectors are fine.
Changes are scoped: scope "page" needs url; "template" needs template (key from list_templates); "site" = everywhere.

BUILDER LAYOUTS (pages/templates whose get_content shows builder.source)
- edit_layout({id, sid, action, html|text|attrs|wrapper|target}) actions: replace, remove, insert_before,
  insert_after, prepend, append, set_inner, set_text, set_attrs, wrap, duplicate, move_before, move_after, move_into.
- get_content({id}) returns builder.outline (sid, tag, class, text of every element) and builder.source.
- set_page_layout({id, source, mode}) replaces the whole layout.
- Style builder elements with set_style(selector "[data-sid=…]", scope "page" with the page url — or scope
  "site" when the element is inside a component so every instance changes).
TXT
			),
			'syntax'     => array(
				'title' => 'Builder syntax (layouts, templates, components)',
				'text'  => <<<'TXT'
Builder source is HTML with three additions. Plain HTML is printed exactly as written; every element gets a
data-sid automatically (do not invent them).

1) BINDINGS  {{ path | filter:arg }}  — escaped for where they stand (text, attribute, URL). {{{ path }}} prints markup.
   site.name site.description site.url site.logo site.logo_html site.icon site.year site.rtl
   post.title post.url post.excerpt post.content post.date post.modified post.author.name post.author.avatar
   post.image (URL) post.image.large post.image.alt post.image.html post.categories post.tags post.category.name
   post.terms.<taxonomy> post.meta.<key> post.acf.<field> post.reading_time post.comments post.type post.id
   product.price_html product.price product.regular_price product.sale_price product.on_sale product.discount
   product.sku product.in_stock product.rating product.review_count product.add_to_cart (button/form)
   product.add_to_cart_url product.gallery product.attributes product.short_description product.categories product.related
   term.name term.description term.url term.count term.image   archive.title archive.description
   user.logged_in user.name user.avatar user.account_url user.login_url user.logout_url
   menus.<location> (items: title url active children[])   cart.count cart.total cart.url shop.url
   request.search request.<query arg>   query.posts query.found (main query)   props.<name> (inside components)
2) BLOCKS
   {{#if product.on_sale}} … {{else if post.has_image}} … {{else}} … {{/if}}   (==, !=, >, <, >=, <=, &&, ||, !, contains)
   {{#unless user.logged_in}} … {{/unless}}
   {{#each post.categories as cat}} <a href="{{ cat.url }}">{{ cat.name }}</a>{{#unless loop.last}}, {{/unless}} {{else}} none {{/each}}
   loop.index loop.number loop.first loop.last loop.count inside loops.
3) BUILDER TAGS (always write open + close tag)
   <sve-loop query='{"post_type":"product","posts_per_page":8,"orderby":"popularity","product_cat":"tours"}' as="item" paginate>
      <article><a href="{{ item.url }}"><img src="{{ item.image.medium }}" alt="{{ item.title }}"></a>
      <h3>{{ item.title }}</h3>{{{ item.price_html }}}</article>
      <sve-empty><p>Nothing found.</p></sve-empty>
   </sve-loop>
     query keys: post_type posts_per_page orderby(date|title|rand|price|popularity|rating|menu_order|modified) order
     category tag product_cat product_tag taxonomy+terms ids exclude exclude_current on_sale featured in_stock
     current_term author search meta_key meta_value offset parent
     source="main" (the archive/search page's own posts), source="related", source="terms" (query: {taxonomy, number}),
     items="post.categories" (any list). <sve-pagination></sve-pagination> after a paginated loop.
   <sve-component ref="Card" props='{"title":"Phuket","image":"…"}'>slot content</sve-component>
   <sve-island ref="PriceCalculator" props='{"base":120}'>fallback</sve-island>   (React component)
   <sve-content></sve-content>  the post's own content (in templates)
   <sve-menu location="primary" class="nav" depth="2"></sve-menu>   <sve-search placeholder="Search tours"></sve-search>
   <sve-breadcrumbs></sve-breadcrumbs>   <sve-shortcode>[contact-form-7 id="12"]</sve-shortcode>
   <sve-block name="core/latest-posts" attrs='{"postsToShow":3}'></sve-block>   (any Gutenberg block of any plugin)
   <sve-if test="cart.count > 0">…</sve-if>   <sve-slot></sve-slot> (inside a component: the children)
FORMS: <form data-sve-form="contact"> … <input name="email" type="email" required> … <button>Send</button></form>
   is submitted automatically, stored (list_form_submissions) and e-mailed to the site. Add data-sve-success="Thanks!".
TXT
				. "\n\nFILTERS\n" . implode( "\n", $filters ),
			),
			'components' => array(
				'title' => 'Components, React islands and libraries',
				'text'  => <<<'TXT'
COMPONENTS (reusable, like React components but server-rendered — fast, SEO friendly)
save_component({name:"Card", kind:"html", html:"<article class=\"card\"><img src=\"{{ props.image }}\" alt=\"\"><h3>{{ props.title }}</h3><sve-slot></sve-slot></article>",
  css:".card{border-radius:var(--radius);padding:1rem} .card h3{font-size:1.25rem}",
  props:[{name:"title",type:"text",default:"Title"},{name:"image",type:"image"}]})
Use: <sve-component ref="Card" props='{"title":"Bali"}'></sve-component> in layouts, or insert_html({component:"Card", props}) on theme pages.
Changing the component later changes every place it is used. kind "section" = a full-width section preset.
Component CSS is printed once per page. js (admin keys): runs once per instance with `root` = the instance element.

REACT ISLANDS (interactive widgets; the rest of the page stays plain HTML)
save_component({name:"Counter", kind:"react", module:`
  import React, {useState} from "react";
  import htm from "htm";
  const html = htm.bind(React.createElement);
  export default function Counter({start = 0}) { const [n, setN] = useState(start);
    return html`<button class="btn" onClick=${() => setN(n + 1)}>Clicked ${n}</button>`; }`,
  html:"<button class=\"btn\">Clicked 0</button>"})          ← html = server-rendered fallback (SEO, no layout jump)
- module is an ES module with a default-exported component. Bare imports load from esm.sh with one shared React
  (any npm package works: "framer-motion", "@radix-ui/react-tabs", "lucide-react", "recharts", "clsx"…).
  Write JSX only when the editor is open (it compiles JSX: use field "jsx" instead of "module"); otherwise use htm or React.createElement.
- Place with <sve-island ref="Counter" props='{"start":5}'></sve-island> or <sve-component ref="Counter">.
- shadcn/ui style: the editor's Add panel has shadcn-like presets; or write Radix + Tailwind classes in an island.
- React islands, embed components, custom scripts and custom library URLs need an admin key.

LIBRARIES: list_libraries / set_library({id:"swiper", enabled:true, scope:"site"|"page"|"template"}).
GSAP + ScrollTrigger load automatically when an element has a motion. Each library entry tells how to use it.
TAILWIND: classes work when Tailwind is enabled in the editor's Design panel (the editor compiles the CSS).
TXT
			),
			'design'     => array(
				'title' => 'Design system, responsive, motion',
				'text'  => <<<'TXT'
DESIGN TOKENS: set_design_tokens({vars:{"--color-primary":"#0f766e","--color-accent":"#f59e0b","--radius":"14px",
  "--space":"1rem","--container":"1200px","--shadow":"0 10px 30px rgb(0 0 0/.08)"}, fonts:[{family:"Vazirmatn",
  source:"google", weights:[400,700], role:"body"}, {family:"Inter", role:"heading"}]})
  Use as var(--color-primary). Font roles create --sve-font-body / --sve-font-heading.
GLOBAL CSS: set_global_css({css, mode:"append"}).
RESPONSIVE: every style can differ per device (base / tablet ≤1023px / mobile ≤767px); hide_element per device.
  Design mobile-first friendly layouts with flex/grid + clamp() font sizes.
MOTION: set_motion presets fade, fade-up, fade-down, fade-left, fade-right, zoom-in, zoom-out, flip-up, rotate-in,
  blur-in, slide-up/down/left/right, parallax (scrub), float, pulse, spin, stagger-children (animates the children
  one after another), reveal-text (words), custom (from/to GSAP vars). Triggers: scroll (default), load, hover,
  click, scrub. Visitors who prefer reduced motion do not get animations.
ACCESSIBILITY: keep contrast ≥ 4.5:1, alt text on images, real <button>/<a>, visible focus styles (state focus-visible).
RTL: the site language decides direction (site.rtl); use logical properties (margin-inline-start, padding-inline).
TXT
			),
			'seo'        => array(
				'title' => 'SEO',
				'text'  => <<<'TXT'
set_seo({url | template | post_id, title, description, image, canonical, noindex, nofollow, jsonld, ogTitle, ogDescription, keywords})
- Template SEO may use %title% %site% %tagline% %excerpt% %sep%, e.g. title "%title% %sep% %site%".
- Works alone or through Yoast / Rank Math / AIOSEO / SEOPress (values are fed into them).
- jsonld: a JSON string (Product, TouristTrip, FAQPage, Organization, BreadcrumbList…). Components of kind "jsonld" can hold reusable schema with bindings.
- Content edits are applied on the server (PHP 8.4+), so search engines see them.
TXT
			),
			'admin'      => array(
				'title' => 'Settings, plugins and any WordPress API',
				'text'  => <<<'TXT'
get_settings / update_settings({values:{blogname, blogdescription, show_on_front, page_on_front, posts_per_page,
  permalink_structure, timezone_string, site_icon, woocommerce_currency…}}) — admin keys may change other options too
  (never siteurl/home/users/roles).
list_plugins / manage_plugin({action:"install"|"activate"|"deactivate", plugin:"slug or file"}) — admin keys.
wp_rest({method, route, params|body}) — call ANY REST route of WordPress or a plugin as the key's user
  (e.g. GET /wp/v2/types, GET /wc/v3/orders, POST /wp/v2/comments, Yoast/ACF/Contact Form 7 routes).
  GET works for every key; other methods need an admin key.
list_form_submissions({form}) — what visitors sent through builder forms.
Revisions: list_revisions / restore_revision({id}). Drafts (draft keys): get_activity shows pending changes;
  publish_draft (admin) / discard_draft.
TXT
			),
		);
	}

	public static function text( string $topic ): string {
		$topics = self::topics();
		if ( 'all' === $topic || ! isset( $topics[ $topic ] ) ) {
			return implode( "\n\n", array_map( static fn ( array $t ): string => '## ' . $t['title'] . "\n" . $t['text'], $topics ) );
		}

		return '## ' . $topics[ $topic ]['title'] . "\n" . $topics[ $topic ]['text'];
	}
}
