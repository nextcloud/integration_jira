<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Tests;

use OCA\Jira\AppInfo\Application;
use OCA\Jira\Service\NetworkService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NetworkServiceTest extends TestCase {

	/** @var list<string> */
	private array $warnings = [];

	private function networkServiceAnswering(string $body): NetworkService {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});
		return new NetworkService(
			$this->createMock(IAppConfig::class),
			$this->createMock(IConfig::class),
			$clientService,
			$logger,
			$this->createMock(ICrypto::class),
			$l10n,
		);
	}

	/**
	 * A proxy in front of Jira can answer with an HTML page and status 200; that is no answer
	 * from Jira, and the callers expect an array.
	 *
	 * @dataProvider provideAnswersThatAreNoJsonObject
	 */
	public function testABasicRequestAnsweredWithoutAJsonObjectIsAnError(string $body): void {
		$result = $this->networkServiceAnswering($body)->basicRequest('https://jira.example.com', 'dXNlcjpwYXNz', 'rest/api/2/serverInfo');

		$this->assertSame(['error' => 'Unexpected answer from Jira'], $result);
		$this->assertSame(['Unexpected answer from Jira to rest/api/2/serverInfo'], $this->warnings);
	}

	public static function provideAnswersThatAreNoJsonObject(): array {
		return [
			'an HTML page' => ['<!DOCTYPE html><html><body>Request blocked</body></html>'],
			'a JSON string' => ['"maintenance"'],
			'a JSON number' => ['42'],
			'nothing' => [''],
		];
	}

	public function testABasicRequestAnsweredWithAJsonObjectReturnsIt(): void {
		$result = $this->networkServiceAnswering('{"baseUrl":"https://jira.example.com"}')->basicRequest('https://jira.example.com', 'dXNlcjpwYXNz', 'rest/api/2/serverInfo');

		$this->assertSame(['baseUrl' => 'https://jira.example.com'], $result);
		$this->assertSame([], $this->warnings);
	}

	public function testAnExpiredTokenIsRefreshedWithTheStoredClientCredentials(): void {
		// answer like the server once the app values are stored lazy: a read
		// without lazy: true only sees the non-lazy values, so it gets the default
		$lazyValues = [
			'client_id' => 'the-client-id',
			'client_secret' => 'encrypted:the-client-secret',
		];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnCallback(
			fn (string $key, string $default = '', bool $lazy = false): string => $lazy ? ($lazyValues[$key] ?? $default) : $default
		);
		$appConfig->method('getAppValue')->willReturnCallback(fn (string $key, string $default = ''): string => $default);
		$config = $this->createMock(IConfig::class);
		// a legacy read of a lazy key gets the default on the server
		$config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default = '') => $default);
		$config->method('getUserValue')->willReturnCallback(
			fn ($userId, $appName, $key, $default = '') => match ($key) {
				'token' => 'encrypted:the-access-token',
				'refresh_token' => 'encrypted:the-refresh-token',
				'token_expires_at' => '1',
				default => $default,
			}
		);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(fn (string $value): string => substr($value, strlen('encrypted:')));

		$refused = $this->createMock(IResponse::class);
		$refused->method('getStatusCode')->willReturn(401);
		$posted = [];
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $uri, array $options) use (&$posted, $refused): IResponse {
			$posted[] = [$uri, $options['body'] ?? null];
			return $refused;
		});
		$client->method('get')->willReturn($refused);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$service = new NetworkService(
			$appConfig,
			$config,
			$clientService,
			$this->createMock(LoggerInterface::class),
			$crypto,
			$this->createMock(IL10N::class),
		);

		$service->oauthRequest('user1', 'oauth/token/accessible-resources');

		$this->assertSame([[
			Application::JIRA_AUTH_URL . 'oauth/token',
			[
				'client_id' => 'the-client-id',
				'client_secret' => 'the-client-secret',
				'grant_type' => 'refresh_token',
				'refresh_token' => 'the-refresh-token',
			],
		]], $posted);
	}
}
