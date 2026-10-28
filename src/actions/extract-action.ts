/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ActionContext, ActionContextSingle, IFileAction, IFolder, INode } from '@nextcloud/files'

import ArchiveArrowUpSvg from '@mdi/svg/svg/archive-arrow-up.svg?raw'
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { Folder, Permission, registerFileAction } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * MIME types the backend can extract.
 *
 * These mirror ExtractionController::SUPPORTED_MIME_TYPES on the server, so the
 * action is only offered for archives the OCS endpoint accepts. Nextcloud
 * reports `application/gzip` for .gz and .tgz.
 */
export const SUPPORTED_ARCHIVE_MIME_TYPES = [
	'application/zip',
	'application/x-rar-compressed',
	'application/x-tar',
	'application/gzip',
	'application/x-gzip',
	'application/x-7z-compressed',
	'application/x-bzip2',
	'application/x-deb',
	'application/x-compressed',
]

interface ExtractedFolder {
	fileId: number
	source: string
	root: string
	owner: string | null
	permissions: number
	mtime: number
	'mount-type': string
	'owner-display-name': string | null
}

interface ExtractionResponse {
	ocs: {
		data: {
			code?: number
			desc?: string
			extracted?: ExtractedFolder
		}
	}
}

// The helpers below are module-scoped function declarations. The project's
// ESLint config (antfu/top-level-function) requires the `function` keyword, so
// they cannot be arrow functions. skipcq silences DeepSource's JS-0067, which
// assumes these are globals that could collide with other scripts.
// skipcq: JS-0067
/**
 * Resolve the archive MIME type of a node.
 *
 * `node.mime` is the resolved MIME type. It falls back to
 * `application/octet-stream` when the type could not be determined, in which
 * case the raw DAV property is used so an unresolved archive can still be
 * recognised.
 *
 * @param node The node to inspect
 */
function archiveMimeOf(node: INode): string | undefined {
	const mime = node.mime
	if (mime !== '' && mime !== 'application/octet-stream') {
		return mime
	}
	return node.attributes?.getcontenttype as string | undefined
}

// skipcq: JS-0067
/**
 * Whether a node is an archive the app can extract.
 *
 * @param node The node to inspect
 */
function isSupportedArchive(node: INode): boolean {
	const mime = archiveMimeOf(node)
	return mime !== undefined && SUPPORTED_ARCHIVE_MIME_TYPES.includes(mime)
}

// skipcq: JS-0067
/**
 * Whether a node lives on an external storage.
 *
 * @param node The node to inspect
 */
function isExternalStorage(node: INode): boolean {
	return String(node.attributes?.['mount-type'] ?? '').startsWith('external')
}

// skipcq: JS-0067
/**
 * Build the folder node the server created.
 *
 * @param extracted The folder description returned by the server
 */
function toFolder(extracted: ExtractedFolder): Folder {
	return new Folder({
		id: extracted.fileId,
		source: extracted.source,
		root: extracted.root,
		owner: extracted.owner,
		permissions: extracted.permissions,
		mtime: new Date(extracted.mtime * 1000),
		attributes: {
			'mount-type': extracted['mount-type'],
			'owner-id': extracted.owner,
			'owner-display-name': extracted['owner-display-name'],
		},
	})
}

// skipcq: JS-0067
/**
 * Read the extracted folder out of an OCS response.
 *
 * A refusal the server describes (encryption enabled, a missing file, an
 * unsupported type) is reported with OCP.Toast and signalled by returning null.
 * Throwing is deliberately avoided: the Files app would then also show its own
 * generic "{action}: failed" notification, so the user would see two messages.
 *
 * @param response The parsed OCS response
 */
function readExtractedFolder(response: ExtractionResponse): ExtractedFolder | null {
	const { code, desc, extracted } = response.ocs.data

	if (code === 0 || extracted === undefined) {
		window.OCP.Toast.error(desc ?? t('extract', 'Extraction failed'))
		return null
	}

	return extracted
}

// skipcq: JS-0067
/**
 * Build the request body for the extraction endpoint.
 *
 * @param node The archive to extract
 * @param folder The folder the archive lives in
 */
function buildRequestBody(node: INode, folder: IFolder) {
	return {
		nameOfFile: node.basename,
		directory: folder.path,
		external: isExternalStorage(node) ? 1 : 0,
		mime: archiveMimeOf(node),
	}
}

// skipcq: JS-0067
/**
 * Whether the action applies to the current selection.
 *
 * @param context The file action context
 */
function isEnabled(context: ActionContext): boolean {
	if (context.nodes.length !== 1) {
		return false
	}

	const [node] = context.nodes
	return node !== undefined
		&& isSupportedArchive(node)
		&& (node.permissions & Permission.UPDATE) !== 0
}

// skipcq: JS-0067
/**
 * Extract the selected archive and add the resulting folder to the file list.
 *
 * @param context The file action context
 */
async function exec(context: ActionContextSingle): Promise<boolean | null> {
	const [node] = context.nodes
	if (node === undefined) {
		return false
	}

	const url = generateOcsUrl('/apps/extract/api/v1/extraction/execute')
	const { data: response } = await axios.post<ExtractionResponse>(
		url,
		buildRequestBody(node, context.folder),
	)
	const extracted = readExtractedFolder(response)
	if (extracted === null) {
		// The reason was already shown; stay silent so the Files app does not add
		// a second, generic notification.
		return null
	}

	emit('files:node:created', toFolder(extracted))

	window.OCP.Files.Router.goToRoute(
		null,
		{ view: 'files', fileid: String(extracted.fileId) },
		{ dir: context.folder.path },
	)

	return null
}

export const extractAction: IFileAction = {
	id: 'extract',
	displayName: () => t('extract', 'Extract here'),
	iconSvgInline: () => ArchiveArrowUpSvg,
	enabled: isEnabled,
	exec,
	order: 25,
}

// skipcq: JS-0067
/**
 * Register the "Extract here" file action.
 */
export function registerExtractAction(): void {
	registerFileAction(extractAction)
}
