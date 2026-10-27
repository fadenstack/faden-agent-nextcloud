# Pilot verification — 13 October 2026

## 27 October: opt-in governed Files history (0.1.4)

The current phase adds paired native Files writes, fresh authenticated profiles,
prepare/dispatch central policy and one-use exact approval to typed Undo/Redo.
The published-SDK build passes 125 UI tests; the explicit companion-SDK build
passes 129 (remaining cases are configuration-specific skips). Typecheck,
lint/style, build/package and 75 PHP tests/143 assertions pass. Native HTTP
passes 27 authentication/CSRF/policy/replay/conflict checks on the isolated local
Nextcloud35.0.2/PHP8.5.12/SQLite clone. Session/upgrade/protection and existing
storage regression drivers remain under `tests/integration/`.

Public recovery only certifies new private standard-file updates made under a
standard-only native input policy. Native checks include protected snapshots that
cannot be retroactively certified and widening a private audience even when the
general movement policy allows sharing. The gateway producer/authentication
suite passes 64 tests independently. The native/browser issuer is synthetic;
real deployment TLS/CA, production database, storage and event effects remain open.

The normal package still uses published SDK 0.2.0-rc.2. Explicit command controls
are verified with a separate public-export SDK0.3.0-rc.1 test overlay, never a
release package. History defaults off. Earlier sections describe prior checkpoints.

## 27 October: private scopes and shared native history

The 0.1.2 → 0.1.3 normal app upgrade passes on a local isolated Nextcloud
35.0.2/PHP8.5.12/SQLite clone. Old journal rows, ciphertext and MACs remain byte
identical. Native HTTP verification plus paired typed Undo/Redo, scope revocation,
unknown effects, cross-cloud dependencies, Redo invalidation and shared resource
limits pass 37 checks. Five barrier-released two-process file-reference races
each produce one durable claim and one resource conflict. The driver is
`tests/integration/history-principal.php`; the issuer is a synthetic loopback
fixture, with real gateway authentication tested separately. These checks do not
enable public history or establish deployment TLS/production-database acceptance.

## 27 October: internal Files history execution

The new native executor is tested on the isolated Nextcloud 35.0.1/SQLite clone.
The actual Files broker's journal is reversed and reapplied with exact-byte and
native ID checks. Human edits, stale/competing claims, changed paths, substituted
plans, native scope, audience and user changes refuse execution. Empty originals
and outputs retain correct native size/ETag metadata. Independent DAV HTTP
readback checks both metadata and saved bytes.

A real quota-limited write fails after truncation and retains an unresolved action,
snapshots and resource claim. A separate PHP process holding a native read stream
blocks the write lock; the uncommitted claim is released and a fresh plan succeeds
after that lock is released. Stateful suites run sequentially. Test drivers are
under `tests/integration/history-execute*.php` and require the disposable-fixture
environment marker; the external runner also verifies container labels/mounts.

This does not verify a cloud-governed history button or enable an execution route.
The published SDK dependency remains 0.2.0-rc.2. Production databases, encrypted and
external storage, other supported Nextcloud versions, native workflow side effects
and the complete history release matrix remain unaccepted.

## The SDK's Vue panel (SDK 0.2.0-rc.2)

The hand-made chat was replaced by `@fadenstack/ui-vue` with `@fadenstack/ui-web`, from npm.

| Check | Result |
| --- | --- |
| Typecheck, eslint, stylelint, build, package | clean; the package carries the ui-vue and ui-web LICENSE and NOTICE |
| Unit tests (Vitest, the SDK's fake server) | 33 passed, including composer send, write approval through the card, Esc and the account in the settings |
| `tests/integration/nextcloud.mjs` | 40 HTTP checks on 35 (:8090) and on 31 (:8088) |
| `search.mjs` (Node 24) | 73 checks on 35 and on 31 |
| `search-security.mjs` (Node 24) | 56 API checks on 35, 55 on 31 |
| Browser, Nextcloud 35, dark | sign-in to the real server, the panel and its settings (five languages, account); "find the latest supplier contract and its notice period": search cards, two tool approvals, the October contract's 30 days |
| Browser, Nextcloud 31, light | sign-in, panel and settings |
| Browser, Nextcloud 35, a German user | the panel in German; a question answered after three tool approvals |

The real server's agent lists the app's tools without classes until an administrator
accepts them on the agent's Apps tab; until then reading tools ask too.

## v0.1 Search and Document Discovery release checks

The search implementation is committed as independent functional increments:
native discovery, document/capability search, chat result cards, then bounded
pagination and governed retrieval. The original interface Phase 1 below refers
to the earlier native-drawer specification, not this search specification.

| Check | Nextcloud 35.0.1 | Nextcloud 31.0.14 |
| --- | --- | --- |
| Native filename/folder/type/date/size discovery and document passages | 73 authenticated checks passed | 73 passed |
| Sharing, revocation, signed cursors and nested/racing creation | 56 passed | 55 passed; creation-date filter truthfully unavailable |
| Existing file workflow regression | 40 passed | 40 passed |
| Latest-contract query measured locally | 405 ms | 365 ms |
| First real PDF passage / cached follow-up | 1,714 / 549 ms | 1,883 / 447 ms |

These are local Docker/SQLite measurements, not promises for arbitrary deployments.
The PDF checks parsed once across repeated searches and compared emitted page
locations to actual PDF.js records. The native indexed Files capability is absent
on both instances and returns an explicit unavailable response. Positive provider
behavior and withholding revoked excerpts have PHP contract coverage; no configured
live indexing platform was available to verify.

32 frontend/SDK/Vue tests and 17 PHP tests / 29 assertions passed. They cover
actual SDK host orchestration, typed result cards, lazy Add to context, included
folder creation, exact individual approval, cache authorization/revision changes
and byte bounds. Type checking, lint, styles, production build, strict Composer
validation and zero production npm vulnerabilities passed. CI now runs the native
search and security suites on both version images; remote CI has not run yet.

The supplied real Fadenstack `nextcloud-agent` v1 completed three turns using the
production SDK/host and real authenticated Nextcloud 35 APIs. It selected the
October synthetic contract (file 401), retrieved its termination passage rather
than inferring it from the name, and answered **30 days' written notice** with the
source link. An exact Markdown proposal in deliberately included folder 400 was
approved once before native creation. Actual file **455** was read back with the
30-day passage and source link. Turn durations were 40.0, 37.9 and 110.9 seconds;
model generation and approval timing are distinct from native search duration.
Artifacts are in ignored `build/live-search`; fixtures are explicitly synthetic.
All 22 real-server TLS/CORS/CSRF checks passed with certificate validation enabled.

After adding the creation-client upgrade guard, the complete real workflow passed
again and saved file **486**. Its three turns took 77.9, 41.7 and 98.4 seconds.
Old clients now receive a reload error before any creation; the updated adapter
handles the native creation plan. SDK labels and result dates follow the Nextcloud
account language independently of the browser language, with German unit coverage.

The packaged app passed 40 workflow, 73 discovery/document and 55 security checks
in an isolated Nextcloud 35 runtime on port 8092 with no `vendor` or `node_modules`.
The final upgrade-guard/locale package then passed 40 workflow, 73 discovery and
56 security checks on port 8093, again without development dependencies. The
temporary package containers are stopped and their test volume retained.

The mandatory live browser search workflow is now complete on Nextcloud 35,
using the supplied HTTPS Fadenstack server and published real model. Browser input
worked after the user resumed the session. Native filename discovery displayed
typed cards for contracts 401 and 402, ordered by modification date, with parent
folder, date, Open in Nextcloud and Add to context actions. The two bounded native
queries took 791 and 720 ms. Adding contract 401 through its card produced an
included composer attachment. `nc_search_in_file` verified one exact occurrence
of **30 days' written notice** at actual text line 12 in 484 ms; its passage card
and final answer showed the supporting text and source link.

The next request displayed the exact destination and full Markdown in the
individual approval card. **Allow once** created file **488**:
`/Fadenstack Search Pilot 2026-10-13/Discovery Browser Summary 2026-10-13.md`.
Independent authenticated WebDAV read-back matched all **985 approved UTF-8 bytes**
exactly and confirmed the source link to contract 401. The returned file link
opened the saved summary in Nextcloud Text. Screenshots of the result cards,
verified answer, approval, completed write and saved document are in the gallery.
The three browser turns took 333.3, 92.9 and 241.5 seconds, including model
generation and manual test approval timing; these are not native API latencies.

The first request specified "current folder" without including a folder chip.
The host correctly rejected selection-only listing/read tools; the agent explained
the missing scope and explicitly searched accessible filenames instead. Include a
folder for strict folder-scoped requests, or use a discovered folder resource ID.
The included contract's parent matched the visible current folder for creation.
One narrative link for the older contract used the wrong localhost port; the
trusted result-card links, latest-contract answer and saved source link were
correct. Model-written prose remains reviewable; canonical cards are the reliable
opening actions. Connection verification failures show their reason instead of
silently clearing the UI. Original PDF/editor acceptance remains recorded below.

**v0.1 Search and Document Discovery: mandatory functional increments and the
real-server browser demonstration complete.** Optional indexed content search
degrades truthfully on this deployment; a live positive full-text provider and a
broader browser/storage matrix remain unverified.

This is an installable development pilot. The mandatory real-server PDF → model
summary → individually approved Markdown scenario passed in the Nextcloud 35
browser drawer. Remaining browser/storage coverage is documented separately.

**Phase 1 (Native Agent Interface and Connection): complete for the Nextcloud 35
pilot.** The user completed the additional full-screen editor Send test; its
finished conversation was independently inspected in the existing browser tab.

## Tested environments

| Component | Actual version/configuration |
| --- | --- |
| Primary Nextcloud | 35.0.1, official 35-apache image, PHP 8.5.11, SQLite |
| Compatibility Nextcloud | 31.0.14, official 31-apache image, PHP 8.3.30, SQLite |
| Browser | Chrome 154 on Windows |
| Fadenstack SDK | client/agents/ui/testing 0.2.0-rc.1 |
| Frontend | Vue 3.5.42, Nextcloud Vue 9.13.x, Vite 7.3.6, Node 24.21.0 |
| Supplied Fadenstack pilot | https://10.250.10.61, nextcloud-agent v1, qwen3.8-27b-fp8 |

The remote Fadenstack deployment was independently inspected over verified SSH:
0.4.4rc2 before its hardware interruption, 0.4.4 after recovery, nginx 1.31.6.
The published agent was inspected in its console; no manual tool schema was
entered. The SDK reports all five tools automatically.

## Automated checks

- TypeScript, ESLint, Stylelint and clean production Vite build passed.
- 21 Vitest tests passed using actual SDK classes and FakeGateway: lazy context,
  excluded chips, frozen turn context, tool manifest discovery, published policy,
  write approval/denial, changed identities, revocation, memory isolation and input
  validation.
- The Vue composer test mounts actual components, relocates the live Vue root
  into a Viewer modal, and confirms Send/Enter,
  Shift+Enter, a waiting write card, and the individual Allow click reaching the
  production host and real SDK. The licensed SDK CSS/layout is adapted to Vue;
  Nextcloud theme tokens replace browser colors, without a React dependency.
- Viewer bridge tests cover visible document IDs, legacy URL IDs, unknown IDs,
  one titlebar toggle, the public focus-trap event and drawer relocation on close.
- PHPUnit: 10 tests, 12 assertions passed on PHP 8.5.11.
- 38 real HTTP checks passed separately on Nextcloud 35 and 31: direct navigation,
  actual login and
  CSRF middleware, selection/read/list, per-user capabilities, approved creation,
  one-use proposals, updates, unsafe names, no-overwrite behavior, denied writes,
  real concurrent WebDAV ETag changes, bounded extraction, read-only shares and
  second-user isolation.
- Composer strict validation and PHP coding-style dry run passed.
- Production npm audit: zero vulnerabilities. Developer-tool transitive advisories
  remain documented separately; no forced incompatible tooling downgrade.
- A fresh isolated Nextcloud 35 Docker volume initialized automatically and enabled
  the app successfully. The temporary container is stopped; its test volume is
  retained. The repeatable CI workflow is added but has not run remotely.
- The packaged app also passed all 38 HTTP checks in an isolated Nextcloud 35
  container with no `vendor` or `node_modules` directory. That container is stopped.

## Browser checks

On Nextcloud 35, the header mascot and app-menu entry open an independent native
right drawer without changing the Files URL. No assistant tab is added to file
details. The selected PDF checkbox produces a composer chip and source link.
The SDK reads file bytes only when the included chip is sent. The composer stays
visible while the conversation scrolls; background identity checks retain controls.

A loopback-only browser simulation exercised the **real SDK and real Nextcloud**:
sign-in, PDF.js extraction of “Reasons to use Nextcloud.pdf”, streamed response,
full target/content approval card, approved Markdown creation, and opening the
returned file URL in Nextcloud Text. The resulting file is
`browser-smoke-1791844141763.md` (file ID 297 in this development instance).
Its text explicitly identifies it as a simulation. This test proves the host
integration and approval path; it does not prove a live model's summary quality.

The Nextcloud 31 header drawer and public **Ask Fadenstack** file action were also
verified without navigating away from Files. Full browser model testing was on 35.
Light/dark support uses native Nextcloud variables; both themes were inspected in
the real connected drawer and screenshots saved. The original system-default user
theme was restored. A formal accessibility audit and the remaining browser/storage
matrix are pending.

The Nextcloud 35 Text full-screen Viewer titlebar now exposes the same mascot
toggle. In the live editor it opened the drawer, signed in against the real server,
and attached the open saved Markdown (file 317), with its exact source link.
Desktop geometry leaves the editor alongside the drawer; below the desktop
breakpoint it uses an overlay. Browser sizing was restored after both checks.
The context is saved content, not unsaved editor buffers; the composer states this.
The earlier browser-automation submission check was inconclusive. The user then
sent "can you edit this?" from this full-screen drawer, with the saved Markdown
attached. Inspection of the existing tab confirmed the allowed `nc_read_file`
call, its completed tool result, and a finished model reply referencing the
document's five sections and asking what changes were wanted (77.4 seconds).
This closes the editor Send acceptance check. It verifies a read/conversation;
that request did not apply an edit. The screenshot is retained in the gallery.

## Completed real-server browser acceptance

The browser drawer signed in directly to `https://10.250.10.61` as the supplied
pilot user through the published SDK. Selecting **Reasons to use Nextcloud.pdf**
(file 73) added an included composer attachment; PDF.js extracted its text in the
browser. The real `qwen3.8-27b-fp8` model produced five substantive bullet points
and a Nextcloud source link. The summary turn took about 133 seconds.

A follow-up requested `nextcloud-live-browser-summary-20261013.md`. The drawer
displayed the exact `/` target and complete Markdown, then required **Allow once**.
After that click the real Nextcloud operation created file **317**. The returned
link opened the saved Markdown in Nextcloud Text, showing all five points and the
PDF source link. WebDAV read-back independently confirmed the stored content.
This acceptance uses the real server/model and is separate from the earlier
simulation described above.

Twenty-two real HTTPS/CORS/CSRF checks passed after the server recovered and the
scoped edge policy was reapplied. Certificate and hostname validation remained
enabled. The trusted public CA was verified over existing authenticated SSH;
no private key or real credential was committed or packaged. See
[network deployment](pilot-network.md) for configuration, fingerprint and rollback.

The [screenshot gallery](screenshots.md) captures the real PDF summary, full
approval card, completed write, saved Markdown and full-screen editor drawer. Source and
result artifacts are in ignored `build/live`. `tests/integration/live.mjs` provides
a repeatable real SDK/Nextcloud test, using the maintained Node PDF.js entry point
for that platform. Browser testing additionally verifies the shipped PDF worker.

The real SDK denial scenario also passed: the model requested `nc_create_text_file`,
the exact pending proposal was denied, no commit occurred, and authenticated WebDAV
returned 404 for that target. `build/live/denial.json` records the result.

The complete repeatable CLI acceptance also passed, creating file **334**:
`live-nextcloud-summary-2026-10-13T07-51-24-155Z.md`. It verified the PDF summary,
source link, exact one-use creation proposal, stored contents and authenticated
result-link redirects. `build/live/result.json` and the Markdown artifacts record
the run. Initial test-harness issues with nested approval notifications and SPA
redirect assertions were corrected; the final complete run exited successfully.

Both development instances are restored to the supplied HTTPS origin and
`nextcloud-agent`, with insecure HTTP disabled. The simulation server is stopped.
No real Fadenstack credentials are persisted in the app, package or test fixtures.
