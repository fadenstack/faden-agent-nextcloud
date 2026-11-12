# Local SDK 0.3.0 candidate

This branch uses the owner's local `0.3.0-sync.2` tarballs and overrides all eight
`@fadenstack/*` packages to the same feed. It is not an npm release. Keep these
`file:` dependencies local until both SDKs are published together; do not push or
publish this candidate. Node 24 and npm 11.3 are required by the app.

The app requires core's `host_tools.dispatch_check` capability. Every model tool
call receives a fresh prepare/dispatch check; an old gateway is refused at sign-in.
Native permissions, context, exact payload previews and individual approvals
continue to apply. A time/work/Bypass grant never overrides a native per-action
approval. SDK grant cards, markers, second sign-in and reasoning controls use the
SDK's existing chat UI rather than a separate Nextcloud permission system.

Sign in with a code to approve the connection on the configured Fadenstack
server, or use the password form for local accounts. Pairing supports one
configured Nextcloud agent. Code links are validated by the SDK against that
server's origin. Cancel or closing a pending pairing stops its local wait; an
unapproved code expires at the server. No Nextcloud password is sent to Fadenstack.

Tokens, chats and local standing approvals deliberately stay in memory, per
runtime. No browser chat database, key export, or persistent credential store is
enabled by this migration. Closing/signing out clears them. The SDK's revocation
request has an independent deadline so local Stop cannot prevent best-effort
server sign-out. Server-held audit/History data follows its existing retention.

An exempt output no longer adds a routine "no AI mark" line by default; the SDK's
Settings option can show it. Actual stored marks
and failures to keep a required mark remain visible. File marks are still
prepared natively before approval, not added by an SDK post-approval rewrite.

For browser deployment, both the Fadenstack server's CORS configuration and the
Nextcloud CSP must permit the configured origins and SDK headers. Pairing needs
the new pairing routes too. A successful server-side or isolated proxy test is
not proof that a direct cross-origin browser deployment is configured correctly.
