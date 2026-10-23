<?php

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Controller;

use DateTime;
use OCA\Jira\AppInfo\Application;
use OCA\Jira\Service\NetworkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;

class ConfigController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IAppConfig $appConfig,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
		private NetworkService $networkService,
		private ICrypto $crypto,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @param array $values
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	#[NoAdminRequired]
	public function setConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if (in_array($key, ['basic_auth_header', 'token', 'refresh_token']) && $value !== '') {
				$value = $this->crypto->encrypt($value);
			}
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
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'base_url');
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
		if (!$this->areAdminValues($values, ['link_preview_enabled'])) {
			return new DataResponse(['error' => 'Unknown admin setting or value'], Http::STATUS_BAD_REQUEST);
		}
		foreach ($values as $key => $value) {
			$this->appConfig->setAppValueString($key, $value, lazy: true);
		}
		return new DataResponse(1);
	}

	#[PasswordConfirmationRequired]
	public function setSensitiveAdminConfig(array $values): DataResponse {
		if (!$this->areAdminValues($values, ['client_id', 'client_secret', 'forced_instance_url', 'link_preview_enabled'])) {
			return new DataResponse(['error' => 'Unknown admin setting or value'], Http::STATUS_BAD_REQUEST);
		}
		foreach ($values as $key => $value) {
			if (in_array($key, ['client_secret'], true) && $value !== '') {
				$value = $this->crypto->encrypt($value);
			}
			$this->appConfig->setAppValueString($key, $value, lazy: true);
		}
		return new DataResponse([]);
	}

	/**
	 * Whether every value is a string for one of the allowed keys. A lazy write of
	 * any other key of the app, such as the server's own enabled, would hide that
	 * row from the server.
	 *
	 * @param array $values
	 * @param list<string> $allowedKeys
	 * @return bool
	 */
	private function areAdminValues(array $values, array $allowedKeys): bool {
		foreach ($values as $key => $value) {
			if (!in_array($key, $allowedKeys, true) || !is_string($value)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $url
	 * @param string $login
	 * @param string $password
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	#[NoAdminRequired]
	public function connectToSoftware(string $url, string $login, string $password): DataResponse {
		$forcedInstanceUrl = $this->appConfig->getAppValueString('forced_instance_url', lazy: true);
		$targetInstanceUrl = ($forcedInstanceUrl === '')
			? $url
			: $forcedInstanceUrl;

		$basicAuthHeader = base64_encode($login . ':' . $password);

		$info = $this->networkService->basicRequest($targetInstanceUrl, $basicAuthHeader, 'rest/api/2/myself');
		if (isset($info['displayName'])) {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $info['displayName']);
			// self-hosted Jira identifies accounts by key, Jira Cloud (connected with an API token) by accountId
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_key', strval($info['key'] ?? ''));
			if (isset($info['accountId'])) {
				$this->config->setUserValue($this->userId, Application::APP_ID, 'user_account_id', $info['accountId']);
			}
			$this->config->setUserValue($this->userId, Application::APP_ID, 'url', $targetInstanceUrl);
			// the base URL Jira builds its links with, which differs when Nextcloud reaches Jira under another
			// name, such as Atlassian's API gateway
			$serverInfo = $this->networkService->basicRequest($targetInstanceUrl, $basicAuthHeader, 'rest/api/2/serverInfo');
			if (isset($serverInfo['baseUrl']) && is_string($serverInfo['baseUrl']) && $serverInfo['baseUrl'] !== '') {
				$this->config->setUserValue($this->userId, Application::APP_ID, 'base_url', $serverInfo['baseUrl']);
			} else {
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'base_url');
			}
			$basicAuthHeader = $basicAuthHeader === '' ? '' : $this->crypto->encrypt($basicAuthHeader);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'basic_auth_header', $basicAuthHeader);
			return new DataResponse(['user_name' => $info['displayName']]);
		} else {
			return new DataResponse(['user_name' => '', 'error' => $info['error'] ?? '']);
		}
	}

	/**
	 * receive oauth code and get oauth access token
	 *
	 * @param string $code
	 * @param string $state
	 * @return RedirectResponse
	 * @throws PreConditionNotMetException
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function oauthRedirect(string $code = '', string $state = ''): RedirectResponse {
		$configState = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_state');
		$clientID = $this->appConfig->getAppValueString('client_id', lazy: true);
		$clientSecret = $this->appConfig->getAppValueString('client_secret', lazy: true);
		$clientSecret = $clientSecret === '' ? '' : $this->crypto->decrypt($clientSecret);

		// anyway, reset state
		$this->config->deleteUserValue($this->userId, Application::APP_ID, 'oauth_state');

		if ($clientID && $clientSecret && $configState !== '' && $configState === $state) {
			$redirect_uri = $this->config->getUserValue($this->userId, Application::APP_ID, 'redirect_uri');
			$result = $this->networkService->requestOAuthAccessToken([
				'client_id' => $clientID,
				'client_secret' => $clientSecret,
				'code' => $code,
				'redirect_uri' => $redirect_uri,
				'grant_type' => 'authorization_code'
			]);
			if (isset($result['access_token'])) {
				$accessToken = $result['access_token'];
				$encryptedAccessToken = $accessToken === '' ? '' : $this->crypto->encrypt($accessToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $encryptedAccessToken);
				$refreshToken = $result['refresh_token'];
				$encryptedRefreshToken = $refreshToken === '' ? '' : $this->crypto->encrypt($refreshToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $encryptedRefreshToken);
				if (isset($result['expires_in'])) {
					$nowTs = (new Datetime())->getTimestamp();
					$expiresAt = $nowTs + (int)$result['expires_in'];
					$this->config->setUserValue($this->userId, Application::APP_ID, 'token_expires_at', $expiresAt);
				}
				// get accessible resources
				$resources = $this->networkService->oauthRequest($this->userId, 'oauth/token/accessible-resources');
				if (!isset($resources['error']) && count($resources) > 0) {
					$encodedResources = json_encode($resources);
					$this->config->setUserValue($this->userId, Application::APP_ID, 'resources', $encodedResources);
					// get user info
					$cloudId = $resources[0]['id'];
					$info = $this->networkService->oauthRequest($this->userId, 'ex/jira/' . $cloudId . '/rest/api/2/myself');
					if (isset($info['accountId'], $info['displayName'])) {
						$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $info['displayName']);
						// in cloud version, accountId is there and key is not
						$this->config->setUserValue($this->userId, Application::APP_ID, 'user_account_id', $info['accountId']);
					}
					return new RedirectResponse(
						$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts'])
						. '?jiraToken=success'
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
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts'])
			. '?jiraToken=error&message=' . urlencode($result)
		);
	}
}
