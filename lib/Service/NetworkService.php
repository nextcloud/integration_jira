<?php
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Service;

use DateTime;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\Jira\AppInfo\Application;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

use Throwable;

/**
 * Service to make network requests
 */
class NetworkService {

	private IClient $client;

	public function __construct(
		private IConfig $config,
		IClientService $clientService,
		private LoggerInterface $logger,
		private ICrypto $crypto,
		private IL10N $l10n
	) {
		$this->client = $clientService->newClient();
	}

	/**
	 * @param string $authHeader
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @param string $contentType
	 * @param bool $jsonResponse
	 * @return array|mixed|resource|string|string[]|IResponse
	 * @throws PreConditionNotMetException
	 */
	public function request_integration(string $authHeader, string $endPoint, array $params = [], string $method = 'GET',
		string $contentType = '', bool $jsonResponse = true, bool $returnRaw = false) {
		return $this->request(
			$authHeader,
			Application::INTEGRATION_API_URL . $endPoint,
			$params,
			$method,
			$contentType,
			$jsonResponse,
			$returnRaw);
	}

	/**
	 * @param string $authHeader
	 * @param string $url
	 * @param array $params
	 * @param string $method
	 * @param string $contentType
	 * @param bool $jsonResponse
	 * @return array|mixed|resource|string|string[]|IResponse
	 * @throws PreConditionNotMetException
	 */
	public function request(string $authHeader, string $url, array $params = [], string $method = 'GET',
		string $contentType = '', bool $jsonResponse = true, bool $returnRaw = false) {
		try {
			$options = [
				'headers' => [
					'User-Agent' => Application::INTEGRATION_USER_AGENT,
				],
			];
			if ($contentType !== '') {
				$options['headers']['Content-Type'] = $contentType;
			}
			if ($authHeader !== '') {
				$options['headers']['Authorization'] = $authHeader;
			}

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
			} elseif ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} elseif ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} elseif ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			if ($returnRaw) {
				return $response;
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			}
			if ($jsonResponse) {
				return json_decode($body, true, flags: JSON_UNESCAPED_UNICODE);
			}
			return $body;
		} catch (ServerException | ClientException $e) {
			$body = $e->getResponse()->getBody();
			$this->logger->warning('Network API error : ' . $body, ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		} catch (Exception | Throwable $e) {
			$this->logger->warning('Network API error', ['exception' => $e, 'app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $userId
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @return array
	 * @throws PreConditionNotMetException
	 */
	public function oauthRequest(string $userId, string $endPoint, array $params = [], string $method = 'GET'): array {
		$this->checkTokenExpiration($userId);
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$accessToken = $accessToken === '' ? '' : $this->crypto->decrypt($accessToken);

		$response = $this->request_integration(
			'Bearer ' . $accessToken,
			$endPoint,
			$params,
			$method,
			returnRaw: true,
		);
		if (is_array($response)) {
			return $response;
		}
		$body = $response->getBody();
		$respCode = $response->getStatusCode();
		$headers = $response->getHeaders();

		if ($respCode >= 400) {
			return ['error' => $this->l10n->t('Bad credentials')];
		}
		$decodedResult = json_decode($body, true);
		if (isset($headers['x-aaccountid']) && is_array($headers['x-aaccountid']) && count($headers['x-aaccountid']) > 0) {
			$decodedResult['my_account_id'] = $headers['x-aaccountid'][0];
		}
		return $decodedResult;
	}

	/**
	 * @param string $userId
	 * @return void
	 * @throws PreConditionNotMetException
	 */
	private function checkTokenExpiration(string $userId): void {
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		$refreshToken = $refreshToken === '' ? '' : $this->crypto->decrypt($refreshToken);
		$expireAt = $this->config->getUserValue($userId, Application::APP_ID, 'token_expires_at');
		if ($refreshToken !== '' && $expireAt !== '') {
			$nowTs = (new Datetime())->getTimestamp();
			$expireAt = (int) $expireAt;
			// if token expires in less than a minute or is already expired
			if ($nowTs > $expireAt - 60) {
				$this->refreshToken($userId);
			}
		}
	}

	/**
	 * @param string $userId
	 * @return bool
	 * @throws PreConditionNotMetException
	 */
	private function refreshToken(string $userId): bool {
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$clientSecret = $clientSecret === '' ? '' : $this->crypto->decrypt($clientSecret);
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		$refreshToken = $refreshToken === '' ? '' : $this->crypto->decrypt($refreshToken);
		if (!$refreshToken) {
			$this->logger->error('No Jira refresh token found', ['app' => Application::APP_ID]);
			return false;
		}

		$result = $this->requestOAuthAccessToken([
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'grant_type' => 'refresh_token',
			'refresh_token' => $refreshToken,
		]);
		if (isset($result['access_token'], $result['refresh_token'])) {
			$accessToken = $result['access_token'];
			$refreshToken = $result['refresh_token'];
			$encryptedAccessToken = $accessToken === '' ? '' : $this->crypto->encrypt($accessToken);
			$this->config->setUserValue($userId, Application::APP_ID, 'token', $encryptedAccessToken);
			$encryptedRefreshToken = $refreshToken === '' ? '' : $this->crypto->encrypt($refreshToken);
			$this->config->setUserValue($userId, Application::APP_ID, 'refresh_token', $encryptedRefreshToken);
			if (isset($result['expires_in'])) {
				$nowTs = (new Datetime())->getTimestamp();
				$expiresAt = $nowTs + (int) $result['expires_in'];
				$this->config->setUserValue($userId, Application::APP_ID, 'token_expires_at', $expiresAt);
			}
			return true;
		} else {
			// impossible to refresh the token
			$this->logger->error(
				'Token is not valid anymore. Impossible to refresh it. '
				. $result['error'] . ' '
				. ($result['error_description'] ?? '[no error description]'),
				['app' => Application::APP_ID]
			);
			return false;
		}
	}

	/**
	 * @param array $params
	 * @return array
	 */
	public function requestOAuthAccessToken(array $params = []): array {
		return $this->request(
			'',
			Application::JIRA_AUTH_URL . 'oauth/token',
			$params,
			method: 'POST',
		);
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
		return $this->request(
			'Basic ' . $authHeader,
			$url . '/' . $endPoint,
			$params,
			$method,
			contentType: $method === 'POST' ? 'application/json' : '',
		);
	}
}
