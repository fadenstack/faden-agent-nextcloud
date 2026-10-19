# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

- Files the agent creates or replaces are marked as AI-generated (EU AI Act Art. 50(2))
  through `@fadenstack/provenance` 0.2.0-rc.3: the server's signed record as Nextcloud file
  metadata `fadenstack-provenance`, copied to copies; Markdown also carries it in YAML front
  matter; a line under each write says how the file is marked.
- First release
