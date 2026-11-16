# Security policy

## Reporting a vulnerability

Please tell us privately first. Do not report a security problem in a public issue, pull request or discussion.

Report it in one of these ways:

- **By e-mail:** <security@fadenstack.com>.
- **Through our contact form:** <https://emagin8.de/contact?subject=Fadenstack%20security>, with the subject
  "Fadenstack security".

Describe what you found and how to reproduce it:

- the app's version (Nextcloud's **Apps** page shows it) and your Nextcloud version;
- whether the app was connected to a Fadenstack server, and that server's version;
- the steps, the requests or a small proof of concept;
- what an attacker gains, and what they need first (a Nextcloud account, an admin role, access to the network).

English and German are both fine.

## What happens next

We confirm that we received your report and keep you informed while we fix it. A fix ships in a new release of
the app. We then publish a security advisory that describes the problem and names you, if you want to be named.
Please keep the details to yourself until that release is out.

## Please don't

- test against Nextcloud or Fadenstack installations you do not own or are not allowed to test;
- access, change or delete data that isn't yours;
- disrupt a service (no denial-of-service testing).

## Supported versions

Each release line gets security fixes, free of charge, for five years from its release.

| Version | Security fixes |
| --- | --- |
| 0.2 (preview, released December 2026) | Yes, until 8 December 2031 |
| 0.1 (pilot builds, never released) | No |

Fixes ship as new releases of the app. Keep the app current through Nextcloud's **Apps** page.

## Scope

This policy covers this repository and the Nextcloud app released from it, "Fadenstack Agent" (`fadenstack_agent`).
Report a problem in the Fadenstack server or the Fadenstack SDKs the same way.

This app is not made by Nextcloud GmbH. Report a problem in Nextcloud itself, or in another Nextcloud app, to
Nextcloud's security team: <https://nextcloud.com/security/>.

Our policy page on the website says the same: <https://fadenstack.com/security/#report>. The machine-readable
contact is <https://fadenstack.com/.well-known/security.txt>.
