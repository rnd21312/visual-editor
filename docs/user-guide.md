# User guide

## The workspace

```
┌ top bar: ☰ panels · ← Dashboard · page picker · Select/Browse · Desktop/Tablet/Mobile · undo/redo · Save ─┐
│ Navigator (left)           │ Live page (iframe)                │ Inspector (right)                    │
│ Elements · Add · Classes · │ hover = box, tag, size            │ Style · All · Content · CSS · SEO    │
│ Changes · Site             │ click = select                    │                                      │
└────────────────────────────┴───────────────────────────────────┴──────────────────────────────────────┘
```

- **Select / Browse** — *Select* inspects; *Browse* lets you click through the site normally.
- **Page picker** (top bar or **Ctrl+K**) — search every page, post and template; filter by type.
- **Panels** can be hidden (☰ / ▤), put into **focus mode** (⛶), resized by dragging the thin bar (double-click resets) and detached into their own window (⧉, allow pop-ups once). The layout is remembered.

## Editing an element

Click it on the page (or in *Elements*).

| Tab | What you can change |
| --- | --- |
| **Style** | Spacing, typography, colours, background, border, size, layout, shadow… Add any other property under *Other properties*. Switch **Normal / :hover / :focus / :active** to style states. |
| **All** | Every CSS property the browser resolved for the element, live, editable. |
| **Content** | Text (also: double-click on the page), link and image addresses, any attribute, inner HTML, hide on desktop / tablet / mobile. |
| **CSS** | Your own CSS; `selector` stands for the selected element (`selector:hover { … }`). |
| **SEO** | Page audit, Google preview, per-page SEO, and alt / title / rel / loading for the element; change the **tag** (div → section, h2 → h3). |

### Scope — where does a change apply?

- **This page** — only this URL.
- **This template** — every URL rendered by the same theme template (all products, all posts…). Default on template pages.
- **Whole site** — every page. Default for header, footer and menus.

### Selectors

The selector box shows what a rule will hit and how many elements match. Pick a shorter selector (a class or tag) to change many elements at once.

### Devices

*Tablet* and *Mobile* preview at that width; styles set while one is active apply to that size and smaller (real media queries).

## Structure

- **Move** — drag the ⠿ handle on the selected element (or its row in *Elements*); hold **Alt** to nudge freely. Buttons for up / down, duplicate, delete (**Delete**), **Ctrl+D**.
- **Add** — tags (section, heading, image, form…), your own HTML, **Code, embed & JSON** (HTML, embed snippets whose scripts run, JSON-LD), or saved **components**. Click to insert next to the selection, or drag onto the page / Elements list.
- **Group / Ungroup**, **Copy / Paste style**, **Save as component**, **Copy selector**, **Reset** — all in the right-click menu. **Ctrl+click** selects several elements.
- **Components** — select an element → *Save selected*. Edit a component once and every use follows.
- **Classes** — edit a CSS class once and every element using it follows; add your own with **+ class**.
- **Elements** shows only what is displayed at the current screen size (untick to see everything, including `<head>`).

## Templates

Product pages, blog posts and archives are one theme template shown for many URLs. They appear under *Templates* in the picker. SEO titles for templates can use `%title%` and `%site%`.

## SEO

The **SEO** tab audits title, description, H1, heading order, image alt and link text; shows a Google preview; and lets you set per-page or per-template title, description, share image, canonical, noindex / nofollow and JSON-LD.

## Saving

**Ctrl+S** publishes. Nothing is live until you save. **Ctrl+Z / Ctrl+Shift+Z** undo and redo (undoing a structural change reloads the preview). A saved document replaces the previous one, which is kept as a revision.

## Keyboard shortcuts

| Keys | Action |
| --- | --- |
| Ctrl+S | Save / publish |
| Ctrl+Z / Ctrl+Shift+Z | Undo / redo |
| Ctrl+K | Open the page picker |
| Ctrl+D | Duplicate the selected element |
| Delete | Delete the selected element |
| Ctrl+click | Multi-select |
| Alt+drag (handle) | Free nudge |
| Double-click text | Edit text in place |
| Right-click | Context menu |

The gear menu lists them too.
