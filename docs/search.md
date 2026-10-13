# Search and document discovery

## Architecture

One TypeScript `NextcloudSearchService` sits behind the host tools. It composes
the existing authenticated `NextcloudFiles` adapter and extraction code.
PHP `SearchAccessService` validates filters, resolves folder scopes and encodes
bounded XML plans; the browser sends same-origin WebDAV SEARCH using the active
Nextcloud session. Every returned ID is resolved again through `FileService`.
Neither agent credentials nor administrator credentials authorize search.

## Phase 1: file discovery

`nc_search_files` supports an optional filename query, `all` or a folder resource
scope, extensions/MIME types, modification/creation dates, sizes, favorites,
sorting and a 1–50 limit (default 20). Folder scope searches descendants.
Results describe filenames, not indexed document contents.

Relevance uses three disjoint native queries: exact filename, prefix, substring.
Each tier is ordered by modification descending, then stable filename/path/ID
ties. Other sorts use the native modified/name/size ordering. An empty relevance
query is explicitly reported as modification sorting. No relevance score is
invented. Responses and parsing are bounded; partial results are marked.

Nextcloud excludes the scope folder after native query limiting. The plan excludes
that folder inside the query so it does not consume the extra-result probe.

The official [WebDAV SEARCH API](https://docs.nextcloud.com/server/stable/developer_manual/client_apis/WebDAV/search.html)
documents these metadata filters. Optional content providers use the
[Unified Search API](https://docs.nextcloud.com/server/stable/developer_manual/digging_deeper/search.html);
provider presence does not by itself establish safe content-search support.

### Increment validation (13 October 2026)

45 live HTTP/WebDAV discovery checks passed separately on Nextcloud 35.0.1 and
31.0.14. They cover filename/partial matching, recursive scope, type/date/size
filters, source URLs, empty results, bounded output, special characters and
private-user isolation. Latest-contract queries measured 480 ms and 527 ms in
these local SQLite fixtures; these are observations, not deployment guarantees.
21 SDK/frontend tests and 15 PHP tests passed. Remaining content/UI/share-revocation
coverage follows in the subsequent search increments.

## Phase 2: passages and optional content index

`nc_search_in_file` searches a known search result or an included document.
`exact_phrase` is a case-insensitive literal phrase with normalized whitespace;
`terms` finds individual case-insensitive keywords. Excerpts are bounded and have
real PDF.js page records or extracted text line numbers. A full count is returned
only for complete extraction; partial documents report an unknown total.

The existing PDF.js extractor now has a shared platform-neutral core. Browser
worker wiring and the maintained Node entry use that same bounded extraction.
There is no second parser. Scans without text and encrypted/unsupported documents
produce explicit errors.

Parsed text stays in page memory: at most four documents, five minutes, 50,000
characters per document. Repeated searches reauthorize through the active storage
and compare ETag/path; changed revisions are parsed again. Identity changes clear
the cache. No document bytes are kept in browser persistent storage.

`nc_search_content` uses the public Nextcloud `IFullTextSearchManager` only when
the Full Text Search and Files provider are enabled, registered and indexed. It
requests the Files provider alone and uses bounded native page pagination. Returned
IDs are resolved under the current user and the storage is opened for a content
authorization probe before any indexed excerpt is exposed. Other app providers
and unverified external references are excluded. Native index matches may include
provider metadata/comments: use document search to substantiate a factual answer.
Absent capability returns `available: false` and an explanation, never a filename
fallback or an organization-wide download scan.

### Increment validation

On Nextcloud 35, 68 live checks passed including the actual sample PDF, correct
page-record mapping, a single parse across repeated queries, text ETag invalidation
and honest missing-index behavior. A PDF passage query measured 1514 ms and its
cached follow-up 247 ms locally. 28 frontend/SDK tests passed. 17 PHP tests / 29
assertions cover query validation and the optional provider contract, including
withholding revoked excerpts. A configured live indexing platform is not installed
in these development instances; that positive backend remains unverified live.
The same 68 HTTP/WebDAV/document checks also passed on Nextcloud 31, with a
1489 ms PDF query and 257 ms cached follow-up. The sample's actual PDF.js page
records, not an assumed fixed page count, are checked against each emitted match.
