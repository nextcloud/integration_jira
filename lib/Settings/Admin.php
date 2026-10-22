<?php

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Jira\Settings;

use OCA\Jira\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

class Admin implements ISettings {

	public function __construct(
		private IAppConfig $appConfig,
		private IInitialState $initialStateService,
	) {
	}

	public function getForm(): TemplateResponse {
		$clientID = $this->appConfig->getAppValueString('client_id', lazy: true);
		$clientSecret = $this->appConfig->getAppValueString('client_secret', lazy: true);
		$forcedInstanceUrl = $this->appConfig->getAppValueString('forced_instance_url', lazy: true);
		$linkPreviewEnabled = $this->appConfig->getAppValueString('link_preview_enabled', '0', lazy: true);

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
