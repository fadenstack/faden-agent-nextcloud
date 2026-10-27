# Architecture decisions

The starting repository is the official Nextcloud app template (AGPL-3.0-or-later).
The integration targets Nextcloud 31–35, PHP 8.2+, Vue 3 and Node 24. Nextcloud 35
is the primary target. Docker HTTP checks cover 31 and 35; intermediate versions
and external/encrypted storage need separate deployment verification.

The global `BeforeTemplateRenderedEvent` loads the shell on authenticated user
pages, following Nextcloud Assistant's header integration. A native `NcAppSidebar`
renders an independent chat drawer; no tab is added to the file-info sidebar.
33–35 use `@nextcloud/files` 4 public file actions/sidebar events; 31–32 use
version 3 file actions through an npm alias. Navigation entry clicks open the
drawer in place; opening that route in a new tab redirects to Files with the drawer.
Neither version offers a public selected-row getter. A small DOM adapter reads
the checked visible row's ID, with a public **Ask Fadenstack** file action as the
fallback. It does not access private Vue/Pinia stores. PHP reauthorizes all IDs.

Reference inspection: `../faden-agent-chrome/src/panel/browserAgent.ts`, context
tools, PDF handling, and `../fadenstack-sdk-typescript/packages/{client,agents,ui}`.
The browser reference uses older `FadenAgent`/`FadenClient` exports; this app uses
the published 0.2.0-rc.2 `FadenstackAgent`/`FadenstackClient` contracts. No Chrome
storage, local agent definitions or model selection are copied.

The chat is the SDK's Vue panel (`@fadenstack/ui-vue` with the `@fadenstack/ui-web`
stylesheet), not a copy of it. The app adds what is Nextcloud's through the panel's
extension points: part renderers for search result cards and for file-write approvals
(the exact proposal, registered before Allow), the context bar and Summarize button
slots, the account in the settings slot, and `beforeSend`/`afterSend` for the
Nextcloud session check and the file context a turn includes. `src/styles/nextcloud.css`
maps the panel's tokens onto the user's Nextcloud theme; the build scopes the SDK
stylesheet under the drawer (`vite.config.ts`), because Nextcloud's global button and
field styles outrank one-class rules. Esc inside the panel closes its menus or stops
an answer, and never also the drawer.

## Boundaries

- Nextcloud registers a global header button, drawer and native administration settings. PHP
  resolves nodes inside the active user's folder, checks permissions at operation
  time and owns bounded reads, immutable write proposals and revision checks.
- The frontend subscribes to the framework-neutral SDK observables without Vue
  proxying SDK objects (which contain private fields). The SDK owns authentication,
  streaming, sessions, tool governance, cancellation and approval decisions.
- Tokens, transcripts and approvals use fresh memory stores. A server-issued
  Nextcloud session binding is checked before sending requests and executing host
  operations. A changed identity ends the frontend runtime.
- Selecting a file creates an opaque session-scoped context capability and reads
  metadata only. The SDK's implicit `AttachmentViewModel` offers a chip in the
  composer that can be excluded, following the Chrome agent's lazy attachments.
  A send freezes the included capability. Known search resources can also be
  retrieved, after fresh permission checks; paths and external URLs are rejected.
  Folder listing is bounded and never reads children.
- Supported reads are UTF-8 text, Markdown, CSV, JSON and text PDFs extracted by
  Mozilla PDF.js in the browser. Byte, page, character and time limits are explicit.
- Each write is a one-use server proposal. Its target, original text, revision and
  replacement are displayed in the SDK approval card. The user's Allow click
  approves that exact proposal; the host tool then commits it. Write tools use the
  SDK's stricter destructive class to require individual approval in every mode.
  Updates open the public `File::fopen('r+')` write stream and compare storage ETags
  and the approved before-state bytes under its exclusive lock before
  truncating/writing. Creations reauthorize the
  parent and use native DAV `If-None-Match: *` with an expiring one-use receipt
  verifying the exact content and destination. There is no overwrite fallback.
- Fadenstack calls go directly from the browser to the configured origin, with
  SDK credentials and an origin guard. There is no AI proxy backend,
  conversion service, or independent execution loop. CSP permits only that origin;
  administrators must configure matching CORS on Fadenstack.
- Version 0.1.2 adds four app-owned encrypted action-journal tables and an hourly
  retention job. Existing Files writes record intent before dispatch and verified
  or uncertain outcomes afterwards. This is separate from SDK conversation history;
  typed governed Undo/Redo is not enabled yet. See [security](security.md).
- `FilesHistoryPlanner` validates an internal whole-text update Undo/Redo preview
  against the recorded action, current native scope, object/parent/path, storage
  revision and exact bytes. `FilesHistoryPlan` is immutable preview data. Preparing
  or revalidating it performs no native write, claims no approval and exposes no
  new route/tool. The internal `FilesHistoryExecutor` claims one journal attempt,
  repeats target/policy/revision/byte checks under the native write stream, applies
  only the recorded whole-text inverse/forward value and verifies native readback.
  It has no HTTP/controller/tool/UI caller. Trusted principal-pair binding,
  compatible SDK commands and current central authorization/individual approval
  must be integrated before enabling history execution.
- Guarded stream writes flush and use public `IUpdater`/`IScanner` interfaces
  while the native write lock is held. This refreshes size, parent metadata and
  the file ETag, including repeated writes within one second. Native `fopen`
  emits node hooks when opened; closing alone does not update the file cache.
  Complete native workflow/event side-effect acceptance remains a release gate:
  opening a write stream can itself emit events before a guarded byte write is
  dispatched. A rejected byte update does not establish absence of those effects.
- Internal identity primitives use a short Fadenstack agent-device token to fetch
  a bounded authenticated profile from the configured origin through Nextcloud's
  HTTP client. `JournalIdentity::forPrincipal` separates cloud tenant/user/agent
  namespaces while retaining native-user quotas; device/profile drift invalidates
  claim sessions. They do not change the active Files broker's native-only identity
  or add an endpoint. Native loopback HTTP and paired journal execution are tested
  independently of real gateway authentication. Full governed wiring and deployment
  TLS acceptance must precede exposing history. SDK sign-in remains independent of
  enterprise SSO.
- Version 0.1.3 coordinates history claims, dependencies, Redo invalidation and
  retention limits across private scopes belonging to the same native user. Files
  references also conflict when they resolve to the same native file ID. The owner
  quota row serializes these decisions; old encrypted rows and MACs stay unchanged.
  This is not a lock across different native users or a transaction around native
  storage and other installed apps.

One reusable `NextcloudSearchService` composes those existing boundaries for
discovery, metadata, authorized extraction and passages. Its PHP counterpart
validates bounded native DAV plans and optionally uses Nextcloud's public
FullTextSearch manager for the indexed Files provider. Result cards associate
host-produced typed data with the SDK's public call ID and lifecycle; neither
SDK internals nor model-produced JSON drive the cards. See [search](search.md).

## Validation

Each phase is implemented as a vertical slice. Automated SDK simulation tests are
distinct from actual Nextcloud HTTP/permission tests and from a live model pilot.
The mandatory PDF → streamed summary → approved Markdown scenario must be recorded
as unverified until it succeeds against a published Fadenstack agent and real model.
