<!--
 - SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Security Policy

## Supported versions

Only the latest release is supported with security updates. Older releases do
not receive backports; please upgrade to the newest version before reporting.

| Version | Supported |
|---------|-----------|
| Latest release | :white_check_mark: |
| Older releases | :x: |

## Supported platforms

| Component | Supported |
|-----------|-----------|
| Nextcloud | 33, 34, 35 |
| PHP | 8.1 – 8.x (`^8.1`; PHP 9 is not yet supported) |

## Reporting a vulnerability

Please do not open a public issue for a security problem.

Report it privately through GitHub's
[private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability):
open the **Security** tab of
[ashcoft/extract](https://github.com/ashcoft/extract/security) and choose
**Report a vulnerability**.

Please include:

- the affected version,
- the steps to reproduce,
- the impact you believe it has,
- and any proof-of-concept you can share.

## What to expect

- We aim to acknowledge a report within a few days.
- We will confirm whether the report is accepted and, if it is, keep you updated
  on the fix and the release that carries it.
- We will credit you in the advisory unless you prefer otherwise.

## Known advisories that cannot be fixed here

Dependabot reports two vulnerabilities that no change in this repository can
resolve. They are documented in
[`.github/dependabot.yml`](.github/dependabot.yml) and listed here so they are
not mistaken for oversights:

- **`undici` 6.29.0** (ReDoS in the WebSocket client) — reached only through
  `semantic-release → @semantic-release/npm → npm → node-gyp`. `npm` vendors
  this dependency inside its own tarball, so no `overrides` entry or lockfile
  edit reaches it, and both `npm` 11.21.1 and 12.2.1 still bundle 6.29.0. It is
  a development dependency and is never shipped.
- **`elliptic` 6.6.1** — a development dependency pulled in by the build
  toolchain. 6.6.1 is the newest published version, so no fixed release exists
  yet.

## Scope

This app runs inside a Nextcloud instance. Vulnerabilities in Nextcloud itself
should be reported to the
[Nextcloud security team](https://nextcloud.com/security/) instead.
