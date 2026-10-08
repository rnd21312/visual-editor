# Data model

Everything the editor does is stored in a single JSON document, validated and sanitised on save, kept in the option **`sve_document`**.

```jsonc
{
  "v": 1,
  "css": "…site-wide custom CSS…",
  "edits": [ /* style / content / css edits for a selector */ ],
  "ops": [ /* structural operations, replayed in order */ ],
  "components": [ /* reusable snippets */ ],
  "seo": { "<pageKey>": { /* title, description, image, canonical, robots, jsonld */ } },
  "access": "admin"            // or "editor"
}
```

## Edits

An edit targets a CSS **selector** and carries any of:

- per-device, per-state **styles** (`desktop` / `tablet` / `mobile` × `normal` / `hover` / `focus` / `active`),
- custom **css**,
- **text**, **html** or **attrs** changes,
- a **scope**: `page`, `template` (with a template key) or `site`.

Edits are compiled to CSS (`lib/visualCss.ts`, PHP twin `Compiler.php`) and printed inside `<style id="sve-css">` in `<head>`. Content edits are shipped as `<script id="sve-data">` and applied by the runtime.

## Ops (structure)

`insert`, `move`, `remove`, `tag` (re-tag) and `exec` (embed). They are replayed **in order** by the runtime. Moved and inserted elements get a `data-ve-id` attribute; selectors use `:nth-child(k of :not([data-ve-id]))` so positions stay stable, and `<template data-ve-ph>` placeholders keep original positions.

## Templates

Each request gets a **template key** (`front`, `single:product`, `tax:category`, …) from `Templates.php`. The preview prints `<meta name="sve-template">`, which the editor reads. SEO for a template is stored under `seo["tpl:<key>"]`.

## Components

`{ id, name, kind, html }` where `kind` is `html`, `embed` (scripts re-created) or `jsonld`. Edit once, every use follows.

## Other options

| Option | Content |
| --- | --- |
| `sve_draft` | Pending AI proposals awaiting approval |
| `sve_revisions` | Last 15 live documents |
| API settings | Enabled flag, level, **hash** of the key, hint, counters |

Limits: 500 edits per document, request bodies ≤ 256 KB.

## REST endpoints (`/wp-json/sve/v1`)

| Method | Route | Who | Purpose |
| --- | --- | --- | --- |
| GET / PUT | `/admin/visual` | editor-capable user | Read / save the document |
| GET | `/admin/pages` | editor-capable user | Page picker data |
| GET / DELETE | `/admin/draft` | editor-capable user | Read / discard the pending AI draft |
| GET | `/admin/revisions` | administrator | List revisions |
| POST | `/admin/revisions/{id}/restore` | administrator | Restore a revision |
| GET / PUT | `/admin/api` | administrator | AI access status and level |
| POST | `/admin/api/key` | administrator | Create or rotate the AI key |
| POST | `/mcp` | Bearer key | MCP endpoint ([docs](ai-mcp.md)) |

Reset everything:

```bash
curl -X PUT https://site/wp-json/sve/v1/admin/visual \
  -H 'Content-Type: application/json' -H 'X-WP-Nonce: …' \
  -d '{"v":1,"css":"","edits":[],"ops":[],"components":[],"seo":{}}'
```
