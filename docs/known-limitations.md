# Limitations and unfinished acceptance

- The mandatory live PDF → summary → approved Markdown flow passed on Nextcloud
  35 against the real supplied server. This remains a development pilot; remaining
  browser/storage coverage is listed below.
- Tools are discovered on first connection and need approval in Fadenstack. The
  optional pilot instructions JSON is not loaded by the app.
- One selected file or folder per request. Automatic checkbox context uses the
  visible Files row DOM; custom layouts or an off-screen virtualized selection may
  require the public **Ask Fadenstack** file action. Multiple documents are deferred.
- DOCX/XLSX/PPTX, scans/OCR, password PDFs are unsupported. PDFs needing external
  CMaps can have incomplete extraction; inspect results.
- Text input ≤2 MiB, PDFs ≤10 MiB; extraction ≤50,000 characters and 50 PDF pages.
  Partial reads are explicit; follow-on selective reading is not implemented.
- Write previews/replacements ≤256 KiB. Context/proposals expire after one hour/ten
  minutes. Revision/path changes require reselection.
- Conversation history exists only in the current page runtime; no SSO. Version
  0.1.2 adds encrypted server-side action receipts/snapshots for existing writes,
  with quotas and retention. Version 0.1.4 adds opt-in paired Undo/Redo endpoints,
  tools and controls for new standard-only/private text updates. The published
  SDK dependency remains 0.2.0-rc.2; explicit buttons require the separately tested
  unpublished 0.3.0-rc.1 command SDK and compatible gateway. Key rotation, production-database races and
  inspected resolution of expired unknown effects remain release gates.
- Version 0.1.3 coordinates private cloud/configuration scopes within one native
  user. Upgrade and two-process alias races pass on NC35.0.2/PHP8.5.12/SQLite.
  Cross-native-user shared-file history and production database races remain open;
  history defaults off. Legacy/protected/shared snapshots are not publicly reversible.
- The new 0.1.2 scope/journal/activity foundation is tested on NC35 with SQLite.
  It does not inherit NC31 acceptance from earlier search-only checks. Different
  source/destination roots and app-specific dirty/refresh adapters remain open.
- HTTP checks cover 31 and 35. 32–34, Firefox/Safari, formal screen-reader audits
  and external/encrypted storage have not received complete pilot verification.
- The 31 development image's Text app logs a color-picker translation error. It
  reproduces with Fadenstack Agent disabled; this underlying dev-image issue is
  not fixed here.
- Updates require seekable Nextcloud write streams. I/O failures can leave partial
  content after writing starts; no success is reported. Versioning aids recovery.
  Current native file size/ETag and parent metadata are refreshed explicitly after
  flushing, under the native write lock. Native workflow/event side effects of
  streamed writes require separate acceptance; this is not a universal transaction
  around other installed apps' hooks.
- Folder output is bounded, but Nextcloud materializes the underlying directory.
- Primary German elements are translated; server/profile/error text can remain English.
- Production npm audit passes. Inherited developer tooling has remaining transitive
  advisories; no forced major downgrade of Nextcloud tooling was applied.

Next work: verify the remaining storage and browser configurations. The scoped
pilot CORS policy needs reapplication after Fadenstack upgrades regenerate edge
templates; see [network deployment](pilot-network.md). The current model can take
minutes per turn, independently of the local UI's reactive rendering.

## Full-screen editor integration

Nextcloud 31–35 Viewer does not expose a header-action registration API. A bounded
DOM adapter mounts in its visible titlebar and uses the public Viewer focus event;
it never reads private Vue instances. This was exercised in Nextcloud 35 Text.
Other embedded Office editors and private editor buffers have not been verified.
Only the saved file is attached. Save edits before sending; a missing or mismatched
document ID/name is rejected rather than attaching the file selected behind it.

## v0.1 search

- Filename and metadata discovery use native WebDAV SEARCH. The agent turns
  natural instructions into those filters; there is no semantic search engine.
- `created_after` is verified only on 35. Earlier supported versions return an
  explicit unsupported-filter explanation. Imported creation timestamps may be absent.
- Relevance uses exact/prefix/substring filename tiers, not provider semantics.
  Modification sorting is explicitly labelled as such.
- Native offset paging is available only for metadata ordering in one-storage
  scopes. Nested mounts and relevance tiers return a bounded first page. Concurrent
  changes can shift native offset pages. Narrow a partial result set.
- Parsed content remains bounded to the existing formats, bytes/pages/characters.
  Matches beyond processed text cannot be counted or assigned source locations.
- Cross-document indexed search requires an existing supported native Files
  full-text platform. None is installed in the pilots; missing capability and the
  positive contract are tested, but a configured platform is not verified live.
  Index metadata/comments can also match; verify facts in document contents.
- Creates use native atomic no-overwrite PUT and a one-use verified receipt.
  Parent reauthorization and receipt verification are separate requests, not a
  distributed transaction around folder renames or storage I/O. On failure,
  inspect the destination before retrying; no false success is returned.
- The complete latest-contract workflow passed with the real server/model and
  synthetic fixtures through the production SDK host. The new browser search
  demonstration is awaiting manual Sign in/Send because browser automation input
  did not reliably reach the controls. Mounted Vue/SDK rendering and approval
  tests pass; original real browser PDF/editor acceptance remains valid.
