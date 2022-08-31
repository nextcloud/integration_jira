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

use DateTime;
use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IL10N;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Jira\Service\JiraAPIService;
use OCA\Jira\AppInfo\Application;

class ConfigController extends Controller {

	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IURLGenerator
	 */
	private $urlGenerator;
	/**
	 * @var IL10N
	 */
	private $l;
	/**
	 * @var JiraAPIService
	 */
	private $jiraAPIService;
	/**
	 * @var string|null
	 */
	private $userId;

	public function __construct(string $appName,
								IRequest $request,
								IConfig $config,
								IURLGenerator $urlGenerator,
								IL10N $l,
								JiraAPIService $jiraAPIService,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->l = $l;
		$this->jiraAPIService = $jiraAPIService;
		$this->userId = $userId;
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

		if (isset($values['url']) && $values['url'] === '') {
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'url');
		}

		if (isset($values['user_name']) && $values['user_name'] === '') {
			// logout
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'basic_auth_header');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'refresh_token');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'url');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_key');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_name');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_account_id');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'resources');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'last_open_check');
		}

		return new DataResponse(1);
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
		return new DataResponse(1);
	}

	/**
	 * @NoAdminRequired
	 * @param string $url
	 * @param string $login
	 * @param string $password
	 * @return DataResponse
	 */
	public function connectToSoftware(string $url, string $login, string $password): DataResponse {
		$forcedInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'forced_instance_url');
		$targetInstanceUrl = ($forcedInstanceUrl === '')
			? $url
			: $forcedInstanceUrl;

		$basicAuthHeader = base64_encode($login . ':' . $password);

		$info = $this->jiraAPIService->basicRequest($targetInstanceUrl, $basicAuthHeader, 'rest/api/2/myself');
		if (isset($info['displayName'])) {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $info['displayName']);
			// in self hosted version, key is the only account identifier
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_key', strval($info['key']));
			$this->config->setUserValue($this->userId, Application::APP_ID, 'url', $targetInstanceUrl);
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
		$configState = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_state');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');

		// anyway, reset state
		$this->config->deleteUserValue($this->userId, Application::APP_ID, 'oauth_state');

		if ($clientID && $clientSecret && $configState !== '' && $configState === $state) {
			$redirect_uri = $this->config->getUserValue($this->userId, Application::APP_ID, 'redirect_uri');
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
				if (isset($result['expires_in'])) {
					$nowTs = (new Datetime())->getTimestamp();
					$expiresAt = $nowTs + (int) $result['expires_in'];
					$this->config->setUserValue($this->userId, Application::APP_ID, 'token_expires_at', $expiresAt);
				}
				// get accessible resources
				$resources = $this->jiraAPIService->oauthRequest($this->userId, 'oauth/token/accessible-resources');
				if (!isset($resources['error']) && count($resources) > 0) {
					$encodedResources = json_encode($resources);
					$this->config->setUserValue($this->userId, Application::APP_ID, 'resources', $encodedResources);
					// get user info
					$cloudId = $resources[0]['id'];
					$info = $this->jiraAPIService->oauthRequest($this->userId, 'ex/jira/' . $cloudId . '/rest/api/2/myself');
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
