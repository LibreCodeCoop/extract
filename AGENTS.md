<!--
 - SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Agent notes for `extract`

## Branching and releases — read this first

**Never commit or push directly to `main`.** Every change must go through a pull
request, without exception and regardless of how small the change looks.

Required workflow:

1. Branch off `main` (for example `fix/<short-description>`).
2. Commit there.
3. Push the branch and open a pull request against `main`.
4. Let the pull request be reviewed and merged through the normal process.

Do not merge a change yourself, and do not push commits straight to `main`.

Why this matters here: pushing to `main` triggers the automated Release workflow
(`.github/workflows/release.yml`). For a push whose commits are release-worthy,
that workflow runs semantic-release, bumps the version, creates a tag and
publishes a GitHub release, so the change ships with no review step and cannot
be undone cleanly once the tag and release exist.

Not every push releases. `release.config.cjs` marks maintenance commits
(`chore`, `docs`, `ci`, `refactor`, `style`, `test`, `build`, `revert`) as
no-release, and the job is skipped entirely when the head commit message
contains `skip release`. A direct push of such a change produces no release, but
it still lands on `main` without review — which is the reason for the rule.

Also do not rewrite published history (no force-push, no rebasing `main`) to
undo a change that already landed. If a change reached `main` by mistake, say so
and ask how to proceed.

## Repository layout

- `lib/Controller/ExtractionController.php` — OCS endpoint that drives extraction.
- `lib/Service/ExtractionService.php` — wraps the `zip`/`rar`/`7za` tooling.
- `src/actions/extract-action.ts` — the "Extract here" file action.
- `tests/unit/` — PHPUnit suite.

## Checks to run before opening a pull request

```bash
composer run test:unit     # phpunit -c tests/phpunit.xml
composer run cs:check      # php-cs-fixer --dry-run
composer run psalm         # static analysis
composer run lint          # php -l over every PHP file
npm run lint               # ESLint (needs npm ci first)
```

The unit suite needs PHP 8.1 through 8.x (`composer.json` requires `^8.1`) with
the `zip` and `zlib` extensions. `zlib` is required because
`WrappedTarballExtractionTest` builds its gzip fixtures with `Phar::GZ`. The 7z
and tarball tests also need `7za` on the `PATH`, and skip themselves when the
binary is absent.

## Style

- PHP files use tabs, `declare(strict_types=1)`, and the Nextcloud coding
  standard enforced by `composer run cs:check`.
- Write comments only where the code cannot speak for itself; do not narrate the
  change or restate the code.
- Every file needs REUSE copyright and licence information. `reuse` accepts an
  SPDX comment block at the top of the file, or an entry in `REUSE.toml`. A file
  with neither fails the `reuse-compliance-check` job.
