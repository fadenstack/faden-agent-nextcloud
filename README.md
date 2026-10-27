# Fadenstack Agent for Nextcloud

A native global chat drawer for an organization-published Fadenstack agent. The SDK owns
authentication, streaming, sessions, governance and approvals. Nextcloud owns file
access. Supports Nextcloud 31–35, with 35 as the primary target.

**v0.1 search implementation:** native filename/folder/recent discovery, selected
document passages, source-linked result cards and capability-aware indexed search.
The real latest-contract → termination passage → approved Markdown workflow passed
against the supplied Fadenstack server on Nextcloud 35. File operations and permission
checks pass on Nextcloud 31 and 35. Browser search acceptance and an installed native
index remain separately documented; this is not an App Store publication. See
[verification](docs/verification.md) and [limitations](docs/known-limitations.md).

Phase 1 (native interface and connection) is complete for the Nextcloud 35 pilot,
including a user-tested real-server conversation from the full-screen Text editor.

## Features

- Header mascot and app-menu entry open a Vue 3 right drawer without leaving Files.
- The full-screen Viewer titlebar also has a mascot toggle. Its drawer attaches
  the open saved document and keeps desktop editing space available alongside it.
- The Fadenstack SDK's own Vue chat panel (`@fadenstack/ui-vue`, the look every
  Fadenstack client shares) in the user's Nextcloud theme: tool modes, history,
  streaming, stop, Enter-to-send, attachment chips, and a settings menu with the
  language and the account.
- One SDK toolbar with Nextcloud typography and the shared Fadenstack avatar.
  **Assistant details** opens a native dialog with the configured connection,
  published identifier and current file metadata. Opening it reads no content.
- The context row distinguishes **Next message**, **This response** and
  **Not included**. A running response keeps its original source while selection
  changes prepare the next message.
- The current single file selection appears as a removable composer attachment;
  selecting it transmits no file contents. Content is read when you send.
- UTF-8 TXT, Markdown, CSV and JSON; text PDFs through maintained Mozilla PDF.js.
- Bounded extraction with explicit partial results, revisions and source links.
- Nine host tools reuse metadata, reading, listing, creating and updating, adding
  filename/metadata discovery, document passages and optional native indexed search.
- Readable result cards with parent folder, modified date, excerpts, Open in
  Nextcloud and Add to context. No separate RAG or indexing service is required.
- Individual write cards with full target/replacement/original; one-use server
  proposals, safe creation and ETag conflict checks on updates.
- A working indicator with the same SDK Stop control stays visible when chat is
  hidden. Verified saves update supported clean Files lists through native events;
  active editors retain their view and require an explicit handoff.
- Native source/destination policy and per-turn budgets restrict the published
  Files tools. Encrypted write-ahead receipts record existing creates/updates.
  Optional **Agent actions** provides governed Undo/Redo for eligible private text
  updates. It requires the companion command SDK and gateway contract; see
  [administrator setup](docs/admin-guide.md). Additional app tools remain planned.
- Per-user session capabilities, server permissions, CSRF, CSP, sanitized Markdown,
  the chat in English, German, French, Spanish and Simplified Chinese (the user
  picks it in the settings; Automatic is their Nextcloud language), the app's own
  screens in English and German.

## Build and install

Use Node 24 and npm 11. Published SDK 0.2.0-rc.2 dependencies are pinned in the
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

Extract `build/fadenstack_agent-0.1.4.tar.gz` into Nextcloud's `custom_apps` directory.
Keep the resulting folder name `fadenstack_agent`, then run
`php occ app:enable fadenstack_agent` as the Nextcloud web user. For upgrades, use
Nextcloud's normal `occ upgrade` lifecycle. Version 0.1.2 creates four app-owned
journal tables and registers an hourly retention job. Version 0.1.3 adds an
owner-wide history generation counter without rewriting encrypted entries.
Normal Nextcloud background jobs must run. No runtime PHP package dependencies
are required.

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
node --experimental-transform-types tests/integration/search.mjs
node --experimental-transform-types tests/integration/search-security.mjs
```

35 is at `http://localhost:8090`; the isolated local administrator is `pilot-admin`
/ `local-pilot-only`. Fixture users `pilot-alice` and `pilot-bob` use
`local-pilot-user`. These are Docker development credentials only. No real
Fadenstack credentials are shipped. Do not expose this stack to other networks.

For 31, start service `nextcloud`, run its setup script and pass
`NEXTCLOUD_URL=http://localhost:8088` to the integration test. Volumes are separate.

Read [architecture](docs/architecture.md), [development](docs/development.md),
[security](docs/security.md), [integration](docs/integration.md) and
[search tool contracts](docs/search.md), [user guide](docs/user-guide.md),
[administrator guide](docs/admin-guide.md) and [licenses](docs/licenses.md).
App/template code is AGPL-3.0-or-later; Fadenstack SDK
packages retain their Apache-2.0 licenses.
