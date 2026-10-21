<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Tests;

use OC\Collaboration\Reference\LinkReferenceProvider;
use OC\Collaboration\Reference\ReferenceManager;
use OCA\Jira\Reference\JiraReferenceProvider;
use OCA\Jira\Service\JiraAPIService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class JiraReferenceProviderTest extends TestCase {

	private const ISSUE_URL = 'https://ncintegration.atlassian.net/browse/NC-42';

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

	private function createProvider(IAppConfig $appConfig, array $userValues = [], ?IConfig $config = null): JiraReferenceProvider {
		if ($config === null) {
			$config = $this->createMock(IConfig::class);
			// a legacy read of a lazy key gets the default on the server
			$config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default = '') => $default);
			$config->method('getUserValue')->willReturnCallback(fn ($userId, $appName, $key, $default = '') => $userValues[$key] ?? $default);
		}

		return new JiraReferenceProvider(
			$this->createMock(JiraAPIService::class),
			$appConfig,
			$config,
			$this->createMock(IL10N::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(ReferenceManager::class),
			$this->createMock(LinkReferenceProvider::class),
			'user1',
		);
	}

	public function testIssueLinksAreNotMatchedWhenTheAdminDisabledLinkPreviews(): void {
		$provider = $this->createProvider($this->appConfigWithLazyValues(['link_preview_enabled' => '0']));

		$this->assertFalse($provider->matchReference(self::ISSUE_URL));
	}

	public function testIssueLinksAreMatchedWhenTheAdminEnabledLinkPreviews(): void {
		$provider = $this->createProvider($this->appConfigWithLazyValues(['link_preview_enabled' => '1']));

		$this->assertTrue($provider->matchReference(self::ISSUE_URL));
	}

	public function testIssueLinksAreMatchedWhenNothingIsStored(): void {
		$this->assertTrue($this->createProvider($this->appConfigWithLazyValues([]))->matchReference(self::ISSUE_URL));
	}

	public function testIssueLinksAreNotMatchedWhenTheUserDisabledLinkPreviews(): void {
		$provider = $this->createProvider($this->appConfigWithLazyValues(['link_preview_enabled' => '1']), ['link_preview_enabled' => '0']);

		$this->assertFalse($provider->matchReference(self::ISSUE_URL));
	}

	public function testOtherLinksAreNotMatchedWithoutReadingTheAppConfig(): void {
		// links of any app reach the provider until one matches, and a lazy read would load the lazy values for them
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method($this->anything());
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('getAppValue');

		$this->assertFalse($this->createProvider($appConfig, config: $config)->matchReference('https://github.com/nextcloud/server/pull/1'));
	}
}
