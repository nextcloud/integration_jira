<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Tests;

use OCA\Jira\Settings\Admin;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use PHPUnit\Framework\TestCase;

class AdminTest extends TestCase {

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

	public function testTheFormShowsTheLazilyStoredValues(): void {
		$appConfig = $this->appConfigWithLazyValues([
			'client_id' => 'the-client-id',
			'client_secret' => 'encrypted-client-secret',
			'forced_instance_url' => 'https://jira.example.com',
			'link_preview_enabled' => '1',
		]);
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects($this->once())
			->method('provideInitialState')
			->with('admin-config', [
				'client_id' => 'the-client-id',
				'client_secret' => 'dummySecret',
				'forced_instance_url' => 'https://jira.example.com',
				'link_preview_enabled' => true,
			]);

		(new Admin($appConfig, $initialState))->getForm();
	}

	public function testTheFormShowsTheDefaultsWhenNothingIsStored(): void {
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects($this->once())
			->method('provideInitialState')
			->with('admin-config', [
				'client_id' => '',
				'client_secret' => '',
				'forced_instance_url' => '',
				'link_preview_enabled' => false,
			]);

		(new Admin($this->appConfigWithLazyValues([]), $initialState))->getForm();
	}
}
