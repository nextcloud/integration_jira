/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import JiraIcon from '../../components/icons/JiraIcon.vue'
import Dashboard from '../../views/Dashboard.vue'
import { CLOCK, httpError, useFixedTimers } from '../helpers.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const NOTIFICATIONS_URL = '/index.php/apps/integration_jira/notifications?filterProjects=false'
const UPDATED = '2026-09-18T11:30:00.000+0000'

/**
 * An issue as the server sends it to the widget.
 *
 * @param fields the fields to add or override
 * @param rest what to add or override next to the fields
 */
function notification(fields = {}, rest = {}) {
	return {
		id: '10001',
		key: 'NC-42',
		jiraUrl: 'https://jira.example.com',
		fields: {
			summary: 'The dashboard widget stays empty',
			updated: UPDATED,
			creator: {
				displayName: 'Jane Doe',
				avatarUrls: { '48x48': 'https://jira.example.com/avatar/48' },
				accountId: 'acc-1',
			},
			...fields,
		},
		...rest,
	}
}

/**
 * Mount the widget and let its first request settle.
 *
 * @param notifications what the server answers with
 * @param options.shallow whether to stub the child components
 * @param options.props props to pass on
 * @param options
 */
async function mountWidget(notifications = [], options = {}) {
	axios.get.mockResolvedValue({ data: notifications })
	const wrapper = mount(Dashboard, {
		shallow: options.shallow ?? false,
		props: { title: 'Jira notifications', ...options.props },
	})
	await flushPromises()
	return wrapper
}

/**
 * Mount the widget with a request that fails, which is how it reaches every
 * state but 'ok'.
 *
 * @param error what axios rejects with
 */
async function mountFailing(error) {
	axios.get.mockRejectedValue(error)
	const wrapper = mount(Dashboard, { props: { title: 'Jira notifications' } })
	await flushPromises()
	return wrapper
}

describe('JiraDashboard', () => {
	beforeEach(() => vi.clearAllMocks())
	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	describe('the items it hands to the widget', () => {
		it('maps a notification to every field the widget renders', async () => {
			const wrapper = await mountWidget([notification()])

			expect(wrapper.vm.items).toEqual([{
				id: '10001:2026-09-18T11:30:00.000+0000',
				targetUrl: 'https://jira.example.com/browse/NC-42',
				avatarUrl: '/index.php/apps/integration_jira/avatar?accountId=acc-1',
				avatarUsername: 'Jane Doe',
				overlayIconUrl: '/apps/integration_jira/img/sound-border.svg',
				mainText: 'The dashboard widget stays empty',
				subText: 'Jane Doe #NC-42',
			}])
		})

		it('keeps only the most recent notification of an issue', async () => {
			const wrapper = await mountWidget([
				notification({ updated: '2026-09-19T08:00:00.000+0000' }),
				notification(),
			])

			expect(wrapper.vm.items).toHaveLength(1)
			expect(wrapper.vm.items[0].id).toBe('10001:2026-09-19T08:00:00.000+0000')
		})

		it('shows the newest version of an issue, whichever order the answers arrived in', async () => {
			const stale = notification({ updated: '2026-09-18T11:30:00.000+0000', summary: 'Old summary' })
			const fresh = notification({ updated: '2026-09-19T08:00:00.000+0000', summary: 'New summary' })
			const wrapper = await mountWidget([stale, fresh])

			expect(wrapper.vm.items).toHaveLength(1)
			expect(wrapper.vm.items[0].mainText).toBe('New summary')
			expect(wrapper.vm.items[0].id).toBe('10001:2026-09-19T08:00:00.000+0000')
		})

		it('keeps the copy it saw first when two carry the same update time', async () => {
			const wrapper = await mountWidget([
				notification({ summary: 'First copy' }),
				notification({ summary: 'Second copy' }),
			])

			expect(wrapper.vm.items).toHaveLength(1)
			expect(wrapper.vm.items[0].mainText).toBe('First copy')
		})

		it('keeps notifications of different issues apart', async () => {
			const wrapper = await mountWidget([notification(), notification({}, { key: 'NC-43' })])

			expect(wrapper.vm.items.map((item) => item.subText))
				.toEqual(['Jane Doe #NC-42', 'Jane Doe #NC-43'])
		})

		it('keys an item by issue and update time, so an edited issue is a new item', async () => {
			const wrapper = await mountWidget([notification()])

			expect(wrapper.vm.getUniqueKey(notification({ updated: '2026-09-19T08:00:00.000+0000' })))
				.toBe('10001:2026-09-19T08:00:00.000+0000')
		})
	})

	describe('an issue Jira described without an update time', () => {
		it('does not count as the newest notification', async () => {
			const sparse = notification({ updated: undefined }, {
				id: '10009',
				key: 'NC-9',
				jiraUrl: 'https://sparse.example.com',
			})
			const wrapper = await mountWidget([sparse, notification()])

			expect(wrapper.vm.lastDate).toBe('2026-09-18T11:30:00.000+0000')
			expect(wrapper.vm.showMoreUrl).toBe('https://jira.example.com')
		})

		it('is still listed', async () => {
			const sparse = notification({ updated: undefined }, { id: '10009', key: 'NC-9' })
			const wrapper = await mountWidget([sparse])

			expect(wrapper.vm.items).toHaveLength(1)
			expect(wrapper.text()).toContain('The dashboard widget stays empty')
		})
	})

	describe('the creator avatar', () => {
		it('asks by account id when the creator has one', async () => {
			const wrapper = await mountWidget([], { shallow: true })

			expect(wrapper.vm.getCreatorAvatarUrl(notification()))
				.toBe('/index.php/apps/integration_jira/avatar?accountId=acc-1')
		})

		it('falls back to the account key of an older Jira', async () => {
			const wrapper = await mountWidget([], { shallow: true })
			const creator = { displayName: 'Jane Doe', avatarUrls: {}, key: 'jane.doe' }

			expect(wrapper.vm.getCreatorAvatarUrl(notification({ creator })))
				.toBe('/index.php/apps/integration_jira/avatar?accountKey=jane.doe')
		})

		it('has nothing to ask for when Jira sent no avatar urls', async () => {
			const wrapper = await mountWidget([], { shallow: true })
			const creator = { displayName: 'Jane Doe' }

			expect(wrapper.vm.getCreatorAvatarUrl(notification({ creator }))).toBe('')
		})

		it('has nothing to ask for when the issue has no creator', async () => {
			const wrapper = await mountWidget([], { shallow: true })

			expect(wrapper.vm.getCreatorAvatarUrl(notification({ creator: undefined }))).toBe('')
		})
	})

	describe('an issue the widget cannot say much about', () => {
		it('renders a notification whose issue has no creator', async () => {
			const wrapper = await mountWidget([notification({ creator: undefined })])

			expect(wrapper.vm.items).toHaveLength(1)
			expect(wrapper.vm.items[0]).toMatchObject({ avatarUsername: '', subText: '#NC-42' })
			expect(wrapper.text()).toContain('The dashboard widget stays empty')
		})

		it('renders a notification whose creator has no name', async () => {
			const creator = { avatarUrls: {}, accountId: 'acc-1' }
			const wrapper = await mountWidget([notification({ creator })])

			expect(wrapper.vm.items[0].subText).toBe('#NC-42')
			expect(wrapper.text()).toContain('The dashboard widget stays empty')
		})

		it.each([
			['accountId', { displayName: 'Jane', avatarUrls: {}, accountId: 'jane doe&role=admin' }],
			['accountKey', { displayName: 'Jane', avatarUrls: {}, key: 'jane doe&role=admin' }],
		])('escapes the %s it puts in the avatar query', async (param, creator) => {
			const wrapper = await mountWidget([], { shallow: true })

			expect(wrapper.vm.getCreatorAvatarUrl(notification({ creator })))
				.toBe(`/index.php/apps/integration_jira/avatar?${param}=jane%20doe%26role%3Dadmin`)
		})
	})

	describe('fetching', () => {
		it('asks the server for the notifications of the dashboard', async () => {
			await mountWidget([])

			expect(axios.get).toHaveBeenCalledTimes(1)
			expect(axios.get).toHaveBeenCalledWith(NOTIFICATIONS_URL, {})
		})

		it('passes the project filter on to the server', async () => {
			await mountWidget([], { props: { filterProjects: true } })

			expect(axios.get).toHaveBeenCalledWith(
				'/index.php/apps/integration_jira/notifications?filterProjects=true',
				{},
			)
		})

		it('asks only for what changed once it holds notifications', async () => {
			useFixedTimers()
			await mountWidget([notification()])

			await vi.advanceTimersByTimeAsync(60000)

			expect(axios.get).toHaveBeenCalledTimes(2)
			expect(axios.get).toHaveBeenLastCalledWith(NOTIFICATIONS_URL, {
				params: { since: '2026-09-18T11:30:00.000+0000' },
			})
		})

		it('has nothing to ask about before the first answer', async () => {
			const wrapper = await mountWidget([])

			expect(wrapper.vm.lastDate).toBeNull()
		})

		it('asks about the newest notification it holds, even if it holds one', async () => {
			const wrapper = await mountWidget([notification()])

			expect(wrapper.vm.lastDate).toBe('2026-09-18T11:30:00.000+0000')
		})

		it.each([
			['first', true],
			['last', false],
		])('asks about the newest of several, when it comes %s', async (_, newestFirst) => {
			const older = notification({ updated: '2026-09-17T08:00:00.000+0000' }, { id: '10009', key: 'NC-9' })
			const held = newestFirst ? [notification(), older] : [older, notification()]
			const wrapper = await mountWidget(held)

			expect(wrapper.vm.lastDate).toBe('2026-09-18T11:30:00.000+0000')
		})

		it('polls once a minute', async () => {
			useFixedTimers()
			await mountWidget([])
			const started = Date.now()

			await vi.advanceTimersByTimeAsync(59999)
			// only the line above may have moved the clock
			expect(Date.now() - started).toBe(59999)
			expect(axios.get).toHaveBeenCalledTimes(1)

			await vi.advanceTimersByTimeAsync(1)
			expect(axios.get).toHaveBeenCalledTimes(2)

			await vi.advanceTimersByTimeAsync(120000)
			expect(axios.get).toHaveBeenCalledTimes(4)
		})
	})

	describe('what it adds on a later poll', () => {
		it('runs on a clock that tells the two comparisons apart', async () => {
			// the code this replaced compared against moment(undefined), which is
			// the clock; these tests only discriminate while it precedes the data
			useFixedTimers()

			expect(moment(CLOCK).isBefore(UPDATED)).toBe(true)
			expect(moment().isBefore(UPDATED)).toBe(true)
		})

		it('adds what the server answered with', async () => {
			useFixedTimers()
			const wrapper = await mountWidget([notification()])
			axios.get.mockResolvedValue({
				data: [notification({ updated: '2026-09-19T08:00:00.000+0000' }, { id: '10002', key: 'NC-43' })],
			})

			await vi.advanceTimersByTimeAsync(60000)

			expect(wrapper.vm.notifications.map((n) => n.key)).toEqual(['NC-43', 'NC-42'])
		})

		it('adds nothing when the server answers with nothing', async () => {
			useFixedTimers()
			const wrapper = await mountWidget([notification()])
			const before = wrapper.vm.notifications
			axios.get.mockResolvedValue({ data: [] })

			await vi.advanceTimersByTimeAsync(60000)

			expect(wrapper.vm.notifications).toBe(before)
		})

		it('keeps an issue a second request in flight had already moved past', async () => {
			// the server filters by since itself, so a notification it sends is
			// new even when an answer that arrived first has moved lastDate past it
			useFixedTimers()
			const wrapper = await mountWidget([notification({ updated: '2026-09-18T10:00:00.000+0000' })])
			let answerSlow
			axios.get.mockReturnValueOnce(new Promise((resolve) => {
				answerSlow = resolve
			}))
			axios.get.mockResolvedValueOnce({
				data: [notification({ updated: '2026-09-18T11:00:00.000+0000' }, { id: '10003', key: 'NC-44' })],
			})

			wrapper.vm.fetchNotifications()
			wrapper.vm.fetchNotifications()
			await flushPromises()
			answerSlow({
				data: [
					notification({ updated: '2026-09-18T11:00:00.000+0000' }, { id: '10003', key: 'NC-44' }),
					notification({ updated: '2026-09-18T10:30:00.000+0000' }, { id: '10002', key: 'NC-43' }),
				],
			})
			await flushPromises()

			expect(wrapper.vm.items.map((item) => item.subText)).toContain('Jane Doe #NC-43')
		})
	})

	describe('when the request fails', () => {
		it('asks the user to connect when there is no token', async () => {
			const wrapper = await mountFailing(httpError(400))

			expect(wrapper.vm.state).toBe('no-token')
			expect(wrapper.text()).toContain('No Jira account connected')
			expect(wrapper.find('.connect-button a').attributes('href'))
				.toBe('/index.php/settings/user/connected-accounts')
			expect(showError).not.toHaveBeenCalled()
		})

		it('reports an unauthorised answer to the user', async () => {
			const wrapper = await mountFailing(httpError(401))

			expect(wrapper.vm.state).toBe('error')
			expect(wrapper.text()).toContain('Error connecting to Jira')
			expect(showError).toHaveBeenCalledWith('Failed to get Jira notifications')
		})

		it('keeps quiet about anything else', async () => {
			const wrapper = await mountFailing(httpError(500))

			expect(wrapper.vm.state).toBe('loading')
			expect(showError).not.toHaveBeenCalled()
		})

		it.each([
			['no token', 400],
			['an unauthorised answer', 401],
		])('stops polling after %s', async (_, status) => {
			useFixedTimers()
			await mountFailing(httpError(status))

			await vi.advanceTimersByTimeAsync(180000)

			expect(axios.get).toHaveBeenCalledTimes(1)
		})
	})

	describe('after a transient failure', () => {
		it('says so once the failures stop looking transient', async () => {
			useFixedTimers()
			axios.get.mockRejectedValue(httpError(500))
			const wrapper = mount(Dashboard, { props: { title: 'Jira notifications' } })
			await flushPromises()

			await vi.advanceTimersByTimeAsync(60000)
			expect(wrapper.vm.state).toBe('loading')

			await vi.advanceTimersByTimeAsync(60000)
			expect(wrapper.vm.state).toBe('unreachable')
			expect(wrapper.text()).toContain('Could not reach Jira')
			// the account is fine, so there is nothing to connect
			expect(wrapper.find('.connect-button').exists()).toBe(false)
			expect(showError).not.toHaveBeenCalled()
		})

		it('forgets the failures once a poll succeeds', async () => {
			useFixedTimers()
			axios.get.mockRejectedValue(httpError(500))
			const wrapper = mount(Dashboard, { props: { title: 'Jira notifications' } })
			await flushPromises()
			await vi.advanceTimersByTimeAsync(60000)

			axios.get.mockResolvedValue({ data: [] })
			await vi.advanceTimersByTimeAsync(60000)
			expect(wrapper.vm.state).toBe('ok')

			axios.get.mockRejectedValue(httpError(500))
			await vi.advanceTimersByTimeAsync(120000)

			expect(wrapper.vm.state).toBe('ok')
		})

		it('keeps polling and recovers', async () => {
			useFixedTimers()
			axios.get.mockRejectedValueOnce(httpError(500))
			const wrapper = mount(Dashboard, { props: { title: 'Jira notifications' } })
			await flushPromises()
			expect(wrapper.vm.state).toBe('loading')

			axios.get.mockResolvedValue({ data: [notification()] })
			await vi.advanceTimersByTimeAsync(60000)

			expect(wrapper.vm.state).toBe('ok')
			expect(wrapper.vm.items).toHaveLength(1)
		})
	})

	describe('what it says when it has nothing to show', () => {
		it.each([
			['no-token', 'No Jira account connected', JiraIcon],
			['error', 'Error connecting to Jira', CloseIcon],
			['ok', 'No Jira notifications!', CheckIcon],
		])('for state %s', async (state, message, icon) => {
			const wrapper = await mountWidget([], { shallow: true })
			wrapper.vm.state = state

			expect(wrapper.vm.emptyContentMessage).toBe(message)
			expect(wrapper.vm.emptyContentIcon).toBe(icon)
		})

		it('says nothing while it is still loading', async () => {
			axios.get.mockReturnValue(new Promise(() => {}))
			const wrapper = mount(Dashboard, { props: { title: 'Jira notifications' } })

			expect(wrapper.vm.state).toBe('loading')
			expect(wrapper.vm.emptyContentMessage).toBe('')
			expect(wrapper.vm.emptyContentIcon).toBe(CheckIcon)
			expect(wrapper.text()).not.toContain('No Jira notifications!')
		})

		it('tells the user there is nothing when the server sent nothing', async () => {
			const wrapper = await mountWidget([])

			expect(wrapper.text()).toContain('No Jira notifications!')
		})
	})

	describe('the link to Jira', () => {
		it('points at the instance the notifications came from', async () => {
			// NcDashboardWidget draws the link once it has as many items as it shows
			const full = Array.from({ length: 7 }, (_, i) => notification({}, { id: `100${i}`, key: `NC-${i}` }))
			const wrapper = await mountWidget(full)

			expect(wrapper.vm.showMoreUrl).toBe('https://jira.example.com')
			expect(wrapper.find('a.more').attributes('href')).toBe('https://jira.example.com')
			expect(wrapper.find('a.more').text()).toBe('Jira notifications')
		})

		it.each([
			['first', true],
			['last', false],
		])('follows the instance of the newest notification, when it comes %s', async (_, newestFirst) => {
			const other = notification({ updated: '2026-09-17T08:00:00.000+0000' }, {
				id: '10009',
				key: 'NC-9',
				jiraUrl: 'https://other.atlassian.net',
			})
			const held = newestFirst ? [notification(), other] : [other, notification()]
			const wrapper = await mountWidget(held)

			expect(wrapper.vm.showMoreUrl).toBe('https://jira.example.com')
		})

		it('has nowhere to point before the first notification', async () => {
			const wrapper = await mountWidget([])

			expect(wrapper.vm.showMoreUrl).toBeNull()
		})
	})

	describe('the polling loop', () => {
		it('stops when the widget goes away', async () => {
			useFixedTimers()
			const wrapper = await mountWidget([])

			wrapper.unmount()
			await vi.advanceTimersByTimeAsync(180000)

			expect(axios.get).toHaveBeenCalledTimes(1)
		})

		it('stops while the browser tab is hidden and picks up again', async () => {
			useFixedTimers()
			const wrapper = await mountWidget([])

			wrapper.vm.windowVisibility = false
			await flushPromises()
			await vi.advanceTimersByTimeAsync(180000)
			expect(axios.get).toHaveBeenCalledTimes(1)

			wrapper.vm.windowVisibility = true
			await flushPromises()
			expect(axios.get).toHaveBeenCalledTimes(2)
		})

		it('follows the visibility of the browser tab', async () => {
			const wrapper = await mountWidget([])

			vi.spyOn(document, 'hidden', 'get').mockReturnValue(true)
			document.dispatchEvent(new Event('visibilitychange'))
			expect(wrapper.vm.windowVisibility).toBe(false)

			vi.spyOn(document, 'hidden', 'get').mockReturnValue(false)
			document.dispatchEvent(new Event('visibilitychange'))
			expect(wrapper.vm.windowVisibility).toBe(true)
		})

		it('stops listening for visibility when the widget goes away', async () => {
			const wrapper = await mountWidget([])
			const handler = wrapper.vm.changeWindowVisibility
			const remove = vi.spyOn(document, 'removeEventListener')

			wrapper.unmount()

			expect(remove).toHaveBeenCalledWith('visibilitychange', handler)
		})
	})
})
