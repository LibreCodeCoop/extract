<!--
 - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Extract release procedure (Nextcloud 33–35)

The shared [LibreCode Release Tool](https://github.com/LibreCodeCoop/release-tool)
coordinates versioning, release preparation, signed publication and verification.
The consumer contract is [`.nextcloud-release.yml`](../.nextcloud-release.yml).

## Stable branches

- `main` is the integration branch and currently supports Nextcloud 33–35.
- `stable33`, `stable34` and `stable35` are the intended maintained lines,
  each with matching `appinfo/info.xml` Nextcloud minimum and maximum.
- Keep the application version family aligned with the stable line:
  3.x, 4.x or 5.x respectively. Update the XML, npm manifest and npm lock
  together. Existing release tags must remain immutable.
- Nextcloud 33–35 share PHP 8.3–8.5 compatibility; Composer development
  uses `config.platform.php=8.3.16` without a runtime PHP `require`.

## Quality gates

- PHPUnit 12 on PHP 8.3, 8.4 and 8.5, with `7za` installed to avoid skipped
  archive tests, no notices, and no PHPUnit deprecations.
- Static analysis, coding standards, OpenAPI, JavaScript build and lint.
- Playwright browser/API tests against disposable Nextcloud instances.
  `main` checks NC33/34/35; stable branches check their corresponding major.
- A compiled, version-matched release archive checked by
  `make appstore && make verify-appstore-package`.
- Consumer validation using `release-tool config:validate`.

## Required repository secrets

The publisher requires `APP_PRIVATE_KEY` (matching the Extract app's own
public certificate) and `APPSTORE_TOKEN` (authorized for Extract).
Do **not** reuse another Nextcloud app's signing key or token.
The organization GitHub App is configured separately through
`WORKFLOW_SYNC_APP_PRIVATE_KEY`.

## Release lifecycle

1. Once a release line is reviewed and integrated, create its stable branch.
   Validate the branch version, its initial reachable tags and changelog.
2. Use **Actions → Prepare release** with the chosen stable branch and
   prerelease/final channel. Review and merge the generated preparation PR.
3. Verify package, Playwright matrix, signing credentials and the
   generated GitHub Release draft.
4. Publish the GitHub draft; the reusable publisher builds the exact tagged
   app, signs its archive, uploads the release asset and submits it to the
   Nextcloud App Store.
5. Verify the GitHub release asset, publisher workflow and the App Store
   publication independently.

The two human gates are deliberate: the release preparation PR merge and
the publication of the draft. An unsigned local package is **not** a release.
