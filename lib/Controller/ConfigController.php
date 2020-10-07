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
use Psr\Log\LoggerInterface;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;

use OCP\AppFramework\Http\ContentSecurityPolicy;

use OCP\IRequest;
use OCP\IDBConnection;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCP\Http\Client\IClientService;

use OCA\Jira\Service\JiraAPIService;
use OCA\Jira\AppInfo\Application;

class ConfigController extends Controller {


	private $userId;
	private $config;
	private $dbconnection;
	private $dbtype;

	public function __construct($AppName,
								IRequest $request,
								IServerContainer $serverContainer,
								IConfig $config,
								IAppManager $appManager,
								IAppData $appData,
								IDBConnection $dbconnection,
								IURLGenerator $urlGenerator,
								IL10N $l,
								LoggerInterface $logger,
								IClientService $clientService,
								JiraAPIService $jiraAPIService,
								$userId) {
		parent::__construct($AppName, $request);
		$this->l = $l;
		$this->userId = $userId;
		$this->appData = $appData;
		$this->serverContainer = $serverContainer;
		$this->config = $config;
		$this->dbconnection = $dbconnection;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
		$this->clientService = $clientService;
		$this->jiraAPIService = $jiraAPIService;
	}

	/**
	 * set config values
	 * @NoAdminRequired
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
		}

		if (isset($values['user_name']) && $values['user_name'] === '') {
			// logout
			$this->config->setUserValue($this->userId, Application::APP_ID, 'basic_auth_header', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'token', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_key', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_account_id', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'resources', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'last_open_check', '');
		}

		$response = new DataResponse(1);
		return $response;
	}

	/**
	 * set admin config values
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}
		$response = new DataResponse(1);
		return $response;
	}

	/**
	 * @param string $url
	 * @param string $login
	 * @param string $password
	 * @return DataResponse
	 */
	public function connectToSoftware(string $url, string $login, string $password): DataResponse {
		$basicAuthHeader = base64_encode($login . ':' . $password);

		$info = $this->jiraAPIService->basicRequest($url, $basicAuthHeader, 'rest/api/2/myself');
		if (isset($info['displayName'])) {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $info['displayName']);
			// in self hosted version, key is the only account identifier
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_key', strval($info['key']));
			$this->config->setUserValue($this->userId, Application::APP_ID, 'url', $url);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'basic_auth_header', $basicAuthHeader);
			return new DataResponse(['user_name' => $info['displayName']]);
		} else {
			return new DataResponse(['user_name' => '', 'error' => $info['error'] ?? '']);
		}
	}

	/**
	 * receive oauth code and get oauth access token
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $code
	 * @param string $state
	 * @return RedirectResponse
	 */
	public function oauthRedirect(string $code = '', string $state = ''): RedirectResponse {
		$configState = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_state', '');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');

		// anyway, reset state
		$this->config->setUserValue($this->userId, Application::APP_ID, 'oauth_state', '');

		if ($clientID && $clientSecret && $configState !== '' && $configState === $state) {
			$redirect_uri = $this->urlGenerator->linkToRouteAbsolute('integration_jira.config.oauthRedirect');
			$result = $this->jiraAPIService->requestOAuthAccessToken([
				'client_id' => $clientID,
				'client_secret' => $clientSecret,
				'code' => $code,
				'redirect_uri' => $redirect_uri,
				'grant_type' => 'authorization_code'
			], 'POST');
			if (isset($result['access_token'])) {
				$accessToken = $result['access_token'];
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $accessToken);
				$refreshToken = $result['refresh_token'];
				$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $refreshToken);
				// get accessible resources
				$resources = $this->jiraAPIService->oauthRequest($accessToken, $refreshToken, $clientID, $clientSecret, $this->userId, 'oauth/token/accessible-resources');
				if (!isset($resources['error']) && count($resources) > 0) {
					$encodedResources = json_encode($resources);
					$this->config->setUserValue($this->userId, Application::APP_ID, 'resources', $encodedResources);
					// get user info
					$cloudId = $resources[0]['id'];
					$info = $this->jiraAPIService->oauthRequest($accessToken, $refreshToken, $clientID, $clientSecret, $this->userId, 'ex/jira/'.$cloudId.'/rest/api/2/myself');
					if (isset($info['accountId'], $info['displayName'])) {
						$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $info['displayName']);
						// in cloud version, accountId is there and key is not
						$this->config->setUserValue($this->userId, Application::APP_ID, 'user_account_id', $info['accountId']);
					}
					return new RedirectResponse(
						$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']) .
						'?jiraToken=success'
					);
				} else {
					$result = $this->l->t('Error getting OAuth accessible resource list.') . ' ' . $resources['error'];
				}
			} else {
				$result = $this->l->t('Error getting OAuth access token.') . ' ' . $result['error'];
			}
		} else {
			$result = $this->l->t('Error during OAuth exchanges');
		}
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']) .
			'?jiraToken=error&message=' . urlencode($result)
		);
	}
}
