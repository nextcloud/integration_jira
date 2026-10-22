<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Tests;

use OCA\Jira\Migration\Version1501Date202610211025;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version1501Date202610211025Test extends TestCase {

	public function testEveryAppValueIsMarkedLazy(): void {
		$calls = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('updateLazy')->willReturnCallback(
			function (string $app, string $key, bool $lazy) use (&$calls): bool {
				$calls[] = [$app, $key, $lazy];
				return true;
			}
		);

		$result = (new Version1501Date202610211025($appConfig))
			->postSchemaChange($this->createMock(IOutput::class), fn () => null, []);

		$this->assertNull($result);
		$this->assertSame([
			['integration_jira', 'client_id', true],
			['integration_jira', 'client_secret', true],
			['integration_jira', 'forced_instance_url', true],
			['integration_jira', 'link_preview_enabled', true],
		], $calls);
	}
}
