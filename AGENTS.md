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
(`.github/workflows/release.yml`). It runs semantic-release, bumps the version,
creates a tag and publishes a GitHub release. A direct push therefore ships a
release immediately, with no review step, and it cannot be undone cleanly once
the tag and release exist.

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

The unit suite needs PHP 8.1+ with the `zip` extension, plus `7za` on the `PATH`
for the 7z and tarball tests, which skip themselves when the binary is absent.

## Style

- PHP files use tabs, `declare(strict_types=1)`, and the Nextcloud coding
  standard enforced by `composer run cs:check`.
- Write comments only where the code cannot speak for itself; do not narrate the
  change or restate the code.
