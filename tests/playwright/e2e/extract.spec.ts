/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { Buffer } from 'node:buffer'
import { randomUUID } from 'node:crypto'

const user = process.env.NC_ADMIN_USER ?? 'e2e'
const password = process.env.NEXTCLOUD_ADMIN_PASSWORD
if (!password) {
	throw new Error('NEXTCLOUD_ADMIN_PASSWORD is required for isolated Nextcloud E2E tests')
}
const authorization = 'Basic ' + Buffer.from(user + ':' + password).toString('base64')
const fixture = Buffer.from('UEsDBBQAAAAIAK1mdV2a8R5qHQAAABsAAAAJAAAAaGVsbG8udHh0c60oKUpMLlFIKsovL04tUnA1clUoSCwuTk3hAgBQSwECFAMUAAAACACtZnVdmvEeah0AAAAbAAAACQAAAAAAAAAAAAAAgAEAAAAAaGVsbG8udHh0UEsFBgAAAAABAAEANwAAAEQAAAAAAA==', 'base64')
const apiHeaders = {
	Authorization: authorization,
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
}
const extractEndpoint = '/ocs/v2.php/apps/extract/api/v1/extraction/execute?format=json'

/** DAV operations run only against the disposable user configured for CI. */
function dav(path: string): string {
	return '/remote.php/dav/files/' + encodeURIComponent(user) + '/' + path.split('/').map(encodeURIComponent).join('/')
}

/** Upload a small synthetic ZIP into the disposable Nextcloud installation. */
async function uploadArchive(request: APIRequestContext, dir = ''): Promise<string> {
	const name = 'extract-e2e-' + randomUUID().slice(0, 8) + '.zip'
	const response = await request.put(dav(dir ? dir + '/' + name : name), {
		headers: { Authorization: authorization, 'Content-Type': 'application/zip' },
		data: fixture,
	})
	expect(response.status()).toBe(201)
	return name
}

/** Clean only our test-owned files, never other users' data. */
async function deleteFile(request: APIRequestContext, path: string): Promise<void> {
	const response = await request.delete(dav(path), { headers: { Authorization: authorization } })
	expect([204, 404]).toContain(response.status())
}

test('OCS extracts a ZIP to the chosen destination on Nextcloud Files', async ({ request }) => {
	const file = await uploadArchive(request)
	const destination = 'extract-e2e-' + randomUUID().slice(0, 8)
	try {
		const response = await request.post(extractEndpoint, {
			headers: apiHeaders,
			data: {
				nameOfFile: file,
				directory: '/',
				external: false,
				mime: 'application/zip',
				targetDirName: destination,
			},
		})
		expect(response.ok()).toBeTruthy()
		const result = await response.json()
		expect(result.ocs.data.code).toBe(1)
		const created = await request.get(dav(destination + '/hello.txt'), { headers: { Authorization: authorization } })
		expect(created.ok()).toBeTruthy()
		expect(await created.text()).toBe('Extract browser E2E passed\n')
	} finally {
		await deleteFile(request, file)
		await deleteFile(request, destination)
	}
})

test('OCS rejects destination traversal before writing any files', async ({ request }) => {
	const file = await uploadArchive(request)
	try {
		const response = await request.post(extractEndpoint, {
			headers: apiHeaders,
			data: {
				nameOfFile: file,
				directory: '/',
				external: false,
				mime: 'application/zip',
				targetDirName: '../escape',
			},
		})
		expect(response.ok()).toBeTruthy()
		const result = await response.json()
		expect(result.ocs.data.code).toBe(0)
		expect(result.ocs.data.desc).toContain('Invalid extraction folder name')
	} finally {
		await deleteFile(request, file)
	}
})

test('Files UI displays the uploaded archive without extracting it', async ({ request, page }) => {
	const dir = 'extract-e2e-folder-' + randomUUID().slice(0, 8)
	const created = await request.fetch(dav(dir), {
		method: 'MKCOL',
		headers: { Authorization: authorization },
	})
	expect(created.status()).toBe(201)
	const file = await uploadArchive(request, dir)
	try {
		await page.goto('/login')
		await page.locator('#user').fill(user)
		await page.locator('#password').fill(password)
		await page.locator('button[type=submit]').click()
		// Open an isolated folder containing only our synthetic test archive.
		// This avoids Nextcloud Files' virtualized main-directory list.
		await page.goto('/apps/files/files?dir=' + encodeURIComponent('/' + dir))
		const archive = page.getByRole('row', { name: file.slice(0, -4) })
		await expect(archive).toBeVisible({ timeout: 30_000 })
		await expect(archive.getByRole('button', { name: 'Download: ' + file })).toBeVisible()
		const response = await request.get(dav(dir + '/' + file.slice(0, -4)), { headers: { Authorization: authorization } })
		expect(response.status()).toBe(404)
	} finally {
		await deleteFile(request, dir)
	}
})
