# Releasing

## A release on GitHub

1. Set the version everywhere: `node scripts/version.mjs 0.2.0-beta.1` (package.json, its lock, `appinfo/info.xml` and
   `src/version.ts`; `node scripts/version.mjs --check` says whether they agree). In `CHANGELOG.md`, turn
   "Unreleased" into `## [0.2.0-beta.1] - <date>`.
2. Commit, tag `v0.2.0-beta.1` on that commit and push the tag. The Release workflow (`.github/workflows/release.yml`):
   - checks that the tag names the version, runs the checks and tests, builds, prepares the production PHP
     dependencies and packages `fadenstack_agent-<version>.tar.gz`;
   - makes the GitHub release with it (a pre-release when the version has a suffix);
   - runs core's shared supply-chain workflow, pinned by commit: the SBOM (CycloneDX, BSI TR-03183-2), the OSV scan
     with OpenVEX and CSAF (`.vex/openvex.json` holds this repository's statements), the OpenSSF Scorecard, a keyless
     cosign signature and GitHub's build provenance and SBOM attestations, all added to the release. A high-severity
     finding without a VEX statement stops it.

   Run by hand (Actions → Release → Run workflow), the workflow is a dry run: the checks, the package, the SBOM, the
   scan and the Scorecard, in the run's summary; nothing is signed, attested or released.
3. Check the release's package: `gh attestation verify fadenstack_agent-<version>.tar.gz --repo
   fadenstack/faden-agent-nextcloud`.

## The Nextcloud App Store

Only the holder of the app's private key does this, on the owner's go. The key (`fadenstack_agent.key`) and the
certificate Nextcloud issued for it (`fadenstack_agent.crt`) never enter this repository, GitHub or CI.

4. Unpack the release's package. On a Nextcloud server that has the app's certificate, sign the app's code:
   `occ integrity:sign-app --privateKey=<path>/fadenstack_agent.key --certificate=<path>/fadenstack_agent.crt
   --path=<unpacked>/fadenstack_agent`. It writes `appinfo/signature.json`. Pack the folder again with
   `fadenstack_agent/` at the archive's root, as `fadenstack_agent-<version>-signed.tar.gz`.
5. Sign that file for the store: `openssl dgst -sha512 -sign <path>/fadenstack_agent.key
   fadenstack_agent-<version>-signed.tar.gz | openssl base64 -A`.
6. Add the signed package to the GitHub release, and say in the release notes that it is the release's package plus
   `appinfo/signature.json`, with its SHA-256.
7. On apps.nextcloud.com, under **Upload app release**, give its download address and the signature from step 5. A
   version with a suffix is a pre-release in the store too.
