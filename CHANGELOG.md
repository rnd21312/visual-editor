# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.3.0]

### Added
- **Site transfer: choose what to move.** Export the whole site or any parts: design, pages (all or picked ones), templates and layouts, menus, blog posts, products (with variations, attributes, categories, brands, images), Code Snippets, other plugin / WooCommerce settings, theme files (zip). Never included: orders, users, form entries, licences and API keys.
- **Step-by-step import with a live progress bar**: theme files → product attributes → categories → images → pages / posts / products → their meta → menus → settings → snippets → editor → caches. Each step is a short request, so large sites do not hit the hosting time limit; an interrupted import continues where it stopped.
- Import from an export link (no upload size limit), or from a file.
- Importing only the look keeps the target site's own products: menu items that point to products or categories of the old site are matched by slug to the ones on this site.
- MCP: `export_site` takes `groups` and `pages`; `import_site` takes `groups`, returns a `job` and is called again with `{job}` until `done`.
- **Persian (fa_IR) translation** of every dashboard screen.

### Fixed
- Importing a package into the same site it came from is refused with a clear message.

## [1.2.0]

### Added (MCP)
- `export_site` (admin keys): exports the whole front end to a private link valid for a day, with a summary (pages, layout posts, menus, settings, media). The file never passes through the AI.
- `import_site` (admin keys): downloads such a link from another site and imports it; `preview: true` only describes it; `media`, `max_media`, `theme`, `replace` as on the Site transfer page. An AI connected to two sites can move a front end from one to the other.

## [1.1.0]

### Added
- **Site transfer** (AI (MCP) → Site transfer): download the whole front end of a site as one JSON file and import it on another site. Included: Visual Editor document (styles, elements, components, tokens, libraries, scripts, SEO of pages), builder templates, pages and layout post types (Elementor library and kit, block theme templates and parts, reusable blocks, theme layouts) with their meta, menus and locations, widgets, theme customizer settings and theme/Elementor options, the front page and site icon. Not included: products, blog posts, orders, users, form entries, snippets, theme and plugin files.
- Import copies the images into the new media library (originals, up to 400 files) and rewrites every address — also inside Elementor data — or keeps images loading from the old site. Pages with the same address are replaced or added as copies; the exported theme is switched to when installed.

## [1.0.2]

### Added (MCP)
- `get_page_source`: the raw HTML a visitor gets, in windows of up to 120k characters.
- `get_page_assets`: every stylesheet, inline style, script, font and image of a page with its origin (theme, plugin, uploads, core, external); `include_css` + `match` returns the CSS that styles a class.
- `export_page`: a page as a portable bundle (body html, all CSS with absolute `url()`, images, scripts) to rebuild on another site.
- `list_files` / `read_file` (admin keys): read-only access to themes, plugins, mu-plugins and uploads.

### Fixed (auth)
- A wrong or unexpanded pasted key now gets 403 with a clear message instead of 401. mcp-remote's key header overrode its own OAuth token, so every 401 opened a new sign-in tab.
- Loopback redirect addresses match on any port (RFC 8252), so desktop tools that reuse their client_id with a new port are not sent to an error page. A forgotten desktop client_id is registered again.
- An app this administrator already approved skips the consent click. Access tokens last a day, refresh tokens 90 days; refresh works without `client_id`; a rotated refresh token stays valid for 60 seconds.
- Sites that use only OAuth (no pasted keys) got 403 for every request.
- Expired OAuth tokens no longer count as wrong keys (IP lockout); `WWW-Authenticate` says `invalid_token`.
- `X-SVE-Key` is accepted next to an `Authorization` header; the Claude Desktop (mcp-remote) snippet uses it.

### Fixed (dashboard)
- The AI (MCP) page crashed with a fatal TypeError (`snippets()` got an array) once a key existed.
- Saving settings and creating, revoking or testing keys no longer reloads the page.

## [1.0.0]

Merges the Suntourz visual builder into this plugin.

### Added
- **Builder**: pages and templates (single product / post, archives, search, 404, header, footer) with live data, loops, menus and shortcodes; components with props and slots; React islands; Tailwind; GSAP motion presets; libraries; design tokens; forms; server-side rendering of edits on PHP 8.4+; Persian (RTL) editor interface.
- **MCP**: about 60 tools, live-editor bridge (screenshots, computed styles), several API keys with levels `read` / `draft` / `live` / `admin`.
- **OAuth 2.1** for web AI apps (Claude.ai connectors): dynamic client registration, consent page with a level choice, PKCE S256, one-hour access tokens, rotating refresh tokens, "Disconnect all apps".
- **AI (MCP)** dashboard page: keys, site description, connection test, setup snippets, pending-approval banner, activity log. Each site names its MCP server after its domain.

### Changed (MCP)
- Three tools (`site`, `do`, `wp`) instead of sixty: the tool list is about 450 tokens instead of about 9,700. Operations are listed by `site` and their schemas come on demand. Compact JSON results.
- Every result carries the site host; the instructions name the site so several connected sites are not mixed up.
- `wp` can search routes and never reaches the plugin's admin API or application passwords; answers are capped at 40 KB.
- Faster editor start: shell renders before the preview, Persian dictionary loads only for Persian users, shared chunks are preloaded.

### Fixed (found by running it in a real WordPress)
- The plugin failed to activate: the autoloader still used the old namespace prefix. `tests/boot-check.php` now guards it.
- Databases without named locks (SQLite) no longer make every write fail with "document is busy".
- `get_page_outline` crashed on every call (undefined filter variable).
- Side panels wrap instead of scrolling sideways.

### Fixed (carried over from 0.x)
- Usage stats are stored apart from the keys, so a late request can no longer bring a revoked key back; a key stops working when its user is no longer an administrator.
- `batch` runs several operations as one step: one lock, one revision, document rolled back if one fails. The lock is re-entrant.
- `get_page_outline` returns one short line per element with ids (`n12`) accepted as selectors (about a tenth of the tokens), `find` filters, bare wrappers are hidden. Page fetches are capped at 3 MB.
- A rate-limited message in a batch keeps the earlier replies; unknown tools answer -32602; an empty template key is refused by `set_seo`; a site folder such as `/shop` no longer eats the start of `/shopping/`.
- The editor asks before an AI draft replaces unsaved work.
- Writes to the document are serialised with a database lock.
- CSS escapes can no longer smuggle `@import`, `url(javascript:)` or `expression()` past the filter.
- Custom CSS using `selector > child` on a selector list now applies to every selector of the list.

### Changed
- The overlay-style editor (Dock, classes panel, context menu) is replaced by the builder editor.

## [0.1.0] — 2026-10-07

### Added
- First public release.
