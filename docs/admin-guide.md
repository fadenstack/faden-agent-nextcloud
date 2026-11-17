# Administrator guide

Install the packaged app under `custom_apps/fadenstack_agent`, enable it with
`occ app:enable fadenstack_agent`, and configure its HTTPS Fadenstack origin and
published agent identifier in Additional settings. Give employees individual
Fadenstack access to that agent. Nextcloud access always comes from each user's
own authenticated session; no administrator search credentials are configured.

## The action journal (always written)

The app writes an action journal into Nextcloud's database for **every approved
text create or update and every folder it creates**, whether history is on or off.
It holds the file's text before and after the change, encrypted with the
instance secret (`ICrypto`), with its header sealed by an HMAC. Its rows are keyed
by a hash of the Nextcloud user id. The text is kept for at most 7 days. The record
without text stays 30 more days, and an unclear outcome stays until it is
inspected. An hourly background job removes what has expired. These periods are
fixed in code; there is no switch. Deleting a user erases that user's rows at
once, and disabling or removing the app erases all of them (see below). Include
the journal tables in your backups and in your records of processing.

## Optional action history (0.1.7)

Only the undo and redo tools are optional; the journal above is written either
way. History defaults off. For a deployment whose Fadenstack server accepts the
prepare/dispatch checks below, set the app-owned flag with
`occ config:app:set fadenstack_agent history_enabled --value=1` and reload Files.
An explicit native `scope_policy.tools` must include `nc_actions_undo`,
`nc_actions_redo`, `nc_actions_reconcile` and `nc_actions_revalidate` for all four controls; empty policy
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
guard refuses them. Version 0.1.7 offers **Verify file revision** for a settled
private standard text update. It checks the exact content of the recorded saved
or undone state and requires fresh individual approval. Only the encrypted journal
revision changes; file bytes, action state and ordering stay unchanged. Undo/Redo
then requires a separate approval. Later edits, ambiguous/expired snapshots,
shared targets and dependent actions remain blocked. Verification cannot prove
notification or other external effects.
Keep background jobs running for physical snapshot expiry. Database-engine
conversion needs its own migration acceptance.

**Deleted accounts.** When a Nextcloud account is deleted, the app erases that
account's journal entries, snapshots and open approvals at once; Nextcloud deletes
its preferences, which hold the person's workflow-engine keys. If the erasure fails,
Nextcloud's log says so, the deletion still goes ahead, and the entries expire as
above.

**Disabling or removing the app.** Nextcloud runs the same uninstall step for both,
so both erase the whole journal with its snapshots, all open approvals, and every
person's workflow-engine keys. Undo is no longer possible for earlier changes, and
people connect their workflow engines again after the app is enabled again. The
organisation's settings (server address, agent, policies) stay, so the app comes
back paired. The files the agent wrote keep their AI marking. To remove the app
and keep its data, use `occ app:remove --keep-data fadenstack_agent`. The app's
empty tables stay after removal, as Nextcloud leaves them.

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

Files the agent creates or replaces receive signed origin metadata by default:
the native app requests a signature over the content's hash, never its text, and
Nextcloud keeps it as the file's metadata `fadenstack-provenance` (over WebDAV
`nc:metadata-fadenstack-provenance`, for search and DLP tools; users cannot change
it over WebDAV). It stays through rename, move, new versions, Nextcloud Text's saves
and the trash; the app copies it to copies. Markdown files also carry
`ai-generated: true` and `ai-record-id` in YAML front matter, which Text shows as a
labelled box and which travels with a download. The final marked text is shown
before Allow and is the text recorded in History. Marking policy is reread before
dispatch. An unavailable signer or changed policy blocks dispatch; a metadata
storage failure after saving is reported without repeating the save.
This native path conservatively marks changed corrections and refuses unsupported
visible-label or strict long-text watermark requirements. It does not attest a
generation watermark. PHP's intl extension supplies the shared NFC normalization.
The explicit `file_provenance_enabled=0` switch works only in development mode
for unsigned local transport fixtures. Production ignores that opt-out. Marking
does not enable providers or grant native access.

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
verfügbaren, indizierten nativen Files-Anbieter voraus. Prüfen Sie dessen Berechtigungen
und Zugriffsregeln in Ihrer Installation. Ohne Anbieter wird die Einschränkung
ausdrücklich angezeigt. Nextcloud 35 ist das primäre Testziel; die übrigen Grenzen
stehen in der obigen Kompatibilitätstabelle und den Verifikationsunterlagen.

Vom Agenten erstellte oder ersetzte Dateien werden als KI-generiert gekennzeichnet:
Der Fadenstack-Server signiert einen Nachweis jedes Schreibvorgangs, Nextcloud
speichert ihn als Metadaten `fadenstack-provenance` der Datei (auch beim Kopieren),
Markdown-Dateien tragen zusätzlich `ai-generated` und `ai-record-id` im Front Matter.
Diese Kennzeichnungen dürfen nicht entfernt werden.

Das Aktionsjournal wird **immer** geschrieben, nicht nur bei eingeschaltetem
Verlauf: für jede freigegebene Textdatei, die der Agent anlegt oder ändert, und für
jeden Ordner, den er anlegt. Es enthält den Text vor und nach der Änderung,
verschlüsselt mit dem Instanzgeheimnis (`ICrypto`), in der Datenbank von Nextcloud,
zugeordnet über einen Hashwert der Benutzerkennung. Der Text bleibt höchstens 7 Tage,
der Eintrag ohne Text weitere 30 Tage; ein unklares Ergebnis bleibt bis zur Prüfung.
Diese Fristen stehen fest im Code; es gibt keinen Schalter. Das Löschen eines
Benutzers entfernt dessen Einträge sofort. Deaktivieren oder Entfernen der App
entfernt das ganze Journal, alle offenen Freigaben und die gespeicherten
Workflow-Schlüssel aller Benutzer; die Einstellungen der Organisation bleiben.
`occ app:remove --keep-data fadenstack_agent` behält alles. Nehmen
Sie die Journaltabellen in Ihre Sicherungen und Ihr Verzeichnis der
Verarbeitungstätigkeiten auf. Optional sind nur die Werkzeuge zum Rückgängigmachen
und Wiederholen (`history_enabled`).
