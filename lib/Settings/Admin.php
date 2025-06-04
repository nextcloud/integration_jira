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

class Admin implements ISettings {

	public function __construct(
		private IConfig $config,
		private IInitialState $initialStateService,
	) {
	}

	public function getForm(): TemplateResponse {
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$forcedInstanceUrl = $this->config->getAppValue(Application::APP_ID, 'forced_instance_url');
		$linkPreviewEnabled = $this->config->getAppValue(Application::APP_ID, 'link_preview_enabled', '0');

		$adminConfig = [
			'client_id' => $clientID,
			'client_secret' => $clientSecret !== '' ? 'dummySecret' : '',
			'forced_instance_url' => $forcedInstanceUrl,
			'link_preview_enabled' => $linkPreviewEnabled === '1',
		];
		$this->initialStateService->provideInitialState('admin-config', $adminConfig);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
