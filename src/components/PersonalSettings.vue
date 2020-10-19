<template>
	<div id="jira_prefs" class="section">
		<h2>
			<a class="icon icon-jira" />
			{{ t('integration_jira', 'Jira integration') }}
		</h2>
		<div id="jira-content">
			<div v-if="connected">
				<div class="jira-grid-form">
					<label>
						<a class="icon icon-checkmark-color" />
						{{ t('integration_jira', 'Connected as {username}', { username: state.user_name }) }}
					</label>
					<button @click="onLogoutClick">
						<span class="icon icon-close" />
						{{ t('integration_jira', 'Disconnect from Jira') }}
					</button>
				</div>

				<div id="jira-search-block">
					<input
						id="search-jira"
						type="checkbox"
						class="checkbox"
						:checked="state.search_enabled"
						@input="onSearchChange">
					<label for="search-jira">{{ t('integration_jira', 'Enable unified search for tickets') }}</label>
					<br><br>
					<p v-if="state.search_enabled" class="settings-hint">
						<span class="icon icon-details" />
						{{ t('integration_jira', 'Warning, everything you type in the search bar will be sent to Jira.') }}
					</p>
					<input
						id="notification-jira"
						type="checkbox"
						class="checkbox"
						:checked="state.notification_enabled"
						@input="onNotificationChange">
					<label for="notification-jira">{{ t('integration_jira', 'Enable notifications for open tickets') }}</label>
				</div>
			</div>
			<div v-else>
				<h3>
					<span class="icon icon-timezone" />
					{{ t('integration_jira', 'Jira Cloud') }}
				</h3>
				<div v-if="showOAuth">
					<button
						class="oauth-connect"
						@click="onOAuthClick">
						<span class="icon icon-external" />
						{{ t('integration_jira', 'Connect to Jira Cloud') }}
					</button>
					<br><br>
				</div>
				<div v-else>
					<p class="settings-hint">
						{{ t('integration_jira', 'Ask your Nextcloud administrator to configure a Jira Cloud OAuth app in order to be able to connect to this service.') }}
					</p>
					<br>
				</div>
				<h3>
					<span class="icon icon-home" />
					{{ t('integration_jira', 'Self-hosted Jira Software') }}
				</h3>
				<div class="jira-grid-form jira-sub">
					<label>
						<span class="icon icon-link" />
						{{ t('integration_jira', 'Jira self-hosted instance address') }}
					</label>
					<input v-model="state.url"
						type="text"
						:placeholder="t('integration_jira', 'Jira address')">
					<label v-show="state.url">
						<span class="icon icon-user" />
						{{ t('integration_jira', 'User') }}
					</label>
					<input v-show="state.url"
						v-model="login"
						type="text"
						:placeholder="t('integration_jira', 'Jira user name')"
						@keyup.enter="onSelfHostedAuth">
					<label v-show="state.url">
						<span class="icon icon-password" />
						{{ t('integration_jira', 'Password') }}
					</label>
					<input v-show="state.url"
						v-model="password"
						type="password"
						:placeholder="t('integration_jira', 'Jira password')"
						@keyup.enter="onSelfHostedAuth">
					<button v-show="state.url"
						:class="{ loading: connecting }"
						@click="onSelfHostedAuth">
						<span class="icon icon-external" />
						{{ t('integration_jira', 'Connect to your Jira instance') }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'PersonalSettings',

	components: {
	},

	props: [],

	data() {
		return {
			state: loadState('integration_jira', 'user-config'),
			login: '',
			password: '',
			connecting: false,
		}
	},

	computed: {
		showOAuth() {
			return this.state.client_id && this.state.client_secret
		},
		connected() {
			return this.state.user_name && this.state.user_name !== ''
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
			showSuccess(t('integration_jira', 'Successfully connected to Jira!'))
		} else if (zmToken === 'error') {
			showError(t('integration_jira', 'OAuth access token could not be obtained:') + ' ' + urlParams.get('message'))
		}
	},

	methods: {
		onLogoutClick() {
			this.state.user_name = ''
			this.saveOptions({ user_name: '' })
		},
		onNotificationChange(e) {
			this.state.notification_enabled = e.target.checked
			this.saveOptions({ notification_enabled: this.state.notification_enabled ? '1' : '0' })
		},
		onSearchChange(e) {
			this.state.search_enabled = e.target.checked
			this.saveOptions({ search_enabled: this.state.search_enabled ? '1' : '0' })
		},
		saveOptions(values) {
			const req = {
				values,
			}
			const url = generateUrl('/apps/integration_jira/config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_jira', 'Jira options saved'))
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
		onSelfHostedAuth() {
			this.connecting = true
			const req = {
				url: this.state.url,
				login: this.login,
				password: this.password,
			}
			const url = generateUrl('/apps/integration_jira/soft-connect')
			axios.put(url, req)
				.then((response) => {
					this.state.user_name = response.data.user_name
					if (response.data.user_name === '') {
						showError(t('integration_jira', 'Login/password are invalid or account is locked'))
					}
				})
				.catch((error) => {
					showError(
						t('integration_jira', 'Failed to connect to Jira Software')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
					this.connecting = false
				})
		},
		onOAuthClick() {
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
			const requestUrl = 'https://auth.atlassian.com/authorize?client_id=' + encodeURIComponent(this.state.client_id)
				+ '&audience=api.atlassian.com'
				+ '&scope=' + encodeURIComponent(scopes.join(' '))
				+ '&response_type=code'
				+ '&prompt=consent'
				+ '&redirect_uri=' + encodeURIComponent(this.state.redirect_uri)
				+ '&state=' + encodeURIComponent(oauthState)

			const req = {
				values: {
					oauth_state: oauthState,
					url: '',
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
.jira-sub,
.oauth-connect {
	margin-left: 40px;
}

#jira-search-block {
	margin-top: 30px;
}

.jira-grid-form label {
	line-height: 38px;
}

.jira-grid-form input {
	width: 100%;
}

.jira-grid-form {
	max-width: 600px;
	display: grid;
	grid-template: 1fr / 1fr 1fr;
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

body.theme--dark .icon-jira {
	background-image: url(./../../img/app.svg);
}

#jira-content {
	margin-left: 40px;
}

#jira-search-block .icon {
	width: 22px;
}

</style>
