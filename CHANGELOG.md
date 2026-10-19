# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **AI (MCP)** page in the dashboard: key, permission level, site description, connection test, setup snippets (Claude Code, Claude Desktop, Cursor, VS Code, curl) and a log of AI changes.
- **Full access** level: the `wp` tool calls any WordPress REST route as the administrator who created the key; `upload_media` adds files by URL.
- Each site names its MCP server after its domain and tells the AI what the site is, so several sites can be connected at once.
- `edit` applies several changes in one call, all or nothing, with one revision.
- **OAuth 2.1** for web AI apps such as Claude.ai connectors: dynamic client registration, consent page, authorization code with PKCE S256, one-hour access tokens and rotating refresh tokens, "Disconnect all apps".

### Changed
- Six tools instead of seventeen: the tool list went from about 2,250 to about 770 tokens, and a page outline is about 90 % smaller (short ids instead of long selectors).

### Fixed
- Approving an AI draft no longer reverts changes saved live in the meantime (three-way merge).
- Concurrent writes can no longer overwrite each other; the Outline fetch refuses private addresses; revoking a key can no longer be undone by a late request.
- Embeds added by an administrator survive saves by editors and MCP writes.
- CSS escapes can no longer smuggle `@import` or `url(javascript:)` past the filter; `.btn--primary` selectors work; `:hover` applies to every selector of a list.

## [0.1.0] — 2026-10-07

### Added
- First public release.
