# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

### Added

- First release
