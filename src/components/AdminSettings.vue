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
			<NcNoteCard type="info">
				{{ t('integration_jira', 'If you want to allow your Nextcloud users to use OAuth to authenticate to Jira, create an application in your Jira admin settings and set the ID and secret here.') }}
				<br>
				<a class="external" href="https://developer.atlassian.com">
					{{ t('integration_jira', 'Jira app settings') }}
				</a>
				<br><br>
				{{ t('integration_jira', 'Make sure you set the redirection/callback URL to') }}
				<br>
				<strong>{{ redirect_uri }}</strong>
				<br><br>
				{{ t('integration_jira', 'Don\'t forget to make your Jira OAuth application public.') }}
				<br>
				<a class="external" href="https://developer.atlassian.com/cloud/jira/platform/oauth-2-authorization-code-grants-3lo-for-apps/#publishing-your-oauth-2-0--3lo--app">
					{{ t('integration_jira', 'How to make Jira OAuth public') }}
				</a>
				<br><br>
				{{ t('integration_jira', 'Put the "Client ID" and "Client secret" below. Your Nextcloud users will then see a "Connect to Jira" button in their personal settings.') }}
			</NcNoteCard>
			<NcTextField
				v-model="state.client_id"
				type="password"
				:label="t('integration_jira', 'Client ID')"
				:placeholder="t('integration_jira', 'ID of your application')"
				:readonly="readonly"
				@focus="readonly = false"
				@update:model-value="onInput">
				<template #icon>
					<KeyOutlineIcon :size="20" />
				</template>
			</NcTextField>
			<NcTextField
				v-model="state.client_secret"
				type="password"
				:label="t('integration_jira', 'Client secret')"
				:placeholder="t('integration_jira', 'Your application secret')"
				:readonly="readonly"
				@focus="readonly = false"
				@update:model-value="onInput">
				<template #icon>
					<KeyOutlineIcon :size="20" />
				</template>
			</NcTextField>
			<br>
			<NcTextField
				v-model="state.forced_instance_url"
				:label="t('integration_jira', 'Restrict self hosted URL to')"
				:placeholder="t('integration_jira', 'Instance address')"
				@update:model-value="onInput">
				<template #icon>
					<EarthIcon :size="20" />
				</template>
			</NcTextField>
			<NcFormBoxSwitch
				v-model="state.link_preview_enabled"
				@update:model-value="onInput()">
				{{ t('integration_jira', 'Enable link previews') }}
			</NcFormBoxSwitch>
		</div>
	</div>
</template>

<script>
import KeyOutlineIcon from 'vue-material-design-icons/KeyOutline.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'

import JiraIcon from './icons/JiraIcon.vue'

import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'

export default {
	name: 'AdminSettings',

	components: {
		NcNoteCard,
		NcTextField,
		NcFormBoxSwitch,
		JiraIcon,
		KeyOutlineIcon,
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
					link_preview_enabled: this.state.link_preview_enabled ? '1' : '0',
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
		max-width: 800px;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	h2 {
		display: flex;
		align-items: center;
		justify-content: start;
		gap: 8px;
	}

	.line {
		display: flex;
		align-items: center;
		gap: 4px;

		> label {
			display: flex;
			align-items: center;
			gap: 4px;
		}
	}
}
</style>
