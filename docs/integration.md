# Integration and pilot

The app connects to an enabled published agent with per-user access grants. It
never creates agents, publishes instructions, picks models or grants access.
`contracts/nextcloud-pilot.json` is an optional administrator-reviewed pilot
definition; the application does not load it. Review its model alias/instructions
before importing. The supplied server now has `nextcloud-agent` v1 published with
`qwen3.8-27b-fp8`, which is configured in the local Nextcloud instances.

| Tool | Host class | Scope |
| --- | --- | --- |
| nc_get_selected_file | read | Included turn's fixed metadata |
| nc_read_file | read | Included file only |
| nc_list_folder | read | Included folder or file's parent; output ≤200 entries |
| nc_create_text_file | destructive | Individually approved .txt/.md creation |
| nc_update_text_file | destructive | Individually approved .txt/.md replacement with read ETag |

The write tools use the stricter SDK destructive class so Auto, remembered approvals
and contract downgrades cannot skip individual cards. A nonempty published contract
omitting a tool prevents offering it. On the first SDK connection, the server
automatically discovers the manifest's five tools. Approve them through Fadenstack's
own discovery/governance interface, then reconnect to load the published policy.
No manual tool-schema entry is needed. An empty contract in this SDK means
unclassified tools requiring approval. SDK
manifests/events contain schemas and operational counts, not documents or credentials.

## CORS and certificates

Browser SDK requests use `credentials: omit`, memory bearer tokens, rejected
redirects and only the configured origin. Allow exact Nextcloud origins on both
`/api` and `/v1`: `http://localhost:8090`, optionally `http://localhost:8088` in this
dev stack. Permit OPTIONS, GET, POST, PUT, DELETE and Authorization, Content-Type,
X-Faden-Tool-Mode, X-Faden-Events, X-Faden-Conversation-Id and X-Faden-Turn-Id headers.
Streaming responses need the same allowed-origin header and no proxy buffering.
Do not use a wildcard or enable cross-origin cookie credentials.

The inspected gateway provides `tls_edge_cors_origins`; the backend does not install
CORS middleware. Gateway configuration alone cannot enable SDK login/token refresh.
Use a reviewed backend configuration or scoped reverse-proxy CORS policy while
preserving existing cookie CSRF protections.

The pilot edge now permits the exact development origins only on SDK login/token
routes and `/v1/agents/nextcloud-agent`. Twenty-two live HTTPS/CORS/CSRF checks
passed on 13 October 2026, including excluded origins/admin routes, authenticated
access requirements, cookie CSRF and retained HSTS/nosniff headers. Browser sign-in
and a real PDF summary also succeeded. See [deployment](pilot-network.md).

Application requests retain certificate validation. The public organization CA was
obtained over existing verified SSH, its identity/fingerprint checked, and added to
this Windows user's trusted roots. No private key was copied. The public CA copy
is ignored by Git and excluded from the app package.

## Mandatory live acceptance

Resolve CORS/CA trust, connect once, approve the discovered tools in Fadenstack,
publish the policy and reconnect. Sign in through the drawer, select a text PDF,
verify its composer chip is included, and summarize
it. Verify streaming and a usable source link. Request a Markdown summary, inspect
the target/full replacement, allow once, and open the actual result. Repeat with
Deny, a stale update and the second user's unauthorized source ID/context. Record
completion only after the complete live scenario succeeds.
