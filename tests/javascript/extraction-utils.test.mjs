/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import assert from 'node:assert/strict'
import test from 'node:test'
import { destinationParentPath, suggestedDestinationName } from '../../src/actions/extraction-utils.ts'

test('suggesting a destination keeps meaningful filename suffixes', () => {
	assert.equal(suggestedDestinationName('backup.2026.zip'), 'backup.2026')
	assert.equal(suggestedDestinationName('backup.tar.gz'), 'backup')
	assert.equal(suggestedDestinationName('backup.TAR.XZ'), 'backup')
	assert.equal(suggestedDestinationName('archive.tar'), 'archive')
	assert.equal(suggestedDestinationName('archive.7z'), 'archive')
	assert.equal(suggestedDestinationName('without-extension'), 'without-extension')
})

test('a flat destination stays in the current directory', () => {
	assert.equal(destinationParentPath('/', 'backup'), '/')
	assert.equal(destinationParentPath('/Documents', 'backup'), '/Documents')
	assert.equal(destinationParentPath('/Documents/', 'backup'), '/Documents')
})

test('a nested destination navigates to its actual parent', () => {
	assert.equal(destinationParentPath('/', 'reports/2026'), '/reports')
	assert.equal(destinationParentPath('/Documents', 'reports/2026'), '/Documents/reports')
	assert.equal(destinationParentPath('/Documents/', 'reports/2026/monthly'), '/Documents/reports/2026')
})
