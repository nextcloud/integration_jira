<template>
	<div v-if="showOAuth" id="jira_prefs" class="section">
		<h2>
			<a class="icon icon-jira" />
			{{ t('integration_jira', 'Jira integration') }}
		</h2>
		<p class="settings-hint">
			{{ t('integration_jira', 'Your Nextcloud administrator configured the access to {url}', { url: state.oauth_instance_url }) }}
		</p>
		<div class="jira-grid-form">
			<label for="jira-token">
				<a class="icon icon-category-auth" />
				{{ t('integration_jira', 'Jira access token') }}
			</label>
			<input id="jira-token"
				v-model="state.token"
				type="password"
				:placeholder="t('integration_jira', 'Get a token in Jira settings')"
				@input="onInput">
			<button id="jira-oauth" @click="onOAuthClick">
				<span class="icon icon-external" />
				{{ t('integration_jira', 'Get access with OAuth') }}
			</button>
		</div>
		<div id="jira-search-block">
			<input
				id="search-jira"
				type="checkbox"
				class="checkbox"
				:checked="state.search_enabled"
				@input="onSearchChange">
			<label for="search-jira">{{ t('integration_jira', 'Enable unified search for tickets.') }}</label>
			<br>
			<br>
			<input
				id="notification-jira"
				type="checkbox"
				class="checkbox"
				:checked="state.notification_enabled"
				@input="onNotificationChange">
			<label for="notification-jira">{{ t('integration_jira', 'Enable notifications for open tickets.') }}</label>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'PersonalSettings',

	components: {
	},

	props: [],

	data() {
		return {
			state: loadState('integration_jira', 'user-config'),
			initialToken: loadState('integration_jira', 'user-config').token,
		}
	},

	computed: {
		showOAuth() {
			return this.state.oauth_instance_url
				&& this.state.client_id
				&& this.state.client_secret
		},
	},

	watch: {
	},

	mounted() {
		const paramString = window.location.search.substr(1)
		// eslint-disable-next-line
		const urlParams = new URLSearchParams(paramString)
		const zmToken = urlParams.get('jiraToken')
		if (zmToken === 'success') {
			showSuccess(t('integration_jira', 'OAuth access token successfully retrieved!'))
		} else if (zmToken === 'error') {
			showError(t('integration_jira', 'OAuth access token could not be obtained:') + ' ' + urlParams.get('message'))
		}
	},

	methods: {
		onNotificationChange(e) {
			this.state.notification_enabled = e.target.checked
			this.saveOptions()
		},
		onSearchChange(e) {
			this.state.search_enabled = e.target.checked
			this.saveOptions()
		},
		onInput() {
			const that = this
			delay(function() {
				that.saveOptions()
			}, 2000)()
		},
		saveOptions() {
			const req = {
				values: {
					token: this.state.token,
					search_enabled: this.state.search_enabled ? '1' : '0',
					notification_enabled: this.state.notification_enabled ? '1' : '0',
				},
			}
			// if manually set, this is not an oauth access token
			if (this.state.token !== this.initialToken) {
				req.values.token_type = 'access'
			}
			const url = generateUrl('/apps/integration_jira/config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_jira', 'Jira options saved.'))
				})
				.catch((error) => {
					showError(
						t('integration_jira', 'Failed to save Jira options')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
				})
		},
		onOAuthClick() {
			const redirectEndpoint = generateUrl('/apps/integration_jira/oauth-redirect')
			const redirectUri = window.location.protocol + '//' + window.location.host + redirectEndpoint
			const oauthState = Math.random().toString(36).substring(3)
			const scopes = [
				'offline_access',
				'read:me',
				'read:jira-work',
				'read:jira-user',
				'write:jira-work',
				'manage:jira-project',
				'manage:jira-configuration',
				'manage:jira-data-provider',
			]
			// const requestUrl = this.state.oauth_instance_url + '/oauth/authorize?client_id=' + encodeURIComponent(this.state.client_id)
			const requestUrl = 'https://auth.atlassian.com/authorize?client_id=' + encodeURIComponent(this.state.client_id)
				+ '&audience=api.atlassian.com'
				+ '&scope=' + encodeURIComponent(scopes.join(' '))
				+ '&response_type=code'
				+ '&prompt=consent'
				+ '&redirect_uri=' + encodeURIComponent(redirectUri)
				+ '&state=' + encodeURIComponent(oauthState)

			const req = {
				values: {
					oauth_state: oauthState,
				},
			}
			const url = generateUrl('/apps/integration_jira/config')
			axios.put(url, req)
				.then((response) => {
					window.location.replace(requestUrl)
				})
				.catch((error) => {
					showError(
						t('integration_jira', 'Failed to save Jira OAuth state')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
				})
		},
	},
}
</script>

<style scoped lang="scss">
#jira-search-block {
	margin-left: 30px;
	margin-top: 30px;
}
.jira-grid-form label {
	line-height: 38px;
}
.jira-grid-form input {
	width: 100%;
}
.jira-grid-form {
	max-width: 900px;
	display: grid;
	grid-template: 1fr / 1fr 1fr 1fr;
	margin-left: 30px;
	button .icon {
		margin-bottom: -1px;
	}
}
#jira_prefs .icon {
	display: inline-block;
	width: 32px;
}
#jira_prefs .grid-form .icon {
	margin-bottom: -3px;
}
.icon-jira {
	background-image: url(./../../img/app-dark.svg);
	background-size: 23px 23px;
	height: 23px;
	margin-bottom: -4px;
}
body.dark .icon-jira {
	background-image: url(./../../img/app.svg);
}
</style>
