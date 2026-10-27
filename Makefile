# SPDX-FileCopyrightText: 2016 Bernhard Posselt <dev@bernhard-posselt.com>
# SPDX-License-Identifier: AGPL-3.0-or-later

# Generic Makefile for building and packaging a Nextcloud app which uses npm
# and Composer.
#
# Dependencies:
# * make
# * which
# * tar: for building the archives
# * zip: optional, for the additional .zip archive
# * rsync: for staging the app-store package
# * node/npm: for building the frontend
# * php/composer: for the PHP dependencies and tests

app_name=$(notdir $(CURDIR))
app_id=$(shell sed -n 's/.*<id>\(.*\)<\/id>.*/\1/p' $(CURDIR)/appinfo/info.xml | head -1)
build_tools_directory=$(CURDIR)/build/tools
source_build_directory=$(CURDIR)/build/artifacts/source
source_package_name=$(source_build_directory)/$(app_name)
appstore_build_directory=$(CURDIR)/build/artifacts
app_dir=$(appstore_build_directory)/$(app_id)
npm=$(shell which npm 2> /dev/null)
composer=$(shell which composer 2> /dev/null)

all: build

# === Version bumping (used by semantic-release) ===
# Usage: make bump-version VERSION=x.x.x
.PHONY: bump-version
bump-version: bump-info-xml bump-package-json
	echo "Version bumped to $(VERSION)"

.PHONY: bump-info-xml
bump-info-xml:
ifndef VERSION
	$(error VERSION is undefined. Usage: make bump-version VERSION=x.x.x)
endif
	echo "Bumping appinfo/info.xml version to $(VERSION)"
	sed -i "s|<version>[^<]*</version>|<version>$(VERSION)</version>|" appinfo/info.xml
	NEW_VERSION=$$(sed -n 's/.*<version>\(.*\)<\/version>.*/\1/p' appinfo/info.xml | head -n 1 | xargs); \
	if [ "$$NEW_VERSION" != "$(VERSION)" ]; then \
	echo "ERROR: Failed to update appinfo/info.xml. Expected $(VERSION), got $$NEW_VERSION"; \
	exit 1; \
	fi
	echo "appinfo/info.xml version updated to $(VERSION)"

.PHONY: bump-package-json
bump-package-json:
ifndef VERSION
	$(error VERSION is undefined. Usage: make bump-version VERSION=x.x.x)
endif
	echo "Bumping package.json version to $(VERSION)"
	node -e "const pkg=require('./package.json'); pkg.version='$(VERSION)'; require('fs').writeFileSync('package.json', JSON.stringify(pkg, null, 2)+'\n');"
	NEW_VERSION=$$(node -p "require('./package.json').version"); \
	if [ "$$NEW_VERSION" != "$(VERSION)" ]; then \
	echo "ERROR: Failed to update package.json. Expected $(VERSION), got $$NEW_VERSION"; \
	exit 1; \
	fi
	echo "package.json version updated to $(VERSION)"

# === Build targets ===
.PHONY: build
build:
	npm run build

# Alias kept so existing workflows keep working
.PHONY: npm
npm: build

.PHONY: install
install:
	npm ci

.PHONY: dev-setup
dev-setup: clean install build

.PHONY: production-setup
production-setup: clean install build

# Removes the build directory
.PHONY: clean
clean:
	rm -rf ./build

# Same as clean but also removes composer and npm dependencies
.PHONY: distclean
distclean: clean
	rm -rf vendor vendor-bin/*/vendor node_modules js

# Builds the source and app-store package
.PHONY: dist
dist:
	make source
	make appstore

# Builds the source package
.PHONY: source
source:
	rm -rf $(source_build_directory)
	mkdir -p $(source_build_directory)
	tar cvz --exclude-vcs \
	--exclude="../$(app_name)/build" \
	--exclude="../$(app_name)/js/node_modules" \
	--exclude="../$(app_name)/node_modules" \
	--exclude="../$(app_name)/*.log" \
	--exclude="../$(app_name)/js/*.log" \
	-f $(source_package_name).tar.gz ../$(app_name) \

# Builds the app-store archives (.tar.gz and .zip).
#
# Only the files Nextcloud needs at runtime are staged. Nextcloud autoloads
# lib/ itself, so neither vendor/ nor vendor-bin/ belongs in the archive; the
# built frontend lives in js/ and is produced by `make build` beforehand.
.PHONY: appstore
appstore:
	rm -rf $(app_dir)
	mkdir -p $(app_dir)
	rsync -a \
	--include='CHANGELOG.md' --include='COPYING' \
	--include='l10n/' --include='l10n/**' \
	--exclude='.*' \
	--exclude='*.md' \
	--exclude='build' \
	--exclude='node_modules' \
	--exclude='src' \
	--exclude='tests' \
	--exclude='vendor' \
	--exclude='vendor-bin' \
	--exclude='LICENSES' \
	--exclude='*.log' \
	--exclude='*.tar.gz' \
	--exclude='*.zip' \
	--exclude='composer.json' \
	--exclude='composer.lock' \
	--exclude='package.json' \
	--exclude='package-lock.json' \
	--exclude='Makefile' \
	--exclude='psalm.xml' \
	--exclude='tsconfig.json' \
	--exclude='phpunit*.xml' \
	--exclude='vite.config.js' \
	--exclude='eslint.config.mjs' \
	--exclude='stylelint.config.cjs' \
	--exclude='.php-cs-fixer.dist.php' \
	--exclude='release.config.cjs' \
	--exclude='openapi.json' \
	. $(app_dir)/
	cd $(appstore_build_directory) && tar -czf $(app_id).tar.gz $(app_id)
	command -v zip >/dev/null 2>&1 && \
	{ cd $(appstore_build_directory) && zip -rq $(app_id).zip $(app_id); } || \
	echo "Warning: zip not installed, skipping zip archive"
	echo "Built $(appstore_build_directory)/$(app_id).tar.gz and $(app_id).zip"

# === Tests and checks ===
.PHONY: test
test:
	composer run test:unit
	npm run lint

.PHONY: lint
lint:
	composer run lint
	composer run cs:check
	npm run lint

check-version:
	if [ "${version}" = "" ]; then\
	echo "Error: You must set version, eg. make -e version=v0.0.1 appstore";\
	exit 1;\
	fi

