<?php

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Controller;

use OCA\Jira\Service\JiraAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;

use OCP\IRequest;

class JiraAPIController extends Controller {

	private JiraAPIService $jiraAPIService;

	private ?string $userId;

	public function __construct(string $appName,
		IRequest $request,
		JiraAPIService $jiraAPIService,
		?string $userId) {
		parent::__construct($appName, $request);
		$this->jiraAPIService = $jiraAPIService;
		$this->userId = $userId;
	}

	/**
	 * get jira user avatar
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $accountId
	 * @param string $accountKey
	 * @return DataDisplayResponse
	 */
	public function getJiraAvatar(string $accountId = '', string $accountKey = ''): DataDisplayResponse {
		$avatarContent = $this->jiraAPIService->getJiraAvatar($this->userId, $accountId, $accountKey);
		if (is_null($avatarContent)) {
			return new DataDisplayResponse('', 401);
		} else {
			$response = new DataDisplayResponse($avatarContent);
			$response->cacheFor(60 * 60 * 24);
			return $response;
		}
	}

	/**
	 * get notifications list
	 * @NoAdminRequired
	 *
	 * @param ?string $since
	 * @return DataResponse
	 */
	public function getNotifications(?string $since = null, bool $filterProjects = false): DataResponse {
		$result = $this->jiraAPIService->getNotifications($this->userId, $since, 7, $filterProjects);
		if (!isset($result['error'])) {
			$response = new DataResponse($result);
		} else {
			$response = new DataResponse($result, 401);
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function getProjects(): DataResponse {
		$result = $this->jiraAPIService->getProjects($this->userId);
		if (!isset($result['error'])) {
			$response = new DataResponse($result);
		} else {
			$response = new DataResponse($result, 401);
		}
		return $response;
	}
}
