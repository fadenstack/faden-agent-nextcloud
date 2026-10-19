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
  under its exclusive lock before truncating/writing. A stream write passes the file
  cache by, so after it the storage's updater scans the file again, as Nextcloud's
  WebDAV does after a streamed upload: the file's ETag, size and time are the written
  file's for sync clients and the app's own reads. Creations reauthorize the
  parent and use native DAV `If-None-Match: *` with an expiring one-use receipt
  verifying the exact content and destination. There is no overwrite fallback.
- Each committed write is marked as made with AI (EU AI Act Art. 50(2)) through
  `@fadenstack/provenance`: the write tools declare their `output`, and the host's
  write runs inside the module's `place()`. The Fadenstack server signs a record of
  the approved text (by its hash, never the text); `NextcloudFileMarker` hands the
  signed record (the SDK's sidecar document) to the commit; `FileService` keeps it
  as Nextcloud file metadata `fadenstack-provenance`, which only the server may
  change, on the update commit or the finished creation. A Markdown file also
  carries `ai-generated: true` and `ai-record-id` in its YAML front matter, written
  by the SDK's front matter marker; PHP accepts the marked text only when it is the
  approved text with exactly that mark, else it writes the approved text. The
  commit's answer reads both back, and the markers report `verified`. `CopyListener`
  (`NodeCopiedEvent`) copies the record to copies of such files, also inside a
  copied folder. Translations and small corrections (the SDK's similarity
  classifier) are exempt and only recorded; a write goes on unmarked when the server
  cannot sign, under the server's default policy `record`. A commit Nextcloud
  refuses after the record was signed comes back in Nextcloud's words (for the model
  and the card) as a `NextcloudRequestError` named by what failed: `NextcloudLocked`
  (423) while another app holds the file, such as Text with files_lock while it is
  open, `NextcloudConflict` (409) for a file changed since the read. The module
  reports the placement as not written with that name as its `failure`, and the
  server keeps the record as not written.
- Fadenstack calls go directly from the browser to the configured origin, with
  SDK credentials and an origin guard. There is no AI proxy backend, database table,
  conversion service, or independent execution loop. CSP permits only that origin;
  administrators must configure matching CORS on Fadenstack.

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
