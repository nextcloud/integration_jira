/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AdminSettings from '../../components/AdminSettings.vue'
import { httpError, setInitialState, useFixedTimers } from '../helpers.js'

vi.mock('@nextcloud/axios', () => ({ default: { put: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/password-confirmation', () => ({ confirmPassword: vi.fn() }))

const ADMIN_URL = '/index.php/apps/integration_jira/admin-config'
const SENSITIVE_URL = '/index.php/apps/integration_jira/sensitive-admin-config'

/**
 * Mount the admin settings with an admin config of the test's choosing.
 *
 * @param config what the server put on the page
 */
function mountSettings(config = {}) {
	setInitialState('admin-config', {
		client_id: 'the-client',
		client_secret: 'dummySecret',
		forced_instance_url: '',
		link_preview_enabled: true,
		...config,
	})
	return mount(AdminSettings)
}

/**
 * One of the text fields, by the placeholder the admin sees in it.
 *
 * @param wrapper the mounted settings
 * @param placeholder the placeholder text
 */
function field(wrapper, placeholder) {
	return wrapper.find(`input[placeholder="${placeholder}"]`)
}

describe('AdminSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.put.mockResolvedValue({ data: {} })
		confirmPassword.mockResolvedValue()
	})

	afterEach(() => vi.useRealTimers())

	it('shows the redirect uri an admin has to register with Atlassian', () => {
		expect(mountSettings().find('#jira-content strong').text())
			.toBe('http://nextcloud.local/index.php/apps/integration_jira/oauth-redirect')
	})

	it('keeps the credential fields readonly until they are focused', async () => {
		const wrapper = mountSettings()
		const clientId = field(wrapper, 'ID of your application')
		expect(clientId.attributes('readonly')).toBe('')

		await clientId.trigger('focus')

		expect(clientId.attributes('readonly')).toBeUndefined()
		expect(field(wrapper, 'Your application secret').attributes('readonly')).toBeUndefined()
	})

	describe('typing in a field', () => {
		it('waits for the admin to stop typing before saving', async () => {
			useFixedTimers()
			const wrapper = mountSettings()

			await field(wrapper, 'ID of your application').setValue('typed-id')
			await vi.advanceTimersByTimeAsync(1999)
			expect(axios.put).not.toHaveBeenCalled()

			await vi.advanceTimersByTimeAsync(1)
			expect(axios.put).toHaveBeenCalledTimes(1)
		})

		it('saves one burst of typing once', async () => {
			useFixedTimers()
			const wrapper = mountSettings()
			const clientId = field(wrapper, 'ID of your application')

			await clientId.setValue('typed')
			await vi.advanceTimersByTimeAsync(1000)
			await clientId.setValue('typed-id')
			await vi.advanceTimersByTimeAsync(2000)

			expect(axios.put).toHaveBeenCalledTimes(1)
			expect(axios.put.mock.calls[0][1].values.client_id).toBe('typed-id')
		})

		it('saves what the admin typed, through the endpoint that asks for the password', async () => {
			useFixedTimers()
			const wrapper = mountSettings()

			await field(wrapper, 'Instance address').setValue('https://jira.example.com')
			await vi.advanceTimersByTimeAsync(2000)

			expect(confirmPassword).toHaveBeenCalled()
			expect(axios.put).toHaveBeenCalledWith(SENSITIVE_URL, {
				values: {
					client_id: 'the-client',
					forced_instance_url: 'https://jira.example.com',
					link_preview_enabled: '1',
				},
			})
		})

		it('leaves the stored secret alone while the field still shows the placeholder', async () => {
			useFixedTimers()
			const wrapper = mountSettings()

			await field(wrapper, 'ID of your application').setValue('typed-id')
			await vi.advanceTimersByTimeAsync(2000)

			expect(axios.put.mock.calls[0][1].values).not.toHaveProperty('client_secret')
		})

		it('sends a secret the admin actually typed', async () => {
			useFixedTimers()
			const wrapper = mountSettings()

			await field(wrapper, 'Your application secret').setValue('a-new-secret')
			await vi.advanceTimersByTimeAsync(2000)

			expect(axios.put.mock.calls[0][1].values.client_secret).toBe('a-new-secret')
		})

		it('sends a switched off link preview as the string the server stores', async () => {
			useFixedTimers()
			const wrapper = mountSettings()

			await wrapper.find('input[type="checkbox"]').setValue(false)
			await vi.advanceTimersByTimeAsync(2000)

			expect(axios.put.mock.calls[0][1].values.link_preview_enabled).toBe('0')
		})
	})

	describe('saving', () => {
		it('sends nothing until the password dialog is answered', async () => {
			let answerDialog
			confirmPassword.mockReturnValue(new Promise((resolve) => {
				answerDialog = resolve
			}))
			const wrapper = mountSettings()

			const saving = wrapper.vm.saveOptions({ client_secret: 'secret' }, true)
			await flushPromises()
			expect(confirmPassword).toHaveBeenCalled()
			expect(axios.put).not.toHaveBeenCalled()

			answerDialog()
			await saving
			await flushPromises()

			expect(axios.put).toHaveBeenCalledWith(SENSITIVE_URL, {
				values: { client_secret: 'secret' },
			})
		})

		it('does not ask for the password for an option that is not sensitive', async () => {
			const wrapper = mountSettings()

			await wrapper.vm.saveOptions({ link_preview_enabled: '1' })
			await flushPromises()

			expect(confirmPassword).not.toHaveBeenCalled()
			expect(axios.put).toHaveBeenCalledWith(ADMIN_URL, {
				values: { link_preview_enabled: '1' },
			})
		})

		it('confirms a saved option to the admin', async () => {
			const wrapper = mountSettings()

			await wrapper.vm.saveOptions({ link_preview_enabled: '1' })
			await flushPromises()

			expect(showSuccess).toHaveBeenCalledWith('Jira admin options saved')
		})

		it('reports what the server said when saving fails', async () => {
			axios.put.mockRejectedValue(httpError(500, 'read-only config'))
			const wrapper = mountSettings()

			await wrapper.vm.saveOptions({ link_preview_enabled: '1' })
			await flushPromises()

			expect(showError).toHaveBeenCalledWith('Failed to save Jira admin options: read-only config')
		})
	})
})
