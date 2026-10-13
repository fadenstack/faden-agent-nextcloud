# Fadenstack Agent for Nextcloud

A native global chat drawer for an organization-published Fadenstack agent. The SDK owns
authentication, streaming, sessions, governance and approvals. Nextcloud owns file
access. Supports Nextcloud 31–35, with 35 as the primary target.

**Pilot status:** the real PDF → model summary → individually approved Markdown
scenario passed against the supplied Fadenstack server on Nextcloud 35. File
operations and permission checks pass on Nextcloud 31 and 35. This is a development
pilot with documented browser/storage limits. See
[verification](docs/verification.md) and [limitations](docs/known-limitations.md).

Phase 1 (native interface and connection) is complete for the Nextcloud 35 pilot,
including a user-tested real-server conversation from the full-screen Text editor.

## Features

- Header mascot and app-menu entry open a Vue 3 right drawer without leaving Files.
- The full-screen Viewer titlebar also has a mascot toggle. Its drawer attaches
  the open saved document and keeps desktop editing space available alongside it.
- Browser-agent-style SDK chat, compact mode/history/account menus, streaming,
  stop, Enter-to-send, attachment pills and Nextcloud light/dark theme colors.
- The current single file selection appears as a removable composer attachment;
  selecting it transmits no file contents. Content is read when you send.
- UTF-8 TXT, Markdown, CSV and JSON; text PDFs through maintained Mozilla PDF.js.
- Bounded extraction with explicit partial results, revisions and source links.
- Five host tools for metadata, reading, listing, creating and updating.
- Individual write cards with full target/replacement/original; one-use server
  proposals, safe creation and ETag conflict checks on updates.
- Per-user session capabilities, server permissions, CSRF, CSP, sanitized Markdown,
  English and primary German interface strings.

## Build and install

Use Node 24 and npm 11. Published SDK 0.2.0-rc.1 dependencies are pinned in the
lockfile; sibling repositories are not needed to build.

```sh
npm ci
npm run typecheck
npm test
npm run lint
npm run stylelint
npm run build
npm run package
```

Extract `build/fadenstack_agent-0.1.0.tar.gz` into Nextcloud's `custom_apps` directory.
Keep the resulting folder name `fadenstack_agent`, then run
`php occ app:enable fadenstack_agent` as the Nextcloud web user. No runtime PHP
package dependencies or database migrations are required.

In **Administration settings → Additional settings → Fadenstack Agent**, configure
the HTTPS server origin, published agent identifier and integration toggle. Reload
Files, click the header mascot or **Fadenstack Agent** in the app menu, and sign in with your own
Fadenstack account. Instructions, model selection, grants and tool contracts stay
in Fadenstack. The SDK reports tools on the first connection; approve discovered
tools in Fadenstack and reconnect after publishing policy changes. No shared
administrator API key is used.

The server must allow the exact Nextcloud origin on `/api` and `/v1` and use a
certificate trusted by the browser. See [integration](docs/integration.md).

## Local development

```sh
docker compose up -d nextcloud35
node scripts/setup-dev.mjs nextcloud35
npm run test:integration
```

35 is at `http://localhost:8090`; the isolated local administrator is `pilot-admin`
/ `local-pilot-only`. Fixture users `pilot-alice` and `pilot-bob` use
`local-pilot-user`. These are Docker development credentials only. No real
Fadenstack credentials are shipped. Do not expose this stack to other networks.

For 31, start service `nextcloud`, run its setup script and pass
`NEXTCLOUD_URL=http://localhost:8088` to the integration test. Volumes are separate.

Read [architecture](docs/architecture.md), [development](docs/development.md),
[security](docs/security.md), [integration](docs/integration.md) and
[licenses](docs/licenses.md). App/template code is AGPL-3.0-or-later; Fadenstack SDK
packages retain their Apache-2.0 licenses.
