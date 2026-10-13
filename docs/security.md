# Security

Nextcloud and Fadenstack identities are independent. Every file operation resolves
the active Nextcloud user's folder through `IRootFolder::getUserFolder`, then checks
read/create/update permissions. Fadenstack checks agent grants and centralized policy.

Random context capabilities bind node, parent, path and ETag to the current PHP
session/user. They expire after one hour (≤32 contexts). Model tool arguments accept
no paths or alternative file IDs. Each turn freezes its deliberately included
context. Changing selection resets inclusion for the next request.

Writes use immutable proposals (ten minutes, ≤10 pending, ≤256 KiB replacement and
original preview). Only the user's Allow click marks that exact proposal approved;
the SDK then commits it once. Creation locks the folder and checks nonexistence.
Updates use a permission-checked Nextcloud write stream and compare storage ETags
under its exclusive lock before changing bytes. Storage failures after writing
begins may leave partial content; the error asks the user to inspect the file.
Nextcloud versioning remains the recovery mechanism where enabled.

Modifying endpoints retain CSRF and use explicit session handling. Admin settings
retain administrator middleware. The app's toggle is `integration_enabled`;
`enabled` is reserved for Nextcloud activation. Context responses use no-store.

Tokens, transcripts and approvals use fresh SDK memory stores, never localStorage
or IndexedDB. Pagehide/sign-out abort work and discard the runtime. Every outgoing
SDK request checks Nextcloud's session binding and configuration first. Periodic
and visibility checks clear stale views. Logout invalidates backend capabilities.

UTF-8 text reads reject NUL and input >2 MiB. PDFs are extracted by Mozilla PDF.js
with ≤10 MiB input, ≤50 pages, ≤50,000 output characters and a 30-second timeout.
Partial output is explicit. Folder output is capped at 200; Nextcloud internally
materializes its listing, so exceptionally large folders may use more memory.

CSP grants only the configured HTTPS connect origin. Loopback HTTP requires an
explicit development option. SDK transport rejects unknown origins and redirects.
Markdown passes through the SDK serializer and DOMPurify; images, forms and styles
are removed. The app logs no contents or credentials. Context is labelled untrusted;
pilot server instructions treat documents as data, not authorized instructions.

External/encrypted storage, permission revocation during I/O and full browser/CA
coverage remain deployment validation work. See limitations and verification.
