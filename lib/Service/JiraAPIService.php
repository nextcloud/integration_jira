<?php

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Service;

use DateTime;
use Exception;
use OCA\Jira\AppInfo\Application;
use OCP\AppFramework\Services\IAppConfig;
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
		private IAppConfig $appConfig,
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
		return is_array($resources) ? $resources : [];
	}

	/**
	 * Whether the user connected a Jira account, with OAuth for Jira Cloud or with basic auth for a self-hosted Jira
	 */
	public function isUserConnected(string $userId): bool {
		return $this->config->getUserValue($userId, Application::APP_ID, 'token') !== ''
			|| $this->config->getUserValue($userId, Application::APP_ID, 'basic_auth_header') !== '';
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
			$forcedInstanceUrl = $this->appConfig->getAppValueString('forced_instance_url', lazy: true);
			if ($forcedInstanceUrl !== '' && $forcedInstanceUrl !== $jiraUrl) {
				return [
					'error' => 'Unauthorized Jira instance URL',
				];
			}

			$params = [];
			if ($this->isJiraCloudUrl($jiraUrl)) {
				// a Jira Cloud site connected with an API token: same endpoint and JQL as the OAuth branch below
				$endPoint = 'rest/api/3/search/jql';
				$params = ['jql' => 'assignee = currentUser()', 'fields' => '*all'];
			}
			$issuesResult = $this->networkService->basicRequest($jiraUrl, $basicAuthHeader, $endPoint, $params);
			if (isset($issuesResult['error'])) {
				return $issuesResult;
			}
			foreach ($issuesResult['issues'] as $k => $issue) {
				$issuesResult['issues'][$k]['jiraUrl'] = $jiraUrl;
				$issuesResult['issues'][$k]['my_account_id'] = $issuesResult['my_account_id'] ?? '';
				if ($filterProjects && count($dashboardJiraProjects) > 0) {
					// the personal settings save project ids, as the cloud branch below expects
					if (in_array($issuesResult['issues'][$k]['fields']['project']['id'], $dashboardJiraProjects)) {
						$myIssues[] = $issuesResult['issues'][$k];
					}
				} else {
					$myIssues[] = $issuesResult['issues'][$k];
				}
			}
		} else {
			// Jira cloud — /rest/api/2/search was removed (CHANGE-2046),
			// must use the new /rest/api/3/search/jql endpoint which requires
			// an explicit JQL and an explicit fields list.
			$endPoint = 'rest/api/3/search/jql';
			$params = ['jql' => 'assignee = currentUser()', 'fields' => '*all'];
			$resources = $this->getJiraResources($userId);

			foreach ($resources as $resource) {
				$cloudId = $resource['id'];
				$jiraUrl = $resource['url'];
				$issuesResult = $this->networkService->oauthRequest($userId, 'ex/jira/' . $cloudId . '/' . $endPoint, $params);
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
			$forcedInstanceUrl = $this->appConfig->getAppValueString('forced_instance_url', lazy: true);
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
			$forcedInstanceUrl = $this->appConfig->getAppValueString('forced_instance_url', lazy: true);
			if ($forcedInstanceUrl !== '' && $forcedInstanceUrl !== $jiraUrl) {
				return [
					'error' => 'Unauthorized Jira instance URL',
				];
			}

			if ($this->isJiraCloudUrl($jiraUrl)) {
				// a Jira Cloud site connected with an API token: same endpoint as the OAuth branch below
				$endPoint = 'rest/api/3/search/jql';
				$params['fields'] = '*all';
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
			// Jira cloud — /rest/api/2/search was removed (CHANGE-2046),
			// must use the new /rest/api/3/search/jql endpoint which requires
			// an explicit fields list.
			$endPoint = 'rest/api/3/search/jql';
			$params['fields'] = '*all';
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

	/**
	 * Whether a URL connected with login and password (or API token) is a Jira Cloud site.
	 * Jira Cloud removed rest/api/2/search (CHANGE-2046) while self-hosted Jira only has
	 * that one. The reference provider recognizes Jira Cloud links by the same domain.
	 */
	private function isJiraCloudUrl(string $url): bool {
		return str_ends_with($this->getHost($url), '.atlassian.net');
	}

	/**
	 * Whether a host is the Jira a user connected to with login and password (or API token):
	 * the host they connected to, or the host of the base URL Jira builds its links with, which
	 * differs when Nextcloud reaches Jira under another name, such as an internal address. Jira
	 * is asked for its base URL when the user connects and, while none is kept, for a host other
	 * than the connected one; an answer it gives is kept until the user disconnects or connects again.
	 */
	private function isJiraHost(string $userId, string $basicAuthHeader, string $host): bool {
		if ($host === '') {
			return false;
		}
		$jiraUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url');
		if ($host === $this->getHost($jiraUrl)) {
			return true;
		}
		$baseUrl = $this->config->getUserValue($userId, Application::APP_ID, 'base_url');
		if ($baseUrl === '') {
			$serverInfo = $this->networkService->basicRequest($jiraUrl, $basicAuthHeader, 'rest/api/2/serverInfo');
			if (!isset($serverInfo['baseUrl']) || !is_string($serverInfo['baseUrl']) || $serverInfo['baseUrl'] === '') {
				return false;
			}
			$baseUrl = $serverInfo['baseUrl'];
			$this->config->setUserValue($userId, Application::APP_ID, 'base_url', $baseUrl);
		}
		return $host === $this->getHost($baseUrl);
	}

	/**
	 * The host of a URL in lower case and without a trailing dot, or '' when it has none.
	 */
	private function getHost(string $url): string {
		$host = parse_url($url, PHP_URL_HOST);
		return is_string($host) ? rtrim(strtolower($host), '.') : '';
	}

	/**
	 * Whether the user is connected to the Jira site on this host
	 */
	public function isConnectedToSite(string $userId, string $host): bool {
		return $this->getSite($userId, $host) !== null;
	}

	/**
	 * The Jira site on this host the user is connected to: for a login and password or an API token,
	 * the host the user connected to or the host of the base URL kept from Jira (see isJiraHost),
	 * which differs when Nextcloud reaches Jira under another name, such as Atlassian's API gateway;
	 * for OAuth, one of the sites the user authorised. Only stored values decide, as the link preview
	 * asks this for every Jira link.
	 *
	 * @return array{url: string}|array{cloudId: string}|null
	 */
	private function getSite(string $userId, string $host): ?array {
		$host = strtolower($host);
		if ($this->config->getUserValue($userId, Application::APP_ID, 'basic_auth_header') !== '') {
			$jiraUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url');
			$baseUrl = $this->config->getUserValue($userId, Application::APP_ID, 'base_url');
			$jiraHosts = array_filter([$this->getHost($jiraUrl), $this->getHost($baseUrl)], fn (string $jiraHost): bool => $jiraHost !== '');
			return in_array($host, $jiraHosts, true) ? ['url' => $jiraUrl] : null;
		}
		if ($this->config->getUserValue($userId, Application::APP_ID, 'token') === '') {
			return null;
		}
		foreach ($this->getJiraResources($userId) as $resource) {
			if (is_string($resource['id'] ?? null) && is_string($resource['url'] ?? null)
				&& $this->getHost($resource['url']) === $host) {
				return ['cloudId' => $resource['id']];
			}
		}
		return null;
	}

	/**
	 * @param string $siteHost the host of the Jira site the issue is on
	 */
	public function getIssueInfo(string $userId, string $issueId, string $siteHost): ?array {
		$site = $this->getSite($userId, $siteHost);
		if ($site === null) {
			return null;
		}
		$endPoint = 'rest/api/3/issue/' . $issueId;

		// a login and password or an API token
		if (isset($site['url'])) {
			// check if there is a forced instance
			$forcedInstanceUrl = $this->appConfig->getAppValueString('forced_instance_url', lazy: true);
			if ($forcedInstanceUrl !== '' && $forcedInstanceUrl !== $site['url']) {
				return [
					'error' => 'Unauthorized Jira instance URL',
				];
			}

			$basicAuthHeader = $this->crypto->decrypt($this->config->getUserValue($userId, Application::APP_ID, 'basic_auth_header'));
			$issuesResult = $this->networkService->basicRequest($site['url'], $basicAuthHeader, $endPoint);
		} else {
			// Jira cloud
			$issuesResult = $this->networkService->oauthRequest($userId, 'ex/jira/' . $site['cloudId'] . '/' . $endPoint);
		}
		if (isset($issuesResult['error'])) {
			return $issuesResult;
		}
		return isset($issuesResult['fields']) ? $issuesResult : null;
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
			$forcedInstanceUrl = $this->appConfig->getAppValueString('forced_instance_url', lazy: true);
			if ($forcedInstanceUrl !== '' && $forcedInstanceUrl !== $jiraUrl) {
				return [
					'error' => 'Unauthorized Jira instance URL',
				];
			}

			return $this->networkService->basicRequest($jiraUrl, $basicAuthHeader, $endPoint, $params);
		} else {
			$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
			$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
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
		// the credentials only go to Jira itself: Jira Cloud serves most avatars from other hosts,
		// such as secure.gravatar.com, which need none
		$imageHost = $this->getHost($imageUrl);
		if ($basicAuthHeader !== '') {
			if ($this->isJiraHost($userId, $basicAuthHeader, $imageHost)) {
				$options['headers']['Authorization'] = 'Basic ' . $basicAuthHeader;
			}
		} elseif ($accessToken !== '') {
			if ($imageHost === 'api.atlassian.com') {
				$options['headers']['Authorization'] = 'Bearer ' . $accessToken;
			}
		} else {
			return null;
		}

		try {
			return $this->client->get($imageUrl, $options)->getBody();
		} catch (Exception $e) {
			// for example a host other than Jira refusing an avatar, as it gets no credentials
			return null;
		}
	}
}
