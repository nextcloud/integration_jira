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
use OCP\Collaboration\Reference\Reference;
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

	/**
	 * A provider for a user connected to every site, unless another service is given
	 */
	private function createProvider(IAppConfig $appConfig, array $userValues = [], ?IConfig $config = null,
		?JiraAPIService $jiraAPIService = null, ?string $userId = 'user1',
		?LinkReferenceProvider $linkReferenceProvider = null): JiraReferenceProvider {
		if ($jiraAPIService === null) {
			$jiraAPIService = $this->createMock(JiraAPIService::class);
			$jiraAPIService->method('isConnectedToSite')->willReturn(true);
		}
		if ($config === null) {
			$config = $this->createMock(IConfig::class);
			// a legacy read of a lazy key gets the default on the server
			$config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default = '') => $default);
			$config->method('getUserValue')->willReturnCallback(fn ($userId, $appName, $key, $default = '') => $userValues[$key] ?? $default);
		}

		return new JiraReferenceProvider(
			$jiraAPIService,
			$appConfig,
			$config,
			$this->createMock(IL10N::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(ReferenceManager::class),
			$linkReferenceProvider ?? $this->createMock(LinkReferenceProvider::class),
			$userId,
		);
	}

	/**
	 * A service that must not be asked, as the settings already decide
	 */
	private function unaskedService(): JiraAPIService {
		$jiraAPIService = $this->createMock(JiraAPIService::class);
		$jiraAPIService->expects($this->never())->method($this->anything());
		return $jiraAPIService;
	}

	public function testIssueLinksAreNotMatchedWhenTheAdminDisabledLinkPreviews(): void {
		$provider = $this->createProvider($this->appConfigWithLazyValues(['link_preview_enabled' => '0']), jiraAPIService: $this->unaskedService());

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
		$provider = $this->createProvider($this->appConfigWithLazyValues(['link_preview_enabled' => '1']), ['link_preview_enabled' => '0'], jiraAPIService: $this->unaskedService());

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

	/**
	 * @dataProvider provideIssueLinks
	 */
	public function testIssueLinksAreLookedUpByTheirKeyOnTheirSite(string $url, string $host, string $key): void {
		$jiraAPIService = $this->createMock(JiraAPIService::class);
		$jiraAPIService->method('isConnectedToSite')->with('user1', $host)->willReturn(true);
		$jiraAPIService->expects($this->once())
			->method('getIssueInfo')
			->with('user1', $key, $host)
			->willReturn(['key' => $key, 'fields' => ['summary' => 'An issue', 'creator' => null, 'priority' => null, 'status' => null]]);
		$provider = $this->createProvider($this->appConfigWithLazyValues([]), jiraAPIService: $jiraAPIService);

		$this->assertTrue($provider->matchReference($url));
		$reference = $provider->resolveReference($url);
		$this->assertSame('An issue', $reference?->getTitle());
		$this->assertSame('integration_jira_search', $reference?->getRichObjectType());
	}

	public static function provideIssueLinks(): array {
		return [
			'letters only' => ['https://ncintegration.atlassian.net/browse/NC-42', 'ncintegration.atlassian.net', 'NC-42'],
			'digits in the project key' => ['https://ncintegration.atlassian.net/browse/AB12C-345', 'ncintegration.atlassian.net', 'AB12C-345'],
			'a letter and a digit' => ['https://ncintegration.atlassian.net/browse/N2-7', 'ncintegration.atlassian.net', 'N2-7'],
			'an underscore' => ['https://ncintegration.atlassian.net/browse/NC_T-1', 'ncintegration.atlassian.net', 'NC_T-1'],
			'a lower case key' => ['https://ncintegration.atlassian.net/browse/nc2t-1', 'ncintegration.atlassian.net', 'nc2t-1'],
			'an upper case host' => ['https://NCintegration.Atlassian.NET/browse/NC-42', 'NCintegration.Atlassian.NET', 'NC-42'],
			'a query' => ['https://ncintegration.atlassian.net/browse/NC-42?focusedCommentId=10000', 'ncintegration.atlassian.net', 'NC-42'],
			'a fragment' => ['https://ncintegration.atlassian.net/browse/NC-42#comment', 'ncintegration.atlassian.net', 'NC-42'],
			'a trailing slash' => ['https://ncintegration.atlassian.net/browse/NC-42/', 'ncintegration.atlassian.net', 'NC-42'],
			// the server takes these characters into a link, so a link that ends a sentence keeps its punctuation
			'a full stop' => ['https://ncintegration.atlassian.net/browse/NC-42.', 'ncintegration.atlassian.net', 'NC-42'],
			'a comma' => ['https://ncintegration.atlassian.net/browse/NC-42,', 'ncintegration.atlassian.net', 'NC-42'],
			'a closing parenthesis' => ['https://ncintegration.atlassian.net/browse/NC-42)', 'ncintegration.atlassian.net', 'NC-42'],
			'an exclamation mark' => ['https://ncintegration.atlassian.net/browse/NC-42!', 'ncintegration.atlassian.net', 'NC-42'],
			'a colon' => ['https://ncintegration.atlassian.net/browse/NC-42:', 'ncintegration.atlassian.net', 'NC-42'],
			'a semicolon' => ['https://ncintegration.atlassian.net/browse/NC-42;', 'ncintegration.atlassian.net', 'NC-42'],
			'another site' => ['https://other.atlassian.net/browse/OT-1', 'other.atlassian.net', 'OT-1'],
			'a site name starting with a digit' => ['https://9site.atlassian.net/browse/NC-1', '9site.atlassian.net', 'NC-1'],
		];
	}

	/**
	 * @dataProvider provideLinksThatAreNoJiraIssue
	 */
	public function testLinksThatAreNoJiraIssueAreNotMatched(string $url): void {
		$jiraAPIService = $this->createMock(JiraAPIService::class);
		$jiraAPIService->expects($this->never())->method($this->anything());

		$this->assertFalse($this->createProvider($this->appConfigWithLazyValues([]), jiraAPIService: $jiraAPIService)->matchReference($url));
	}

	public static function provideLinksThatAreNoJiraIssue(): array {
		return [
			'a project key starting with a digit' => ['https://ncintegration.atlassian.net/browse/2NC-42'],
			'a project key of one letter' => ['https://ncintegration.atlassian.net/browse/N-42'],
			'no issue number' => ['https://ncintegration.atlassian.net/browse/NC'],
			'letters after the issue number' => ['https://ncintegration.atlassian.net/browse/NC-42abc'],
			'an underscore after the issue number' => ['https://ncintegration.atlassian.net/browse/NC-42_'],
			'no number after the dash' => ['https://ncintegration.atlassian.net/browse/NC-'],
			'a Jira link inside another link' => ['https://example.com/?url=https://ncintegration.atlassian.net/browse/NC-42'],
			'http' => ['http://ncintegration.atlassian.net/browse/NC-42'],
			'a host that only starts like a Jira site' => ['https://ncintegration.atlassian.net.example.com/browse/NC-42'],
			'a board with an issue open' => ['https://ncintegration.atlassian.net/jira/software/projects/NC/boards/1?selectedIssue=NC-42'],
		];
	}

	public function testIssueLinksOfASiteTheUserIsNotConnectedToAreNotMatched(): void {
		$jiraAPIService = $this->createMock(JiraAPIService::class);
		$jiraAPIService->expects($this->once())
			->method('isConnectedToSite')
			->with('user1', 'someother-company.atlassian.net')
			->willReturn(false);
		$jiraAPIService->expects($this->never())->method('getIssueInfo');
		$provider = $this->createProvider($this->appConfigWithLazyValues([]), jiraAPIService: $jiraAPIService);

		$this->assertFalse($provider->matchReference('https://someother-company.atlassian.net/browse/NC-42'));
	}

	public function testIssueLinksAreNotMatchedWithoutAUser(): void {
		$jiraAPIService = $this->createMock(JiraAPIService::class);
		$jiraAPIService->expects($this->never())->method($this->anything());

		$this->assertFalse($this->createProvider($this->appConfigWithLazyValues([]), jiraAPIService: $jiraAPIService, userId: null)->matchReference(self::ISSUE_URL));
	}

	/**
	 * @dataProvider provideIssueAnswersThatAreNoIssue
	 */
	public function testAnIssueThatCannotBeShownGetsTheGenericLinkPreview(?array $answer, bool $throws): void {
		$jiraAPIService = $this->createMock(JiraAPIService::class);
		$jiraAPIService->method('isConnectedToSite')->willReturn(true);
		$getIssueInfo = $jiraAPIService->expects($this->once())->method('getIssueInfo');
		if ($throws) {
			$getIssueInfo->willThrowException(new \RuntimeException('Jira could not be reached'));
		} else {
			$getIssueInfo->willReturn($answer);
		}
		$linkPreview = new Reference(self::ISSUE_URL);
		$linkReferenceProvider = $this->createMock(LinkReferenceProvider::class);
		$linkReferenceProvider->expects($this->once())->method('resolveReference')->with(self::ISSUE_URL)->willReturn($linkPreview);
		$provider = $this->createProvider($this->appConfigWithLazyValues([]), jiraAPIService: $jiraAPIService, linkReferenceProvider: $linkReferenceProvider);

		$this->assertSame($linkPreview, $provider->resolveReference(self::ISSUE_URL));
	}

	public static function provideIssueAnswersThatAreNoIssue(): array {
		return [
			'an error' => [['error' => 'Issue does not exist or you do not have permission to see it.'], false],
			'an answer without fields' => [['key' => 'NC-42'], false],
			'no site' => [null, false],
			'an exception' => [null, true],
		];
	}

	public function testIssueLinksOfASiteTheUserIsNotConnectedToAreNotResolved(): void {
		$jiraAPIService = $this->createMock(JiraAPIService::class);
		$jiraAPIService->method('isConnectedToSite')->willReturn(false);
		$jiraAPIService->expects($this->never())->method('getIssueInfo');
		$linkReferenceProvider = $this->createMock(LinkReferenceProvider::class);
		$linkReferenceProvider->expects($this->never())->method('resolveReference');
		$provider = $this->createProvider($this->appConfigWithLazyValues([]), jiraAPIService: $jiraAPIService, linkReferenceProvider: $linkReferenceProvider);

		$this->assertNull($provider->resolveReference('https://someother-company.atlassian.net/browse/NC-42'));
	}
}
