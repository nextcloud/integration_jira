<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020, Julien Veyssier
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\Jira\Search;

use OCA\Jira\Service\JiraAPIService;
use OCA\Jira\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;

class JiraSearchProvider implements IProvider {

	/** @var IAppManager */
	private $appManager;

	/** @var IL10N */
	private $l10n;

	/** @var IURLGenerator */
	private $urlGenerator;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var JiraAPIService
	 */
	private $service;

	/**
	 * CospendSearchProvider constructor.
	 *
	 * @param IAppManager $appManager
	 * @param IL10N $l10n
	 * @param IConfig $config
	 * @param IURLGenerator $urlGenerator
	 * @param JiraAPIService $service
	 */
	public function __construct(IAppManager $appManager,
								IL10N $l10n,
								IConfig $config,
								IURLGenerator $urlGenerator,
								JiraAPIService $service) {
		$this->appManager = $appManager;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->service = $service;
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'jira-search';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l10n->t('Jira');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(string $route, array $routeParameters): int {
		if (strpos($route, Application::APP_ID . '.') === 0) {
			// Active app, prefer Jira results
			return -1;
		}

		return 20;
	}

	/**
	 * @inheritDoc
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		if (!$this->appManager->isEnabledForUser(Application::APP_ID, $user)) {
			return SearchResult::complete($this->getName(), []);
		}

		$limit = $query->getLimit();
		$term = $query->getTerm();
		$offset = $query->getCursor();
		$offset = $offset ? intval($offset) : 0;

		$theme = $this->config->getUserValue($user->getUID(), 'accessibility', 'theme');
		$thumbnailUrl = ($theme === 'dark')
			? $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
			: $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');

		$accessToken = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'token');
		$basicAuthHeader = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'basic_auth_header');
		$searchEnabled = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'search_enabled', '0') === '1';
		if (($accessToken === '' && $basicAuthHeader === '') || !$searchEnabled) {
			return SearchResult::paginated($this->getName(), [], 0);
		}

		$searchResults = $this->service->search($user->getUID(), $term, $offset, $limit);

		if (isset($searchResults['error'])) {
			return SearchResult::paginated($this->getName(), [], 0);
		}

		$formattedResults = array_map(function (array $entry) use ($thumbnailUrl): JiraSearchResultEntry {
			return new JiraSearchResultEntry(
				$this->getThumbnailUrl($entry, $thumbnailUrl),
				$this->getMainText($entry),
				$this->getSubline($entry),
				$this->getLinkToJira($entry),
				'',
				true
			);
		}, $searchResults);

		return SearchResult::paginated(
			$this->getName(),
			$formattedResults,
			$offset + $limit
		);
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getMainText(array $entry): string {
		return $entry['fields']['summary'];
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getSubline(array $entry): string {
		$displayName = $entry['fields'] && $entry['fields']['creator'] && $entry['fields']['creator']['displayName']
			? $entry['fields']['creator']['displayName']
			: '';
		$priorityName = $entry['fields'] && $entry['fields']['priority'] && $entry['fields']['priority']['name']
			? $entry['fields']['priority']['name']
			: '';
		$statusName = $entry['fields'] && $entry['fields']['status'] && $entry['fields']['status']['name']
			? $entry['fields']['status']['name']
			: '';
		$prefix = $priorityName && $statusName
			? '[' . $statusName . '/' . $priorityName . '] '
			: ($priorityName
				? '[' . $priorityName . '] '
				: ($statusName
					? '[' . $statusName . '] '
					: ''));
		return $prefix . $displayName;
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getLinkToJira(array $entry): string {
		return $entry['jiraUrl'] . '/browse/' . $entry['key'];
	}

	/**
	 * @param array $entry
	 * @param string $thumbnailUrl
	 * @return string
	 */
	protected function getThumbnailUrl(array $entry, string $thumbnailUrl): string {
		$displayName = $entry['fields'] && $entry['fields']['creator'] && $entry['fields']['creator']['displayName']
			? $entry['fields']['creator']['displayName']
			: '';
		$accountId = $entry['fields']['creator']['accountId'] ?? '';
		$accountKey = $entry['fields']['creator']['key'] ?? '';
		return $accountId
			? $this->urlGenerator->linkToRoute('integration_jira.jiraAPI.getJiraAvatar', []) . '?accountId=' . urlencode($accountId)
			: ($accountKey
				? $this->urlGenerator->linkToRoute('integration_jira.jiraAPI.getJiraAvatar', []) . '?accountKey=' . urlencode($accountKey)
				: ($displayName
					? $this->urlGenerator->linkToRouteAbsolute('core.GuestAvatar.getAvatar', ['guestName' => $displayName, 'size' => 64])
					: $thumbnailUrl));
	}
}
