<?php
/**
 * Nextcloud - jira
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Jira\Controller;

use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Jira\Service\JiraAPIService;

class JiraAPIController extends Controller {

	/**
	 * @var JiraAPIService
	 */
	private $jiraAPIService;
	/**
	 * @var string|null
	 */
	private $userId;

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
			$response->cacheFor(60*60*24);
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
	public function getNotifications(?string $since = null): DataResponse {
		$result = $this->jiraAPIService->getNotifications($this->userId, $since, 7);
		if (!isset($result['error'])) {
			$response = new DataResponse($result);
		} else {
			$response = new DataResponse($result, 401);
		}
		return $response;
	}
}
