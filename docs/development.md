# Development

The [official Nextcloud development environment guide](https://docs.nextcloud.com/server/stable/developer_manual/getting_started/devenv.html)
recommends Docker and also explains a manual source checkout. This repo uses
official images, a mounted app, separate named volumes and localhost-only ports.
`dev/nextcloud.config.php` enables debug and disables App Store writes. That
configuration is excluded from the installable package; production must not use it.
Development Apache disables caching for this app's assets so rebuilding and
reloading picks up changes; this override is also excluded from the package.
PHP development settings also revalidate modified source on each request instead
of the official image's 60-second OPcache delay.

Requirements: Node 24, npm 11, Docker Compose; Composer and PHP 8.3+ for PHP tests
(the development OCP 35 stubs require 8.3; app code supports PHP 8.2 on Nextcloud 31).
Nextcloud itself uses the supported PHP version in its official image.

1. Run `npm ci`, `npm run build`.
2. Run `docker compose up -d nextcloud35`; wait for `occ status` to report installed.
3. After an interrupted initialization, run `docker compose exec -u www-data
   nextcloud35 php occ maintenance:install --database sqlite --database-name nextcloud
   --admin-user pilot-admin --admin-pass local-pilot-only`.
4. Run `node scripts/setup-dev.mjs nextcloud35` to enable the app and create fixtures.
   Configure your actual Fadenstack server in administration settings.
5. Run `npm run test:integration`. Unique test files and a read-only share remain
   available for inspection. The test refuses remote hosts.
6. Use `npm run watch`, then reload Files after changes.
7. Run `composer install`, `composer test:unit`, `composer cs:check` for PHP checks.

For 31, use service `nextcloud` and port 8088. Do not attach a 35 image to a 31
volume. Avoid `docker compose down -v` unless intentionally discarding dev data.

Frontend tests use `@fadenstack/testing` with actual SDK classes. HTTP tests log in
through real Nextcloud middleware and check actual nodes/permissions, CSRF, context
isolation, read-only shares and conflicts. Neither substitutes for the live model
acceptance scenario. CI checks Node/PHP, both HTTP versions and package generation.

`tests/integration/network.mjs` checks the real deployment's HTTPS/CORS/CSRF
behavior without credentials. For the full model workflow, use Node 24:

```sh
node --experimental-transform-types tests/integration/live.mjs
```

Provide `FADENSTACK_USERNAME` and `FADENSTACK_PASSWORD` only in the process
environment, or supply a JSON line on an interactive stdin. Configure
`NODE_EXTRA_CA_CERTS` with an independently verified public CA when needed. The
test refuses remote Nextcloud hosts, signs in through actual Nextcloud middleware,
uses the production host/tools/approval classes and the published SDK, and changes
only the PDF.js platform entry point to its maintained Node build. It reads the
selected PDF fixture (default ID 73), validates a real source-linked summary,
allows one exact new Markdown proposal, and reads back the actual saved file.
Artifacts in ignored `build/live` contain no tokens or passwords. It allows ten
minutes per turn by default; `FADENSTACK_TURN_TIMEOUT_MS` changes that limit.
Browser checks additionally cover the browser PDF worker and actual Vue UI.

## AI marks on written files

Run `node --experimental-transform-types tests/integration/provenance.mjs` on each
local instance. It drives the production host, tools, approvals and provenance
module with the SDK's fake gateway as the model and record signer, creates a
Markdown note and a text file and replaces the note, then checks the file metadata
and front matter through rename, move, copy, a folder copy, a new version, shares
and the trash. With `FADENSTACK_PROVENANCE_SERVER`, `FADENSTACK_PROVENANCE_AGENT`
and `FADENSTACK_PROVENANCE_TOKEN` (an agent's or a maintainer's token, environment
only) that server signs the records and its detector's verdict on the note is
reported. Text's own save is checked in a browser; the shared front matter vectors
in `tests/fixtures/provenance` keep the PHP check and the SDK's marker in step.

## Search release tests and representative data

Run `node --experimental-transform-types tests/integration/search.mjs` and
`node --experimental-transform-types tests/integration/search-security.mjs` on
each local instance. The first checks native discovery, PDF passages, cache
invalidation and missing-index reporting; the second shares and revokes synthetic
data, checks signed cursors, and exercises approved nested creation and races.
CI runs both on Nextcloud 31 and 35 without real Fadenstack credentials.

`node scripts/seed-search.mjs` creates a named synthetic folder with two dated
supplier contracts. They deliberately differ: the current October contract has
30 days' termination notice; the superseded September contract has 90 days.
Only the named fixture files are uploaded; no user collection is crawled. The
local-only test client refuses a remote Nextcloud destination.

Run `node --experimental-transform-types tests/integration/live-search.mjs` with
the same ephemeral credential/CA mechanism above for the real published-agent
discovery → supporting passage → individually approved current-folder Markdown
demonstration. It uses production SDK/host services, validates each write preview,
checks server approval before commit and reads back the resulting file. Credential
values are never written to its `build/live-search` artifacts. Test approvals are
automatic only in this explicit integration runner; the product always displays
the individual write card.

Set `FADENSTACK_SCENARIO=deny` with the ID of a local fixture in
`NEXTCLOUD_FILE_ID` for a real model write-denial test. It rejects the exact
proposal, stops follow-on model work and checks that WebDAV returns 404.
