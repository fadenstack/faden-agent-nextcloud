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
