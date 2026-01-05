/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { registerWidget } from '@nextcloud/vue/components/NcRichText'
import { linkTo } from '@nextcloud/router'
import { getCSPNonce } from '@nextcloud/auth'

__webpack_nonce__ = getCSPNonce()  // eslint-disable-line
__webpack_public_path__ = linkTo('integration_jira', 'js/') // eslint-disable-line

registerWidget('integration_jira_search', async (el, { richObjectType, richObject, accessible }) => {
	const { createApp } = await import('vue')
	const { default: JiraReference } = await import('./components/JiraReference.vue')

	const app = createApp(
		JiraReference,
		{
			richObjectType,
			richObject,
			accessible,
		},
	)
	app.mixin({ methods: { t, n } })
	app.mount(el)
}, () => {}, { hasInteractiveView: false })
