<?php
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Tests;

use OC\Http\Client\ClientService;
use OCA\Jira\AppInfo\Application;
use OCA\Jira\Service\JiraAPIService;
use OCA\Jira\Service\NetworkService;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Security\ICrypto;

use PHPUnit\Framework\TestCase;

class JiraAPIServiceTest extends TestCase {

	private IUserManager $userManager;
	private ICrypto $crypto;
	private IConfig $config;
	private INotificationManager $notificationManager;
	private NetworkService $networkService;
	private IClientService $clientService;

	private JiraAPIService $apiService;

	public function testDummy() {
		$app = new Application();
		$this->assertEquals('integration_jira', $app::APP_ID);
	}

	public function setUp(): void {
		parent::setUp();

		$this->setupDummies();
	}

	private function setupDummies(): void {
		$this->userManager = $this->createMock(IUserManager::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->config = $this->createMock(IConfig::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->networkService = $this->createMock(NetworkService::class);
		$this->clientService = $this->createMock(ClientService::class);

		$this->apiService = new JiraAPIService(
			$this->userManager,
			$this->config,
			$this->notificationManager,
			$this->networkService,
			$this->crypto,
			$this->clientService
		);
	}

	public function testSearch() {
		$this->networkService->method('oauthRequest')->willReturnCallback(function (
			string $userId, string $endPoint, array $params = [], string $method = 'GET'
		) {
			if (str_contains($endPoint, 'rest/api/2/search')) {
				return json_decode(file_get_contents('tests/data/search.json'), true);
			}
			return 'dummy';
		});

		$this->config->method('getUserValue')->willReturnCallback(function (
			$userId, $appName, $key, $default = ''
		) {
			if ($key === 'url') {
				return 'jira_url';
			}
			if ($key == 'resources') {
				return "[{\"id\":\"7dc26f20-c097-4ca6-8d41-d8617d9b258e\",\"url\":\"https:\\\/\\\/ncintegration.atlassian.net\",\"name\":\"ncintegration\",\"scopes\":[\"manage:jira-project\",\"manage:jira-configuration\",\"manage:jira-data-provider\",\"read:jira-work\",\"write:jira-work\",\"read:jira-user\"],\"avatarUrl\":\"https:\\\/\\\/site-admin-avatar-cdn.prod.public.atl-paas.net\\\/avatars\\\/240\\\/koala.png\"}]";
			}
			return '';
		});

		$expected = $this->apiService->search('admin', 'zop', 0, 5);
		$this->assertEquals(1, sizeof($expected));
		$this->assertEquals('FIRST-1', $expected[0]['key']);
	}
}
