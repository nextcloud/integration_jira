/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import moment from '@nextcloud/moment'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import JiraReference from '../../components/JiraReference.vue'

/**
 * An issue as the reference provider hands it over: whatever Jira returned for
 * the issue, with the fields of the test's choosing.
 *
 * @param fields the fields to add or override
 */
function issue(fields = {}) {
	return {
		id: '10042',
		key: 'NC-42',
		fields: {
			project: { name: 'Nextcloud' },
			summary: 'The dashboard widget stays empty',
			created: '2026-09-18T10:00:00.000+0000',
			updated: '2026-09-18T11:30:00.000+0000',
			status: { name: 'In Progress' },
			issuetype: { name: 'Bug' },
			...fields,
		},
	}
}

/**
 * Render the widget, with the icon components stubbed.
 *
 * @param richObject the issue to render, or null
 */
function render(richObject) {
	return mount(JiraReference, {
		shallow: true,
		props: {
			richObjectType: 'integration_jira-issue',
			richObject,
		},
	})
}

describe('JiraReference', () => {
	it('names an issue by its project and key', () => {
		expect(render(issue()).find('.rows > .row').text()).toBe('[Nextcloud] NC-42')
	})

	it('shows status, type and summary', () => {
		const text = render(issue()).text()
		expect(text).toContain('Type: Bug')
		expect(text).toContain('Status: In Progress')
		expect(text).toContain('The dashboard widget stays empty')
	})

	describe('priority', () => {
		it.each([
			['Highest', 'dark-red'],
			['High', 'orange'],
			['Medium', 'yellow'],
			['Low', 'dark-grey'],
			['Lowest', 'light-grey'],
		])('colours %s %s', (name, colour) => {
			const wrapper = render(issue({ priority: { id: '1', name } }))
			expect(wrapper.find('.priority span').classes()).toContain(`priority-${colour}`)
			expect(wrapper.find('.priority').text()).toContain(`Priority: ${name}`)
		})

		it('colours a priority it does not know yellow', () => {
			expect(render(issue({ priority: { name: 'Blocker' } })).find('.priority span').classes())
				.toContain('priority-yellow')
		})

		it('colours an issue without a priority yellow', () => {
			expect(render(issue()).find('.priority span').classes()).toContain('priority-yellow')
		})
	})

	describe('assignee', () => {
		it('gives the display name of the assignee', () => {
			expect(render(issue({ assignee: { displayName: 'Jane Doe' } })).find('.assignee').text())
				.toContain('Assignee: Jane Doe')
		})

		it('says the issue is unassigned when nobody is', () => {
			expect(render(issue()).find('.assignee').text()).toContain('Assignee: Unassigned')
		})
	})

	describe('labels', () => {
		it('shows one badge per label', () => {
			const wrapper = render(issue({ labels: ['dashboard', 'regression'] }))
			expect(wrapper.findAll('.label-badge').map((badge) => badge.text()))
				.toEqual(['dashboard', 'regression'])
		})

		it('renders an issue whose fields carry no labels at all', () => {
			// Jira only sends the fields it is asked for, and the app asks for
			// whatever the instance returns
			const wrapper = render(issue())
			expect(wrapper.find('.labels').exists()).toBe(false)
			expect(wrapper.text()).toContain('[Nextcloud] NC-42')
		})

		it('has no label row for an empty list', () => {
			expect(render(issue({ labels: [] })).find('.labels').exists()).toBe(false)
		})

		it.each([
			['null', null],
			['a string', 'not-a-list'],
		])('has no label row when labels are %s', (_, labels) => {
			const wrapper = render(issue({ labels }))

			expect(wrapper.find('.labels').exists()).toBe(false)
			expect(wrapper.findAll('.label-badge')).toHaveLength(0)
		})
	})

	describe('dates', () => {
		it('shows created and updated, formatted for reading', () => {
			const wrapper = render(issue())

			// moment's LLL is locale dependent, so format the fixture the same way
			expect(wrapper.find('.created-time').text())
				.toBe(`Created: ${moment('2026-09-18T10:00:00.000+0000').format('LLL')}`)
			expect(wrapper.find('.updated-time').text())
				.toBe(`Updated: ${moment('2026-09-18T11:30:00.000+0000').format('LLL')}`)
		})

		it('shows no date the issue does not carry', () => {
			// moment(undefined) is the current time, which would read as today
			const wrapper = render(issue({ created: undefined, updated: undefined }))

			expect(wrapper.find('.created-time').exists()).toBe(false)
			expect(wrapper.find('.updated-time').exists()).toBe(false)
		})
	})

	it('renders an issue whose fields carry no project', () => {
		const wrapper = render({ key: 'NC-42', fields: { summary: 'No project on this one' } })

		// the title used to read "[undefined] NC-42"
		expect(wrapper.find('.rows > .row').text()).toBe('NC-42')
		expect(wrapper.find('.summary').text()).toBe('No project on this one')
	})

	it('renders without a rich object at all', () => {
		const wrapper = render(null)
		expect(wrapper.find('.created-time').exists()).toBe(false)
		expect(wrapper.find('.updated-time').exists()).toBe(false)
		expect(wrapper.find('.summary').exists()).toBe(false)
		expect(wrapper.find('.labels').exists()).toBe(false)
		expect(wrapper.find('.assignee').text()).toContain('Assignee: Unassigned')
	})
})
