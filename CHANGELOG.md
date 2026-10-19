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

### Added

- Files the agent creates or replaces are marked as AI-generated (EU AI Act Art. 50(2))
  through `@fadenstack/provenance` 0.2.0-rc.3: the server's signed record as Nextcloud file
  metadata `fadenstack-provenance`, copied to copies; Markdown also carries it in YAML front
  matter; a line under each write says how the file is marked.
- First release
