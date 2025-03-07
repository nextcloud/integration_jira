<?php
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'config#oauthRedirect', 'url' => '/oauth-redirect', 'verb' => 'GET'],
		['name' => 'config#connectToSoftware', 'url' => '/soft-connect', 'verb' => 'PUT'],
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
		['name' => 'config#setSensitiveAdminConfig', 'url' => '/sensitive-admin-config', 'verb' => 'PUT'],
		['name' => 'jiraAPI#getNotifications', 'url' => '/notifications', 'verb' => 'GET'],
		['name' => 'jiraAPI#getProjects', 'url' => '/projects', 'verb' => 'GET'],
		['name' => 'jiraAPI#getJiraUrl', 'url' => '/url', 'verb' => 'GET'],
		['name' => 'jiraAPI#getJiraAvatar', 'url' => '/avatar', 'verb' => 'GET'],
	]
];
