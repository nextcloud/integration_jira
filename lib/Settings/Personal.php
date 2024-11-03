<?php
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Settings;

use OCA\Jira\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;

use OCP\Settings\ISettings;

class Personal implements ISettings {

	public function __construct(
		private IConfig $config,
		private IInitialState $initialStateService,
		private ?string $userId,
	) {
	}

	public function getForm(): TemplateResponse {
		$userName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_name');
		$url = $this->config->getUserValue($this->userId, Application::APP_ID, 'url');
		$searchEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'search_enabled', '0');
		$notificationEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'notification_enabled', '0');

		$forcedInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'forced_instance_url');

		// for OAuth
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		// don't expose the client secret to users
		$clientSecret = ($this->config->getAppValue(Application::APP_ID, 'client_secret') !== '');

		$userConfig = [
			'url' => $url,
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'search_enabled' => ($searchEnabled === '1'),
			'notification_enabled' => ($notificationEnabled === '1'),
			'user_name' => $userName,
			'forced_instance_url' => $forcedInstanceUrl,
		];
		$this->initialStateService->provideInitialState('user-config', $userConfig);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
