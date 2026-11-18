# Limitations and unfinished acceptance

## The 0.2 preview (December 2026)

This is a preview. It works only with a Fadenstack server (governed mode). Two acceptance gates are not done
for it:

- **No adversarial test across the tools yet.** The full fault-injection suite (concurrency, races, network
  boundaries) has not run against the app's tools. When the Fadenstack SDK's prompt-injection suite exists, it is
  run against these tools.
- **No named pilot yet.** The app has not run at a customer under a named pilot. That follows with the store
  release after this preview.

The admin's **Experimental: development mode** stays off by default. When it is on, Mail, Talk and the workflow
engines are untested and send content to other people, and HTTP on localhost is allowed. Use it for testing only.

The details below record earlier versions' limits and remain valid where they still apply.

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
- Version 0.1.5's inspected reconciliation verifies file content, not whether an
  external notification/workflow ran. Unknown partial bytes, expired snapshots
  and identical before/after values cannot be force-cleared. Opening native Text
  can change an ETag even without changing bytes; recovery remains conservative.
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
- The app's own screens are in English, German, Spanish, French and Simplified
  Chinese, as the SDK's panel is. Messages that come from the Nextcloud server's
  side of the app (for example "The selected file changed. Select and include it
  again.") and from the Fadenstack server can remain English.
- Historical carrier measurements on 31.0.14 and 35.0.1 found metadata surviving rename,
  move, copy (the app's listener, also in a copied folder), new versions, Text's
  saves, shares and the trash. A recipient of a single-file share does not see it
  over WebDAV (Nextcloud lists no metadata on a share's own root; inside a shared
  folder it does); the record is still the file's. A downloaded `.txt` carries no
  metadata mark in its bytes; this native path does not attest a model watermark.
  A `.md` keeps its front matter.
  Copies made outside Nextcloud (download and upload) lose the metadata. Text adds a
  blank line after the front matter on its first save; the mark stays.
- The 0.1.16 native path signs normalized hashes and prepares final marked bytes
  before approval. Changed corrections are conservatively marked; explicit
  policy exemptions do not create a new record. Unsupported visible-label and
  strict long-text watermark requirements fail before dispatch. An unavailable
  signer also blocks dispatch. These are stricter limits than the old prototype's
  post-approval SDK placement and unsigned fallback.
- Metadata records origin history. It can survive later manual edits and text
  Undo/Redo; its presence alone does not prove that today's text matches its signed
  hash. Text History restores exact recorded bytes, including any front matter;
  it does not reverse the public signature or other native version side effects.
- Signatures prepared for abandoned/expired previews can remain without a confirmed
  placement. Deny/Stop remove pending native authority and attempt a not-written
  report. A report outage never causes a repeated native save or claims delivery.
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
