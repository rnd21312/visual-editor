# AI access (MCP)

The editor can expose a **Model Context Protocol** endpoint so an AI assistant (Claude Code, or any MCP client) can find pages, read their structure and edit styles, text, new elements and SEO — under limits you control.

> Administrators only. The key is shown **once**; only a hash is stored.

## Set up

1. Open the editor and click **✦** in the top bar.
2. Enable AI access, choose a permission level and press **Create key**. Copy the key now.
3. Connect your client to:

```
POST https://your-site.com/wp-json/sve/v1/mcp
Authorization: Bearer <your key>
Content-Type: application/json
```

Claude Code example:

```bash
claude mcp add --transport http site-editor https://your-site.com/wp-json/sve/v1/mcp \
  --header "Authorization: Bearer YOUR_KEY"
```

The endpoint speaks JSON-RPC 2.0 (`initialize`, `ping`, `tools/list`, `tools/call`).

## Permission levels

| Level | What the AI can do |
| --- | --- |
| **Read only** | Inspect pages, templates, outlines and components |
| **Propose changes — I approve them** *(default)* | Changes are written to a **draft**; a banner in the editor lets you review, edit and Save (or discard) |
| **Edit live** | Changes are applied immediately; each live save keeps a revision you can restore |

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

## Typical prompt

> "On the product template, make the *Add to cart* button 20 % larger with a rounded pill shape and set the SEO title to `%title% | %site%`."

The assistant calls `list_templates` → `get_outline` → `set_style` + `set_seo`. In *Propose* mode you review the draft in the editor.

## Safety

- **Rate limits**: 120 reads / minute, 30 changes / minute, 600 changes / day. Wrong keys are blocked temporarily.
- **Validation**: selectors and CSS are checked by `Guard`; HTML is sanitised; request bodies are capped at 256 KB.
- **Revisions**: the last 15 live documents are kept; restore from the ✦ dialog or `POST /sve/v1/admin/revisions/{id}/restore`.
- **Isolation**: the AI cannot touch theme files, PHP, options or users — it can only write into the editor document.
- **Revoke** the key anytime from the ✦ dialog.
