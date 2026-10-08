# Development

## Prerequisites

- Node.js 20+, PHP 8.1+
- A WordPress site. Docker is optional — [WordPress Playground](https://wordpress.github.io/wordpress-playground/) is enough.

## Front end

```bash
cd frontend
npm ci
npm run dev          # Vite dev server (http://localhost:5175)
npm run build        # editor + runtime → ../dist
npm run typecheck
npm run lint
npm test             # Vitest
```

## Run it in WordPress

```bash
npx @wp-playground/cli@latest server \
  --mount-dir ./suntourz-visual-editor /wordpress/wp-content/plugins/suntourz-visual-editor
```

Activate the plugin, log in, open `/?sve_editor=1`. To test with a different theme, install it in the same site — the editor does not depend on one.

On Windows run the command from PowerShell/cmd (not Git Bash) and use `--mount-dir`.

## Testing tips

- Elements inside the preview iframe are another realm: do not use `instanceof Element`.
- Browser panes that never run `requestAnimationFrame` (hidden tabs) stall splash screens and transitions of some themes; query specific selectors rather than waiting for animations.
- Popup windows (detached panels) can be tested by stubbing `window.open`.
- Reset test data with a `PUT /sve/v1/admin/visual` of an empty document (see [data-model.md](data-model.md)).

## Conventions

- `declare(strict_types=1)`, typed properties, escape late.
- Keep `lib/visualCss.ts` (JS) and `Compiler.php` (PHP) in sync — they must produce the same CSS.
- Every new option or REST route: sanitise input, check capabilities, document it.

## Release

1. Bump the version in `suntourz-visual-editor.php` (`SVE_VERSION` and header) and `CHANGELOG.md`.
2. `git tag v0.1.1 && git push --tags`.
3. The **Release zip** workflow builds the bundles and attaches `suntourz-visual-editor.zip` to the release.

## Contributing

Issues and PRs are welcome. Run `npm run typecheck && npm test` first and keep PRs focused. Contributions are licensed GPL-2.0-or-later.
