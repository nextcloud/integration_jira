<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div id="jira_prefs" class="section">
		<h2>
			<JiraIcon class="icon" />
			{{ t('integration_jira', 'Jira integration') }}
		</h2>
		<div id="jira-content">
			<div v-if="connected">
				<div class="line">
					<label>
						<CheckIcon :size="20" />
						{{ t('integration_jira', 'Connected as {username}', { username: state.user_name }) }}
					</label>
					<NcButton @click="onLogoutClick">
						<template #icon>
							<CloseIcon :size="20" />
						</template>
						{{ t('integration_jira', 'Disconnect from Jira') }}
					</NcButton>
				</div>

				<div id="jira-search-block">
					<NcFormBox>
						<NcFormBoxSwitch
							v-model="state.search_enabled"
							@update:model-value="onCheckboxChanged($event, 'search_enabled')">
							{{ t('integration_jira', 'Enable unified search for tickets') }}
						</NcFormBoxSwitch>
						<NcFormBoxSwitch
							v-model="state.link_preview_enabled"
							@update:model-value="onCheckboxChanged($event, 'link_preview_enabled')">
							{{ t('integration_jira', 'Enable user link preview') }}
						</NcFormBoxSwitch>
						<NcFormBoxSwitch
							v-model="state.notification_enabled"
							@update:model-value="onCheckboxChanged($event, 'notification_enabled')">
							{{ t('integration_jira', 'Enable notifications for open tickets') }}
						</NcFormBoxSwitch>
					</NcFormBox>
					<NcNoteCard v-if="state.search_enabled" type="warning">
						{{ t('integration_jira', 'Warning, everything you type in the search bar will be sent to Jira.') }}
					</NcNoteCard>
				</div>

				<NcSelect
					v-model="selectedProjects"
					:options="jiraProjectsOptions"
					:multiple="true"
					:input-label="t('integration_jira', 'Select Jira projects for the Dashboard widget')"
					:no-wrap="true"
					:placeholder="t('integration_jira', 'Select Jira projects')"
					:loading="loadingJiraProjects"
					:disabled="loadingJiraProjects"
					@update:model-value="onJiraSelectedProjectsChanged" />
				<NcNoteCard type="info">
					{{ t('integration_jira', 'Only projects available to your Jira account are listed.') }}
				</NcNoteCard>
			</div>
			<div v-else class="form">
				<h3>
					<WebIcon :size="20" />
					{{ t('integration_jira', 'Jira Cloud') }}
				</h3>
				<div v-if="showOAuth">
					<NcButton
						class="oauth-connect"
						@click="onOAuthClick">
						<template #icon>
							<OpenInNewIcon :size="20" />
						</template>
						{{ t('integration_jira', 'Connect to Jira Cloud') }}
					</NcButton>
					<br><br>
				</div>
				<div v-else>
					<NcNoteCard type="info">
						{{ t('integration_jira', 'Ask your Nextcloud administrator to configure a Jira Cloud OAuth app in order to be able to connect to this service.') }}
					</NcNoteCard>
					<br>
				</div>
				<h3>
					<HomeIcon :size="20" />
					{{ t('integration_jira', 'Self-hosted Jira Software') }}
				</h3>
				<NcTextField v-if="state.forced_instance_url"
					:model-value="state.forced_instance_url"
					:label="t('integration_jira', 'Jira self-hosted instance address')"
					:placeholder="t('integration_jira', 'Jira address')"
					:disabled="true">
					<template #icon>
						<EarthIcon :size="20" />
					</template>
				</NcTextField>
				<NcTextField v-else
					v-model="state.url"
					:label="t('integration_jira', 'Jira self-hosted instance address')"
					:placeholder="t('integration_jira', 'Jira address')">
					<template #icon>
						<EarthIcon :size="20" />
					</template>
				</NcTextField>
				<NcTextField v-show="state.forced_instance_url || state.url"
					v-model="login"
					:label="t('integration_jira', 'User')"
					:placeholder="t('integration_jira', 'Jira user name')"
					@keyup.enter="onSelfHostedAuth">
					<template #icon>
						<AccountIcon :size="20" />
					</template>
				</NcTextField>
				<NcTextField v-show="state.forced_instance_url || state.url"
					v-model="password"
					type="password"
					:label="t('integration_jira', 'Password')"
					:placeholder="t('integration_jira', 'Jira password')"
					@keyup.enter="onSelfHostedAuth">
					<template #icon>
						<KeyOutlineIcon :size="20" />
					</template>
				</NcTextField>

				<NcButton v-show="state.forced_instance_url || state.url"
					:class="{ loading: connecting }"
					:disabled="!login || !password"
					@click="onSelfHostedAuth">
					<template #icon>
						<OpenInNewIcon :size="20" />
					</template>
					{{ t('integration_jira', 'Connect to this Jira instance') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import WebIcon from 'vue-material-design-icons/Web.vue'
import KeyOutlineIcon from 'vue-material-design-icons/KeyOutline.vue'

import JiraIcon from './icons/JiraIcon.vue'

import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcFormBox from '@nextcloud/vue/components/NcFormBox'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'PersonalSettings',

	components: {
		NcButton,
		NcSelect,
		NcNoteCard,
		NcFormBox,
		NcFormBoxSwitch,
		NcTextField,
		JiraIcon,
		CheckIcon,
		CloseIcon,
		OpenInNewIcon,
		EarthIcon,
		HomeIcon,
		AccountIcon,
		WebIcon,
		KeyOutlineIcon,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_jira', 'user-config'),
			login: '',
			password: '',
			connecting: false,
			redirect_uri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_jira/oauth-redirect'),
			dashboardJiraProjectsFilter: loadState('integration_jira', 'user-config')?.dashboard_jira_projects || [],
			jiraProjects: [],
			loadingJiraProjects: false,
			selectedProjects: ['Loading...'],
		}
	},

	computed: {
		showOAuth() {
			return this.state.client_id && this.state.client_secret
		},
		connected() {
			return this.state.user_name && this.state.user_name !== ''
		},
		jiraProjectsOptions() {
			return this.jiraProjects.map((project) => ({
				value: project.id,
				label: project.name,
			}))
		},
	},

	watch: {
	},

	mounted() {
		const paramString = window.location.search.slice(1)
		// eslint-disable-next-line
		const urlParams = new URLSearchParams(paramString)
		const zmToken = urlParams.get('jiraToken')
		if (zmToken === 'success') {
			showSuccess(t('integration_jira', 'Successfully connected to Jira!'))
		} else if (zmToken === 'error') {
			showError(t('integration_jira', 'OAuth access token could not be obtained:') + ' ' + urlParams.get('message'))
		}
		this.fetchJiraProjects()
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
		onCheckboxChanged(newValue, key) {
			this.saveOptions({ [key]: newValue ? '1' : '0' })
		},
		onJiraSelectedProjectsChanged(newValue) {
			this.saveOptions({ dashboard_jira_projects: JSON.stringify(newValue.map(({ value }) => value)) }) // save to array of strings
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
						+ ': ' + error.response.request.responseText,
					)
				})
				.then(() => {
				})
		},
		fetchJiraProjects() {
			if (!this.connected) {
				return
			}

			this.loadingJiraProjects = true
			axios.get(generateUrl('/apps/integration_jira/projects')).then((res) => {
				console.debug('Jira projects: ', res)
				this.jiraProjects = res.data
				this.selectedProjects = this.dashboardJiraProjectsFilter.map((id) => {
					const project = this.jiraProjects.find((p) => p.id === id)
					return {
						value: project.id,
						label: project.name,
					}
				})
			}).catch((error) => {
				console.debug('Failed to get Jira projects: ', error)
				showError(
					t('integration_jira', 'Failed to get Jira projects')
					+ ': ' + error.response.request.responseText,
				)
			}).finally(() => {
				this.loadingJiraProjects = false
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
						if (response.data.error) {
							showError(t('integration_jira', 'Impossible to connect to Jira instance') + ': ' + response.data.error)
						} else {
							showError(t('integration_jira', 'Login/password are invalid or account is locked'))
						}
					}
				})
				.catch((error) => {
					showError(
						t('integration_jira', 'Failed to connect to Jira Software')
						+ ': ' + error.response?.request?.responseText,
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
				+ '&redirect_uri=' + encodeURIComponent(this.redirect_uri)
				+ '&state=' + encodeURIComponent(oauthState)

			const req = {
				values: {
					oauth_state: oauthState,
					url: '',
					redirect_uri: this.redirect_uri,
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
						+ ': ' + error.response.request.responseText,
					)
				})
				.then(() => {
				})
		},
	},
}
</script>

<style scoped lang="scss">
#jira_prefs {
	#jira-content {
		margin-left: 40px;
		display: flex;
		flex-direction: column;
		max-width: 800px;
		gap: 4px;
	}

	h2 {
		display: flex;
		align-items: center;
		gap: 8px;
		justify-content: start;
	}

	h3 {
		font-weight: bold;
		display: flex;
		align-items: center;
		gap: 8px;
	}

	.form {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	.line {
		margin-bottom: 5px;
		display: flex;
		align-items: center;
		gap: 8px;

		> label {
			display: flex;
			align-items: center;
			gap: 4px;
		}
	}
}
</style>
