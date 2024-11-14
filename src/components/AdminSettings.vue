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
		<p class="settings-hint">
			{{ t('integration_jira', 'If you want to allow your Nextcloud users to use OAuth to authenticate to Jira, create an application in your Jira admin settings and set the ID and secret here.') }}
		</p>
		<a class="external" href="https://developer.atlassian.com">
			{{ t('integration_jira', 'Jira app settings') }}
		</a>
		<br><br>
		<p class="settings-hint">
			<InformationOutlineIcon :size="20" class="icon" />
			{{ t('integration_jira', 'Make sure you set the redirection/callback URL to') }}
		</p>
		<strong>{{ redirect_uri }}</strong>
		<br><br>
		<p class="settings-hint">
			<InformationOutlineIcon :size="20" class="icon" />
			{{ t('integration_jira', 'Don\'t forget to make your Jira OAuth application public.') }}
		</p>
		<a class="external" href="https://developer.atlassian.com/cloud/jira/platform/oauth-2-authorization-code-grants-3lo-for-apps/#publishing-your-oauth-2-0--3lo--app">
			{{ t('integration_jira', 'How to make Jira OAuth public') }}
		</a>
		<br><br>
		<p class="settings-hint">
			{{ t('integration_jira', 'Put the "Client ID" and "Client secret" below. Your Nextcloud users will then see a "Connect to Jira" button in their personal settings.') }}
		</p>
		<div id="jira-content">
			<div class="line">
				<label for="jira-client-id">
					<KeyIcon :size="20" class="icon" />
					{{ t('integration_jira', 'Client ID') }}
				</label>
				<input id="jira-client-id"
					v-model="state.client_id"
					type="password"
					:readonly="readonly"
					:placeholder="t('integration_jira', 'ID of your application')"
					@focus="readonly = false"
					@input="onInput">
			</div>
			<div class="line">
				<label for="jira-client-secret">
					<KeyIcon :size="20" class="icon" />
					{{ t('integration_jira', 'Client secret') }}
				</label>
				<input id="jira-client-secret"
					v-model="state.client_secret"
					type="password"
					:readonly="readonly"
					:placeholder="t('integration_jira', 'Your application secret')"
					@focus="readonly = false"
					@input="onInput">
			</div>
			<br>
			<div class="line">
				<label for="jira-forced-instance">
					<EarthIcon :size="20" class="icon" />
					{{ t('integration_jira', 'Restrict self hosted URL to') }}
				</label>
				<input id="jira-forced-instance"
					v-model="state.forced_instance_url"
					type="text"
					:placeholder="t('integration_jira', 'Instance address')"
					@input="onInput">
			</div>
		</div>
	</div>
</template>

<script>
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'

import JiraIcon from './icons/JiraIcon.vue'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'

export default {
	name: 'AdminSettings',

	components: {
		JiraIcon,
		InformationOutlineIcon,
		KeyIcon,
		EarthIcon,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_jira', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
			redirect_uri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_jira/oauth-redirect'),
		}
	},

	watch: {
	},

	mounted() {
	},

	methods: {
		onInput() {
			delay(() => {
				const values = {
					client_id: this.state.client_id,
					forced_instance_url: this.state.forced_instance_url,
				}
				if (this.state.client_secret !== 'dummySecret') {
					values.client_secret = this.state.client_secret
				}
				this.saveOptions(values, true)
			}, 2000)()
		},
		async saveOptions(values, sensitive = false) {
			if (sensitive) {
				await confirmPassword()
			}
			const req = {
				values,
			}
			const url = sensitive
				? generateUrl('/apps/integration_jira/sensitive-admin-config')
				: generateUrl('/apps/integration_jira/admin-config')

			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_jira', 'Jira admin options saved'))
				})
				.catch((error) => {
					showError(
						t('integration_jira', 'Failed to save Jira admin options')
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
	}

	h2,
	.line,
	.settings-hint {
		display: flex;
		align-items: center;
		.icon {
			margin-right: 4px;
		}
	}

	h2 .icon {
		margin-right: 8px;
	}

	.line {
		> label {
			width: 300px;
			display: flex;
			align-items: center;
		}
		> input {
			width: 300px;
		}
	}
}
</style>
