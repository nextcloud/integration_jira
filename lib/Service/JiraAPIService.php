<?php

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Service;

use DateTime;
use OCA\Jira\AppInfo\Application;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;

class JiraAPIService {

	private IClient $client;

	/**
	 * Service to make requests to Jira v3 (JSON) API
	 */
	public function __construct(
		private IUserManager $userManager,
		private IConfig $config,
		private INotificationManager $notificationManager,
		private NetworkService $networkService,
		private ICrypto $crypto,
		IClientService $clientService,
	) {
		$this->client = $clientService->newClient();
	}

	/**
	 * triggered by a cron job
	 * notifies user of their number of new tickets
	 *
	 * @return void
	 */
	public function checkOpenTickets(): void {
		$this->userManager->callForAllUsers(function (IUser $user) {
			$this->checkOpenTicketsForUser($user->getUID());
		});
	}

	/**
	 * @param string $userId
	 * @return void
	 * @throws PreConditionNotMetException
	 */
	private function checkOpenTicketsForUser(string $userId): void {
		$notificationEnabled = ($this->config->getUserValue($userId, Application::APP_ID, 'notification_enabled', '0') === '1');
		if ($notificationEnabled) {
			$lastNotificationCheck = $this->config->getUserValue($userId, Application::APP_ID, 'last_open_check');
			$lastNotificationCheck = $lastNotificationCheck === '' ? null : $lastNotificationCheck;

			$notifications = $this->getNotifications($userId, $lastNotificationCheck);
			if (!isset($notifications['error']) && count($notifications) > 0) {
				$myAccountKey = $this->config->getUserValue($userId, Application::APP_ID, 'user_key');
				$myAccountId = $this->config->getUserValue($userId, Application::APP_ID, 'user_account_id');
				if ($myAccountKey === '' && $myAccountId === '') {
					return;
				}
				$jiraUrl = $notifications[0]['jiraUrl'];
				$lastNotificationCheck = $notifications[0]['fields']['updated'];
				$this->config->setUserValue($userId, Application::APP_ID, 'last_open_check', $lastNotificationCheck);
				$nbOpen = 0;
				foreach ($notifications as $n) {
					$status_key = $n['fields']['status']['statusCategory']['key'] ?? '';
					$assigneeKey = $n['fields']['assignee']['key'] ?? '';
					$assigneeId = $n['fields']['assignee']['accountId'] ?? '';
					$embeddedAccountId = $n['my_account_id'] ?? '';
					// from what I saw, key is used in self-hosted and accountId in cloud version
					// embeddedAccountId can be usefull when accessing multiple cloud resources, it being specific to the resource
					if ((
						($myAccountKey !== '' && $assigneeKey === $myAccountKey)
						|| ($myAccountId !== '' && $myAccountId === $assigneeId)
						|| ($embeddedAccountId !== '' && $embeddedAccountId === $assigneeId)
					)
					&& $status_key !== 'done') {
						$nbOpen++;
					}
				}
				if ($nbOpen > 0) {
					$this->sendNCNotification($userId, 'new_open_tickets', [
						'nbOpen' => $nbOpen,
						'link' => $jiraUrl
					]);
				}
			}
		}
	}

	/**
	 * @param string $userId
	 * @param string $subject
	 * @param array $params
	 * @return void
	 */
	private function sendNCNotification(string $userId, string $subject, array $params): void {
		$manager = $this->notificationManager;
		$notification = $manager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject('dum', 'dum')
			->setSubject($subject, $params);

		$manager->notify($notification);
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function getJiraResources(string $userId): array {
		$strRes = $this->config->getUserValue($userId, Application::APP_ID, 'resources');
		$resources = json_decode($strRes, true);
		return ($resources && count($resources) > 0) ? $resources : [];
	}

	/**
	 * @param string $userId
	 * @param ?string $since
	 * @param ?int $limit
	 * @return array
	 */
	public function getNotifications(string $userId, ?string $since = null, ?int $limit = null, bool $filterProjects = false): array {
		$myIssues = [];

		$endPoint = 'rest/api/2/search';

		$basicAuthHeader = $this->config->getUserValue($userId, Application::APP_ID, 'basic_auth_header');
		$basicAuthHeader = $basicAuthHeader === '' ? '' : $this->crypto->decrypt($basicAuthHeader);
		$dashboardJiraProjects = $this->config->getUserValue($userId, Application::APP_ID, 'dashboard_jira_projects', '[]');
		$dashboardJiraProjects = json_decode($dashboardJiraProjects, true);

		// self-hosted Jira
		if ($basicAuthHeader !== '') {
			$jiraUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url');

			// check if there is a forced instance
			$forcedInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'forced_instance_url');
			if ($forcedInstanceUrl !== '' && $forcedInstanceUrl !== $jiraUrl) {
				return [
					'error' => 'Unauthorized Jira instance URL',
				];
			}

			$issuesResult = $this->networkService->basicRequest($jiraUrl, $basicAuthHeader, $endPoint);
			if (isset($issuesResult['error'])) {
				return $issuesResult;
			}
			foreach ($issuesResult['issues'] as $k => $issue) {
				$issuesResult['issues'][$k]['jiraUrl'] = $jiraUrl;
				$issuesResult['issues'][$k]['my_account_id'] = $issuesResult['my_account_id'] ?? '';
				if ($filterProjects && count($dashboardJiraProjects) > 0) {
					if (in_array($issuesResult['issues'][$k]['fields']['project']['name'], $dashboardJiraProjects)) {
						$myIssues[] = $issuesResult['issues'][$k];
					}
				} else {
					$myIssues[] = $issuesResult['issues'][$k];
				}
			}
		} else {
			// Jira cloud
			$resources = $this->getJiraResources($userId);

			foreach ($resources as $resource) {
				$cloudId = $resource['id'];
				$jiraUrl = $resource['url'];
				$issuesResult = $this->networkService->oauthRequest($userId, 'ex/jira/' . $cloudId . '/' . $endPoint);
				if (!isset($issuesResult['error']) && isset($issuesResult['issues'])) {
					foreach ($issuesResult['issues'] as $k => $issue) {
						$issuesResult['issues'][$k]['jiraUrl'] = $jiraUrl;
						$issuesResult['issues'][$k]['my_account_id'] = $issuesResult['my_account_id'] ?? '';
						if ($filterProjects && count($dashboardJiraProjects) > 0) {
							if (in_array($issuesResult['issues'][$k]['fields']['project']['id'], $dashboardJiraProjects)) {
								$myIssues[] = $issuesResult['issues'][$k];
							}
						} else {
							$myIssues[] = $issuesResult['issues'][$k];
						}
					}
				} else {
					return $issuesResult;
				}
			}
		}

		if (!is_null($since)) {
			$sinceDate = new Datetime($since);
			$sinceTimestamp = $sinceDate->getTimestamp();
			$myIssues = array_filter($myIssues, function ($elem) use ($sinceTimestamp) {
				$date = new Datetime($elem['fields']['updated']);
				$elemTs = $date->getTimestamp();
				return $elemTs > $sinceTimestamp;
			});
		}

		// sort by updated
		usort($myIssues, function ($a, $b) {
			$a = new Datetime($a['fields']['updated']);
			$ta = $a->getTimestamp();
			$b = new Datetime($b['fields']['updated']);
			$tb = $b->getTimestamp();
			return ($ta > $tb) ? -1 : 1;
		});

		return $myIssues;
	}

	public function getProjects(string $userId) {
		$projects = [];

		$endPoint = 'rest/api/3/project/search';

		$basicAuthHeader = $this->config->getUserValue($userId, Application::APP_ID, 'basic_auth_header');

		$basicAuthHeader = $basicAuthHeader === '' ? '' : $this->crypto->decrypt($basicAuthHeader);

		// self-hosted Jira
		if ($basicAuthHeader !== '') {
			$jiraUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url');

			// check if there is a forced instance
			$forcedInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'forced_instance_url');
			if ($forcedInstanceUrl !== '' && $forcedInstanceUrl !== $jiraUrl) {
				return [
					'error' => 'Unauthorized Jira instance URL',
				];
			}

			$projectsResult = $this->networkService->basicRequest($jiraUrl, $basicAuthHeader, $endPoint);
			if (isset($projectsResult['error'])) {
				return $projectsResult;
			}

			foreach ($projectsResult['values'] as $k => $project) {
				$projectsResult['values'][$k]['jiraUrl'] = $jiraUrl;
				$projects[] = $projectsResult['values'][$k];
			}
		} else {
			// Jira cloud
			$resources = $this->getJiraResources($userId);

			foreach ($resources as $resource) {
				$cloudId = $resource['id'];
				$jiraUrl = $resource['url'];
				$projectsResult = $this->networkService->oauthRequest($userId, 'ex/jira/' . $cloudId . '/' . $endPoint);
				if (!isset($projectsResult['error']) && isset($projectsResult['values'])) {
					foreach ($projectsResult['values'] as $k => $project) {
						$projectsResult['values'][$k]['jiraUrl'] = $jiraUrl;
						$projects[] = $projectsResult['values'][$k];
					}
				} else {
					return $projectsResult;
				}
			}
		}

		return $projects;
	}

	/**
	 * @param string $userId
	 * @param string $query
	 * @param int $offset
	 * @param int $limit
	 * @return array
	 */
	public function search(string $userId, string $query, int $offset = 0, int $limit = 7): array {
		$myIssues = [];

		$endPoint = 'rest/api/2/search';

		// jira cloud does not support "*TERM*" but just "TERM*"
		// self-hosted jira is fine with "*TERM*"...
		// other problem, '*' does not work with japanese chars (for example)
		$words = preg_split('/\s+/', $query);
		$searchString = '';
		foreach ($words as $word) {
			// put a star only if it's only latin letters
			if (preg_match('/^[a-z]+$/i', $word)) {
				$searchString .= $word . '* ';
			} else {
				$searchString .= $word . ' ';
			}
		}
		$searchString = preg_replace('/\s+\*\*/', '', $searchString);
		$searchString = preg_replace('/\s+$/', '', $searchString);

		$params = [
			'jql' => 'text ~ "' . $searchString . '"',
			'limit' => 10,
		];

		$basicAuthHeader = $this->config->getUserValue($userId, Application::APP_ID, 'basic_auth_header');
		$basicAuthHeader = $basicAuthHeader === '' ? '' : $this->crypto->decrypt($basicAuthHeader);
		// self-hosted Jira
		if ($basicAuthHeader !== '') {
			$jiraUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url');

			// check if there is a forced instance
			$forcedInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'forced_instance_url');
			if ($forcedInstanceUrl !== '' && $forcedInstanceUrl !== $jiraUrl) {
				return [
					'error' => 'Unauthorized Jira instance URL',
				];
			}

			$issuesResult = $this->networkService->basicRequest($jiraUrl, $basicAuthHeader, $endPoint, $params);
			if (isset($issuesResult['error'])) {
				return $issuesResult;
			}
			foreach ($issuesResult['issues'] as $k => $issue) {
				$issuesResult['issues'][$k]['jiraUrl'] = $jiraUrl;
				$myIssues[] = $issuesResult['issues'][$k];
			}
		} else {
			// Jira cloud
			$resources = $this->getJiraResources($userId);

			foreach ($resources as $resource) {
				$cloudId = $resource['id'];
				$jiraUrl = $resource['url'];
				$issuesResult = $this->networkService->oauthRequest($userId, 'ex/jira/' . $cloudId . '/' . $endPoint, $params);
				if (!isset($issuesResult['error']) && isset($issuesResult['issues'])) {
					foreach ($issuesResult['issues'] as $k => $issue) {
						$issuesResult['issues'][$k]['jiraUrl'] = $jiraUrl;
						$myIssues[] = $issuesResult['issues'][$k];
					}
				} else {
					return $issuesResult;
				}
			}
		}
		return array_slice($myIssues, $offset, $limit);
	}

	public function getIssueInfo(string $userId, string $issueId): ?array {
		$issueInfo = null;
		$endPoint = 'rest/api/3/issue/' . $issueId;

		$basicAuthHeader = $this->config->getUserValue($userId, Application::APP_ID, 'basic_auth_header');
		$basicAuthHeader = $basicAuthHeader === '' ? '' : $this->crypto->decrypt($basicAuthHeader);

		// self-hosted Jira
		if ($basicAuthHeader !== '') {
			$jiraUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url');

			// check if there is a forced instance
			$forcedInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'forced_instance_url');
			if ($forcedInstanceUrl !== '' && $forcedInstanceUrl !== $jiraUrl) {
				return [
					'error' => 'Unauthorized Jira instance URL',
				];
			}

			$issuesResult = $this->networkService->basicRequest($jiraUrl, $basicAuthHeader, $endPoint);
			if (isset($issuesResult['error'])) {
				return $issuesResult;
			}
			if (isset($issuesResult['fields'])) {
				$issueInfo = $issuesResult;
			}
		} else {
			// Jira cloud
			$resources = $this->getJiraResources($userId);

			foreach ($resources as $resource) {
				$cloudId = $resource['id'];
				$issuesResult = $this->networkService->oauthRequest($userId, 'ex/jira/' . $cloudId . '/' . $endPoint);
				if (!isset($issuesResult['error']) && isset($issuesResult['fields'])) {
					$issueInfo = $issuesResult;
				} else {
					return $issuesResult;
				}
			}
		}

		return $issueInfo;
	}

	/**
	 * @param string $userId
	 * @param string $accountId
	 * @param string $accountKey
	 * @return array
	 */
	public function getAccountInfo(string $userId, string $accountId, string $accountKey): array {
		$params = [];
		if ($accountId) {
			$params['accountId'] = $accountId;
		} elseif ($accountKey) {
			$params['key'] = $accountKey;
		} else {
			return ['error' => 'not found'];
		}
		$endPoint = 'rest/api/2/user';

		$basicAuthHeader = $this->config->getUserValue($userId, Application::APP_ID, 'basic_auth_header');
		$basicAuthHeader = $basicAuthHeader === '' ? '' : $this->crypto->decrypt($basicAuthHeader);
		if ($basicAuthHeader !== '') {
			$jiraUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url');

			// check if there is a forced instance
			$forcedInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'forced_instance_url');
			if ($forcedInstanceUrl !== '' && $forcedInstanceUrl !== $jiraUrl) {
				return [
					'error' => 'Unauthorized Jira instance URL',
				];
			}

			return $this->networkService->basicRequest($jiraUrl, $basicAuthHeader, $endPoint, $params);
		} else {
			$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
			$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
			//			$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
			//			$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
			if ($accessToken === '' || $refreshToken === '') {
				return ['error' => 'no credentials'];
			}

			$resources = $this->getJiraResources($userId);

			foreach ($resources as $resource) {
				$cloudId = $resource['id'];
				//				$jiraUrl = $resource['url'];
				$result = $this->networkService->oauthRequest($userId, 'ex/jira/' . $cloudId . '/' . $endPoint, $params);
				if (!isset($result['error'])) {
					return $result;
				}
			}
		}
		return ['error' => 'not found'];
	}

	/**
	 * authenticated request to get an image from jira
	 *
	 * @param string $userId
	 * @param string $accountId
	 * @param string $accountKey
	 * @return ?string
	 */
	public function getJiraAvatar(string $userId, string $accountId, string $accountKey): ?string {
		$accountInfo = $this->getAccountInfo($userId, $accountId, $accountKey);
		if (isset($accountInfo['error'])
			|| !isset($accountInfo['avatarUrls'])
			|| !isset($accountInfo['avatarUrls']['48x48'])
		) {
			return null;
		}

		$imageUrl = $accountInfo['avatarUrls']['48x48'];

		$options = [
			'headers' => [
				'User-Agent' => 'Nextcloud Jira integration',
			]
		];

		$basicAuthHeader = $this->config->getUserValue($userId, Application::APP_ID, 'basic_auth_header');
		$basicAuthHeader = $basicAuthHeader === '' ? '' : $this->crypto->decrypt($basicAuthHeader);
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$accessToken = $accessToken === '' ? '' : $this->crypto->decrypt($accessToken);
		if ($basicAuthHeader !== '') {
			$options['headers']['Authorization'] = 'Basic ' . $basicAuthHeader;
		} elseif ($accessToken !== '') {
			$options['headers']['Authorization'] = 'Bearer ' . $accessToken;
		} else {
			return null;
		}

		return $this->client->get($imageUrl, $options)->getBody();
	}
}
