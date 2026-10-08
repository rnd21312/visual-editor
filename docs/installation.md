# Installation

## Requirements

| | Minimum |
| --- | --- |
| WordPress | 6.5 |
| PHP | 8.1 |
| Theme | Any — classic, block or page-builder |
| Node.js (source builds only) | 20 |

## From the release zip

1. Download `visual-editor.zip` from the [releases page](https://github.com/rnd21312/visual-editor/releases).
2. **Plugins → Add New → Upload Plugin** → select the zip → **Install Now** → **Activate**.

## From source

```bash
git clone https://github.com/rnd21312/visual-editor.git
cd visual-editor/frontend
npm ci
npm run build          # writes ../dist
```

Copy the `visual-editor` folder (with `dist/`) into `wp-content/plugins/` and activate it.

## Open the editor

- **Dashboard → Visual editor**, or
- **Edit visually** in the black admin bar while viewing any page, or
- `https://your-site.com/?sve_editor=1` (add `&sve_url=<encoded page URL>` to open a specific page).

The editor opens in its own window (no wp-admin chrome) and shows your real site.

## Who can use it

By default only administrators. In the editor's gear menu an administrator can allow **editors** too. AI access is always administrator-only.

## Compatibility notes

- **Any theme** — the editor works on the rendered page, not on theme files.
- **SEO plugins** — with Yoast, Rank Math or AIOSEO active, use that plugin for title, description and canonical and keep this editor for everything else. On themes that print no SEO tags, the editor prints the title, description, share image, canonical and JSON-LD you set.
- **Caching plugins** — clear the cache after saving so visitors see the changes.
- **Structural edits** (insert / move / re-tag) are applied by JavaScript when the page loads; text, links and SEO are also visible to crawlers that execute JavaScript.
- Text edits are tied to an element's position, so after a major layout change re-check them.

## Uninstall

Deactivating restores your theme exactly as it was. Edits stay in the `sve_document` option (plus `sve_draft`, `sve_revisions`, and the hashed API key) and return if you reactivate; delete those options for a clean slate.
