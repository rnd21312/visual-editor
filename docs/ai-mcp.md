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

| Tool | Purpose |
| --- | --- |
| `status` | Key permissions, request limits, number of changes waiting for approval |
| `list_pages` | Find pages, posts, products… (`search`, `type`, `page`) |
| `list_templates` | Theme templates (single product, single post, category archive…) with a sample URL each |
| `get_page` | Everything already changed for a URL (page, template and site-wide) |
| `get_outline` | Map of a page: every visible tag with CSS selector, classes and text (built from server-rendered HTML; `max_nodes` ≤ 600) |
| `list_components` | Saved reusable components |
| `set_style` | Set CSS properties for a selector (per device and state) |
| `add_css` | Add custom CSS for a selector |
| `set_content` | Change text, links, image URLs, attributes |
| `insert_html` | Insert sanitised HTML next to an element |
| `remove_element` | Remove an element |
| `set_seo` | Title, description, share image, canonical, robots, JSON-LD (page or template) |
| `save_component` | Save a reusable component |
| `discard_draft` | Throw away pending proposals |
| `routes` | Find REST routes by a word in the path (e.g. `product`, `menu`) |
| `wp` | Call a REST route (`method`, `path`, `params`, `fields`). Reads work at every level; changes need Full access |
| `upload_media` | Add an image or file to the media library from a public URL (Full access) |

## Typical prompt

> "On the product template, make the *Add to cart* button 20 % larger with a rounded pill shape and set the SEO title to `%title% | %site%`."

The assistant calls `list_templates` → `get_outline` → `set_style` + `set_seo`. In *Propose* mode you review the draft in the editor.

## Safety

- **Rate limits**: 120 reads / minute, 30 changes / minute, 600 changes / day. Wrong keys are blocked temporarily.
- **Validation**: selectors and CSS are checked by `Guard`; HTML is sanitised; request bodies are capped at 256 KB.
- **Revisions**: the last 15 live documents are kept; restore from the ✦ dialog or `POST /sve/v1/admin/revisions/{id}/restore`.
- **Isolation**: the AI cannot touch theme files, PHP, options or users — it can only write into the editor document.
- **Revoke** the key anytime from the ✦ dialog.
