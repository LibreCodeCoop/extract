/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'
import path from 'path'

export default createAppConfig({
	main: path.join(__dirname, 'src', 'main.ts'),
}, {
	inlineCSS: false,
})
