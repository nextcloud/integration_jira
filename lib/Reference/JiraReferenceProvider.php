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
		$adminLinkPreviewEnabled = $this->config->getAppValue(Application::APP_ID, 'link_preview_enabled', '1') === '1';
		$userLinkPreviewEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'link_preview_enabled', '1') === '1';
		if (!$adminLinkPreviewEnabled || !$userLinkPreviewEnabled) {
			return false;
		}
		return $this->getJiraIssueId($referenceText) !== null;
	}

	/**
	 * @inheritDoc
	 */
	public function resolveReference(string $referenceText): ?IReference {
		if ($this->matchReference($referenceText)) {
			try {
				$issueId = $this->getJiraIssueId($referenceText);
				if ($issueId === null) {
					return $this->linkReferenceProvider->resolveReference($referenceText);
				}

				$issueInfo = $this->jiraAPIService->getIssueInfo($this->userId, $issueId);

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

	private function getJiraIssueId(string $referenceText): ?string {
		$regex = '/https:\/\/[a-zA-Z0-9.-]+\.atlassian\.net\/browse\/([A-Z]+-\d+)/';
		if (preg_match($regex, $referenceText, $matches)) {
			return $matches[1];
		}
		return null;
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
