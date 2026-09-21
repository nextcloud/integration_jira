/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Page } from '@playwright/test'

import { login } from '@nextcloud/e2e-test-server/playwright'
import { test as base, expect } from '@playwright/test'

// the test container always has this admin user
const admin = { userId: 'admin', password: 'admin' }
const ocs = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

// an issue as the app puts it into the rich object of a link preview
const issue = {
	key: 'NC-42',
	fields: {
		project: { name: 'Nextcloud' },
		summary: 'Show the dashboard widgets again',
		issuetype: { name: 'Bug' },
		status: { name: 'In Progress' },
		priority: { name: 'High' },
		labels: ['dashboard', 'regression'],
		assignee: { displayName: 'Jane Doe' },
		created: '2026-09-01T10:00:00.000+0000',
		updated: '2026-09-18T10:00:00.000+0000',
	},
}

// Every test also fails on an uncaught exception, or on an unexpected failing request to one of the app's own routes.
// Errors of other apps on the instance are ignored on purpose.
const test = base.extend<{ appErrors: void }>({
	appErrors: [async ({ page }, use) => {
		const errors: string[] = []
		page.on('pageerror', (error) => errors.push(`uncaught: ${error.message}`))
		page.on('response', (response) => {
			if (response.status() >= 400 && response.url().includes('/integration_jira/')) {
				errors.push(`${response.status()} ${response.request().method()} ${new URL(response.url()).pathname}`)
			}
		})
		await use()
		expect(errors).toEqual([])
	}, { auto: true }],
})

/**
 * Load the reference bundle of the app and render one of its widgets, the way Nextcloud does
 * wherever a link preview appears.
 *
 * @param page a page of a logged in user
 * @param type the rich object type of the widget
 * @param richObject the rich object to render
 */
async function renderReferenceWidget(page: Page, type: string, richObject: object) {
	await page.goto('apps/files/')
	const registered = await page.evaluate(async ([widgetType, object]) => {
		const globals = window as unknown as {
			OC: { appswebroots: Record<string, string> }
			_vue_richtext_widgets: Record<string, { callback: (element: HTMLElement, data: object) => void }>
		}
		await import(/* @vite-ignore */ `${globals.OC.appswebroots.integration_jira}/js/integration_jira-reference.js`)
		const element = document.createElement('div')
		element.id = 'reference-widget'
		document.body.appendChild(element)
		globals._vue_richtext_widgets[widgetType as string].callback(element, {
			richObjectType: widgetType,
			richObject: object,
			accessible: false,
		})
		return Object.keys(globals._vue_richtext_widgets)
	}, [type, richObject] as [string, object])
	expect(registered).toContain(type)
	return page.locator('#reference-widget')
}

test.beforeEach(async ({ page }) => {
	await login(page.request, admin)
})

test.describe('Link previews', () => {
	test('offer the provider to the smart picker', async ({ page }) => {
		const response = await page.request.get('../ocs/v2.php/references/providers', { headers: ocs })
		expect(response.ok()).toBe(true)
		const providers = (await response.json()).ocs.data as Array<{ id: string, title: string, icon_url: string }>
		const provider = providers.find((candidate) => candidate.id === 'jira-search')
		expect(provider).toBeDefined()
		expect(provider?.title).toBe('Jira issues')
		// the provider icon is served by the app
		expect((await page.request.get(provider!.icon_url)).ok()).toBe(true)
	})

	test('render an issue in the reference widget', async ({ page }) => {
		const widget = await renderReferenceWidget(page, 'integration_jira_search', issue)

		await expect(widget.getByText('[Nextcloud] NC-42')).toBeVisible()
		await expect(widget.getByText('Type: Bug')).toBeVisible()
		await expect(widget.getByText('Status: In Progress')).toBeVisible()
		await expect(widget.getByText('Priority: High')).toBeVisible()
		await expect(widget.getByText('Assignee: Jane Doe')).toBeVisible()
		await expect(widget.getByText('Show the dashboard widgets again')).toBeVisible()
		for (const label of issue.fields.labels) {
			await expect(widget.getByText(label, { exact: true })).toBeVisible()
		}
	})
})

test.describe('Search provider', () => {
	test('offer the provider to unified search', async ({ page }) => {
		const response = await page.request.get('../ocs/v2.php/search/providers', { headers: ocs })
		expect(response.ok()).toBe(true)
		const providers = (await response.json()).ocs.data as Array<{ id: string, appId: string, name: string }>
		expect(providers.find((candidate) => candidate.id === 'jira-search')).toMatchObject({
			appId: 'integration_jira',
			name: 'Jira',
		})
	})

	test('answer an empty result for a user without a Jira account', async ({ page }) => {
		const response = await page.request.get('../ocs/v2.php/search/providers/jira-search/search?term=nextcloud', { headers: ocs })
		expect(response.ok()).toBe(true)
		expect((await response.json()).ocs.data.entries).toEqual([])
	})
})
