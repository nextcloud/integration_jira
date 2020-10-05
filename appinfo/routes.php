<?php
/**
 * Nextcloud - Jira
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

return [
    'routes' => [
        ['name' => 'config#oauthRedirect', 'url' => '/oauth-redirect', 'verb' => 'GET'],
        ['name' => 'config#connectToSoftware', 'url' => '/soft-connect', 'verb' => 'PUT'],
        ['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
        ['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
        ['name' => 'jiraAPI#getNotifications', 'url' => '/notifications', 'verb' => 'GET'],
        ['name' => 'jiraAPI#getJiraUrl', 'url' => '/url', 'verb' => 'GET'],
        ['name' => 'jiraAPI#getJiraAvatar', 'url' => '/avatar', 'verb' => 'GET'],
    ]
];
