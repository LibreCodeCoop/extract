/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare global {
	interface Window {
		OCP: Nextcloud.v29.OCP & {
			/**
			 * `OCP.Toast` is provided by the Nextcloud core bundle and is not part
			 * of `@nextcloud/typings`. It is the dependency-free way to show a
			 * message: the `@nextcloud/dialogs` package pulls in all of
			 * `@nextcloud/vue`, taking this bundle from 116 kB to 564 kB for a
			 * single call.
			 */
			Toast: {
				error: (text: string, options?: Record<string, unknown>) => unknown
			}
		}
	}
	declare module '*.svg?raw' {
		const content: string
		export default content
	}
}

export {}
