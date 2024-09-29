<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\BackgroundJob;

use OCA\Jira\Service\JiraAPIService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

use Psr\Log\LoggerInterface;

/**
 * Class CheckOpenTickets
 *
 * @package OCA\Jira\BackgroundJob
 */
class CheckOpenTickets extends TimedJob {

	protected JiraAPIService $jiraAPIService;

	protected LoggerInterface $logger;

	public function __construct(ITimeFactory $time,
		JiraAPIService $jiraAPIService,
		LoggerInterface $logger) {
		parent::__construct($time);
		// Every 15 minutes
		$this->setInterval(60 * 15);

		$this->jiraAPIService = $jiraAPIService;
		$this->logger = $logger;
	}

	protected function run($argument): void {
		$this->jiraAPIService->checkOpenTickets();
		$this->logger->info('Checked if users have open Jira issues.');
	}
}
