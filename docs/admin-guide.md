# Administrator guide

Install the packaged app under `custom_apps/fadenstack_agent`, enable it with
`occ app:enable fadenstack_agent`, and configure its HTTPS Fadenstack origin and
published agent identifier in Additional settings. Give employees individual
Fadenstack access to that agent. Nextcloud access always comes from each user's
own authenticated session; no administrator search credentials are configured.

The nine host tools are discovered on connection. Review them in Fadenstack's
governance interface and reconnect after policy publication. Search and metadata
tools are read-class operations; text creation/update stay destructive-class with
individual immutable approval cards. Existing instructions/contracts remain owned
by Fadenstack. See [integration](integration.md) for tool classes and TLS/CORS.

Filename, folder and recent discovery use native WebDAV SEARCH. Single-document
passages reuse the app's existing text/PDF extraction. Neither requires a RAG
service, database migration, background crawler or new document index.

Indexed content search is optional. Detection requires `fulltextsearch` and
`files_fulltextsearch` enabled for the active user, an available platform, and an
indexed Files provider reported by the public FullTextSearch manager. An installed
app alone is insufficient. The authenticated `/search/capabilities` endpoint and
`nc_search_content` report status. Verify your native platform's permissions and
active access-control rules before offering it. The app additionally reauthorizes
each result and probes current storage read access before exposing excerpts.
The positive provider contract has unit coverage; a configured live platform was
not available in these pilot deployments and remains a deployment acceptance check.

| Environment | Verified behavior |
| --- | --- |
| Nextcloud 35.0.1 / PHP 8.5.11 | Native discovery, creation-date filter, PDF/text passages, security and existing writes; real model workflow |
| Nextcloud 31.0.14 / PHP 8.3.30 | Same native discovery/passages/security/writes; creation-date filter explicitly unavailable |
| Nextcloud 32–34 | Declared compatible range; complete runtime matrix pending; creation-date filtering conservatively unavailable |
| Native Files full-text platform | Contract and negative capability tests; live configured-platform validation pending |

Keep deployment validation separate from measured local performance. Large or
remote storage and native index configuration can change query times. The client
has cancellation and deadlines; backend interruption depends on Nextcloud/provider
behavior. Cached parsed documents stay only in an identity-bound page runtime
and are reauthorized on every retrieval. See [search limits](search.md).

## Hinweise auf Deutsch

Es werden keine administrativen Suchzugangsdaten benötigt. Jeder Zugriff verwendet
die aktuelle Nextcloud-Sitzung des Mitarbeiters. Die neun Werkzeuge werden in
Fadenstack erkannt und dort verwaltet; Schreibvorgänge benötigen eine individuelle
Freigabe. Dateisuche und Suche im einzelnen Dokument benötigen keinen zusätzlichen
RAG-Dienst. Die Volltextsuche über mehrere Dokumente ist optional und setzt einen
verfügbaren, indizierten nativen Files-Anbieter voraus. Prüfe dessen Berechtigungen
und Zugriffsregeln in deiner Installation. Ohne Anbieter wird die Einschränkung
ausdrücklich angezeigt. Nextcloud 35 ist das primäre Testziel; die übrigen Grenzen
stehen in der obigen Kompatibilitätstabelle und den Verifikationsunterlagen.
