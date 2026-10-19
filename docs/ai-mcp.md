# AI access (MCP)

The editor can expose a **Model Context Protocol** endpoint so an AI assistant (Claude Code, or any MCP client) can find pages, read their structure and edit styles, text, new elements and SEO — under limits you control.

> Administrators only. The key is shown **once**; only a hash is stored.

## Set up

1. In the dashboard open **AI (MCP)** (or, in the editor, click **✦** in the top bar).
2. Press **Create API key** and copy it now. Choose a permission level, describe the site to the AI, and switch AI access on.
   The page also has a **Test the connection** button (it shows whether your server passes the `Authorization` header on), setup snippets for Claude Code, Claude Desktop, Cursor, VS Code and curl, the changes waiting for your approval, and a log of what the AI changed.
3. Connect your client to:

```
POST https://your-site.com/wp-json/sve/v1/mcp
Authorization: Bearer <your key>
Content-Type: application/json
```

Claude Code example:

```bash
claude mcp add --transport http wp-your-site-com https://your-site.com/wp-json/sve/v1/mcp \
  --header "Authorization: Bearer YOUR_KEY"
```

The ✦ dialog fills in the server name for you. It is unique per site (`wp-` + the site's domain), so several sites can be connected to the same AI client without their tools being mixed up. Use the **Describe this site to the AI** box to say what each site is; the text is sent to the AI when it connects, and every result repeats which site it changed.

Behind a CDN or proxy, wrong-key blocking uses the proxy's address for everyone. Return the real client address with the `sve_client_ip` filter. A correct key is never blocked.

The endpoint speaks JSON-RPC 2.0 (`initialize`, `ping`, `tools/list`, `tools/call`).

## Permission levels

| Level | What the AI can do |
| --- | --- |
| **Read only** | Inspect pages, templates, outlines and components |
| **Propose changes — I approve them** *(default)* | Changes are written to a **draft**; a banner in the editor lets you review, edit and Save (or discard) |
| **Edit live** | Editor changes (styles, text, elements, SEO) are applied immediately; each live save keeps a revision you can restore |
| **Full access** | Everything above, plus the whole WordPress REST API **as the administrator who created the key**: posts and pages, media, menus, settings, users, plugins, themes, WooCommerce and any plugin that has REST routes |

Full access is as powerful as an administrator login. Deleting content and changing users may not be undoable, and the AI reads your site's content (comments, reviews), which can contain text written to trick it. Use it only with an assistant you trust. Even then the key can never reach this plugin's own admin API (so it cannot create or change keys) or application passwords (they would outlive the key), and it never runs PHP or edits theme and plugin files. Every change is listed in `GET /sve/v1/admin/log`.

## Tools

Six tools, kept short on purpose: the tool list is sent to the AI at the start of every session (about 770 tokens), and results are compact.

| Tool | Purpose |
| --- | --- |
| `site` | Overview: name, theme, plugins, post types, theme templates (with a sample path each), components, changes waiting for approval. With `url` or `template`, also what was already changed there |
| `pages` | Find pages, posts, products… (`search`, `type`, `page`). One line each: id, type, title, path |
| `outline` | Compact map of a page. One line per element with a short id (`n12 h2.title "Tours"`); `find` keeps only matching lines, `max_nodes` ≤ 600 (default 120) |
| `edit` | One or more changes in a single call, applied together or not at all (one revision): `style`, `css`, `content`, `insert`, `remove`, `seo`, `component`, `discard`. Scope `page`, `template` or `site` |
| `wp` | WordPress REST API as the key's administrator. Reads work at every level; changes need Full access. Without `path`, `search` finds routes |
| `upload_media` | Add a file to the media library from a public URL (Full access) |

The short ids from `outline` stand for the long CSS selectors, which stay on the server (valid 10 minutes, for the last outline). `edit` takes an id or a CSS selector wherever it needs a selector.

## Typical prompt

> "On the product template, make the *Add to cart* button 20 % larger with a rounded pill shape and set the SEO title to `%title% | %site%`."

The assistant calls `site` → `outline` (with `find: "cart"`) → one `edit` with a `style` and a `seo` change. In *Propose* mode you review the draft in the editor.

## Safety

- **Rate limits**: 120 reads / minute, 30 changes / minute, 600 changes / day. Wrong keys are blocked temporarily.
- **Validation**: selectors and CSS are checked by `Guard`; HTML is sanitised; request bodies are capped at 256 KB.
- **Revisions**: the last 15 live documents are kept; restore from the ✦ dialog or `POST /sve/v1/admin/revisions/{id}/restore`.
- **Isolation**: the AI cannot touch theme files, PHP, options or users — it can only write into the editor document.
- **Revoke** the key anytime from the ✦ dialog.
