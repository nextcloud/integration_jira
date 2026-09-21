<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Tests;

use OCA\Jira\Controller\JiraAPIController;
use OCA\Jira\Service\JiraAPIService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class JiraAPIControllerTest extends TestCase {

	private JiraAPIService $apiService;
	private JiraAPIController $controller;

	public function setUp(): void {
		parent::setUp();

		$this->apiService = $this->createMock(JiraAPIService::class);
		$this->controller = new JiraAPIController(
			'integration_jira',
			$this->createMock(IRequest::class),
			$this->apiService,
			'user1'
		);
	}

	public function testGetNotificationsWithoutAJiraAccount(): void {
		$this->apiService->method('isUserConnected')->with('user1')->willReturn(false);
		$this->apiService->expects($this->never())->method('getNotifications');

		$this->assertSame(400, $this->controller->getNotifications()->getStatus());
	}

	public function testGetNotificationsWithAJiraAccount(): void {
		$issues = [['key' => 'FIRST-1']];
		$this->apiService->method('isUserConnected')->with('user1')->willReturn(true);
		$this->apiService->expects($this->once())
			->method('getNotifications')
			->with('user1', null, 7, true)
			->willReturn($issues);

		$response = $this->controller->getNotifications(null, true);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($issues, $response->getData());
	}
}
