<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Tests;

use OCA\Jira\Controller\ConfigController;
use OCA\Jira\Service\NetworkService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigControllerTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private IConfig&MockObject $config;
	private NetworkService&MockObject $networkService;
	private ICrypto&MockObject $crypto;
	private ConfigController $controller;

	/** @var list<array{string, string, bool, bool}> */
	private array $writes = [];

	public function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getAppValue')->willReturnCallback(fn (string $key, string $default = ''): string => $default);
		$this->appConfig->method('setAppValueString')->willReturnCallback(
			function (string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->writes[] = [$key, $value, $lazy, $sensitive];
				return true;
			}
		);
		$this->config = $this->createMock(IConfig::class);
		// a legacy read of a lazy key gets the default on the server
		$this->config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default = '') => $default);
		$this->networkService = $this->createMock(NetworkService::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('encrypt')->willReturnCallback(fn (string $value): string => 'encrypted:' . $value);
		$this->crypto->method('decrypt')->willReturnCallback(fn (string $value): string => substr($value, strlen('encrypted:')));
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/settings/user/connected-accounts');

		$this->controller = new ConfigController(
			'integration_jira',
			$this->createMock(IRequest::class),
			$this->appConfig,
			$this->config,
			$urlGenerator,
			$this->createMock(IL10N::class),
			$this->networkService,
			$this->crypto,
			'user1',
		);
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
	 * @return array<string, string> the user values connectToSoftware stored
	 */
	private function connectToSoftwareWith(array $myself): array {
		$stored = [];
		$this->config->method('setUserValue')->willReturnCallback(
			function (string $userId, string $app, string $key, $value) use (&$stored): void {
				$stored[$key] = $value;
			}
		);
		$this->appConfig->method('getAppValueString')->willReturnCallback(fn (string $key, string $default = '') => $default);
		$this->networkService->expects($this->once())
			->method('basicRequest')
			->with('https://jira.example.com', base64_encode('login:password'), 'rest/api/2/myself')
			->willReturn($myself);

		$response = $this->controller->connectToSoftware('https://jira.example.com', 'login', 'password');

		$this->assertSame(['user_name' => 'Jane Doe'], $response->getData());
		return $stored;
	}

	public function testConnectingToSelfHostedJiraStoresTheAccountKey(): void {
		$stored = $this->connectToSoftwareWith(['displayName' => 'Jane Doe', 'key' => 'jdoe']);

		$this->assertSame('jdoe', $stored['user_key']);
		$this->assertArrayNotHasKey('user_account_id', $stored);
	}

	/**
	 * Jira Cloud has no account key; without the account id the open tickets notification
	 * finds no account to compare the assignees with.
	 */
	public function testConnectingToJiraCloudWithAnApiTokenStoresTheAccountId(): void {
		$stored = $this->connectToSoftwareWith(['displayName' => 'Jane Doe', 'accountId' => '712020:abc']);

		$this->assertSame('', $stored['user_key']);
		$this->assertSame('712020:abc', $stored['user_account_id']);
	}

	/**
	 * The base URL kept for the avatars belongs to the Jira the user disconnects from; kept, it
	 * would let the next Jira's credentials go to that host.
	 */
	public function testDisconnectingForgetsTheJiraBaseUrl(): void {
		$deleted = [];
		$this->config->method('deleteUserValue')->willReturnCallback(
			function (string $userId, string $app, string $key) use (&$deleted): void {
				$deleted[] = $key;
			}
		);

		$this->controller->setConfig(['user_name' => '']);

		$this->assertContains('base_url', $deleted);
		$this->assertContains('basic_auth_header', $deleted);
	}

	public function testSensitiveAdminValuesAreStoredLazily(): void {
		$this->controller->setSensitiveAdminConfig([
			'client_id' => 'the-client-id',
			'client_secret' => 'the-client-secret',
			'forced_instance_url' => 'https://jira.example.com',
			'link_preview_enabled' => '1',
		]);

		$this->assertSame([
			['client_id', 'the-client-id', true, false],
			['client_secret', 'encrypted:the-client-secret', true, false],
			['forced_instance_url', 'https://jira.example.com', true, false],
			['link_preview_enabled', '1', true, false],
		], $this->writes);
	}

	public function testAClearedClientSecretIsStoredEmpty(): void {
		$this->crypto->expects($this->never())->method('encrypt');

		$this->controller->setSensitiveAdminConfig(['client_secret' => '']);

		$this->assertSame([['client_secret', '', true, false]], $this->writes);
	}

	public function testAdminValuesAreStoredLazily(): void {
		$this->controller->setAdminConfig(['link_preview_enabled' => '0']);

		$this->assertSame([['link_preview_enabled', '0', true, false]], $this->writes);
	}

	/**
	 * @dataProvider provideRejectedAdminValues
	 */
	public function testAdminValuesOtherThanTheAppsOwnStringsAreRejected(string $endpoint, array $values): void {
		$response = $this->controller->$endpoint($values);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame([], $this->writes);
	}

	public static function provideRejectedAdminValues(): array {
		return [
			'the server\'s enabled row' => ['setSensitiveAdminConfig', ['enabled' => 'yes']],
			'the server\'s installed_version row' => ['setSensitiveAdminConfig', ['installed_version' => '1.5.0']],
			'a known key next to a foreign one' => ['setSensitiveAdminConfig', ['client_id' => 'the-client-id', 'enabled' => 'yes']],
			'a list instead of an object' => ['setSensitiveAdminConfig', ['the-client-id']],
			'a null value' => ['setSensitiveAdminConfig', ['client_secret' => null]],
			'a boolean value' => ['setSensitiveAdminConfig', ['link_preview_enabled' => true]],
			'an array value' => ['setSensitiveAdminConfig', ['forced_instance_url' => ['https://jira.example.com']]],
			'a secret without password confirmation' => ['setAdminConfig', ['client_secret' => 'the-client-secret']],
			'the server\'s enabled row without password confirmation' => ['setAdminConfig', ['enabled' => 'yes']],
			'an integer value' => ['setAdminConfig', ['link_preview_enabled' => 1]],
		];
	}

	public function testConnectingToSelfHostedJiraUsesTheForcedInstance(): void {
		$this->storeLazyAppValues(['forced_instance_url' => 'https://forced.example.com']);
		$this->networkService->expects($this->once())
			->method('basicRequest')
			->with('https://forced.example.com', base64_encode('jdoe:password'), 'rest/api/2/myself')
			->willReturn(['error' => 'Bad credentials']);

		$response = $this->controller->connectToSoftware('https://jira.example.com', 'jdoe', 'password');

		$this->assertSame(['user_name' => '', 'error' => 'Bad credentials'], $response->getData());
	}

	public function testConnectingToSelfHostedJiraUsesTheGivenUrlWithoutAForcedInstance(): void {
		$this->storeLazyAppValues([]);
		$this->networkService->expects($this->once())
			->method('basicRequest')
			->with('https://jira.example.com', base64_encode('jdoe:password'), 'rest/api/2/myself')
			->willReturn(['error' => 'Bad credentials']);

		$this->controller->connectToSoftware('https://jira.example.com', 'jdoe', 'password');
	}

	public function testOAuthRedirectSendsTheStoredClientCredentials(): void {
		$this->storeLazyAppValues([
			'client_id' => 'the-client-id',
			'client_secret' => 'encrypted:the-client-secret',
		]);
		$this->config->method('getUserValue')->willReturnCallback(
			fn ($userId, $appName, $key, $default = '') => match ($key) {
				'oauth_state' => 'the-state',
				'redirect_uri' => 'https://cloud.example.com/apps/integration_jira/oauth-redirect',
				default => $default,
			}
		);
		$this->networkService->expects($this->once())
			->method('requestOAuthAccessToken')
			->with([
				'client_id' => 'the-client-id',
				'client_secret' => 'the-client-secret',
				'code' => 'the-code',
				'redirect_uri' => 'https://cloud.example.com/apps/integration_jira/oauth-redirect',
				'grant_type' => 'authorization_code',
			])
			->willReturn(['error' => 'invalid_grant']);

		$this->controller->oauthRedirect('the-code', 'the-state');
	}
}
