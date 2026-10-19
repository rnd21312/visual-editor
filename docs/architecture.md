# Architecture

Zero Composer dependencies (a PSR-4 autoloader ships in `visual-editor.php`), PHP 8.1+, and two Vite bundles.

```
visual-editor.php        header, constants, autoloader
src/
├── Plugin.php           boot / activate / deactivate
├── Store.php            the document (sve_document), revisions, AI drafts, three-way merge, activity, DB lock
├── Sanitizer.php        validates and shapes every document that is saved
├── Guard.php            selector / CSS / attribute validation (escape-aware)
├── Compiler.php         PHP twin of frontend/src/shared/compile.ts
├── Frontend.php         prints compiled CSS + the runtime on public pages
├── Ssr.php              server-side rendering of text/structure edits (PHP 8.4+)
├── Content.php          pages, posts, products, menus, media, terms
├── Inspector.php        server-side page outline, element lookup, computed-style helpers
├── Builder/             builder pages + templates: Parser, Renderer, Router, Query, Filters, Data, SourceEditor
├── Islands.php          React islands (esm.sh), Libraries.php (Swiper, Alpine, Lenis, Lottie…)
├── Templates.php, Seo.php, Forms.php, Cache.php, PageKey.php, Html.php, Assets.php
├── EditorScreen.php     /?sve_editor=1 window, dashboard + admin-bar links
├── AdminPage.php        "AI (MCP)" page in the dashboard
├── Api.php              API keys (hashed), levels, rate limits, OAuth bearer tokens
├── OAuth.php            OAuth 2.1 for web AI apps (DCR, PKCE S256, rotating refresh tokens)
├── LiveBridge.php       lets the AI ask the open editor for screenshots / computed styles
├── RestController.php   sve/v1/* admin routes + the MCP endpoint
└── Mcp/                 Server (JSON-RPC), Tools (~60), Guide (instructions, builder syntax)
frontend/src/
├── editor/              React 18 editor (zustand store, panels, canvas, inspector)
├── runtime/             tiny public-page runtime → dist/runtime.js (inlined)
└── shared/              compile / apply / selector / motion / source logic used by editor and runtime
```

## Bundles

| Bundle | Loaded where | Purpose |
| --- | --- | --- |
| `main` (+ lazy chunks: Tailwind, CodeMirror, icons) | `/?sve_editor=1` only | The editor UI |
| `runtime.js` | inlined on public pages that have edits | Replays structural ops, motion and islands |

Public visitors never download the editor.

## Save pipeline

1. The editor `PUT`s the document to `/sve/v1/admin/...`; `Store::save` merges it three-way when someone else saved in the meantime.
2. `Sanitizer` validates selectors and CSS (`Guard`), strips unsafe HTML unless the user has `unfiltered_html`.
3. `Store::store` takes a DB lock, archives the previous document as a revision, writes the new one and purges caches.
4. Next page view: CSS is printed in `<head>`; on PHP 8.4+ text and structure edits are rendered in the HTML.

## Security

- Editor routes need `manage_options` (or `edit_pages` when an administrator allowed editors).
- API keys and OAuth tokens are stored as hashes; wrong keys are throttled per IP; every key is rate limited.
- Non-admin AI levels run with `kses` filters on; `admin` level is as powerful as an administrator login.
- Concurrent writes are serialised with a MySQL named lock (`Store::locked`).

## Tests

`tests/guard-check.php` (Guard, Compiler) and `tests/oauth-check.php` (OAuth flow) are plain assert scripts, run in CI together with `tsc` and the Vite build.
