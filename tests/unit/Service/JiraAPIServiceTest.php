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
use OCP\AppFramework\Services\IAppConfig;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class JiraAPIServiceTest extends TestCase {

	private IUserManager $userManager;
	private ICrypto $crypto;
	private IAppConfig&MockObject $appConfig;
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
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getAppValue')->willReturnCallback(fn (string $key, string $default = ''): string => $default);
		$this->config = $this->createMock(IConfig::class);
		// a legacy read of a lazy key gets the default on the server
		$this->config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default = '') => $default);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->networkService = $this->createMock(NetworkService::class);
		$this->clientService = $this->createMock(ClientService::class);

		$this->apiService = new JiraAPIService(
			$this->userManager,
			$this->appConfig,
			$this->config,
			$this->notificationManager,
			$this->networkService,
			$this->crypto,
			$this->clientService
		);
	}

	public function testSearch() {
		$this->networkService->method('oauthRequest')->willReturnCallback(function (
			string $userId, string $endPoint, array $params = [], string $method = 'GET',
		) {
			if (str_contains($endPoint, 'rest/api/3/search/jql')) {
				$this->assertSame('*all', $params['fields'] ?? null);
				return json_decode(file_get_contents('tests/data/search.json'), true);
			}
			return 'dummy';
		});

		$this->config->method('getUserValue')->willReturnCallback(function (
			$userId, $appName, $key, $default = '',
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

	/**
	 * @dataProvider provideProjectFilters
	 */
	public function testSelfHostedNotificationsAreFilteredByTheSavedProjectIds(
		string $savedProjects,
		bool $filterProjects,
		array $expectedKeys,
	): void {
		$this->crypto->method('decrypt')->willReturn('Basic dXNlcjpwYXNz');
		$this->storeLazyAppValues([]);
		$this->config->method('getUserValue')->willReturnCallback(
			fn ($userId, $appName, $key, $default = '') => match ($key) {
				'basic_auth_header' => 'encrypted',
				'url' => 'https://jira.example.com',
				'dashboard_jira_projects' => $savedProjects,
				default => $default,
			}
		);
		$this->networkService->method('basicRequest')->willReturn([
			'issues' => [
				[
					'key' => 'NC-1',
					'fields' => [
						'project' => ['id' => '10000', 'name' => 'Nextcloud'],
						'updated' => '2026-09-18T10:00:00.000+0000',
					],
				],
				[
					'key' => 'TALK-1',
					'fields' => [
						'project' => ['id' => '10001', 'name' => 'Talk'],
						'updated' => '2026-09-18T11:00:00.000+0000',
					],
				],
			],
		]);

		$notifications = $this->apiService->getNotifications('admin', null, null, $filterProjects);

		$this->assertSame($expectedKeys, array_column($notifications, 'key'));
	}

	public static function provideProjectFilters(): array {
		return [
			'one project' => ['["10000"]', true, ['NC-1']],
			'the other project' => ['["10001"]', true, ['TALK-1']],
			'both projects' => ['["10000","10001"]', true, ['TALK-1', 'NC-1']],
			'nothing saved' => ['[]', true, ['TALK-1', 'NC-1']],
			'the unfiltered widget' => ['["10000"]', false, ['TALK-1', 'NC-1']],
		];
	}

	/**
	 * @dataProvider provideProjectFilters
	 */
	public function testJiraCloudNotificationsAreFilteredByTheSavedProjectIds(
		string $savedProjects,
		bool $filterProjects,
		array $expectedKeys,
	): void {
		$this->config->method('getUserValue')->willReturnCallback(
			fn ($userId, $appName, $key, $default = '') => match ($key) {
				'basic_auth_header' => '',
				'resources' => '[{"id":"cloud-id","url":"https://ncintegration.atlassian.net"}]',
				'dashboard_jira_projects' => $savedProjects,
				default => $default,
			}
		);
		$this->networkService->method('oauthRequest')->willReturn([
			'issues' => [
				[
					'key' => 'NC-1',
					'fields' => [
						'project' => ['id' => '10000', 'name' => 'Nextcloud'],
						'updated' => '2026-09-18T10:00:00.000+0000',
					],
				],
				[
					'key' => 'TALK-1',
					'fields' => [
						'project' => ['id' => '10001', 'name' => 'Talk'],
						'updated' => '2026-09-18T11:00:00.000+0000',
					],
				],
			],
		]);

		$notifications = $this->apiService->getNotifications('admin', null, null, $filterProjects);

		$this->assertSame($expectedKeys, array_column($notifications, 'key'));
	}

	private function stubUserConfig(string $token, string $basicAuthHeader): void {
		$this->config->method('getUserValue')->willReturnCallback(
			fn ($userId, $appName, $key, $default = '') => match ($key) {
				'token' => $token,
				'basic_auth_header' => $basicAuthHeader,
				default => $default,
			}
		);
	}

	public function testUserWithoutAnyJiraAccountIsNotConnected(): void {
		$this->stubUserConfig('', '');
		$this->assertFalse($this->apiService->isUserConnected('user1'));
	}

	public function testUserWithAJiraCloudTokenIsConnected(): void {
		$this->stubUserConfig('encrypted-token', '');
		$this->assertTrue($this->apiService->isUserConnected('user1'));
	}

	public function testUserWithSelfHostedJiraCredentialsIsConnected(): void {
		$this->stubUserConfig('', 'encrypted-basic-auth-header');
		$this->assertTrue($this->apiService->isUserConnected('user1'));
	}

	/**
	 * Answer like the server once the app values are stored lazy: a read without
	 * lazy: true only sees the non-lazy values, so it gets the default.
	 */
	private function storeLazyAppValues(array $values): void {
		$this->appConfig->method('getAppValueString')->willReturnCallback(
			fn (string $key, string $default = '', bool $lazy = false): string => $lazy ? ($values[$key] ?? $default) : $default
		);
	}

	/**
	 * @dataProvider provideSelfHostedRequests
	 */
	public function testSelfHostedRequestsAreRefusedForAnInstanceOtherThanTheForcedOne(string $method, array $arguments): void {
		$this->storeLazyAppValues(['forced_instance_url' => 'https://forced.example.com']);
		$this->crypto->method('decrypt')->willReturn('dXNlcjpwYXNz');
		$this->config->method('getUserValue')->willReturnCallback(
			fn ($userId, $appName, $key, $default = '') => match ($key) {
				'basic_auth_header' => 'encrypted',
				'url' => 'https://jira.example.com',
				default => $default,
			}
		);
		$this->networkService->expects($this->never())->method('basicRequest');

		$this->assertSame(['error' => 'Unauthorized Jira instance URL'], $this->apiService->$method(...$arguments));
	}

	/**
	 * @dataProvider provideSelfHostedRequests
	 */
	public function testSelfHostedRequestsGoToTheUsersInstanceWithoutAForcedOne(string $method, array $arguments): void {
		$this->storeLazyAppValues([]);
		$this->crypto->method('decrypt')->willReturn('dXNlcjpwYXNz');
		$this->config->method('getUserValue')->willReturnCallback(
			fn ($userId, $appName, $key, $default = '') => match ($key) {
				'basic_auth_header' => 'encrypted',
				'url' => 'https://jira.example.com',
				default => $default,
			}
		);
		$this->networkService->expects($this->once())
			->method('basicRequest')
			->with('https://jira.example.com', 'dXNlcjpwYXNz')
			->willReturn(['error' => 'Bad credentials']);

		$this->assertSame(['error' => 'Bad credentials'], $this->apiService->$method(...$arguments));
	}

	public static function provideSelfHostedRequests(): array {
		return [
			'notifications' => ['getNotifications', ['user1']],
			'projects' => ['getProjects', ['user1']],
			'search' => ['search', ['user1', 'crash']],
			'issue info' => ['getIssueInfo', ['user1', 'NC-1']],
			'account info' => ['getAccountInfo', ['user1', '', 'jdoe']],
		];
	}
}
