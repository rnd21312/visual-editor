# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

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
