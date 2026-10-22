<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Reference;

use Exception;
use OC\Collaboration\Reference\LinkReferenceProvider;
use OC\Collaboration\Reference\ReferenceManager;
use OCA\Jira\AppInfo\Application;
use OCA\Jira\Service\JiraAPIService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use Throwable;

class JiraReferenceProvider extends ADiscoverableReferenceProvider implements ISearchableReferenceProvider {

	private const RICH_OBJECT_TYPE_JIRA_ISSUE_SEARCH = Application::APP_ID . '_search';

	public function __construct(
		private JiraAPIService $jiraAPIService,
		private IAppConfig $appConfig,
		private IConfig $config,
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private ReferenceManager $referenceManager,
		private LinkReferenceProvider $linkReferenceProvider,
		private ?string $userId,
	) {
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
	public function getTitle(): string {
		return $this->l10n->t('Jira issues');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(): int {
		return 10;
	}

	/**
	 * @inheritDoc
	 */
	public function getIconUrl(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
	}

	/**
	 * @inheritDoc
	 */
	public function getSupportedSearchProviderIds(): array {
		return ['jira-search'];
	}

	/**
	 * @inheritDoc
	 */
	public function matchReference(string $referenceText): bool {
		// the server asks the providers in turn about a link in any app until one matches, so only read the settings for a Jira link
		$issueLink = $this->getIssueLink($referenceText);
		if ($issueLink === null || $this->userId === null) {
			return false;
		}
		$adminLinkPreviewEnabled = $this->appConfig->getAppValueString('link_preview_enabled', '1', lazy: true) === '1';
		$userLinkPreviewEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'link_preview_enabled', '1') === '1';
		return $adminLinkPreviewEnabled && $userLinkPreviewEnabled
			&& $this->jiraAPIService->isConnectedToSite($this->userId, $issueLink['host']);
	}

	/**
	 * @inheritDoc
	 */
	public function resolveReference(string $referenceText): ?IReference {
		if ($this->matchReference($referenceText)) {
			try {
				$issueLink = $this->getIssueLink($referenceText);
				if ($issueLink === null) {
					return $this->linkReferenceProvider->resolveReference($referenceText);
				}

				$issueInfo = $this->jiraAPIService->getIssueInfo($this->userId, $issueLink['key'], $issueLink['host']);
				if (!isset($issueInfo['fields'])) {
					// for example an issue the user cannot see
					return $this->linkReferenceProvider->resolveReference($referenceText);
				}

				$reference = new Reference($referenceText);
				$reference->setTitle($this->getMainText($issueInfo));
				$reference->setDescription($this->getSubline($issueInfo));
				$reference->setRichObject(
					self::RICH_OBJECT_TYPE_JIRA_ISSUE_SEARCH,
					$issueInfo
				);
				return $reference;
			} catch (Exception|Throwable $e) {
				// fallback to opengraph
				return $this->linkReferenceProvider->resolveReference($referenceText);
			}
		}

		return null;
	}

	/**
	 * The site host and the issue key of a link to an issue on a Jira Cloud site
	 *
	 * @return array{host: string, key: string}|null
	 */
	private function getIssueLink(string $referenceText): ?array {
		// a project key is a letter followed by letters, digits or underscores, and the issue key adds a number;
		// the link can go on with a path, a query, a fragment or the punctuation of the sentence it ends
		$regex = '/^https:\/\/([a-z0-9.-]+\.atlassian\.net)\/browse\/([a-z][a-z0-9_]+-\d+)(?![a-z0-9_])/i';
		if (preg_match($regex, $referenceText, $matches) !== 1) {
			return null;
		}
		return ['host' => $matches[1], 'key' => $matches[2]];
	}

	/**
	 * @inheritDoc
	 */
	public function getCachePrefix(string $referenceId): string {
		return $this->userId ?? '';
	}

	/**
	 * @inheritDoc
	 */
	public function getCacheKey(string $referenceId): ?string {
		return $referenceId;
	}

	/**
	 * @param string $userId
	 * @return void
	 */
	public function invalidateUserCache(string $userId): void {
		$this->referenceManager->invalidateCache($userId);
	}

	protected function getMainText(array $entry): string {
		return $entry['fields']['summary'];
	}

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
}
