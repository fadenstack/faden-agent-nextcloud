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
- History exists only in the current page runtime; no durable storage or SSO.
- HTTP checks cover 31 and 35. 32–34, Firefox/Safari, formal screen-reader audits
  and external/encrypted storage have not received complete pilot verification.
- The 31 development image's Text app logs a color-picker translation error. It
  reproduces with Fadenstack Agent disabled; this underlying dev-image issue is
  not fixed here.
- Updates require seekable Nextcloud write streams. I/O failures can leave partial
  content after writing starts; no success is reported. Versioning aids recovery.
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
