/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import Dashboard from './views/Dashboard.vue'

document.addEventListener('DOMContentLoaded', function() {

	OCA.Dashboard.register('jira_notifications', (el, { widget }) => {
		const app = createApp(Dashboard, {
			title: widget.title,
		})
		app.mixin({ methods: { t, n } })
		app.mount(el)
	})

	OCA.Dashboard.register('jira_notifications_filter', (el, { widget }) => {
		const app = createApp(Dashboard, {
			title: widget.title,
			filterProjects: true,
		})
		app.mixin({ methods: { t, n } })
		app.mount(el)
	})

})
