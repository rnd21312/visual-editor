# AI access (MCP)

The plugin exposes a **Model Context Protocol** endpoint so an AI assistant (Claude Code, Claude.ai, Cursor, any MCP client) can see the site, edit styles and layouts, build pages and templates, and manage content — under limits you control.

> Administrators only. The key is shown **once**; only a hash is stored.

## Set up

1. In the dashboard open **AI (MCP)** (or, in the editor, open the **AI** panel).
2. Create an API key with a permission level, copy it now, describe the site to the AI, and switch AI access on. You can have several keys with different levels.
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

The AI panel fills in the server name for you. It is unique per site (`wp-` + the site's domain), so several sites can be connected to the same AI client without their tools being mixed up. Use the **Describe this site to the AI** box to say what each site is; the text is sent to the AI when it connects, and every result repeats which site it changed.

Behind a CDN or proxy, wrong-key blocking uses the proxy's address for everyone. Return the real client address with the `sve_client_ip` filter. A correct key is never blocked.

The endpoint speaks JSON-RPC 2.0 (`initialize`, `ping`, `tools/list`, `tools/call`).

## Connect a web app (OAuth)

Apps that ask for a server address instead of a pasted key, such as Claude.ai custom connectors, use OAuth 2.1:

1. Add a connector with the endpoint address from the **AI (MCP)** page.
2. The app opens a page on your site. Sign in as an administrator and press **Allow**.
3. The app gets an access token (valid one hour, renewed automatically with a rotating refresh token). It acts as you, with the permission level you pick on the consent page. **Disconnect all apps** on the page ends every connection.

Details: the app registers itself (RFC 7591), the flow is authorization code with PKCE `S256` (no secrets, no plain PKCE), redirect addresses must be https, loopback or an editor scheme, and tokens are stored only as hashes. The site publishes `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource`; if your server blocks `/.well-known/` (nginx configs that deny dot-folders do), allow it. The page's **Test the connection** button tells you.

## Permission levels

| Level | What the AI can do |
| --- | --- |
| **read** | Inspect pages, templates, outlines, components, media, settings. Changes nothing |
| **draft** *(default)* | Style/layout/SEO changes become a **draft** you review and publish in the editor; new content is created as drafts |
| **live** | Changes go live at once; every change keeps a revision (`restore_revision`). Markup is filtered (no scripts) |
| **admin** | Everything, plus scripts, plugins, settings and any WordPress/plugin REST route (`wp_rest`) as the administrator who created the key |

Admin is as powerful as an administrator login. Deleting content and changing users may not be undoable. Use it only with an assistant you trust.

## Tools

About 60 tools in the groups below; `tools/list` marks the ones the current key is not allowed to call.

| Group | Examples |
| --- | --- |
| Look | `get_site_overview`, `get_guide`, `list_content`, `get_page_outline`, `find_elements`, `inspect_element`, `screenshot`, `render_preview` |
| Content | `create_content`, `update_content`, `set_page_layout`, `edit_layout`, `list_media`, `upload_media`, `save_menu`, `save_term` |
| Templates | `list_templates`, `create_template`, `update_template` |
| Style & structure | `set_style`, `add_css`, `set_content`, `insert_html`, `move_element`, `remove_element`, `change_tag`, `hide_element`, `set_motion` |
| Design system | `set_design_tokens`, `set_global_css`, `save_component`, `set_library`, `add_script` |
| SEO & settings | `set_seo`, `get_settings`, `update_settings`, `manage_plugin` |
| History | `list_revisions`, `restore_revision`, `publish_draft`, `discard_draft`, `get_activity` |
| Escape hatch | `wp_rest` (any REST route, admin level) |

Keep the visual editor open while the AI works: it answers screenshot and computed-style requests and shows what the AI points at (`show_in_editor`).

## Safety

- **Rate limits** per key: 240 reads / minute, 60 changes / minute, 3000 changes / day. Wrong keys are blocked temporarily per IP.
- **Validation**: selectors and CSS are checked by `Guard` (CSS escapes resolved first); HTML is sanitised; request bodies are capped at 8 MB.
- **Revisions**: the last 30 live documents are kept; restore from the editor or with `restore_revision`.
- **Locking**: concurrent writes are serialised; approving a draft merges it into whatever was saved live in the meantime.
- **Revoke** a key, or **Disconnect all apps**, anytime.
