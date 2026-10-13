# License review

The official Nextcloud template and app retain AGPL-3.0-or-later, with the original
LICENSE included. Nextcloud dependencies retain their original terms. Published
Fadenstack SDK 0.2.0-rc.1 packages remain Apache-2.0; PDF.js is Apache-2.0, Vue is MIT,
DOMPurify is dual Apache-2.0/MPL-2.0.

Builds emit `.mjs.license` attribution summaries. Packaging preserves original
production dependency license/notice files in `third-party`, including SDK NOTICE,
with a dependency inventory. The archive includes app source and build manifests;
provide recipients the corresponding source/build materials for applicable source
obligations. Review notices and source availability before public distribution.
This pilot does not publish to the App Store or change upstream licenses.

The Vue chat skin adapts the layout/CSS and Material icon paths of
`@fadenstack/ui-react` from the sibling SDK. It does not load React. The original
Apache-2.0 LICENSE and NOTICE are preserved in `licenses/`, including Google's
Material Icons attribution. `src/styles/fadenstack.css` is the unmodified SDK
stylesheet; `src/styles/nextcloud.css` maps its tokens to Nextcloud themes.
