# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

From the 0.2 preview, every release has a **Data fields** section. It lists each
value the app stores, sends or writes into your content that was added, changed or
removed, so an organisation can keep its works agreement and records of processing
current. "Works agreement? Yes" marks a field that reaches the organisation's server,
or a changed default or purpose.

## [Unreleased]

The 0.2 preview, planned as `0.2.0-beta.1` on the App Store's beta channel. It works
only with a Fadenstack server (governed mode). See
[known limitations](docs/known-limitations.md).

### Added

- Sign-in without a password: pair the browser with the configured Fadenstack
  server. Pairing and password sign-in share one in-memory token store.
- When an organisation turns the agent off (Fadenstack 0.7.1), the app stops its
  reads and writes in Nextcloud and says "Your organisation turned this agent off."
  in the person's language. It does not try again. The chat shows the same words
  with "Check again", and asks the model nothing until the agent is on again.
- "Stop everything" in the panel's header: one press, with no confirmation, stops
  the running answer, cancels the changes waiting for approval in Nextcloud, and
  ends the agent's grants on the Fadenstack server. What the person allowed
  "always" stays.
- An answer copied out of the chat carries the AI mark in its HTML: a record the
  organisation's Fadenstack server signs from the text's hash. The plain text is
  copied as it is; code is not marked. When the organisation's policy requires the
  mark and it cannot be made, nothing is copied and the chat says so.
- `SECURITY.md`: how to report a vulnerability. Version 0.2 gets security fixes
  until 8 December 2031.
- The release package carries `SECURITY.md` and `CHANGELOG.md`, and its
  third-party inventory names a licence for every component.

### Changed

- The Fadenstack TypeScript SDK 0.3.0 (the `0.3.0-standalone.10` pre-release, as
  local packages until the SDK's registry release). The chat's copy, link and
  file-saving services come from `@fadenstack/ui-web`.
- Every app tool call the model makes is checked by the Fadenstack server before
  it runs (see Data fields). The app refuses a server that does not offer the check. Each native
  write still asks for its own approval.
- The administrator's development checkbox is now "Experimental: development
  mode", off by default, with a warning in five languages: Mail, Talk and the
  workflow engines are untested in this mode and send content to other people.
- The store description says that the text of the files you include, and what
  the agent's tools read, go to the organisation's Fadenstack server and on to its
  model.
- The administrator guide says that the action journal is written for every
  approved change, whether history is on or off, and for how long.
- German texts address the reader as "Sie" throughout.
- Routine "not marked" notices follow the shared preference; real marking
  failures stay visible.
- The browser CORS helper covers pairing, sign-in revocation and the SDK's
  headers.

### Fixed

- The off-switch's sentence is in the person's language wherever it shows. When
  Nextcloud notices the switch before the chat does, the chat shows its notice
  with "Check again" once, instead of an error line.
- While the agent is off, the Actions dialog says so instead of listing only the
  entries that still load.
- The first change of a session waits up to a minute for the app's view to load
  (it was 15 seconds, which a busy server exceeded). If the view does not load,
  the chat says so in words and nothing is changed; Stop ends the wait.
- `appinfo/info.xml` validates against the App Store's schema.
- A sign-in revocation is no longer cut off when the person stops the agent.
- Shell scripts and the development containers' files are checked out with LF
  line endings on Windows.
- The integration search test uses its own two-page PDF instead of an
  administrator's default file.

### Data fields

Compared with 0.1.16. Mode G is governed (with a Fadenstack server), the only mode
of this release.

| Change | Field | Where | Mode | Purpose | Default and who can switch it | Retention | Works agreement? |
|---|---|---|---|---|---|---|---|
| Added | Host tool check: `name`, `arguments` (the tool's full arguments; for a write, the text to be written), `tool_mode`, `origin`, `call_id`, `phase`, `schema_sha256`; headers `x-faden-conversation-id`, `x-faden-turn-id` | Sent from the browser to the organisation's Fadenstack server, `POST /v1/agents/{agent}/tools/check`, before each app tool call the model makes. In 0.1.16 only the person's own history commands were checked | G | The server's policy allows, asks for or refuses each call before the app runs it | On; no one can switch it off | Per the server's policy decision records | Yes |
| Added | Pairing: `agent`, `device_label` ("Nextcloud Files"), `client` (`name` "Fadenstack Nextcloud", `version`, `os` "Browser") | Sent to the organisation's Fadenstack server, `POST /api/agent-tokens/pairings` | G | Sign in without typing the Fadenstack password into Nextcloud | Used when the person chooses pairing; the person | Per the server's sign-in records | Yes |
| Changed | Header `X-Fadenstack-Agent-Token` (the short-lived Fadenstack access token) | Sent from the browser to the organisation's own Nextcloud server with each approved write; Nextcloud checks it with the Fadenstack server (`GET .../profile`) and never stores it | G | The Fadenstack server signs the AI mark under the person's own sign-in | Was: only with history or provider reads on. Now also whenever marking is on, which is always in production; only the experimental development mode can turn marking off | Not stored | No |
| Added | Copy record: `content_sha256`, `text_sha256`, `media_type`, `edit_kind` ("generated"), `receipt_id`, `model`; then the placement report (tool `copy`, sink `clipboard`, which marks held; never the copied text). The copied HTML carries the record's id | Sent to the organisation's Fadenstack server, `POST /v1/agents/{agent}/provenance/records` and `.../provenance/placements`, each time the person copies an answer out of the chat | G | The AI mark on what leaves the chat (AI Act Art. 50(2)) | On; the organisation's marking policy decides whether a copy that cannot be marked is refused; the person cannot switch it off | Per the server's provenance records | Yes |
| Added | Manifest `device` block: `os` (the browser's platform name, such as "Windows"), `app_version`, `plugins` and `mcp_programs` (both empty in this app) | Sent to the organisation's Fadenstack server, `PUT /v1/agents/{agent}/manifest`, when the agent starts | G | The console's list of devices per person | On; no one | Per the server's manifest records | Yes |
| Added | `arguments_sha256` (the SHA-256 of a tool call's arguments, not the arguments) in the `tool.executed` and `approval.decided` events | Sent to the organisation's Fadenstack server, `POST /v1/agents/{agent}/events`, when the server's profile asks for it (Fadenstack 0.7.1 does) | G | Ties the server's record of each call to the arguments that were checked | On with Fadenstack 0.7.1; no one | Per the server's event records | Yes |
| Changed | App manifest `version` | Sent to the organisation's Fadenstack server, `PUT /v1/agents/{agent}/manifest` | G | Now the app's own version; before, a fixed "0.1.5" | On; no one | Per the server's manifest records | No |

**Kept on the device: nothing new.** The SDK's record of tool calls (time, chat,
tool, decision and the arguments' hash) stays in the page's memory and is gone
when the page closes; the app opens no browser database for it. Chats and
approvals stay in memory as before.

**Not determined:** the SDK's other fields (its remaining events and approval
answers). The SDK has no "Data fields" section yet; the rows above were read from
its code. Its own rows that reach this app's users are added here when the app
takes the SDK's release pack.

## [0.1.16] - 2026-11-11

### Added

- Signed file origin metadata and Markdown front matter, prepared before the
  individual approval and recorded as the exact saved bytes in encrypted History.
- Publisher-pinned signature verification, hash-only signing, native readback and
  a file-mark status under successful writes. Copies retain origin metadata.
- Coherent published SDK 0.3.0-rc.2 provenance helpers; no SDK source changes.

### Fixed

- Compare copied files' cached and storage revisions against their own read-time
  values. Preserve guarded writes, content verification and native cache refresh.
- Refuse late marked-content substitution and changes to the marking policy
  while a proposal waits. Surface native lock errors as HTTP 423.

## [0.1.14] - 2026-11-07

### Added

- Experimental, default-off native Collectives, Forms, Notes and Bookmarks
  adapters using the current Nextcloud session and configured collection scope.
- Exact individual approvals, durable per-step Forms construction, retained
  partial drafts and truthful uncertain-submission receipts. These actions
  have an audit trail without certified Undo.
- Native content previews, protected editor handoff, child-only refresh and
  the shared working overlay and Stop control.

### Fixed

- Reserve chat space once around nested native Notes panels, preserving a
  usable rich editor beside the drawer.
- Require actual native CSRF validation on typed provider routes and refuse
  OCS exemption headers on those extension endpoints.

## [0.1.1] - 2026-10-26

### Changed

- Use one visible SDK toolbar, native body typography and canonical Fadenstack
  avatars, with menus contained within the sidebar.
- Show connection and file metadata in a native Assistant details dialog. Label
  context inclusion and freeze the displayed source for the running response.
- Prevent duplicate sign-in submissions and clear the submitted password.
- Patch Vite to 7.3.7 and follow-redirects to 1.16.2.

### Added

- UI regression coverage for details, sign-in, attachment inclusion and frozen
  context. Native permissions, write approvals and Stop keep their existing behavior.

## [0.1.0]

### Changed

- The chat is the Fadenstack SDK's Vue panel (`@fadenstack/ui-vue` 0.2.0-rc.2) instead of
  a copy of it, with Nextcloud's search cards, write approvals, context bar and account
  as its extensions, in the user's Nextcloud theme.
- The chat speaks English, German, French, Spanish and Simplified Chinese; the user picks
  the language in its settings (Automatic is their Nextcloud language), kept per user in
  the browser.
- Esc closes the panel's menus or stops an answer without also closing the drawer.
- A file operation Nextcloud refuses comes back in its own words ("The file is busy. Try
  again.") instead of "Request failed with status code 409", with a name for what failed
  (`NextcloudLocked`, `NextcloudConflict`, ...) that a write refused after its record was
  signed reports to the server as its failure. A file another app holds locked (Text
  while it is open) is answered 423 Locked instead of 409.
- An approved update brings Nextcloud's file cache up to date: the file's ETag, size and
  time change with the write, so sync clients fetch it and the agent's next read in the
  same chat sees the new text (before, it kept the old ETag and the agent was told the
  file was unchanged).

### Added

- First release
