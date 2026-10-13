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
documents these metadata filters. The Unified Search API was inspected, but its
Files provider is unnecessary for these filename operations. Optional indexed
search uses Nextcloud's public FullTextSearch manager with the Files provider
alone; provider presence does not by itself establish safe content-search support.

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

## Phase 3: retrieval and chat results

Results use one `nextcloud:files:ID` model with name, parent path, MIME type, size,
modification timestamp, ETag, source URL, directory flag and read/open capabilities.
`nc_get_file_metadata` and the extended `nc_read_file(resource_id?)` resolve a
known search result or the user-included file. Unknown guessed IDs/paths/URLs are
rejected. Only 150 issued references are retained in the authenticated page.

The existing Vue renderer uses the SDK's public host-call ID and part lifecycle
to display typed result cards. The SDK has no public untruncated tool-result getter
for a custom card; host-produced results are associated with their call ID locally,
without changing the SDK or parsing model text. Cards show file icons, names,
parent folders, modified dates, excerpts and source links. Add to context retrieves
authorized metadata only; reading stays lazy until a message is sent.

Existing write tools accept an optional known `resource_id` for a searched file
or destination folder/file parent. A discovered file must be read before an update;
server read-ETag state, exact approval cards and one-use proposals are reused.
No path-based writes or new execution engine are introduced.

29 SDK/frontend tests passed. The new mounted Vue/real SDK test performs discovery,
renders file cards, attaches metadata without reading, locates a termination
passage with one cached read, then verifies a searched-file creation cannot commit
before Allow once. English and German result labels are included.

## Phase 4: bounds, native pagination and security

Search requests have a 20-second client deadline and cancellation. DAV requests
have a 15-second timeout. Results default to 20 and cannot exceed 50; serialized
metadata/excerpts are additionally bounded to 60 KiB of UTF-8. Duration, retrieval
time, count, partial status and cache-hit status are returned without logging text.

Metadata sorting supports Nextcloud's native SearchDAV offset extension in scopes
containing one storage cache. Repeat the same filters with the opaque `next_cursor`.
Cursors expire in ten minutes, bind the PHP session and filters, and stop at an
offset of 5,000. Relevance tiers and scopes containing nested mounts return a
bounded first page without a cursor: Nextcloud offsets are per storage, not a
reliable global merge. Concurrent edits can shift offset pages; this is not a
snapshot. Output-budget truncation suppresses cursors to avoid skipping unseen
results; reduce the limit or narrow the query.

Creation-date filtering is verified on 35. Older supported installations return
an explicit 422 explanation for `created_after`; modification filters still work.
Imported files with unknown creation metadata cannot satisfy a creation bound.

Approved creation uses same-origin native DAV PUT with `If-None-Match: *`, followed
by an expiring one-use app receipt verifying destination, permission and exact
content hash. The parent is reauthorized immediately before PUT. This fixes
nested-folder creation while retaining core storage hooks and lock handling.
The create timestamp is passed through Nextcloud's supported `X-OC-CTime` header.
A renamed destination during the separate requests is detected, but there is no
distributed folder transaction; an I/O failure or changed receipt returns no
claimed success. Inspect the destination before retrying.

On 35, 55 authenticated security checks passed; 54 passed on 31, including the
explicit older-version creation-filter response. Both cover private users,
read-only sharing, revoked cached passages, revoked old contexts, literal `%`/`_`,
favorites, native paging, cursor tampering/session/filter isolation, nested approved
creation and a concurrent author winning without being overwritten. Forty existing
HTTP workflow checks passed on each version, including creation/editing, approval,
denial and actual ETag conflicts. Unit tests cover byte bounds and cache identity.
