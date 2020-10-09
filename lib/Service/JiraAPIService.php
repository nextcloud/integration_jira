<?php
/**
 * Nextcloud - jira
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Jira\Service;

use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUser;
use OCP\Http\Client\IClientService;
use OCP\Notification\IManager as INotificationManager;
use GuzzleHttp\Exception\ClientException;

use OCA\Jira\AppInfo\Application;

class JiraAPIService {

	private $l10n;
	private $logger;

	/**
	 * Service to make requests to Jira v3 (JSON) API
	 */
	public function __construct (IUserManager $userManager,
								string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								INotificationManager $notificationManager,
								IClientService $clientService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->config = $config;
		$this->userManager = $userManager;
		$this->clientService = $clientService;
		$this->notificationManager = $notificationManager;
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
	 */
	private function checkOpenTicketsForUser(string $userId): void {
		$notificationEnabled = ($this->config->getUserValue($userId, Application::APP_ID, 'notification_enabled', '0') === '1');
		if ($notificationEnabled) {
			$lastNotificationCheck = $this->config->getUserValue($userId, Application::APP_ID, 'last_open_check', '');
			$lastNotificationCheck = $lastNotificationCheck === '' ? null : $lastNotificationCheck;

			$notifications = $this->getNotifications($userId, $lastNotificationCheck);
			if (!isset($notifications['error']) && count($notifications) > 0) {
				$myAccountKey = $this->config->getUserValue($userId, Application::APP_ID, 'user_key', '');
				$myAccountId = $this->config->getUserValue($userId, Application::APP_ID, 'user_account_id', '');
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
					$embededAccountId = $n['my_account_id'] ?? '';
					// from what i saw, key is used in self hosted and accountId in cloud version
					// embededAccountId can be usefull when accessing multiple cloud resources, it being specific to the resource
					if ( (
							($myAccountKey !== '' && $assigneeKey === $myAccountKey)
							|| ($myAccountId !== '' && $myAccountId === $assigneeId)
							|| ($embededAccountId !== '' && $embededAccountId === $assigneeId)
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
			->setDateTime(new \DateTime())
			->setObject('dum', 'dum')
			->setSubject($subject, $params);

		$manager->notify($notification);
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function getJiraResources(string $userId): array {
		$strRes = $this->config->getUserValue($userId, Application::APP_ID, 'resources', '');
		$resources = json_decode($strRes, true);
		$resources = ($resources && count($resources) > 0) ? $resources : [];
		return $resources;
	}

	/**
	 * @param string $userId
	 * @param ?string $since
	 * @param ?int $limit
	 * @return array
	 */
	public function getNotifications(string $userId, ?string $since = null, ?int $limit = null): array {
		$myIssues = [];

		$endPoint = 'rest/api/2/search';

		$basicAuthHeader = $this->config->getUserValue($userId, Application::APP_ID, 'basic_auth_header', '');
		if ($basicAuthHeader !== '') {
			$jiraUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url', '');
			$issuesResult = $this->basicRequest($jiraUrl, $basicAuthHeader, $endPoint);
			if (isset($issuesResult['error'])) {
				return $issuesResult;
			}
			foreach ($issuesResult['issues'] as $k => $issue) {
				$issuesResult['issues'][$k]['jiraUrl'] = $jiraUrl;
				$issuesResult['issues'][$k]['my_account_id'] = $issuesResult['my_account_id'];
				$myIssues[] = $issuesResult['issues'][$k];
			}
		} else {
			$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token', '');
			$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token', '');
			$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
			$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');
			if ($accessToken === '' || $refreshToken === '') {
				return ['error' => 'no credentials'];
			}
			$resources = $this->getJiraResources($userId);

			foreach ($resources as $resource) {
				$cloudId = $resource['id'];
				$jiraUrl = $resource['url'];
				$issuesResult = $this->oauthRequest(
					$accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'ex/jira/' . $cloudId . '/' . $endPoint
				);
				if (!isset($issuesResult['error']) && isset($issuesResult['issues'])) {
					foreach ($issuesResult['issues'] as $k => $issue) {
						$issuesResult['issues'][$k]['jiraUrl'] = $jiraUrl;
						$issuesResult['issues'][$k]['my_account_id'] = $issuesResult['my_account_id'];
						$myIssues[] = $issuesResult['issues'][$k];
					}
				} else {
					return $issuesResult;
				}
			}
		}

		if (!is_null($since)) {
			$sinceDate = new \Datetime($since);
			$sinceTimestamp = $sinceDate->getTimestamp();
			$myIssues = array_filter($myIssues, function($elem) use ($sinceTimestamp) {
				$date = new \Datetime($elem['fields']['updated']);
				$elemTs = $date->getTimestamp();
				return $elemTs > $sinceTimestamp;
			});
		}

		// sort by updated
		$a = usort($myIssues, function($a, $b) {
			$a = new \Datetime($a['fields']['updated']);
			$ta = $a->getTimestamp();
			$b = new \Datetime($b['fields']['updated']);
			$tb = $b->getTimestamp();
			return ($ta > $tb) ? -1 : 1;
		});

		return $myIssues;
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
		// self hosted jira is fine with "*TERM*"...
		$words = preg_split('/\s+/', $query);
		$searchString = '' . implode('* ', $words) . '*';
		$searchString = preg_replace('/\s+\*\*/', '', $searchString);

		$params = [
			'jql' => 'text ~ "'.$searchString.'"',
			'limit' => 10,
		];

		$basicAuthHeader = $this->config->getUserValue($userId, Application::APP_ID, 'basic_auth_header', '');
		if ($basicAuthHeader !== '') {
			$jiraUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url', '');
			$issuesResult = $this->basicRequest($jiraUrl, $basicAuthHeader, $endPoint, $params);
			if (isset($issuesResult['error'])) {
				return $issuesResult;
			}
			foreach ($issuesResult['issues'] as $k => $issue) {
				$issuesResult['issues'][$k]['jiraUrl'] = $jiraUrl;
				$myIssues[] = $issuesResult['issues'][$k];
			}
		} else {
			$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token', '');
			$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token', '');
			$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
			$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');
			if ($accessToken === '' || $refreshToken === '') {
				return ['error' => 'no credentials'];
			}

			$resources = $this->getJiraResources($userId);
			$myIssues = [];

			foreach ($resources as $resource) {
				$cloudId = $resource['id'];
				$jiraUrl = $resource['url'];
				$issuesResult = $this->oauthRequest(
					$accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'ex/jira/' . $cloudId . '/' . $endPoint, $params
				);
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
	 * authenticated request to get an image from jira
	 *
	 * @param string $userId
	 * @param string $imageUrl
	 * @return ?string
	 */
	public function getJiraAvatar(string $userId, string $imageUrl): ?string {
		$options = [
			'headers' => [
				'User-Agent' => 'Nextcloud Jira integration',
			]
		];

		$basicAuthHeader = $this->config->getUserValue($userId, Application::APP_ID, 'basic_auth_header', '');
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token', '');
		if ($basicAuthHeader !== '') {
			$options['headers']['Authorization'] = 'Basic ' . $basicAuthHeader;
		} elseif ($accessToken !== '') {
			$options['headers']['Authorization'] = 'Bearer ' . $accessToken;
		} else {
			return null;
		}

		return $this->client->get($imageUrl, $options)->getBody();
	}

	/**
	 * @param string $url
	 * @param string $authHeader
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @return array
	 */
	public function basicRequest(string $url, string $authHeader,
								string $endPoint, array $params = [], string $method = 'GET'): array {
		try {
			$url = $url . '/' . $endPoint;
			$options = [
				'headers' => [
					'Authorization'  => 'Basic ' . $authHeader,
					'User-Agent' => 'Nextcloud Jira integration',
				]
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					// manage array parameters
					$paramsContent = '';
					foreach ($params as $key => $value) {
						if (is_array($value)) {
							foreach ($value as $oneArrayValue) {
								$paramsContent .= $key . '[]=' . urlencode($oneArrayValue) . '&';
							}
							unset($params[$key]);
						}
					}
					$paramsContent .= http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();
			$headers = $response->getHeaders();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return json_decode($body, true);
			}
		} catch (ClientException $e) {
			$this->logger->warning('Jira API error : '.$e->getMessage(), array('app' => $this->appName));
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $accessToken
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param string $userId
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @return array
	 */
	public function oauthRequest(string $accessToken, string $refreshToken,
							string $clientID, string $clientSecret, string $userId,
							string $endPoint, array $params = [], string $method = 'GET'): array {
		try {
			$url = Application::JIRA_API_URL . '/' . $endPoint;
			$options = [
				'headers' => [
					'Authorization'  => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud Jira integration',
				]
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					// manage array parameters
					$paramsContent = '';
					foreach ($params as $key => $value) {
						if (is_array($value)) {
							foreach ($value as $oneArrayValue) {
								$paramsContent .= $key . '[]=' . urlencode($oneArrayValue) . '&';
							}
							unset($params[$key]);
						}
					}
					$paramsContent .= http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();
			$headers = $response->getHeaders();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				$decodedResult = json_decode($body, true);
				if (isset($headers['x-aaccountid']) && is_array($headers['x-aaccountid']) && count($headers['x-aaccountid']) > 0) {
					$decodedResult['my_account_id'] = $headers['x-aaccountid'][0];
				}
				return $decodedResult;
			}
		} catch (ClientException $e) {
			$this->logger->warning('Jira API error : '.$e->getMessage(), array('app' => $this->appName));
			$response = $e->getResponse();
			$body = (string) $response->getBody();
			// refresh token if it's invalid
			// response can be : 'response:\n{\"code\":401,\"message\":\"Unauthorized\"}'
			if ($response->getStatusCode() === 401) {
				$this->logger->warning('Trying to REFRESH the access token', array('app' => $this->appName));
				// try to refresh the token
				$result = $this->requestOAuthAccessToken([
					'client_id' => $clientID,
					'client_secret' => $clientSecret,
					'grant_type' => 'refresh_token',
					'refresh_token' => $refreshToken,
				], 'POST');
				if (isset($result['access_token'])) {
					$accessToken = $result['access_token'];
					$this->config->setUserValue($userId, Application::APP_ID, 'token', $accessToken);
					// retry the request with new access token
					return $this->oauthRequest(
						$accessToken, $refreshToken, $clientID, $clientSecret, $userId, $endPoint, $params, $method
					);
				}
			}
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param array $params
	 * @param string $method
	 * @return array
	 */
	public function requestOAuthAccessToken(array $params = [], string $method = 'GET'): array {
		try {
			$url = Application::JIRA_AUTH_URL . '/oauth/token';
			$options = [
				'headers' => [
					'User-Agent'  => 'Nextcloud Jira integration',
				]
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('OAuth access token refused')];
			} else {
				return json_decode($body, true);
			}
		} catch (\Exception $e) {
			$this->logger->warning('Jira OAuth error : '.$e->getMessage(), array('app' => $this->appName));
			return ['error' => $e->getMessage()];
		}
	}

}
