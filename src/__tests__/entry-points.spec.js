/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { flushPromises } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { registerWidget } from '@nextcloud/vue/components/NcRichText'
import { setInitialState } from './helpers.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/password-confirmation', () => ({ confirmPassword: vi.fn() }))
vi.mock('@nextcloud/vue/components/NcRichText', () => ({ registerWidget: vi.fn() }))

/**
 * A div for an entry point to mount into.
 *
 * @param id the element id the entry point looks for
 */
function target(id) {
	document.getElementById(id)?.remove()
	const el = document.createElement('div')
	el.id = id
	document.body.appendChild(el)
	return el
}

describe('the dashboard entry point', () => {
	// the module registers on DOMContentLoaded and nothing removes that listener
	// again, so it is imported once and every test reads the same registration
	let registrations

	beforeAll(async () => {
		window.OCA = { Dashboard: { register: vi.fn() } }
		await import('../dashboard.js')
		document.dispatchEvent(new Event('DOMContentLoaded'))
		registrations = [...window.OCA.Dashboard.register.mock.calls]
	})

	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockResolvedValue({ data: [] })
	})

	it('registers both widgets the app declares', () => {
		expect(registrations.map(([id]) => id))
			.toEqual(['jira_notifications', 'jira_notifications_filter'])
	})

	it.each([
		['the plain widget', 0],
		['the filtered widget', 1],
	])('mounts %s with a template that can translate', async (_, index) => {
		// the empty state message comes from the page global, the button label
		// from the instance, which is what the entry point's mixin provides
		axios.get.mockRejectedValue({ response: { status: 400, request: { responseText: '' } } })
		const [, mountWidget] = registrations[index]

		const el = target(`widget-${index}`)
		mountWidget(el, { widget: { title: 'Jira notifications' } })
		await flushPromises()

		expect(el.textContent).toContain('No Jira account connected')
		expect(el.textContent).toContain('Connect to Jira')
	})

	it('asks the server to filter by project for the second widget only', async () => {
		const [[, mountPlain], [, mountFiltered]] = registrations

		mountPlain(target('plain'), { widget: { title: 'Jira notifications' } })
		mountFiltered(target('filtered'), { widget: { title: 'Jira notifications' } })
		await flushPromises()

		expect(axios.get.mock.calls.map(([url]) => url)).toEqual([
			'/index.php/apps/integration_jira/notifications?filterProjects=false',
			'/index.php/apps/integration_jira/notifications?filterProjects=true',
		])
	})
})

describe('the reference entry point', () => {
	beforeEach(() => {
		vi.resetModules()
		vi.clearAllMocks()
	})

	it('registers the rich object type the server sends', async () => {
		await import('../reference.js')

		expect(registerWidget).toHaveBeenCalledWith(
			'integration_jira_search',
			expect.any(Function),
			expect.any(Function),
			{ hasInteractiveView: false },
		)
	})

	it('renders a preview that can translate', async () => {
		await import('../reference.js')
		const [, render] = registerWidget.mock.calls[0]

		const el = target('preview')
		await render(el, {
			richObjectType: 'integration_jira_search',
			richObject: {
				key: 'NC-42',
				fields: {
					project: { name: 'Nextcloud' },
					summary: 'The dashboard widget stays empty',
					status: { name: 'In Progress' },
					issuetype: { name: 'Bug' },
				},
			},
			accessible: true,
		})
		await flushPromises()

		expect(el.textContent).toContain('[Nextcloud] NC-42')
		// the labels of every row come from the t() the entry point installs
		expect(el.textContent).toContain('Status:')
	})
})

describe('the settings entry points', () => {
	beforeEach(() => {
		vi.resetModules()
		vi.clearAllMocks()
		axios.get.mockResolvedValue({ data: [] })
	})

	it('mounts the admin settings where the server put the section', async () => {
		setInitialState('admin-config', {
			client_id: '',
			client_secret: '',
			forced_instance_url: '',
			link_preview_enabled: true,
		})
		const el = target('jira_prefs')

		await import('../adminSettings.js')

		expect(el.textContent).toContain('Jira integration')
		expect(el.textContent).toContain('Client ID')
	})

	it('mounts the personal settings where the server put the section', async () => {
		setInitialState('user-config', {
			user_name: 'jane',
			search_enabled: false,
			link_preview_enabled: true,
			notification_enabled: true,
			dashboard_jira_projects: [],
		})
		const el = target('jira_prefs')

		await import('../personalSettings.js')
		await flushPromises()

		expect(el.textContent).toContain('Connected as jane')
	})
})
