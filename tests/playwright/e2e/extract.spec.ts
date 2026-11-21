/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { Buffer } from 'node:buffer'
import { randomUUID } from 'node:crypto'
import { readFile } from 'node:fs/promises'

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
function davFor(username: string, path: string): string {
	return '/remote.php/dav/files/' + encodeURIComponent(username) + '/' + path.split('/').map(encodeURIComponent).join('/')
}

function dav(path: string): string {
	return davFor(user, path)
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

test('Files action validates, cancels and confirms extraction destinations', async ({ request, page }) => {
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
		// Nextcloud authenticates asynchronously. Do not navigate away while
		// the login POST is still running, or the session can be lost.
		await expect(page.getByRole('button', { name: 'Settings menu' })).toBeVisible({ timeout: 30_000 })
		// Open an isolated folder containing only our synthetic test archive.
		// This avoids Nextcloud Files' virtualized main-directory list.
		await page.goto('/apps/files/files?dir=' + encodeURIComponent('/' + dir))
		const archive = page.getByRole('row', { name: file.slice(0, -4) })
		await expect(archive).toBeVisible({ timeout: 30_000 })
		// Nextcloud's first-login "What's new" modal can cover the actions menu.
		// Dismiss only that onboarding dialog, not dialogs from the app under test.
		const onboarding = page.getByRole('dialog').filter({ has: page.getByRole('button', { name: "What's new?" }) })
		if (await onboarding.isVisible()) {
			await onboarding.getByRole('button', { name: 'Close' }).click()
		}
		// Open the Nextcloud Files action for this archive. A cancelled
		// dialog must not call the extraction endpoint or create a folder.
		await archive.getByRole('button', { name: 'Actions' }).click()
		const action = page.getByRole('menuitem', { name: /Extract/i })
		await expect(action).toBeVisible()
		await action.click()
		const extractionRequests: string[] = []
		page.on('request', (event) => {
			if (event.method() === 'POST' && event.url().includes('/apps/extract/api/v1/extraction/execute')) {
				extractionRequests.push(event.url())
			}
		})
		const dialog = page.getByRole('dialog', { name: 'Extract archive' })
		await expect(dialog).toBeVisible()
		const destinationInput = dialog.getByRole('textbox', { name: 'Destination folder' })
		const extractButton = dialog.getByRole('button', { name: 'Extract', exact: true })
		await expect(destinationInput).toHaveValue(file.slice(0, -4))
		await destinationInput.fill('../escape')
		await expect(extractButton).toBeDisabled()
		await destinationInput.fill(file.slice(0, -4))
		await expect(extractButton).toBeEnabled()
		await dialog.getByRole('button', { name: 'Cancel' }).click()
		await expect(dialog).toBeHidden()
		expect(extractionRequests).toHaveLength(0)
		const absent = await request.get(dav(dir + '/' + file.slice(0, -4)), { headers: { Authorization: authorization } })
		expect(absent.status()).toBe(404)

		// Confirming a different destination must use the Files action's
		// browser-to-OCS contract and write through Nextcloud's DAV API.
		await archive.getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: /Extract/i }).click()
		const confirmed = page.getByRole('dialog', { name: 'Extract archive' })
		await expect(confirmed).toBeVisible()
		await confirmed.getByRole('textbox', { name: 'Destination folder' }).fill('browser-verified')
		await confirmed.getByRole('button', { name: 'Extract', exact: true }).click()
		await expect.poll(async () => {
			const response = await request.get(dav(dir + '/browser-verified/hello.txt'), { headers: { Authorization: authorization } })
			return response.status()
		}, { timeout: 20_000 }).toBe(200)
		expect(extractionRequests).toHaveLength(1)
	} finally {
		await deleteFile(request, dir)
	}
})


/**
 * Integration coverage for the 7za path, which the default Nextcloud image
 * cannot exercise unless the binary is installed in its disposable container.
 */
test('OCS extracts a real 7z archive on Nextcloud', async ({ request }) => {
	const name = 'extract-e2e-' + randomUUID().slice(0, 8) + '.7z'
	const destination = 'extract-7z-' + randomUUID().slice(0, 8)
	const archive = await readFile(new URL('../../Fixtures/valid.7z', import.meta.url))
	try {
		const uploaded = await request.put(dav(name), {
			headers: { Authorization: authorization, 'Content-Type': 'application/x-7z-compressed' },
			data: archive,
		})
		expect(uploaded.status()).toBe(201)
		const response = await request.post(extractEndpoint, {
			headers: apiHeaders,
			data: {
				nameOfFile: name,
				directory: '/',
				external: false,
				mime: 'application/x-7z-compressed',
				targetDirName: destination,
			},
		})
		expect(response.ok()).toBeTruthy()
		const result = await response.json()
		expect(result.ocs.data.code).toBe(1)
		const extracted = await request.get(dav(destination + '/hello.txt'), {
			headers: { Authorization: authorization },
		})
		expect(extracted.status()).toBe(200)
		expect(await extracted.text()).toBe('hello world\n')
	} finally {
		await deleteFile(request, name)
		await deleteFile(request, destination)
	}
})

/**
 * A recipient writing to a shared directory must extract into that recipient's
 * own Files view, not into an invisible or owner-only folder. Create everything
 * inside disposable CI Nextcloud; never touch real accounts or data.
 */
test('A share recipient can extract a ZIP to a writable shared folder', async ({ request }) => {
	const recipient = 'extractci' + randomUUID().replaceAll('-', '').slice(0, 12)
	const recipientPassword = 'ExtractCI!' + randomUUID().replaceAll('-', '')
	const recipientAuthorization = 'Basic ' + Buffer.from(recipient + ':' + recipientPassword).toString('base64')
	const dir = 'extract-shared-' + randomUUID().slice(0, 8)
	const archiveName = 'uploaded-by-recipient.zip'
	let userCreated = false
	let folderCreated = false

	try {
		const newUser = await request.post('/ocs/v2.php/cloud/users?format=json', {
			headers: apiHeaders,
			form: { userid: recipient, password: recipientPassword },
		})
		userCreated = newUser.ok()
		expect(newUser.ok()).toBeTruthy()
		const newUserData = await newUser.json()
		expect([100, 200]).toContain(newUserData.ocs.meta.statuscode)

		const created = await request.fetch(dav(dir), {
			method: 'MKCOL',
			headers: { Authorization: authorization },
		})
		folderCreated = created.status() === 201
		expect(folderCreated).toBeTruthy()

		const share = await request.post('/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json', {
			headers: apiHeaders,
			form: { path: '/' + dir, shareType: '0', shareWith: recipient, permissions: '31' },
		})
		expect(share.ok()).toBeTruthy()
		const shareData = await share.json()
		expect([100, 200]).toContain(shareData.ocs.meta.statuscode)

		const uploaded = await request.put(davFor(recipient, dir + '/' + archiveName), {
			headers: { Authorization: recipientAuthorization, 'Content-Type': 'application/zip' },
			data: fixture,
		})
		expect(uploaded.status()).toBe(201)

		const response = await request.post(extractEndpoint, {
			headers: { ...apiHeaders, Authorization: recipientAuthorization },
			data: {
				nameOfFile: archiveName,
				directory: '/' + dir,
				external: false,
				mime: 'application/zip',
				targetDirName: 'recipient-extracted',
			},
		})
		expect(response.ok()).toBeTruthy()
		const result = await response.json()
		expect(result.ocs.data.code).toBe(1)

		for (const [account, auth] of [[recipient, recipientAuthorization], [user, authorization]]) {
			const extracted = await request.get(davFor(account, dir + '/recipient-extracted/hello.txt'), {
				headers: { Authorization: auth },
			})
			expect(extracted.status()).toBe(200)
			expect(await extracted.text()).toBe('Extract browser E2E passed\n')
		}
	} finally {
		if (folderCreated) {
			await deleteFile(request, dir)
		}
		if (userCreated) {
			await request.delete('/ocs/v2.php/cloud/users/' + encodeURIComponent(recipient) + '?format=json', {
				headers: apiHeaders,
			})
		}
	}
})
