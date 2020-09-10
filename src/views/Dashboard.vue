<template>
	<DashboardWidget :items="items"
		:show-more-url="showMoreUrl"
		:show-more-text="title"
		:loading="state === 'loading'">
		<template v-slot:empty-content>
			<div v-if="state === 'no-token'">
				<a :href="settingsUrl">
					{{ t('integration_jira', 'Click here to configure the access to your Jira account.') }}
				</a>
			</div>
			<div v-else-if="state === 'error'">
				<a :href="settingsUrl">
					{{ t('integration_jira', 'Incorrect access token.') }}
					{{ t('integration_jira', 'Click here to configure the access to your Jira account.') }}
				</a>
			</div>
			<div v-else-if="state === 'ok'">
				{{ t('integration_jira', 'Nothing to show') }}
			</div>
		</template>
	</DashboardWidget>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { DashboardWidget } from '@nextcloud/vue-dashboard'
import { showError } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'

export default {
	name: 'Dashboard',

	components: {
		DashboardWidget,
	},

	props: {
		title: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			notifications: [],
			loop: null,
			state: 'loading',
			settingsUrl: generateUrl('/settings/user/linked-accounts'),
			themingColor: OCA.Theming ? OCA.Theming.color.replace('#', '') : '0082C9',
			darkThemeColor: OCA.Accessibility.theme === 'dark' ? 'ffffff' : '181818',
		}
	},

	computed: {
		showMoreUrl() {
			return ''
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
	},

	beforeMount() {
		this.launchLoop()
	},

	mounted() {
	},

	methods: {
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
			axios.get(generateUrl('/apps/integration_jira/notifications'), req).then((response) => {
				this.processNotifications(response.data)
				this.state = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_jira', 'Failed to get Jira notifications.'))
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
				? n.fields.creator.avatarUrls
					? n.fields.creator.avatarUrls['48x48']
						? generateUrl('/apps/integration_jira/avatar?') + encodeURIComponent('image') + '=' + encodeURIComponent(n.fields.creator.avatarUrls['48x48'])
						: ''
					: ''
				: ''
		},
		getNotificationTypeImage(n) {
			// if (n.type_lookup_id === 2 || n.type === 'update') {
			// return generateUrl('/svg/integration_jira/rename?color=ffffff')
			// } else if (n.type_lookup_id === 3 || n.type === 'create') {
			// return generateUrl('/svg/integration_jira/add?color=ffffff')
			// }
			return generateUrl('/svg/core/actions/sound?color=' + this.darkThemeColor)
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
</style>
