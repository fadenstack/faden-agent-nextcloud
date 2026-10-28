# Administrator guide

Install the packaged app under `custom_apps/fadenstack_agent`, enable it with
`occ app:enable fadenstack_agent`, and configure its HTTPS Fadenstack origin and
published agent identifier in Additional settings. Give employees individual
Fadenstack access to that agent. Nextcloud access always comes from each user's
own authenticated session; no administrator search credentials are configured.

## Optional action history (0.1.5)

History defaults off. The normal lockfile still uses published SDK 0.2.0-rc.2;
its explicit history controls remain unavailable. The separately tested SDK
0.3.0-rc.1 command build is not published or a production release dependency yet.
For an accepted command-SDK/gateway deployment, set the app-owned flag with
`occ config:app:set fadenstack_agent history_enabled --value=1` and reload Files.
An explicit native `scope_policy.tools` must include `nc_actions_undo`,
`nc_actions_redo` and `nc_actions_reconcile` for all three controls; empty policy
adds them only when the flag is enabled. Publish each as destructive with the
schema in `contracts/history-tools.json`.
The gateway must provide authenticated profiles and prepare/dispatch `tools/check`
with matching schema, principal and comparison stamps. Automatic permission is
insufficient: native history always requires individual approval.

Only new paired standard-only/private whole-text updates qualify. Creation,
deletion, protected/broader-input snapshots and older unclassified journal entries
have no public restoration path. Current ACL, scope, classification, private
audience and exact native bytes must still match. **Agent actions** lists these
limits and never restores across a human edit. Review a new preview after account,
device, profile or policy changes. Stop before dispatch cancels preparation;
after dispatch inspect the actual file because the server may complete the write.
No Nextcloud credential is stored in Fadenstack, and SSO is not required.

An uncertain attempt offers **Inspect outcome**. The backend compares current
native bytes with the recorded before/intended values, then asks you to record
that exact observation. It makes no file write. A verified content state can
enable a fresh Undo/Redo; matching an unapplied original's before-state removes
unneeded recovery payloads. Partial/later content, identical before/after values,
expired or uncertified snapshots and a busy writer stay unresolved. Inspect does
not certify notification, indexing or other external effects. An open editor still
requires handoff. There is no force-clear or automatic replay.

Version 0.1.6 fixes PostgreSQL journal tag storage and concurrent expiry handling.
It reads existing raw-binary tags, but new tags require this version. Before an
upgrade, back up the database, data, custom apps and configuration together,
including the Nextcloud instance secret. Restore that consistent backup for a
rollback; replacing only app files with an older version cannot read new tags.
Replacing the instance secret is not a supported journal key-rotation procedure.
Restoring files into a new volume can change native storage ETags despite identical
content. Existing snapshots then remain readable, but the strict Undo revision
guard refuses them. An approved restore-revalidation path is not implemented yet.
Keep background jobs running for physical snapshot expiry. App disable/removal
and database-engine conversion need their own retention/migration acceptance.

The nine host tools are discovered on connection. Review them in Fadenstack's
governance interface and reconnect after policy publication. Search and metadata
tools are read-class operations; text creation/update stay destructive-class with
individual immutable approval cards. Existing instructions/contracts remain owned
by Fadenstack. See [integration](integration.md) for tool classes and TLS/CORS.

Filename, folder and recent discovery use native WebDAV SEARCH. Single-document
passages reuse the app's existing text/PDF extraction. Neither requires a RAG
service, database migration, background crawler or new document index.

The app's new 0.1.2 action journal does require its own four-table migration and
hourly retention job. Use normal `occ upgrade` and keep Nextcloud background jobs
running. Version 0.1.3 adds an owner-wide generation counter to the journal quota
table; its normal migration preserves existing encrypted entries and byte quotas.
The journal/scope/activity foundation has isolated NC35 acceptance;
the earlier NC31 search results below do not validate this new foundation on 31.
The administrator-owned `scope_policy` app setting can restrict native sources,
destinations, classifications, audiences and turn limits. Invalid policy fails
closed; an empty setting preserves legacy user-Files sources and private outputs.
There is no scope-policy editor in Additional settings yet. Source/destination
roots that differ need additional adapter acceptance before deployment.

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
