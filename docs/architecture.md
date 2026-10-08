# Architecture

Zero Composer dependencies (a PSR-4 autoloader ships in the main plugin file) and two front-end bundles built by Vite.

```
suntourz-visual-editor.php   header, constants, autoloader
src/
├── VisualEdits.php     storage (sve_document), sanitising, <head> output, per-page SEO
├── EditorScreen.php    /?sve_editor=1 window, dashboard link, admin-bar link, preview mode
├── RestController.php  sve/v1/admin/* routes
├── Templates.php       template keys per request
├── Pages.php           page picker data
├── Api.php             API key (hashed), levels, rate limits
├── Mcp.php             JSON-RPC / MCP tools
├── Guard.php           selector and CSS validation
├── Compiler.php        PHP twin of the JS edit compiler
├── Outline.php         page map for the AI
└── Assets.php          manifest → dist
frontend/src/
├── editor/             React editor app (Canvas, Dock, panels, ops, state…)
├── runtime/visual.ts   tiny public-page runtime
└── lib/                visualCss.ts, visualApply.ts (shared compile / apply logic)
```

## Two bundles

| Bundle | Loaded where | Purpose |
| --- | --- | --- |
| `visual-editor` | `/?sve_editor=1` only | The editor UI (React 19) |
| `visual-runtime` | every public page that has edits | Replays structural ops and content edits (a few KB) |

Public visitors never download the editor.

## The editor window

`EditorScreen` renders a bare page (no wp-admin) that mounts the React app. The **Canvas** loads the target URL in a same-origin iframe with the `sve` preview parameter, injects the inspector overlay and talks to it directly. Elements inside the iframe belong to another JS realm, so the code never uses `instanceof Element`.

## Save pipeline

1. The editor builds the document and `PUT`s it to `/admin/visual`.
2. `VisualEdits::sanitize()` validates selectors and CSS, strips unsafe HTML, caps sizes.
3. The previous document is archived to `sve_revisions`.
4. Next page view: `<style id="sve-css">` and `<script id="sve-data">` are printed and the runtime applies ops.

## Security

- Editor routes require a capability: `manage_options` (or `edit_pages` when an administrator allowed editors).
- CSS and selectors are validated, HTML is sanitised with `wp_kses_post` unless the user has the `unfiltered_html` capability, so scripts and embeds are limited to privileged users.
- The AI endpoint authenticates a Bearer key (stored hashed), is rate-limited, size-capped and defaults to *propose* mode.
- Nothing writes to disk; deactivation leaves the theme untouched.

## Tests

Vitest covers the compile / apply logic (`lib/visualCss.test.ts`), editor state (`editor/state.test.ts`) and the ops engine (`editor/ops.test.ts`).
