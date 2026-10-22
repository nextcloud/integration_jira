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
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
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

	/**
	 * A user connected with login and password (or an API token) to the given site.
	 */
	private function stubBasicAuthConnection(string $jiraUrl): void {
		$this->storeLazyAppValues([]);
		$this->crypto->method('decrypt')->with('encrypted')->willReturn('dXNlcjpwYXNz');
		$this->config->method('getUserValue')->willReturnCallback(
			fn ($userId, $appName, $key, $default = '') => match ($key) {
				'basic_auth_header' => 'encrypted',
				'url' => $jiraUrl,
				default => $default,
			}
		);
	}

	private const UPDATED_ISSUE = ['key' => 'NCT-1', 'fields' => ['updated' => '2026-10-22T08:00:00.000+0000']];

	/**
	 * Jira Cloud removed rest/api/2/search (CHANGE-2046) and answers 410 Gone there, so a Cloud
	 * site connected with an API token is searched like the OAuth connection does it.
	 *
	 * @dataProvider provideJiraCloudUrls
	 */
	public function testNotificationsOfAJiraCloudSiteConnectedWithAnApiTokenUseTheJqlSearch(string $url): void {
		$this->stubBasicAuthConnection($url);
		$this->networkService->expects($this->once())
			->method('basicRequest')
			->with($url, 'dXNlcjpwYXNz', 'rest/api/3/search/jql', ['jql' => 'assignee = currentUser()', 'fields' => '*all'])
			->willReturn(['issues' => [self::UPDATED_ISSUE]]);

		$issues = $this->apiService->getNotifications('user1');

		$this->assertSame(['NCT-1'], array_column($issues, 'key'));
		$this->assertSame($url, $issues[0]['jiraUrl']);
	}

	/**
	 * @dataProvider provideSelfHostedUrls
	 */
	public function testNotificationsOfASelfHostedJiraKeepTheV2Search(string $url): void {
		$this->stubBasicAuthConnection($url);
		$this->networkService->expects($this->once())
			->method('basicRequest')
			->with($url, 'dXNlcjpwYXNz', 'rest/api/2/search', [])
			->willReturn(['issues' => [self::UPDATED_ISSUE]]);

		$this->assertSame(['NCT-1'], array_column($this->apiService->getNotifications('user1'), 'key'));
	}

	/**
	 * @dataProvider provideJiraCloudUrls
	 */
	public function testSearchOnAJiraCloudSiteConnectedWithAnApiTokenUsesTheJqlSearch(string $url): void {
		$this->stubBasicAuthConnection($url);
		$this->networkService->expects($this->once())
			->method('basicRequest')
			->with($url, 'dXNlcjpwYXNz', 'rest/api/3/search/jql', ['jql' => 'text ~ "live* test*"', 'limit' => 10, 'fields' => '*all'])
			->willReturn(['issues' => [self::UPDATED_ISSUE]]);

		$this->assertSame(['NCT-1'], array_column($this->apiService->search('user1', 'live test'), 'key'));
	}

	/**
	 * @dataProvider provideSelfHostedUrls
	 */
	public function testSearchOnASelfHostedJiraKeepsTheV2Search(string $url): void {
		$this->stubBasicAuthConnection($url);
		$this->networkService->expects($this->once())
			->method('basicRequest')
			->with($url, 'dXNlcjpwYXNz', 'rest/api/2/search', ['jql' => 'text ~ "live* test*"', 'limit' => 10])
			->willReturn(['issues' => [self::UPDATED_ISSUE]]);

		$this->assertSame(['NCT-1'], array_column($this->apiService->search('user1', 'live test'), 'key'));
	}

	/**
	 * A service whose HTTP client records the URL and the headers of every GET.
	 *
	 * @param list<array{string, array}> $requests
	 */
	private function serviceRecordingRequests(array &$requests): JiraAPIService {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('image data');
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use (&$requests, $response) {
			$requests[] = [$url, $options['headers'] ?? []];
			return $response;
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		return new JiraAPIService(
			$this->userManager,
			$this->appConfig,
			$this->config,
			$this->notificationManager,
			$this->networkService,
			$this->crypto,
			$clientService,
		);
	}

	/**
	 * Jira Cloud serves most avatars from secure.gravatar.com or Atlassian's avatar CDN, which
	 * need no credentials and must not get the user's API token. A self-hosted Jira can be
	 * reached under an address other than the base URL it builds its avatar links with, and
	 * both of its hosts get the credentials.
	 *
	 * @dataProvider provideAvatarsForABasicAuthConnection
	 */
	public function testAvatarsGetTheBasicAuthCredentialsOnlyFromTheJiraSite(string $jiraUrl, ?string $baseUrl, string $avatarUrl, bool $withCredentials): void {
		$this->stubAvatarOfABasicAuthConnection($jiraUrl, '', $baseUrl === null ? ['error' => 'Client error: 404'] : ['baseUrl' => $baseUrl], $avatarUrl);
		$requests = [];

		$avatar = $this->serviceRecordingRequests($requests)->getJiraAvatar('user1', '712020:abc', '');

		$this->assertSame('image data', $avatar);
		$this->assertCount(1, $requests);
		$this->assertSame($avatarUrl, $requests[0][0]);
		$this->assertSame($withCredentials ? 'Basic dXNlcjpwYXNz' : null, $requests[0][1]['Authorization'] ?? null);
	}

	public function testTheBaseUrlIsNotAskedForAnAvatarOnTheConnectedHost(): void {
		$this->stubAvatarOfABasicAuthConnection('http://10.0.0.5:8080', '', ['baseUrl' => 'https://jira.example.com'], 'http://10.0.0.5:8080/secure/useravatar?avatarId=1');
		$requests = [];

		$this->serviceRecordingRequests($requests)->getJiraAvatar('user1', '712020:abc', '');

		$this->assertSame(['rest/api/2/user'], $this->askedEndpoints);
		$this->assertSame('Basic dXNlcjpwYXNz', $requests[0][1]['Authorization'] ?? null);
		$this->assertSame([], $this->storedUserValues);
	}

	/**
	 * An avatar on another host than Jira's gets no credentials; if that host then refuses it,
	 * the user gets no avatar rather than a server error.
	 */
	public function testAnAvatarItsHostRefusesIsNoAvatar(): void {
		$this->stubAvatarOfABasicAuthConnection('http://10.0.0.5:8080', '', ['error' => 'Unexpected answer from Jira'], 'https://jira.example.com/secure/useravatar?avatarId=1');
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new \Exception('Client error: `GET https://jira.example.com/secure/useravatar?avatarId=1` resulted in a `401 Unauthorized` response'));
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$service = new JiraAPIService($this->userManager, $this->appConfig, $this->config, $this->notificationManager, $this->networkService, $this->crypto, $clientService);

		$this->assertNull($service->getJiraAvatar('user1', '712020:abc', ''));
	}

	public function testAnAvatarUrlWithoutAHostGetsNoCredentialsAndAsksNothing(): void {
		$this->stubAvatarOfABasicAuthConnection('http://10.0.0.5:8080', '', ['baseUrl' => 'https://jira.example.com'], '/secure/useravatar?avatarId=1');
		$requests = [];

		$this->serviceRecordingRequests($requests)->getJiraAvatar('user1', '712020:abc', '');

		$this->assertSame(['rest/api/2/user'], $this->askedEndpoints);
		$this->assertNull($requests[0][1]['Authorization'] ?? null);
	}

	public function testTheBaseUrlJiraAnswersIsKept(): void {
		$this->stubAvatarOfABasicAuthConnection('http://10.0.0.5:8080', '', ['baseUrl' => 'https://jira.example.com', 'deploymentType' => 'Server'], 'https://jira.example.com/secure/useravatar?avatarId=1');
		$requests = [];

		$this->serviceRecordingRequests($requests)->getJiraAvatar('user1', '712020:abc', '');

		$this->assertSame(['rest/api/2/user', 'rest/api/2/serverInfo'], $this->askedEndpoints);
		$this->assertSame('Basic dXNlcjpwYXNz', $requests[0][1]['Authorization'] ?? null);
		$this->assertSame(['base_url' => 'https://jira.example.com'], $this->storedUserValues);
	}

	/**
	 * An error or an answer without a non-empty base URL string gives no other host the
	 * credentials and is not kept, so Jira is asked again for the next avatar on another host.
	 *
	 * @dataProvider provideUnusableServerInfoAnswers
	 */
	public function testAnUnusableServerInfoAnswerGivesNoOtherHostTheCredentials(array $serverInfo): void {
		$this->stubAvatarOfABasicAuthConnection('http://10.0.0.5:8080', '', $serverInfo, 'https://jira.example.com/secure/useravatar?avatarId=1');
		$requests = [];

		$avatar = $this->serviceRecordingRequests($requests)->getJiraAvatar('user1', '712020:abc', '');

		$this->assertSame('image data', $avatar);
		$this->assertSame(['rest/api/2/user', 'rest/api/2/serverInfo'], $this->askedEndpoints);
		$this->assertNull($requests[0][1]['Authorization'] ?? null);
		$this->assertSame([], $this->storedUserValues);
	}

	public static function provideUnusableServerInfoAnswers(): array {
		return [
			'an error' => [['error' => 'Unexpected answer from Jira']],
			'no base URL' => [['version' => '10.3.0']],
			'an empty base URL' => [['baseUrl' => '']],
			'a base URL that is a list' => [['baseUrl' => ['https://jira.example.com']]],
			'a base URL that is a number' => [['baseUrl' => 8080]],
		];
	}

	/** @var list<string> the endpoints the stubbed Jira was asked */
	private array $askedEndpoints = [];

	/** @var array<string, string> the user values stored */
	private array $storedUserValues = [];

	/**
	 * A user connected with login and password (or API token) to $jiraUrl, whose Jira answers
	 * serverInfo with $serverInfo and the account with an avatar at $avatarUrl; $storedBaseUrl
	 * is a base URL kept from before.
	 */
	private function stubAvatarOfABasicAuthConnection(string $jiraUrl, string $storedBaseUrl, array $serverInfo, string $avatarUrl): void {
		$this->storeLazyAppValues([]);
		$this->crypto->method('decrypt')->with('encrypted')->willReturn('dXNlcjpwYXNz');
		$this->config->method('getUserValue')->willReturnCallback(
			fn ($userId, $appName, $key, $default = '') => match ($key) {
				'basic_auth_header' => 'encrypted',
				'url' => $jiraUrl,
				'base_url' => $storedBaseUrl,
				default => $default,
			}
		);
		$this->config->method('setUserValue')->willReturnCallback(
			function ($userId, $appName, $key, $value): void {
				$this->storedUserValues[$key] = $value;
			}
		);
		$this->networkService->method('basicRequest')->willReturnCallback(
			function (string $url, string $authHeader, string $endPoint, array $params = []) use ($jiraUrl, $serverInfo, $avatarUrl): array {
				$this->assertSame([$jiraUrl, 'dXNlcjpwYXNz'], [$url, $authHeader]);
				$this->askedEndpoints[] = $endPoint;
				return match ($endPoint) {
					'rest/api/2/user' => ['avatarUrls' => ['48x48' => $avatarUrl]],
					'rest/api/2/serverInfo' => $serverInfo,
				};
			}
		);
	}

	public function testAKeptBaseUrlIsNotAskedAgain(): void {
		$this->stubAvatarOfABasicAuthConnection('http://10.0.0.5:8080', 'https://jira.example.com', ['baseUrl' => 'https://other.example.com'], 'https://jira.example.com/secure/useravatar?avatarId=1');
		$requests = [];

		$this->serviceRecordingRequests($requests)->getJiraAvatar('user1', '712020:abc', '');

		$this->assertSame(['rest/api/2/user'], $this->askedEndpoints);
		$this->assertSame('Basic dXNlcjpwYXNz', $requests[0][1]['Authorization'] ?? null);
		$this->assertSame([], $this->storedUserValues);
	}

	public static function provideAvatarsForABasicAuthConnection(): array {
		return [
			'Jira Cloud, gravatar' => ['https://example.atlassian.net', 'https://example.atlassian.net', 'https://secure.gravatar.com/avatar/4c09?d=x', false],
			'Jira Cloud, Atlassian avatar CDN' => ['https://example.atlassian.net', 'https://example.atlassian.net', 'https://avatar-management--avatars.us-west-2.prod.public.atl-paas.net/initials/ZW-1.png', false],
			'self-hosted, avatar on the site' => ['https://jira.example.com', 'https://jira.example.com', 'https://jira.example.com/secure/useravatar?avatarId=10122', true],
			'self-hosted, same host in other case' => ['https://jira.example.com', 'https://jira.example.com', 'https://JIRA.example.com./secure/useravatar?avatarId=10122', true],
			'self-hosted, avatar elsewhere' => ['https://jira.example.com', 'https://jira.example.com', 'https://www.gravatar.com/avatar/4c09', false],
			'self-hosted, host that only starts like the site' => ['https://jira.example.com', 'https://jira.example.com', 'https://jira.example.com.example.org/avatar.png', false],
			'self-hosted, host that only ends like the site' => ['https://jira.example.com', 'https://jira.example.com', 'https://xjira.example.com/avatar.png', false],
			'self-hosted, site stored in other case' => ['https://JIRA.Example.com./', 'https://JIRA.Example.com./', 'https://jira.example.com/secure/useravatar?avatarId=10122', true],
			'self-hosted reached under an internal address, avatar on its base URL' => ['http://10.0.0.5:8080', 'https://jira.example.com/', 'https://jira.example.com/secure/useravatar?avatarId=10122', true],
			'self-hosted reached under an internal address, avatar on that address' => ['http://10.0.0.5:8080', 'https://jira.example.com/', 'http://10.0.0.5:8080/secure/useravatar?avatarId=10122', true],
			'self-hosted reached under an internal address, base URL in other case' => ['http://10.0.0.5:8080', 'https://JIRA.Example.com./', 'https://jira.example.com/secure/useravatar?avatarId=10122', true],
			'self-hosted reached under an internal address, avatar elsewhere' => ['http://10.0.0.5:8080', 'https://jira.example.com/', 'https://www.gravatar.com/avatar/4c09', false],
			'self-hosted without a base URL, avatar on another name' => ['http://10.0.0.5:8080', null, 'https://jira.example.com/secure/useravatar?avatarId=10122', false],
		];
	}

	/**
	 * @dataProvider provideAvatarsForAnOAuthConnection
	 */
	public function testAvatarsGetTheOAuthTokenOnlyFromAtlassiansApi(string $avatarUrl, bool $withToken): void {
		$this->crypto->method('decrypt')->willReturnCallback(fn (string $value): string => 'decrypted-' . $value);
		$this->config->method('getUserValue')->willReturnCallback(
			fn ($userId, $appName, $key, $default = '') => match ($key) {
				'token' => 'token',
				'refresh_token' => 'refresh',
				'resources' => '[{"id":"cloud-1","url":"https://example.atlassian.net"}]',
				default => $default,
			}
		);
		$this->networkService->method('oauthRequest')
			->with('user1', 'ex/jira/cloud-1/rest/api/2/user', ['accountId' => '712020:abc'])
			->willReturn(['avatarUrls' => ['48x48' => $avatarUrl]]);
		$requests = [];

		$this->serviceRecordingRequests($requests)->getJiraAvatar('user1', '712020:abc', '');

		$this->assertCount(1, $requests);
		$this->assertSame($withToken ? 'Bearer decrypted-token' : null, $requests[0][1]['Authorization'] ?? null);
	}

	public static function provideAvatarsForAnOAuthConnection(): array {
		return [
			'gravatar' => ['https://secure.gravatar.com/avatar/4c09?d=x', false],
			'the Jira Cloud site' => ['https://example.atlassian.net/secure/useravatar?avatarId=1', false],
			'Atlassian avatar CDN' => ['https://avatar-cdn.atlassian.com/a3ba/128', false],
			'host that only ends like the API' => ['https://xapi.atlassian.com/avatar/1', false],
			'Atlassian API' => ['https://api.atlassian.com/ex/jira/cloud-1/avatar/1', true],
		];
	}

	/**
	 * Runs the open tickets job for one user whose search returns these issues.
	 *
	 * @param array<string, string> $written receives the user values the job stored
	 * @return list<array> the parameters of every notification sent
	 */
	private function runOpenTicketsJob(array $userValues, array $issues, array &$written = []): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$this->userManager->method('callForAllUsers')->willReturnCallback(function (\Closure $callback) use ($user): void {
			$callback($user);
		});
		$this->config->method('getUserValue')->willReturnCallback(
			fn ($userId, $appName, $key, $default = '') => $userValues[$key] ?? $default
		);
		$this->config->method('setUserValue')->willReturnCallback(
			function ($userId, $appName, $key, $value) use (&$written): void {
				$written[$key] = $value;
			}
		);
		// like Jira Cloud: only the JQL search is left, the v2 one answers 410 Gone
		$this->networkService->method('basicRequest')->willReturnCallback(
			fn (string $url, string $authHeader, string $endPoint) => $endPoint === 'rest/api/3/search/jql'
				? ['issues' => $issues]
				: ['error' => 'Client error: `GET ' . $url . '/' . $endPoint . '` resulted in a `410 Gone` response']
		);
		$sent = [];
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}
		$notification->method('setSubject')->willReturnCallback(function (string $subject, array $params) use (&$sent, $notification) {
			$sent[] = [$subject, $params];
			return $notification;
		});
		$this->notificationManager->method('createNotification')->willReturn($notification);

		$this->apiService->checkOpenTickets();
		return $sent;
	}

	private static function issueAssignedTo(string $key, string $accountId, string $statusCategory): array {
		return ['key' => $key, 'fields' => [
			'updated' => '2026-10-22T08:00:00.000+0000',
			'assignee' => ['accountId' => $accountId],
			'status' => ['statusCategory' => ['key' => $statusCategory]],
		]];
	}

	/**
	 * Jira Cloud's assignees carry an account id and no key, so the job counts a user's open
	 * issues only with the account id stored when the site was connected.
	 */
	public function testTheOpenTicketsJobNotifiesAJiraCloudUserConnectedWithAnApiToken(): void {
		$this->storeLazyAppValues([]);
		$this->crypto->method('decrypt')->willReturn('dXNlcjpwYXNz');
		$written = [];
		$sent = $this->runOpenTicketsJob(
			['notification_enabled' => '1', 'basic_auth_header' => 'encrypted', 'url' => 'https://example.atlassian.net', 'user_account_id' => '712020:me'],
			[
				self::issueAssignedTo('NCT-1', '712020:me', 'new'),
				self::issueAssignedTo('NCT-2', '712020:me', 'indeterminate'),
				self::issueAssignedTo('NCT-3', '712020:me', 'done'),
				self::issueAssignedTo('NCT-4', '712020:someone-else', 'new'),
			],
			$written,
		);

		$this->assertSame([['new_open_tickets', ['nbOpen' => 2, 'link' => 'https://example.atlassian.net']]], $sent);
		$this->assertSame(['last_open_check' => '2026-10-22T08:00:00.000+0000'], $written);
	}

	/**
	 * A connection made before the account id was stored gets no notification until the
	 * user connects again.
	 */
	public function testTheOpenTicketsJobSkipsAConnectionWithoutAnyAccountIdentifier(): void {
		$this->storeLazyAppValues([]);
		$this->crypto->method('decrypt')->willReturn('dXNlcjpwYXNz');
		$written = [];
		$sent = $this->runOpenTicketsJob(
			['notification_enabled' => '1', 'basic_auth_header' => 'encrypted', 'url' => 'https://example.atlassian.net', 'user_key' => ''],
			[self::issueAssignedTo('NCT-1', '712020:me', 'new')],
			$written,
		);

		$this->assertSame([], $sent);
		// skipped before anything is counted, so the issue is still new for the next run after reconnecting
		$this->assertSame([], $written);
	}

	public static function provideJiraCloudUrls(): array {
		return [
			'site' => ['https://example.atlassian.net'],
			'trailing slash' => ['https://example.atlassian.net/'],
			'upper case' => ['https://Example.Atlassian.NET'],
			'trailing dot' => ['https://example.atlassian.net./'],
		];
	}

	public static function provideSelfHostedUrls(): array {
		return [
			'own domain' => ['https://jira.example.com'],
			'atlassian.net inside the host' => ['https://example.atlassian.net.example.com'],
			'atlassian.net in the path' => ['https://jira.example.com/example.atlassian.net'],
			'a domain ending in atlassian.net' => ['https://jira.notatlassian.net'],
		];
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
