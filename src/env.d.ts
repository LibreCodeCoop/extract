/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare global {
	interface Window {
		OCP: Nextcloud.v29.OCP & {
			/**
			 * `OCP.Toast` is provided by the Nextcloud core bundle and is not
			 * part of `@nextcloud/typings`. Reuse the core toast instead of
			 * adding another notification dependency.
			 */
			Toast: {
				error: (text: string, options?: Record<string, unknown>) => unknown
			}
		}
	}
	declare module '*.vue' {
		import type { DefineComponent } from 'vue'
		const component: DefineComponent
		export default component
	}
	declare module '*.svg?raw' {
		const content: string
		export default content
	}
}

export {}
