/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Release policy: features, release-worthy fixes and bug fixes cut a release.
 *
 *   feat! / BREAKING CHANGE  -> major
 *   feat                     -> minor
 *   perf                     -> minor
 *   fix(deps)                -> minor
 *   fix                      -> patch
 *   everything else          -> no release
 *
 * Maintenance commits (chore(deps), docs, test, ci, refactor) do not release:
 * they used to produce a patch release each, which flooded the tags.
 *
 * Use an explicit `release: false` instead of omitting a type. The analyzer
 * falls back to its built-in patch rules when no custom rule matches, so
 * omitting a type would re-enable it.
 */
module.exports = {
	branches: ['main', 'master'],
	plugins: [
		[
			'@semantic-release/commit-analyzer',
			{
				preset: 'conventionalcommits',
				releaseRules: [
					{ breaking: true, release: 'major' },
					{ revert: true, release: false },
					{ type: 'feat', release: 'minor' },
					{ type: 'perf', release: 'minor' },
					{ type: 'fix', scope: 'deps', release: 'minor' },
					{ type: 'fix', scope: null, release: 'patch' },
					{ type: 'fix', scope: '!(deps)', release: 'patch' },
					{ type: 'refactor', release: false },
					{ type: 'docs', release: false },
					{ type: 'style', release: false },
					{ type: 'test', release: false },
					{ type: 'build', release: false },
					{ type: 'ci', release: false },
					{ type: 'chore', release: false },
					{ type: 'revert', release: false },
					{ type: 'deps', release: false },
				],
			},
		],
		'@semantic-release/release-notes-generator',
		'@semantic-release/changelog',
		[
			'@semantic-release/exec',
			{
				// Keep appinfo/info.xml and package.json in step with the tag so
				// the packaged archive carries the released version.
				//
				// ${nextRelease.version} is a semantic-release placeholder that the
				// plugin substitutes, not a JS template literal. Backticks would
				// evaluate it here and throw, because nextRelease does not exist in
				// this file.
				// skipcq: JS-0038
				prepareCmd: 'make bump-version VERSION=${nextRelease.version}',
			},
		],
		[
			'@semantic-release/git',
			{
				assets: ['CHANGELOG.md', 'appinfo/info.xml', 'package.json', 'package-lock.json'],
				// These are semantic-release placeholders, and this is the exact
				// string the @semantic-release/git README documents. Backticks would
				// throw when the config loads.
				// skipcq: JS-0038
				message: 'chore(release): ${nextRelease.version} [skip ci]\n\n${nextRelease.notes}',
			},
		],
		[
			'@semantic-release/github',
			{
				// The build job attaches the archives, so the release stays a
				// draft until they exist and a release never goes public empty.
				assets: null,
				draftRelease: true,
			},
		],
	],
}
