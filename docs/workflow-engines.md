# Workflow engine connections, reads, drafts and approved runs

For step-by-step approval, Stop and read-only recovery, see
[Run a workflow and inspect its outcome](workflow-execution.md).

For a configured editor on the left with chat on the right, see the
[native workflow workspace](engine-workspace.md). Its explicit pause handoff
retains an opaque editor; child-only reload is user requested. Native framing
and browser sign-in restrictions still apply, including n8n's separate-tab
fallback. This does not enable automatic clean-editor refresh.

This experimental slice reads saved workflows and creates new private drafts in Windmill **1.841.0** and n8n **2.43.8**
workflows through your own engine account. Your administrator must configure
the endpoint, opt in to provider reads and publish the matching agent tools.
The engine and Nextcloud integration apps need no source changes.

1. Open the assistant's **Settings → Workflow connections**.
2. Select the administrator-configured connection and paste your own engine
   API token. **Connect** verifies access before saving it encrypted in your
   Nextcloud account. The token field clears after submission.
3. Ask: “Find my connected workflow engines and list their saved workflows.”
   Ask for a particular saved workflow to read its definition.
4. Use **Disconnect** to remove access. An administrator endpoint change or
   the local 30-day expiry requires an explicit reconnect.

![Own engine connection](screenshots/engine-connection.png)

Separate Fadenstack sign-in works without enterprise SSO. Your Nextcloud
password, native session cookie and engine connection token stay outside
Fadenstack. Connecting the API does not sign in an embedded native editor.
Non-Enterprise n8n keys can be broader than this assistant's allowed tools.

Each list returns at most 20 workflows. Definitions are bounded to 128 KiB
and 200 top-level nodes/modules. Reads use current engine permissions and
retain graph fields, credential references and existing origin metadata.
Graphs can contain inline inputs and code. Credential stores and n8n pinned
execution samples are excluded. Unsaved editor changes are outside the read.

![Read saved workflows in chat](screenshots/engine-reads.png)

The shared working overlay and composer both provide **Stop**. A late native
read response is discarded after Stop. Stop does not cancel an external job
that was already running.

![Working overlay with Stop](screenshots/engine-read-stop.png)

Draft creation needs a separate administrator write and movement grant and a
published draft tool. Ask for a **new private draft**, then review the accounts,
destination and exact JSON before **Allow**. Windmill saves an own-user draft
without deploying a flow. n8n creates an inactive workflow with a unique name;
subworkflow callers and MCP availability are disabled. Existing graphs are not
replaced. The engine enforces current creation permissions when called.

![Review a Windmill draft](screenshots/engine-windmill-draft.png)

AI origin is written inside the workflow before approval. Windmill uses
`schema.x-fadenstack-provenance`; n8n uses a metadata comment in the description.
The signed record is checked against the configured publisher's signing key.
This marks origin; it does not grant access or certify legal compliance.
See [AI origin, placement reports and export limits](workflow-provenance.md)
for the embedded carriers, readback checks and behavior after native edits.

![Review an inactive n8n draft](screenshots/engine-n8n-draft.png)

Use **Agent actions → Inspect outcome** after an interrupted acknowledgement.
The generated draft reference identifies the attempt. Inspection sends no
write. Drafts have an audit trail and no Undo/Redo. Never repeat an uncertain
create: Stop can arrive after the engine already saved it.

![Inspect a saved draft after Stop](screenshots/engine-draft-audit.png)

Windmill execution is separately gated. The administrator must explicitly list
the own-user flow in `engine_run_targets`, grant `nc_windmill_run`, enable native
writes and allow the configured source class to move to the **unknown** audience.
For example, the execution-target setting can contain
`[{"connectionId":"my-windmill","reference":"u/alice/example"}]`.
An API connection alone grants no run access. The native token still needs
permission to run that flow; restricted-token capability acceptance remains a
release gate.

Ask to run the configured deployed flow once. Review its native account,
version, exact JSON inputs and graph before **Allow**. A changed deployed flow
invalidates the preview. The assistant never deploys, replaces or activates it.
Dependencies and native credentials can change independently of that version.

![Review a version-bound run](screenshots/engine-windmill-run.png)

The receipt verifies a unique **job dispatch**, and reports whether the engine
says it is queued/running, succeeded, failed or cancelled. Native job success
does not independently verify external effects. Stop does not cancel an
already-dispatched job, and partial external effects have no Undo.

Use **Agent actions → Inspect outcome**, or ask to inspect the recorded
`actionId` with `nc_windmill_read`. This reads the known job without another
run. No code, logs, job results or credential values are returned. A missing
job or lost acknowledgement stays uncertain; never repeat the run automatically.

![Dispatch receipt](screenshots/engine-windmill-run-receipt.png)

![Inspect the recorded native job](screenshots/engine-windmill-run-audit.png)

n8n execution uses a separate **published webhook target** configured by your
administrator. It must belong to your connected native user's personal workflow.
The tested subset has one static POST Webhook node (version2), Header Auth,
an empty options object and a response containing its native execution ID and
the supplied action correlation. The assistant never inserts a webhook or
publishes/activates a workflow.

In **Workflow connections**, select n8n and the published target. Save its
dedicated **X-Fadenstack-Workflow-Key** secret. This is separate from the n8n
API key. Both stay encrypted under your native Nextcloud account; no SSO is
required. Saving a webhook key sends no test execution and does not claim
that the key has been verified. Replacing the API token removes its stored
webhook keys. Disconnect removes both kinds of credential.

![Store a dedicated n8n webhook key](screenshots/engine-n8n-webhook-key.png)

The tested native Webhook node retains the authentication header in its own
execution history. Use a dedicated key for this personal workflow. Native
administrators and authorized execution-history readers may see it. Agent
status responses exclude headers, inputs, outputs and credentials.
Alternatively, the configured version-2.2 **user-bound OAuth** webhook uses
native consent, an encrypted own-user grant and the same individual execution
approval. See [OAuth connection and removal](n8n-oauth.md). The Header Auth
path remains supported; OAuth never silently falls back to it.

Ask to run the configured target once, then review the account, published
workflow/version, credential reference, exact request and unknown effect
audience before **Allow**. Native last-write-wins applies: the active version
can change after our final check. A mismatched native execution readback stays
unknown, with no automatic resend. Execution has no Undo.

![Approve one n8n execution](screenshots/engine-n8n-execution.png)

The response must contain `{"executionId":"<native-id>","correlation":"<action-id>"}`.
The request body is `{"_fadenstack":{"actionId":"<action-id>"},"data":{...}}`.
Native execution readback must match that body and the approved published version.
This contract is required for verified receipts; an arbitrary webhook response
is insufficient. Workflows can fail after producing partial external effects.

Use **Agent actions → Inspect outcome**, or `nc_n8n_read` with the recorded
`actionId`. Read-only recovery checks a complete collection of at most20
native executions. Busy, pruned, missing or ambiguous history stays unknown;
it never authorizes another webhook. Stop prevents future dispatch, and cannot
cancel an already accepted execution.

![Inspect the n8n execution](screenshots/engine-n8n-execution-audit.png)

All six engine tool runtimes and the bounded own-user OAuth connection/dispatch
path exist locally. Automatic authenticated-editor refresh, broader role/fault
tests, production OAuth/TLS and release acceptance remain open.
Providers remain experimental and default off. Live read/draft and bounded
Windmill/Header Auth execution evidence is available in the
[execution acceptance report](https://github.com/fadenstack/fadenstack-dev/blob/master/nextcloud-engine-live-execution-2026-11-09/README.md).
Broader roles, production OAuth/TLS, authenticated editor refresh and package
lifecycle acceptance are separate gates. Earlier illustrated research lives in the
[development report](https://github.com/fadenstack/fadenstack-dev/blob/master/nextcloud-agent-engines-2026-11-08/N8N_EXECUTION_CHECKPOINT.md).
