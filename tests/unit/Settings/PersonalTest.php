<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Tests;

use OCA\Jira\Settings\Personal;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class PersonalTest extends TestCase {

	/**
	 * Answer like the server once the app values are stored lazy: a read without
	 * lazy: true only sees the non-lazy values, so it gets the default.
	 */
	private function appConfigWithLazyValues(array $values): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnCallback(
			fn (string $key, string $default = '', bool $lazy = false): string => $lazy ? ($values[$key] ?? $default) : $default
		);
		$appConfig->method('getAppValue')->willReturnCallback(fn (string $key, string $default = ''): string => $default);
		return $appConfig;
	}

	public function testTheFormShowsTheLazilyStoredAdminValues(): void {
		$appConfig = $this->appConfigWithLazyValues([
			'client_id' => 'the-client-id',
			'client_secret' => 'encrypted-client-secret',
			'forced_instance_url' => 'https://jira.example.com',
		]);
		$config = $this->createMock(IConfig::class);
		// a legacy read of a lazy key gets the default on the server
		$config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default = '') => $default);
		$config->method('getUserValue')->willReturnCallback(fn ($userId, $appName, $key, $default = '') => $default);
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects($this->once())
			->method('provideInitialState')
			->with('user-config', [
				'url' => '',
				'client_id' => 'the-client-id',
				'client_secret' => true,
				'search_enabled' => false,
				'notification_enabled' => false,
				'user_name' => '',
				'forced_instance_url' => 'https://jira.example.com',
				'dashboard_jira_projects' => [],
				'link_preview_enabled' => true,
			]);

		(new Personal($appConfig, $config, $initialState, 'user1'))->getForm();
	}

	public function testTheFormShowsNoAdminValuesWhenNothingIsStored(): void {
		$config = $this->createMock(IConfig::class);
		// a legacy read of a lazy key gets the default on the server
		$config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default = '') => $default);
		$config->method('getUserValue')->willReturnCallback(fn ($userId, $appName, $key, $default = '') => $default);
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects($this->once())
			->method('provideInitialState')
			->with('user-config', $this->callback(function (array $userConfig): bool {
				$this->assertSame('', $userConfig['client_id']);
				$this->assertFalse($userConfig['client_secret']);
				$this->assertSame('', $userConfig['forced_instance_url']);
				return true;
			}));

		(new Personal($this->appConfigWithLazyValues([]), $config, $initialState, 'user1'))->getForm();
	}
}
