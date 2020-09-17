<?php
/**
 * Nextcloud - jira
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Jira\Controller;

use OCP\App\IAppManager;
use OCP\Files\IAppData;
use OCP\AppFramework\Http\DataDisplayResponse;

use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IServerContainer;
use OCP\IL10N;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;

use OCP\AppFramework\Http\ContentSecurityPolicy;

use OCP\ILogger;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Jira\Service\JiraAPIService;
use OCA\Jira\AppInfo\Application;

class JiraAPIController extends Controller {


	private $userId;
	private $config;
	private $dbconnection;
	private $dbtype;

	public function __construct($AppName,
								IRequest $request,
								IServerContainer $serverContainer,
								IConfig $config,
								IL10N $l10n,
								IAppManager $appManager,
								IAppData $appData,
								ILogger $logger,
								JiraAPIService $jiraAPIService,
								$userId) {
		parent::__construct($AppName, $request);
		$this->userId = $userId;
		$this->l10n = $l10n;
		$this->appData = $appData;
		$this->serverContainer = $serverContainer;
		$this->config = $config;
		$this->logger = $logger;
		$this->jiraAPIService = $jiraAPIService;
		$this->accessToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'token', '');
		$this->refreshToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'refresh_token', '');
		$this->clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
		$this->clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');
	}

	/**
	 * get notification list
	 * @NoAdminRequired
	 */
	public function getJiraUrl(): DataResponse {
		$resources = $this->jiraAPIService->getJiraResources($this->userId);
		$jiraUrl = count($resources) > 0 ? $resources[0]['url'] : '';
		return new DataResponse($jiraUrl);
	}

	/**
	 * get jira user avatar
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getJiraAvatar($image): DataDisplayResponse {
		$response = new DataDisplayResponse(
			$this->jiraAPIService->getJiraAvatar(
				$this->accessToken, $this->refreshToken, $this->clientID, $this->clientSecret, $image
			)
		);
		$response->cacheFor(60*60*24);
		return $response;
	}

	/**
	 * get notifications list
	 * @NoAdminRequired
	 */
	public function getNotifications(?string $since): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse('', 400);
		}
		$result = $this->jiraAPIService->getNotifications(
			$this->accessToken, $this->refreshToken, $this->clientID, $this->clientSecret, $this->userId, $since, 7
		);
		if (!isset($result['error'])) {
			$response = new DataResponse($result);
		} else {
			$response = new DataResponse($result, 401);
		}
		return $response;
	}

}
