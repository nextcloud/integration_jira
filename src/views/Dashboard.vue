<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<DashboardWidget :items="items"
		:show-more-url="showMoreUrl"
		:show-more-text="title"
		:loading="state === 'loading'">
		<template #empty-content>
			<NcEmptyContent
				v-if="emptyContentMessage">
				<template #icon>
					<component :is="emptyContentIcon" />
				</template>
				<template #desc>
					{{ emptyContentMessage }}
					<div v-if="state === 'no-token' || state === 'error'" class="connect-button">
						<a :href="settingsUrl">
							<NcButton>
								<template #icon>
									<LoginVariantIcon :size="20" />
								</template>
								{{ t('integration_jira', 'Connect to Jira') }}
							</NcButton>
						</a>
					</div>
				</template>
			</NcEmptyContent>
		</template>
	</DashboardWidget>
</template>

<script>
import LoginVariantIcon from 'vue-material-design-icons/LoginVariant.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

import JiraIcon from '../components/icons/JiraIcon.vue'

import axios from '@nextcloud/axios'
import { generateUrl, imagePath } from '@nextcloud/router'
import { DashboardWidget } from '@nextcloud/vue-dashboard'
import { showError } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

export default {
	name: 'Dashboard',

	components: {
		DashboardWidget,
		NcEmptyContent,
		NcButton,
		JiraIcon,
		LoginVariantIcon,
		CloseIcon,
		CheckIcon,
	},

	props: {
		title: {
			type: String,
			required: true,
		},
		filterProjects: {
			type: Boolean,
			required: false,
			default: () => false,
		},
	},

	data() {
		return {
			notifications: [],
			jiraUrl: null,
			loop: null,
			state: 'loading',
			settingsUrl: generateUrl('/settings/user/connected-accounts'),
			themingColor: OCA.Theming ? OCA.Theming.color.replace('#', '') : '0082C9',
			darkThemeColor: OCA.Accessibility?.theme === 'dark' ? 'ffffff' : '181818',
			windowVisibility: true,
		}
	},

	computed: {
		showMoreUrl() {
			return this.jiraUrl
		},
		items() {
			// only display last apparition of an issue
			const seenKeys = []
			const items = this.notifications.filter((n) => {
				if (seenKeys.includes(n.key)) {
					return false
				} else {
					seenKeys.push(n.key)
					return true
				}
			})

			return items.map((n) => {
				return {
					id: this.getUniqueKey(n),
					targetUrl: this.getNotificationTarget(n),
					avatarUrl: this.getCreatorAvatarUrl(n),
					avatarUsername: this.getCreatorDisplayName(n),
					overlayIconUrl: this.getNotificationTypeImage(n),
					mainText: this.getTargetTitle(n),
					subText: this.getSubline(n),
				}
			})
		},
		lastDate() {
			const nbNotif = this.notifications.length
			return (nbNotif > 0) ? this.notifications[0].fields.updated : null
		},
		lastMoment() {
			return moment(this.lastDate)
		},
		emptyContentMessage() {
			if (this.state === 'no-token') {
				return t('integration_jira', 'No Jira account connected')
			} else if (this.state === 'error') {
				return t('integration_jira', 'Error connecting to Jira')
			} else if (this.state === 'ok') {
				return t('integration_jira', 'No Jira notifications!')
			}
			return ''
		},
		emptyContentIcon() {
			if (this.state === 'no-token') {
				return JiraIcon
			} else if (this.state === 'error') {
				return CloseIcon
			} else if (this.state === 'ok') {
				return CheckIcon
			}
			return CheckIcon
		},
	},

	watch: {
		windowVisibility(newValue) {
			if (newValue) {
				this.launchLoop()
			} else {
				this.stopLoop()
			}
		},
	},

	beforeDestroy() {
		document.removeEventListener('visibilitychange', this.changeWindowVisibility)
	},

	beforeMount() {
		this.launchLoop()
		document.addEventListener('visibilitychange', this.changeWindowVisibility)
	},

	mounted() {
	},

	methods: {
		changeWindowVisibility() {
			this.windowVisibility = !document.hidden
		},
		stopLoop() {
			clearInterval(this.loop)
		},
		async launchLoop() {
			// launch the loop
			this.fetchNotifications()
			this.loop = setInterval(() => this.fetchNotifications(), 60000)
		},
		fetchNotifications() {
			const req = {}
			if (this.lastDate) {
				req.params = {
					since: this.lastDate,
				}
			}
			axios.get(generateUrl(`/apps/integration_jira/notifications?filterProjects=${this.filterProjects}`), req).then((response) => {
				this.processNotifications(response.data)
				this.state = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_jira', 'Failed to get Jira notifications'))
					this.state = 'error'
				} else {
					// there was an error in notif processing
					console.debug(error)
				}
			})
		},
		processNotifications(newNotifications) {
			if (this.lastDate) {
				// just add those which are more recent than our most recent one
				let i = 0
				while (i < newNotifications.length && this.lastMoment.isBefore(newNotifications[i].updated_at)) {
					i++
				}
				if (i > 0) {
					const toAdd = this.filter(newNotifications.slice(0, i))
					this.notifications = toAdd.concat(this.notifications)
				}
			} else {
				// first time we don't check the date
				this.notifications = this.filter(newNotifications)
			}
		},
		filter(notifications) {
			return notifications
		},
		getNotificationTarget(n) {
			return n.jiraUrl + '/browse/' + n.key
		},
		getUniqueKey(n) {
			return n.id + ':' + n.fields.updated
		},
		getCreatorDisplayName(n) {
			return n.fields.creator.displayName
		},
		getCreatorAvatarUrl(n) {
			return (n.fields.creator && n.fields.creator.avatarUrls)
				? n.fields.creator.accountId
					? generateUrl('/apps/integration_jira/avatar?') + encodeURIComponent('accountId') + '=' + encodeURIComponent(n.fields.creator.accountId)
					: n.fields.creator.key
						? generateUrl('/apps/integration_jira/avatar?') + encodeURIComponent('accountKey') + '=' + encodeURIComponent(n.fields.creator.key)
						: ''
				: ''
		},
		getNotificationTypeImage(n) {
			// if (n.type_lookup_id === 2 || n.type === 'update') {
			// return generateUrl('/svg/integration_jira/rename?color=ffffff')
			// } else if (n.type_lookup_id === 3 || n.type === 'create') {
			// return generateUrl('/svg/integration_jira/add?color=ffffff')
			// }
			return imagePath('integration_jira', 'sound-border.svg')
		},
		getSubline(n) {
			return this.getCreatorDisplayName(n) + ' #' + n.key
		},
		getTargetTitle(n) {
			return n.fields.summary
		},
		getFormattedDate(n) {
			return moment(n.fields.updated).format('LLL')
		},
	},
}
</script>

<style scoped lang="scss">
::v-deep .connect-button {
	margin-top: 10px;
}
</style>
