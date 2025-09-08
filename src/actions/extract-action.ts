/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Node, View } from '@nextcloud/files'

import ArchiveArrowUpSvg from '@mdi/svg/svg/archive-arrow-up.svg?raw'
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { FileAction, Folder, Permission } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'

export const extractAction = new FileAction({
	id: 'extract',
	displayName: () => t('extract', 'Extract here'),
	iconSvgInline: () => ArchiveArrowUpSvg,

	enabled(nodes: Node[]) {
		if (nodes.length !== 1) {
			return false
		}

		const node = nodes[0]!

		const ct = node.attributes?.getcontenttype as string | undefined

		if (
			ct === 'application/zip'
			|| ct === 'application/x-tar'
			|| ct === 'application/gzip'
			|| ct === 'application/x-rar-compressed'
			|| ct === 'application/x-7z-compressed'
			|| ct === 'application/x-deb'
			|| ct === 'application/x-bzip2'
		) {
			return (node.permissions & Permission.UPDATE) !== 0
		}

		return false
	},

	async exec(node: Node, view: View, dir: string) {
		const data = {
			nameOfFile: node.attributes?.basename ?? '',
			directory: dir,
			external: node.attributes?.['mount-type']?.startsWith('external') ? 1 : 0,
			mime: node.attributes?.mime,
		}

		const url = generateOcsUrl('/apps/extract/api/v1/extraction/execute')
		axios.post(url, data)
			.then(({ data }) => {
				const time = data.ocs.data.extracted.mtime * 1000
				const folder = new Folder({
					id: data.ocs.data.extracted.fileId,
					source: data.ocs.data.extracted.source,
					root: data.ocs.data.extracted.root,
					owner: data.ocs.data.extracted.owner,
					permissions: data.ocs.data.extracted.permissions,
					mtime: new Date(time),
					attributes: {
						'mount-type': data.ocs.data.extracted['mount-type'],
						'owner-id': data.ocs.data.extracted.owner,
						'owner-display-name': data.ocs.data.extracted['owner-display-name'],
					},
				})

				emit('files:node:created', folder)

				window.OCP.Files.Router.goToRoute(
					{ view: 'files', fileid: data.fileId },
					{ dir },
				)
				return null
			})
			.catch((error) => {
				console.log('Could not send extract request.')
				console.log(error)
			})
		return null
	},

	order: 25,
})
