# Pilot verification — 13 October 2026

This is an installable development pilot. The mandatory real-server PDF → model
summary → individually approved Markdown scenario passed in the Nextcloud 35
browser drawer. Remaining browser/storage coverage is documented separately.

## Tested environments

| Component | Actual version/configuration |
| --- | --- |
| Primary Nextcloud | 35.0.1, official 35-apache image, PHP 8.5.11, SQLite |
| Compatibility Nextcloud | 31.0.14, official 31-apache image, PHP 8.3.30, SQLite |
| Browser | Chrome 154 on Windows |
| Fadenstack SDK | client/agents/ui/testing 0.2.0-rc.1 |
| Frontend | Vue 3.5.42, Nextcloud Vue 9.13.x, Vite 7.3.6, Node 24.21.0 |
| Supplied Fadenstack pilot | https://10.250.10.61, nextcloud-agent v1, qwen3.8-27b-fp8 |

The remote Fadenstack deployment was independently inspected over verified SSH:
0.4.4rc2 before its hardware interruption, 0.4.4 after recovery, nginx 1.31.6.
The published agent was inspected in its console; no manual tool schema was
entered. The SDK reports all five tools automatically.

## Automated checks

- TypeScript, ESLint, Stylelint and clean production Vite build passed.
- 21 Vitest tests passed using actual SDK classes and FakeGateway: lazy context,
  excluded chips, frozen turn context, tool manifest discovery, published policy,
  write approval/denial, changed identities, revocation, memory isolation and input
  validation.
- The Vue composer test mounts actual components, relocates the live Vue root
  into a Viewer modal, and confirms Send/Enter,
  Shift+Enter, a waiting write card, and the individual Allow click reaching the
  production host and real SDK. The licensed SDK CSS/layout is adapted to Vue;
  Nextcloud theme tokens replace browser colors, without a React dependency.
- Viewer bridge tests cover visible document IDs, legacy URL IDs, unknown IDs,
  one titlebar toggle, the public focus-trap event and drawer relocation on close.
- PHPUnit: 10 tests, 12 assertions passed on PHP 8.5.11.
- 38 real HTTP checks passed separately on Nextcloud 35 and 31: direct navigation,
  actual login and
  CSRF middleware, selection/read/list, per-user capabilities, approved creation,
  one-use proposals, updates, unsafe names, no-overwrite behavior, denied writes,
  real concurrent WebDAV ETag changes, bounded extraction, read-only shares and
  second-user isolation.
- Composer strict validation and PHP coding-style dry run passed.
- Production npm audit: zero vulnerabilities. Developer-tool transitive advisories
  remain documented separately; no forced incompatible tooling downgrade.
- A fresh isolated Nextcloud 35 Docker volume initialized automatically and enabled
  the app successfully. The temporary container is stopped; its test volume is
  retained. The repeatable CI workflow is added but has not run remotely.
- The packaged app also passed all 38 HTTP checks in an isolated Nextcloud 35
  container with no `vendor` or `node_modules` directory. That container is stopped.

## Browser checks

On Nextcloud 35, the header mascot and app-menu entry open an independent native
right drawer without changing the Files URL. No assistant tab is added to file
details. The selected PDF checkbox produces a composer chip and source link.
The SDK reads file bytes only when the included chip is sent. The composer stays
visible while the conversation scrolls; background identity checks retain controls.

A loopback-only browser simulation exercised the **real SDK and real Nextcloud**:
sign-in, PDF.js extraction of “Reasons to use Nextcloud.pdf”, streamed response,
full target/content approval card, approved Markdown creation, and opening the
returned file URL in Nextcloud Text. The resulting file is
`browser-smoke-1791844141763.md` (file ID 297 in this development instance).
Its text explicitly identifies it as a simulation. This test proves the host
integration and approval path; it does not prove a live model's summary quality.

The Nextcloud 31 header drawer and public **Ask Fadenstack** file action were also
verified without navigating away from Files. Full browser model testing was on 35.
Light/dark support uses native Nextcloud variables; both themes were inspected in
the real connected drawer and screenshots saved. The original system-default user
theme was restored. A formal accessibility audit and the remaining browser/storage
matrix are pending.

The Nextcloud 35 Text full-screen Viewer titlebar now exposes the same mascot
toggle. In the live editor it opened the drawer, signed in against the real server,
and attached the open saved Markdown (file 317), with its exact source link.
Desktop geometry leaves the editor alongside the drawer; below the desktop
breakpoint it uses an overlay. Browser sizing was restored after both checks.
The context is saved content, not unsaved editor buffers; the composer states this.
An extra editor-specific model request could not be submitted reliably by browser
automation and is not claimed as a completed model test. The completed live
PDF/model/write acceptance below remains the end-to-end evidence.

## Completed real-server browser acceptance

The browser drawer signed in directly to `https://10.250.10.61` as the supplied
pilot user through the published SDK. Selecting **Reasons to use Nextcloud.pdf**
(file 73) added an included composer attachment; PDF.js extracted its text in the
browser. The real `qwen3.8-27b-fp8` model produced five substantive bullet points
and a Nextcloud source link. The summary turn took about 133 seconds.

A follow-up requested `nextcloud-live-browser-summary-20261013.md`. The drawer
displayed the exact `/` target and complete Markdown, then required **Allow once**.
After that click the real Nextcloud operation created file **317**. The returned
link opened the saved Markdown in Nextcloud Text, showing all five points and the
PDF source link. WebDAV read-back independently confirmed the stored content.
This acceptance uses the real server/model and is separate from the earlier
simulation described above.

Twenty-two real HTTPS/CORS/CSRF checks passed after the server recovered and the
scoped edge policy was reapplied. Certificate and hostname validation remained
enabled. The trusted public CA was verified over existing authenticated SSH;
no private key or real credential was committed or packaged. See
[network deployment](pilot-network.md) for configuration, fingerprint and rollback.

The [screenshot gallery](screenshots.md) captures the real PDF summary, full
approval card, completed write, saved Markdown and full-screen editor drawer. Source and
result artifacts are in ignored `build/live`. `tests/integration/live.mjs` provides
a repeatable real SDK/Nextcloud test, using the maintained Node PDF.js entry point
for that platform. Browser testing additionally verifies the shipped PDF worker.

The real SDK denial scenario also passed: the model requested `nc_create_text_file`,
the exact pending proposal was denied, no commit occurred, and authenticated WebDAV
returned 404 for that target. `build/live/denial.json` records the result.

The complete repeatable CLI acceptance also passed, creating file **334**:
`live-nextcloud-summary-2026-10-13T07-51-24-155Z.md`. It verified the PDF summary,
source link, exact one-use creation proposal, stored contents and authenticated
result-link redirects. `build/live/result.json` and the Markdown artifacts record
the run. Initial test-harness issues with nested approval notifications and SPA
redirect assertions were corrected; the final complete run exited successfully.

Both development instances are restored to the supplied HTTPS origin and
`nextcloud-agent`, with insecure HTTP disabled. The simulation server is stopped.
No real Fadenstack credentials are persisted in the app, package or test fixtures.
