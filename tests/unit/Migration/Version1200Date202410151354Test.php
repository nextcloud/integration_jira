<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Tests;

use OCA\Jira\Migration\Version1200Date202410151354;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

class Version1200Date202410151354Test extends TestCase {

	private function createConnection(): IDBConnection {
		$queryBuilder = $this->createMock(IQueryBuilder::class);
		foreach (['update', 'set', 'select', 'from', 'where', 'andWhere', 'setParameter'] as $method) {
			$queryBuilder->method($method)->willReturnSelf();
		}
		$queryBuilder->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$queryBuilder->method('executeQuery')->willReturn($this->createMock(IResult::class));
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturn($queryBuilder);
		return $connection;
	}

	public function testTheClientSecretIsEncryptedAndStaysLazy(): void {
		$connection = $this->createConnection();
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(fn (string $value): string => 'encrypted:' . $value);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())
			->method('getValueString')
			->with('integration_jira', 'client_secret', '', true)
			->willReturn('the-client-secret');
		$appConfig->expects($this->once())
			->method('setValueString')
			->with('integration_jira', 'client_secret', 'encrypted:the-client-secret', true, false)
			->willReturn(true);

		(new Version1200Date202410151354($connection, $crypto, $appConfig))
			->postSchemaChange($this->createMock(IOutput::class), fn () => null, []);
	}

	public function testNoClientSecretIsWrittenWhenNoneIsStored(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string => $default
		);
		$appConfig->expects($this->never())->method('setValueString');

		(new Version1200Date202410151354($this->createConnection(), $this->createMock(ICrypto::class), $appConfig))
			->postSchemaChange($this->createMock(IOutput::class), fn () => null, []);
	}
}
