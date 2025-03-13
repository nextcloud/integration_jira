/* jshint esversion: 6 */

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import Vue from 'vue'
import './bootstrap.js'
import Dashboard from './views/Dashboard.vue'

document.addEventListener('DOMContentLoaded', function() {

	OCA.Dashboard.register('jira_notifications', (el, { widget }) => {
		const View = Vue.extend(Dashboard)
		new View({
			propsData: { title: widget.title },
		}).$mount(el)
	})

	OCA.Dashboard.register('jira_notifications_filter', (el, { widget }) => {
		const View = Vue.extend(Dashboard)
		new View({
			propsData: { title: widget.title, filterProjects: true },
		}).$mount(el)
	})

})
