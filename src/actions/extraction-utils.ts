/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Suggest a destination while preserving extensions inside the archive's name.
 *
 * Match the server's resultFolderName logic for wrapped tarballs and .tar;
 * files like backup.2026.zip must retain their middle extension.
 *
 * @param fileName Name of the archive
 */
export function suggestedDestinationName(fileName: string): string {
	const lower = fileName.toLowerCase()
	for (const extension of ['tar.gz', 'tgz', 'tar.bz2', 'tbz', 'tbz2', 'tar.xz', 'txz', 'tar']) {
		if (lower.endsWith('.' + extension)) {
			return fileName.slice(0, -(extension.length + 1))
		}
	}
	const dot = fileName.lastIndexOf('.')
	return dot < 0 ? fileName : fileName.slice(0, dot)
}

/**
 * Return the actual parent directory of the selected extraction destination.
 *
 * A nested destination such as 'reports/2026' must be shown inside 'reports',
 * not inserted into the original archive directory.
 *
 * @param currentDirectory Directory holding the archive
 * @param targetDirName Validated relative target path
 */
export function destinationParentPath(currentDirectory: string, targetDirName: string): string {
	const parentSegments = targetDirName.split('/').slice(0, -1).join('/')
	const current = currentDirectory.replace(/\/+$/, '')
	return (current + '/' + parentSegments).replace(/\/+$/, '') || '/'
}
