/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PersonalSettings from '../../components/PersonalSettings.vue'
import { httpError, setInitialState } from '../helpers.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const CONFIG_URL = '/index.php/apps/integration_jira/config'
const PROJECTS_URL = '/index.php/apps/integration_jira/projects'

/**
 * Mount the personal settings with a user config of the test's choosing.
 *
 * @param config what the server put on the page
 */
async function mountSettings(config = {}) {
	setInitialState('user-config', {
		user_name: 'jane',
		search_enabled: false,
		link_preview_enabled: true,
		notification_enabled: true,
		url: 'https://jira.example.com',
		client_id: '',
		client_secret: '',
		dashboard_jira_projects: [],
		...config,
	})
	const wrapper = mount(PersonalSettings)
	await flushPromises()
	return wrapper
}

describe('PersonalSettings', () => {
	afterEach(() => vi.restoreAllMocks())

	beforeEach(() => {
		vi.clearAllMocks()
		axios.put.mockResolvedValue({ data: {} })
		axios.get.mockResolvedValue({ data: [] })
		window.history.replaceState({}, '', '/settings/user/connected-accounts')
	})

	describe('whether it shows a connection', () => {
		it('counts a user name as connected', async () => {
			expect((await mountSettings()).vm.connected).toBe(true)
		})

		it.each([
			['an empty user name', ''],
			['no user name at all', undefined],
		])('counts %s as not connected', async (_, userName) => {
			const wrapper = await mountSettings({ user_name: userName })

			expect(wrapper.vm.connected).toBeFalsy()
			expect(axios.get).not.toHaveBeenCalled()
		})

		it('names the connected account', async () => {
			const wrapper = await mountSettings({ user_name: 'jane.doe@example.com' })

			expect(wrapper.text()).toContain('Connected as jane.doe@example.com')
		})

		it('offers OAuth only once an admin configured it', async () => {
			expect((await mountSettings()).vm.showOAuth).toBeFalsy()
			expect((await mountSettings({ client_id: 'id' })).vm.showOAuth).toBeFalsy()
			expect((await mountSettings({ client_id: 'id', client_secret: 'secret' })).vm.showOAuth)
				.toBeTruthy()
		})
	})

	describe('the project filter of the dashboard widget', () => {
		it('asks the server for the projects of a connected account', async () => {
			await mountSettings()

			expect(axios.get).toHaveBeenCalledWith(PROJECTS_URL)
		})

		it('asks for nothing while no account is connected', async () => {
			await mountSettings({ user_name: '' })

			expect(axios.get).not.toHaveBeenCalled()
		})

		it('offers every project of the account, by name', async () => {
			axios.get.mockResolvedValue({
				data: [{ id: '1', name: 'Nextcloud' }, { id: '2', name: 'Talk' }],
			})
			const wrapper = await mountSettings()

			expect(wrapper.vm.jiraProjectsOptions).toEqual([
				{ value: '1', label: 'Nextcloud' },
				{ value: '2', label: 'Talk' },
			])
		})

		it('preselects the projects the user saved', async () => {
			axios.get.mockResolvedValue({
				data: [{ id: '1', name: 'Nextcloud' }, { id: '2', name: 'Talk' }],
			})
			const wrapper = await mountSettings({ dashboard_jira_projects: ['2'] })

			expect(wrapper.vm.selectedProjects).toEqual([{ value: '2', label: 'Talk' }])
			expect(wrapper.findAll('.vs__selected').map((chip) => chip.text())).toEqual(['Talk'])
		})

		it('survives a saved project the account can no longer see', async () => {
			axios.get.mockResolvedValue({ data: [{ id: '1', name: 'Nextcloud' }] })
			const wrapper = await mountSettings({ dashboard_jira_projects: ['1', 'gone'] })

			expect(wrapper.vm.selectedProjects).toEqual([{ value: '1', label: 'Nextcloud' }])
			expect(wrapper.findAll('.vs__selected').map((chip) => chip.text())).toEqual(['Nextcloud'])
			expect(wrapper.vm.loadingJiraProjects).toBe(false)
		})

		it('reports a failure to list the projects', async () => {
			axios.get.mockRejectedValue(httpError(500, 'no projects for you'))
			const wrapper = await mountSettings()

			expect(showError).toHaveBeenCalledWith('Failed to get Jira projects: no projects for you')
			expect(wrapper.vm.loadingJiraProjects).toBe(false)
			// no chip, so nothing that could be saved back as a project id
			expect(wrapper.vm.selectedProjects).toEqual([])
			expect(wrapper.findAll('.vs__selected')).toHaveLength(0)
		})

		it('shows no selection before the projects arrive', async () => {
			axios.get.mockReturnValue(new Promise(() => {}))
			const wrapper = await mountSettings()

			expect(wrapper.vm.selectedProjects).toEqual([])
			expect(wrapper.vm.loadingJiraProjects).toBe(true)
			expect(wrapper.findAll('.vs__selected')).toHaveLength(0)
		})

		it('saves the ids of the chosen projects', async () => {
			const wrapper = await mountSettings()

			wrapper.vm.onJiraSelectedProjectsChanged([{ value: '2', label: 'Talk' }, { value: '3', label: 'Deck' }])

			expect(axios.put).toHaveBeenCalledWith(CONFIG_URL, {
				values: { dashboard_jira_projects: '["2","3"]' },
			})
		})
	})

	describe('saving an option', () => {
		it('sends a switch as the string the server stores', async () => {
			const wrapper = await mountSettings()

			wrapper.vm.onCheckboxChanged(true, 'search_enabled')
			expect(axios.put).toHaveBeenCalledWith(CONFIG_URL, { values: { search_enabled: '1' } })

			wrapper.vm.onCheckboxChanged(false, 'search_enabled')
			expect(axios.put).toHaveBeenLastCalledWith(CONFIG_URL, { values: { search_enabled: '0' } })
		})

		it('saves the switch the user actually clicked', async () => {
			const wrapper = await mountSettings()
			const switches = wrapper.findAll('input[type="checkbox"]')

			await switches[0].setValue(true)
			expect(axios.put).toHaveBeenLastCalledWith(CONFIG_URL, { values: { search_enabled: '1' } })

			await switches[2].setValue(false)
			expect(axios.put).toHaveBeenLastCalledWith(CONFIG_URL, { values: { notification_enabled: '0' } })
		})

		it('warns the user once unified search is on', async () => {
			const wrapper = await mountSettings({ search_enabled: true })

			expect(wrapper.text())
				.toContain('Warning, everything you type in the search bar will be sent to Jira.')
		})

		it('confirms a saved option to the user', async () => {
			const wrapper = await mountSettings()

			wrapper.vm.saveOptions({ search_enabled: '1' })
			await flushPromises()

			expect(showSuccess).toHaveBeenCalledWith('Jira options saved')
		})

		it('reports what the server said when saving fails', async () => {
			axios.put.mockRejectedValue(httpError(500, 'disk full'))
			const wrapper = await mountSettings()

			wrapper.vm.saveOptions({ search_enabled: '1' })
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Failed to save Jira options: disk full')
		})
	})

	describe('disconnecting', () => {
		it('forgets the account and tells the server to do the same', async () => {
			const wrapper = await mountSettings()

			wrapper.vm.onLogoutClick()
			await flushPromises()

			expect(wrapper.vm.state.user_name).toBe('')
			expect(axios.put).toHaveBeenCalledWith(CONFIG_URL, { values: { user_name: '' } })
			expect(wrapper.vm.connected).toBeFalsy()
		})
	})

	describe('connecting to a self-hosted instance', () => {
		it('sends the url, login and password, and keeps the account it gets back', async () => {
			axios.put.mockResolvedValue({ data: { user_name: 'jane' } })
			const wrapper = await mountSettings({ user_name: '' })
			wrapper.vm.login = 'jane'
			wrapper.vm.password = 'hunter2'

			wrapper.vm.onSelfHostedAuth()
			await flushPromises()

			expect(axios.put).toHaveBeenCalledWith('/index.php/apps/integration_jira/soft-connect', {
				url: 'https://jira.example.com',
				login: 'jane',
				password: 'hunter2',
			})
			expect(wrapper.vm.state.user_name).toBe('jane')
			expect(wrapper.vm.connecting).toBe(false)
		})

		it('passes on why the instance refused', async () => {
			axios.put.mockResolvedValue({ data: { user_name: '', error: 'unreachable' } })
			const wrapper = await mountSettings({ user_name: '' })

			wrapper.vm.onSelfHostedAuth()
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Impossible to connect to Jira instance: unreachable')
		})

		it('says so when the credentials are wrong', async () => {
			axios.put.mockResolvedValue({ data: { user_name: '' } })
			const wrapper = await mountSettings({ user_name: '' })

			wrapper.vm.onSelfHostedAuth()
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Login/password are invalid or account is locked')
		})

		it('reports a failed request', async () => {
			axios.put.mockRejectedValue(httpError(500, 'gateway timeout'))
			const wrapper = await mountSettings({ user_name: '' })

			wrapper.vm.onSelfHostedAuth()
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Failed to connect to Jira Software: gateway timeout')
			expect(wrapper.vm.connecting).toBe(false)
		})
	})

	describe('connecting to Jira Cloud', () => {
		it('saves the OAuth state before sending the user to Atlassian', async () => {
			vi.spyOn(window, 'location', 'get').mockReturnValue({
				protocol: 'http:',
				host: 'nextcloud.local',
				search: '',
				replace: vi.fn(),
			})
			const wrapper = await mountSettings({ user_name: '', client_id: 'the-client', client_secret: 'secret' })

			wrapper.vm.onOAuthClick()
			await flushPromises()

			const [url, body] = axios.put.mock.calls[0]
			expect(url).toBe(CONFIG_URL)
			expect(body.values.oauth_state).toMatch(/^\w+$/)
			expect(body.values.url).toBe('')
			expect(body.values.redirect_uri)
				.toBe('http://nextcloud.local/index.php/apps/integration_jira/oauth-redirect')
		})

		it('asks Atlassian for the scopes the app needs', async () => {
			const replace = vi.fn()
			vi.spyOn(window, 'location', 'get').mockReturnValue({
				protocol: 'http:',
				host: 'nextcloud.local',
				search: '',
				replace,
			})
			const wrapper = await mountSettings({ user_name: '', client_id: 'the-client', client_secret: 'secret' })

			wrapper.vm.onOAuthClick()
			await flushPromises()

			const target = new URL(replace.mock.calls[0][0])
			expect(target.origin + target.pathname).toBe('https://auth.atlassian.com/authorize')
			expect(target.searchParams.get('client_id')).toBe('the-client')
			expect(target.searchParams.get('response_type')).toBe('code')
			expect(target.searchParams.get('scope').split(' ')).toContain('read:jira-work')
			expect(target.searchParams.get('state')).toBe(axios.put.mock.calls[0][1].values.oauth_state)
			// the query has to carry it escaped, not raw
			expect(replace.mock.calls[0][0])
				.toContain('redirect_uri=http%3A%2F%2Fnextcloud.local%2Findex.php%2Fapps%2Fintegration_jira%2Foauth-redirect')
		})

		it('reports a failure to save the OAuth state', async () => {
			axios.put.mockRejectedValue(httpError(500, 'no space left'))
			const wrapper = await mountSettings({ user_name: '', client_id: 'id', client_secret: 'secret' })

			wrapper.vm.onOAuthClick()
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Failed to save Jira OAuth state: no space left')
		})
	})

	describe('coming back from Atlassian', () => {
		it('congratulates the user on a successful round trip', async () => {
			window.history.replaceState({}, '', '/settings/user?jiraToken=success')

			await mountSettings()

			expect(showSuccess).toHaveBeenCalledWith('Successfully connected to Jira!')
		})

		it('passes on the message of a failed one', async () => {
			window.history.replaceState({}, '', '/settings/user?jiraToken=error&message=state+mismatch')

			await mountSettings()

			expect(showError).toHaveBeenCalledWith('OAuth access token could not be obtained: state mismatch')
		})

		it('says nothing when the user just opened the page', async () => {
			await mountSettings()

			expect(showSuccess).not.toHaveBeenCalled()
			expect(showError).not.toHaveBeenCalled()
		})
	})
})
