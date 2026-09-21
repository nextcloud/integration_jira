/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Page } from '@playwright/test'

import { login } from '@nextcloud/e2e-test-server/playwright'
import { test as base, expect } from '@playwright/test'

// the test container always has this admin user
const admin = { userId: 'admin', password: 'admin' }

// the dashboard widgets learn from this 400 that no Jira account is connected
const expectedFailures = ['400 GET /index.php/apps/integration_jira/notifications']

// served as the avatar of mocked issues
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64')

// an issue as the app returns it for a connected account
const issue = {
	id: '10042',
	key: 'NC-42',
	jiraUrl: 'https://example.atlassian.net',
	my_account_id: 'account-jane',
	fields: {
		summary: 'Show the dashboard widgets again',
		updated: '2026-09-18T10:00:00.000+0000',
		creator: {
			displayName: 'Jane Doe',
			accountId: 'account-jane',
			avatarUrls: { '48x48': 'https://example.atlassian.net/avatar.png' },
		},
	},
}

// Every test also fails on an uncaught exception, or on an unexpected failing request to one of the app's own routes.
// Errors of other apps on the instance are ignored on purpose.
const test = base.extend<{ appErrors: void }>({
	appErrors: [async ({ page }, use) => {
		const errors: string[] = []
		page.on('pageerror', (error) => errors.push(`uncaught: ${error.message}`))
		page.on('response', (response) => {
			const failure = `${response.status()} ${response.request().method()} ${new URL(response.url()).pathname}`
			if (response.status() >= 400 && response.url().includes('/integration_jira/') && !expectedFailures.includes(failure)) {
				errors.push(failure)
			}
		})
		await use()
		expect(errors).toEqual([])
	}, { auto: true }],
})

/**
 * Flip a switch of the Jira section, check that the new value survives a reload, then flip it back.
 *
 * @param page the page showing the section
 * @param label the text of the switch
 * @param route the app route that stores the value
 */
async function expectSwitchToBeSaved(page: Page, label: string, route: string) {
	const section = page.locator('#jira_prefs')
	const toggle = async () => {
		const saved = page.waitForResponse((response) => response.url().includes(route))
		// the switch hides its input, so click the label
		await section.getByText(label, { exact: true }).click()
		expect((await saved).ok()).toBe(true)
	}

	const before = await section.getByLabel(label, { exact: true }).isChecked()
	await toggle()
	try {
		await page.reload()
		await expect(section.getByLabel(label, { exact: true })).toBeChecked({ checked: !before })
	} finally {
		await toggle()
	}
}

/**
 * Put exactly these widgets on the dashboard of the logged in user.
 *
 * @param page the page whose session is used
 * @param widgets the widget ids
 */
async function showOnlyWidgets(page: Page, ...widgets: string[]) {
	const layout = await page.request.post('../ocs/v2.php/apps/dashboard/api/v3/layout', {
		headers: { 'OCS-APIRequest': 'true' },
		data: { layout: widgets },
	})
	expect(layout.ok()).toBe(true)
}

test.beforeEach(async ({ page }) => {
	await login(page.request, admin)
})

test.describe('Admin settings', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('settings/admin/connected-accounts')
	})

	test('show the Jira section', async ({ page }) => {
		const section = page.locator('#jira_prefs')
		await expect(section.getByRole('heading', { name: /Jira integration/ })).toBeVisible()
		await expect(section.getByLabel('Client ID', { exact: true })).toBeVisible()
		await expect(section.getByLabel('Client secret', { exact: true })).toBeVisible()
		await expect(section.getByLabel('Restrict self hosted URL to', { exact: true })).toBeVisible()
	})

	test('save the link preview setting', async ({ page }) => {
		// the app saves all its admin values together, with a password confirmation
		await expectSwitchToBeSaved(page, 'Enable link previews', '/apps/integration_jira/sensitive-admin-config')
	})
})

test.describe('Personal settings', () => {
	test('offer the ways to connect a Jira account', async ({ page }) => {
		await page.goto('settings/user/connected-accounts')
		const section = page.locator('#jira_prefs')
		await expect(section.getByRole('heading', { name: /Jira integration/ })).toBeVisible()
		await expect(section.getByText('Jira Cloud', { exact: true })).toBeVisible()
		await expect(section.getByText('Self-hosted Jira Software', { exact: true })).toBeVisible()
	})
})

test.describe('Dashboard widgets', () => {
	test('ask to connect a Jira account', async ({ page }) => {
		await showOnlyWidgets(page, 'jira_notifications', 'jira_notifications_filter')
		await page.goto('apps/dashboard/')

		for (const title of ['Jira notifications', 'Jira filtered notifications']) {
			const widget = page.locator('.panel').filter({ hasText: title })
			await expect(widget.getByText('No Jira account connected')).toBeVisible()
			await expect(widget.getByRole('button', { name: 'Connect to Jira' })).toBeVisible()
		}
	})

	test('list the issues of a connected account', async ({ page }) => {
		await showOnlyWidgets(page, 'jira_notifications')
		// answer the way the app does for a connected account, the test container cannot reach Jira
		await page.route('**/apps/integration_jira/notifications**', (route) => route.fulfill({ json: [issue] }))
		await page.route('**/apps/integration_jira/avatar**', (route) => route.fulfill({ contentType: 'image/png', body: png }))
		await page.goto('apps/dashboard/')

		const widget = page.locator('.panel').filter({ hasText: 'Jira notifications' })
		const item = widget.getByRole('link', { name: /Show the dashboard widgets again/ })
		await expect(item).toBeVisible()
		await expect(item).toHaveAttribute('href', 'https://example.atlassian.net/browse/NC-42')
		await expect(widget.getByText('Jane Doe #NC-42')).toBeVisible()
	})
})
