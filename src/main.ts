/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { registerFileAction } from '@nextcloud/files'
import { extractAction } from './actions/extract-action.ts'

registerFileAction(extractAction)
