<!--
 - SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Extract

[![Latest Release](https://img.shields.io/github/v/release/ashcoft/extract)](https://github.com/ashcoft/extract/releases)
[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue.svg)](COPYING)
[![Nextcloud Version](https://img.shields.io/badge/Nextcloud-33--35-blue)](https://nextcloud.com)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-purple)](https://php.net)

Extract archives from the Nextcloud web interface. Pick an archive in the Files
app, choose **Extract here**, and the app unpacks it into a new folder next to
the archive.

## ✨ Features

- 🗜️ **Extract in place** from the Files app file action, no shell access needed
- 🗂️ **Multiple formats**: Zip, Rar, Tar, Tar.gz, Tar.bz2, Gzip, 7z and Deb
- 📁 **Works with external storage**, including group folders
- 🔒 **Respects Nextcloud file permissions** and removes blacklisted file names
- 🧹 **Cleans up** a temporary directory left behind by an interrupted run
- 🔌 **Capabilities API** so clients can discover the supported archive types
- 🌍 **Translated** through the Nextcloud translation pipeline
- 🧪 **Unit tested** and compatible with Nextcloud 33-35

## 📋 Requirements

| Component | Version |
|-----------|---------|
| Nextcloud | 33-35 |
| PHP       | 8.1+ |
| Node.js   | 24+ (development only) |
| npm       | 10.5+ (development only) |

### Archive tooling

| Tool | Purpose | Install |
|------|---------|---------|
| PHP `zip` extension | Zip archives | bundled with most PHP builds |
| PHP `rar` extension **or** `unrar` | Rar archives | `pecl install rar` **or** `apt-get install unrar` |
| `p7zip` / `p7zip-full` | Tar, Gzip, Bzip2, 7z, Deb | `apt-get install p7zip p7zip-full` |

The `zip` PHP extension is required for Zip files. Rar needs either the PHP
`rar` extension or the `unrar` binary; every other format is handled by the
`7za` binary shipped with p7zip.

#### Ubuntu / Debian

```bash
sudo apt-get install p7zip p7zip-full unrar
```

#### Fedora / RHEL / CentOS

```bash
sudo dnf install p7zip p7zip-plugins unrar
```

#### macOS

```bash
brew install p7zip
```

## 📦 Installation

> **Note:** Extract is not listed in the Nextcloud App Store. Install it from a
> release archive or from source as described below.

### From a release archive (recommended)

1. Download the latest `extract.tar.gz` (or `extract.zip`) from the
   [releases page](https://github.com/ashcoft/extract/releases).
2. Extract it into your Nextcloud `apps` directory, using the command that
   matches the archive you downloaded:
   ```bash
   # If you downloaded extract.tar.gz:
   tar -xzf extract.tar.gz -C /path/to/nextcloud/apps

   # If you downloaded extract.zip:
   unzip extract.zip -d /path/to/nextcloud/apps

   chown -R www-data:www-data /path/to/nextcloud/apps/extract
   ```
   The archive already contains the built frontend assets, so no Node.js step is
   needed.
3. Enable the app:
   ```bash
   occ app:enable extract
   ```

### From source

1. Clone the repository into your Nextcloud `apps` directory:
   ```bash
   cd /path/to/nextcloud/apps
   git clone https://github.com/ashcoft/extract.git extract
   ```

2. Set the permissions:
   ```bash
   chown -R www-data:www-data extract
   ```

3. Install the PHP and frontend dependencies and build the assets:
   ```bash
   cd extract
   composer install
   npm ci
   npm run build
   ```

4. Enable the app:
   ```bash
   occ app:enable extract
   ```
   Or go to **Settings** → **Apps**, find "Extract" in the disabled apps section
   and click **Enable**.

## 🚀 Usage

1. Open the **Files** app and navigate to an archive.
2. Right-click the archive and choose **Extract here**.
3. The app unpacks the archive into a new folder named after the archive, in the
   same directory.

The action is only offered when you have permission to change the file, and only
for archives whose MIME type the app can extract.

### Supported formats

| Format | Extensions | Extraction backend |
|--------|------------|--------------------|
| Zip | `.zip` | PHP `zip` extension |
| Rar | `.rar` | PHP `rar` extension or `unrar` |
| Tar | `.tar` | `7za` |
| Tar + Gzip | `.tar.gz`, `.tgz` | `7za` |
| Tar + Bzip2 | `.tar.bz2`, `.tbz2` | `7za` |
| Gzip | `.gz` | `7za` |
| 7z | `.7z` | `7za` |
| Deb | `.deb` | `7za` |

> **Note:** Encrypted archives and Nextcloud's server-side encryption module are
> not supported yet. When server-side encryption is enabled the app refuses to
> extract and returns a message instead.

### External storage

Archives on an external storage are unpacked through a temporary folder in the
app's own data directory, then moved into place. If a previous run was
interrupted, that temporary folder is removed before the new extraction starts.

## 🛠️ Development

### Prerequisites

- Node.js 24+
- npm 10.5+
- PHP 8.1+ with the `zip` extension
- Nextcloud 33-35 development environment

### Setup

```bash
git clone https://github.com/ashcoft/extract.git
cd extract
composer install
npm ci
```

### Build commands

```bash
npm run dev               # development build
npm run build             # production build
npm run watch             # rebuild on change
npm run lint              # ESLint
npm run lint:fix          # ESLint with autofix
npm run stylelint         # Stylelint
npm run typescript:check  # tsc --noEmit
npm run typescript:generate  # regenerate OpenAPI types from openapi.json
```

The `Makefile` wraps the common flows:

```bash
make dev-setup          # clean, install and build the production bundle
make production-setup   # same as dev-setup
make appstore           # build the installable extract.tar.gz and extract.zip
make test               # run the PHPUnit suite and the frontend linter
make lint               # run the PHP and frontend linters
make dist               # build the source package and the app-store archives
```

`make appstore` stages only the runtime files, then writes both
`build/artifacts/extract.tar.gz` and `build/artifacts/extract.zip`. It packages
whatever is in `js/`, so run `make build` first on a fresh checkout (or use
`make dist`, which builds the source package too). Both archives are attached to
every GitHub release.

## 🧪 Testing

Run the PHPUnit suite:

```bash
composer run test:unit     # phpunit -c tests/phpunit.xml
```

Static analysis and linting:

```bash
composer run lint          # php -l over every PHP file
composer run cs:check      # php-cs-fixer --dry-run
composer run psalm         # Psalm
npm run lint               # ESLint
npm run typescript:check   # TypeScript
```

The PHPUnit suite covers the extraction service, the capabilities endpoint and
the mapping between Nextcloud's archive MIME types and the extraction strategy.

## 🚢 Releases

Releases are automated. On every push to `main` the `Release` workflow:

1. runs [semantic-release](https://semantic-release.gitbook.io/) to decide the
   next version from the commit messages,
2. bumps `appinfo/info.xml` and `package.json`, commits the change and creates
   the tag,
3. builds `extract.tar.gz` and `extract.zip` from the tagged commit,
4. uploads both archives to the GitHub release and publishes it.

Because the version comes from the tags, `appinfo/info.xml` must never be ahead
of the latest tag. If it is, the workflow stops and tells you to tag the current
commit so the next release continues from the declared version.

## 🐛 Troubleshooting

### The "Extract here" action is missing

1. Confirm the Extract app is enabled in **Settings** → **Apps**.
2. Check that the file is one of the [supported formats](#supported-formats).
3. Make sure you have permission to change the file; the action needs update
   rights.
4. Review the Nextcloud log at `data/nextcloud.log`.

### "Oops something went wrong."

The archive tool could not unpack the file. Common causes:

- **Tool missing**: for Rar archives install the PHP `rar` extension or the
  `unrar` binary; for every other format install `p7zip` and `p7zip-full` and
  check that `7za` is on the `PATH` of the web server user.
- **Damaged or unreadable archive**: the file is truncated, corrupt, or not
  actually an archive of that format.
- **Permissions**: the web server user cannot read the archive or write into the
  target folder.
- **Insufficient space** on the volume holding the archive.

For a failed `7za` or `unrar` run the Nextcloud log records the exit code and the
tool output. Zip extraction and a missing tool are not logged with that detail.

### "Encryption is not supported yet"

The app cannot extract while Nextcloud's server-side encryption module is
enabled.

### Large archives

Extraction happens on the server, so give the PHP process enough memory and
time. Very large archives may need a higher `memory_limit` and
`max_execution_time`.

## 🤝 Contributing

Contributions are welcome.

1. **Fork** the repository.
2. **Create** a branch for your change.
3. **Make** your change and add or update tests.
4. **Run** the checks: `composer run test:unit && composer run lint && npm run lint`.
5. **Commit** with a [conventional commit](https://www.conventionalcommits.org/)
   message, for example `fix: handle empty archives`.
6. **Push** the branch and open a Pull Request.

The commit type drives the release:

| Commit | Release |
|--------|---------|
| breaking change (`feat!`, `BREAKING CHANGE`) | major |
| `feat` | minor |
| `perf` | minor |
| `fix(deps)` | minor |
| `fix` (any other scope) | patch |
| `chore`, `docs`, `test`, `ci`, `refactor`, `style`, `build`, `revert` | none |

So a dependency fix bumps the minor version, while an ordinary bug fix bumps the
patch version.

## 📄 License

This project is licensed under the GNU Affero General Public License v3.0 or
later - see the [COPYING](COPYING) file for details.

## 🔗 Links

- **Repository**: https://github.com/ashcoft/extract
- **Releases**: https://github.com/ashcoft/extract/releases
- **Upstream**: https://github.com/LibreCodeCoop/extract
- **Original author**: Paul Lereverend
