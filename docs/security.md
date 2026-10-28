# Security

Nextcloud and Fadenstack identities are independent. Every file operation resolves
the active Nextcloud user's folder through `IRootFolder::getUserFolder`, then checks
read/create/update permissions. Fadenstack checks agent grants and centralized policy.

Random context capabilities bind node, parent, path and ETag to the current PHP
session/user. They expire after one hour (≤32 contexts). Model tool arguments accept
known `nextcloud:files:ID` references, never paths or external URLs. Each turn freezes its deliberately included
context. Changing selection resets inclusion for the next request.

Writes use immutable proposals (ten minutes, ≤10 pending, ≤256 KiB replacement and
original preview). Only the user's Allow click marks that exact proposal approved;
the SDK then commits it once. Creation reauthorizes the parent, uses native WebDAV
`If-None-Match: *`, and verifies an expiring one-use destination/content receipt.
Updates use a permission-checked Nextcloud write stream and compare both storage
ETags and the exact approved before-state hash under its exclusive lock before
changing bytes. Native local ETags can collide for same-size/mtime changes;
the byte comparison refuses that conflict. Storage failures after writing
begins may leave partial content; the error asks the user to inspect the file.
Nextcloud versioning remains the recovery mechanism where enabled.

Version 0.1.2 persists an encrypted, MAC-bound write-ahead journal in four app-owned
tables before existing Files writes dispatch. Native ACL and configured source
policy are checked before saved payload access. Snapshots expire after seven days
(hourly bounded maintenance and opportunistic cleanup), with 1 MiB per snapshot
JSON, 25 MiB encrypted payload per native user and 1,000 metadata entries. Unknown dispatched
effects retain an unresolved claim rather than retrying. This records existing
effects. Version 0.1.4 optionally binds new writes to a server-verified cloud
principal pair and exposes typed history endpoints, never a generic snapshot endpoint.
Key rotation, the full database/storage matrix and deletion/recreation inverses
remain release gates. Keep the Nextcloud instance secret with journal backups;
changing it without a migration makes authentication/decryption fail closed.

Version 0.1.6 stores new header and receipt authentication tags as prefixed Base64
ASCII. Nextcloud's public ICrypto returns binary tags, which PostgreSQL text
columns reject. Existing raw-binary SQLite tags still verify without rewriting
immutable receipts. The key, signed fields and payload encryption are unchanged.
Older app versions cannot read new encoded tags: rollback requires a consistent
pre-upgrade backup, not an app-files-only downgrade. Converting a legacy SQLite
database containing binary tags to another engine needs separate acceptance.

Expiry maintenance rechecks each signed row under the owner lock and skips an
observation already changed or deleted by another worker. It does not suppress
integrity failures, clear uncertain effects or guess their outcome. Snapshot
deletion requires the app's retention job to run; stopping/disposing the app does
not by itself prove that its persisted data was erased.

The internal `FilesHistoryExecutor` accepts only a revalidated immutable typed
whole-text update plan. It claims the journal action once, repeats native target,
write/movement policy, storage revision and content-hash checks under a seekable
exclusive stream, and records success only after verified native readback.
Pre-dispatch failures release the claim without losing the action. Failures after
dispatch retain `needs_reconciliation`, both snapshots and the resource claim;
they cannot be retried automatically. Its plan/digest is not an approval or cloud
execution permit. The opt-in HistoryService joins fresh verified identity,
prepare/dispatch central governance and one-use individual approval to this executor.

`CloudIdentityVerifier` projects an authenticated published
Fadenstack agent profile using a short agent-device access token. It sends that
token only to the fixed administrator-configured origin, preserves Nextcloud's
verified CA bundle, refuses redirects, bounds the response and rechecks configuration.
Private addresses are permitted for that fixed origin without enabling a system-wide
fetch proxy. It never stores the token, accepts a browser-asserted subject or
decodes JWT claims as proof. Missing/device-less identities and draft/foreign
agents fail closed. This is independent of SSO and never sends Nextcloud passwords
or cookies to Fadenstack.

`JournalIdentity::forPrincipal` derives a separate native/cloud user namespace and
device/profile-bound claim session. It preserves native-user aggregate quotas and
does not adopt legacy native-only records. With history enabled, modifying Files
and history requests send the current memory-only cloud token in a dedicated header
to the same-origin backend. The backend independently verifies that identity;
no browser-asserted subject is trusted. Identity verification is not a tool grant
or approval. Durable ownership survives app/policy versions while pending approvals
bind their current configuration and device. Native CA/deployment-network
acceptance and server revocation latency remain deployment work. Native loopback HTTP verifies refusal of
redirects, revoked grants, malformed/oversized profiles and slow responses.

Public recovery is deliberately narrower than journal storage: only new updates
recorded under a standard-only input policy to a private standard file receive
MAC-bound `standard_private_v1` metadata. Missing metadata is never backfilled.
Protected/broader-input snapshots cannot become eligible through later classification
changes. Private audience and classification are checked again at preview and under
the executor's stream lock, even if current general movement policy permits sharing.
Full protected-content lineage and shared-file restoration require separate design.

Version 0.1.7 adds explicit settled-state revision verification. It never adopts
another recipe's bytes or changes an unknown outcome. Certified private/local/
unencrypted/writable native access is checked again under the journal owner lock.
Dependency checks and payload quotas apply before the CAS. Old plans and approvals
become stale after a revision receipt; a separate fresh Undo/Redo approval is needed.
It provides no force-clear, key migration or external-effect verification.

Version 0.1.5 adds `nc_actions_reconcile` through the same individual approval,
fresh principal and central prepare/dispatch checks. It resolves only typed,
certified private text content. A bounded native read stream excludes an active
native writer and stays open through the journal version CAS/claim release; no
write stream or write-open hook is used. Shared target/recipe validation is reused
by Undo/Redo and inspection. A changed observation, target, actor or journal version
refuses resolution. Partial/foreign bytes and identical before/after are inconclusive.
Expiry cannot be bypassed. A distinct MAC-bound `reconciled` receipt records
`verified` or `unchanged` content; external effects remain explicitly unverified.
Local SQLite writer and competing-process tests do not establish production
database, external-storage or cross-native-user lock acceptance.

History preview session state contains IDs, digests, comparison stamps and expiry,
never tokens or saved file content. Exact approvals expire after ten minutes,
are capped at ten and are consumed before execution attempts. Every boundary fetches
a fresh cloud profile; dispatch repeats central policy and native validation. Fixed
routes refuse redirects/cookies and bound response size/time. Token arguments use
PHP SensitiveParameter so debug backtraces cannot expose their values. Central
policy receives only tool name and action ID, not original/restored file bytes.

Version 0.1.3 shares native-user history coordination across those private scopes:
an unresolved action cannot be bypassed by switching cloud identity/configuration
or using another reference to the same file ID. Claims, dependency checks and Redo
invalidation serialize on the native owner's quota row. Coordination verifies
metadata MACs without decrypting or returning another scope's snapshot. The normal
migration initializes a shared generation counter and leaves old signed/encrypted
entries unchanged; ambiguous legacy ordering refuses reversal conservatively.
This has native SQLite upgrade and competing-process coverage, not production
database or cross-native-user/shared-file acceptance.

Modifying endpoints retain CSRF and use explicit session handling. Admin settings
retain administrator middleware. The app's toggle is `integration_enabled`;
`enabled` is reserved for Nextcloud activation. Context responses use no-store.

Tokens, transcripts and approvals use fresh SDK memory stores, never localStorage
or IndexedDB. Pagehide/sign-out abort work and discard the runtime. Every outgoing
SDK request checks Nextcloud's session binding and configuration first. Periodic
and visibility checks clear stale views. Logout invalidates backend capabilities.

Native `scope_policy` configuration restricts the published Files contract. Empty
configuration migrates existing access to the user's Files sources/destinations,
standard content and private destinations. It makes no claim about a downstream
model provider. Protected content requires explicit configured processing policy;
unknown classification fails closed. Search is narrowed before discovery/counts.
Each turn is limited to 30 tool attempts, 10 mutation attempts, 180 seconds, 200
records and 256 KiB projected result text unless stricter policy applies. Earlier
conversation content retains its source classifications and is reauthorized
before model replay. A changed or revoked source blocks reuse of that context.
The pinned SDK exposes a session/device binding, which is not a verified subject.

UTF-8 text reads reject NUL and input >2 MiB. PDFs are extracted by Mozilla PDF.js
with ≤10 MiB input, ≤50 pages, ≤50,000 output characters and a 30-second timeout.
Partial output is explicit. Folder output is capped at 200; Nextcloud internally
materializes its listing, so exceptionally large folders may use more memory.

CSP grants only the configured HTTPS connect origin. Loopback HTTP requires an
explicit development option. SDK transport rejects unknown origins and redirects.
Markdown is rendered through the SDK's structured Markdown AST and Vue components;
raw HTML is not inserted. Images, forms and styles are not enabled. The app logs no
contents or credentials. Context is labelled untrusted;
pilot server instructions treat documents as data, not authorized instructions.

External/encrypted storage, permission revocation during I/O and full browser/CA
coverage remain deployment validation work. See limitations and verification.

Search resolves all native IDs under the current user's root, including an active
storage read-access probe before cached content or indexed excerpts are exposed.
Parsed text is scoped to one runtime identity, four revisions and five minutes.
Revision/path changes invalidate it. Strict XML escaping and literal wildcard
escaping prevent query injection. Session-bound signed pagination cannot be reused
by another session or with different filters. Search telemetry contains durations
and counts; file contents are not logged. See [search details](search.md).
