<?php
/**
 * Nextcloud - Jira
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Jira\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\Notification\IManager as INotificationManager;

use OCA\Jira\Dashboard\JiraWidget;
use OCA\Jira\Search\JiraSearchProvider;
use OCA\Jira\Notification\Notifier;

/**
 * Class Application
 *
 * @package OCA\Jira\AppInfo
 */
class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_jira';
	public const JIRA_API_URL = 'https://api.atlassian.com';
	public const JIRA_AUTH_URL = 'https://auth.atlassian.com';

	/**
	 * Constructor
	 *
	 * @param array $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();
		$manager = $container->get(INotificationManager::class);
		$manager->registerNotifierService(Notifier::class);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(JiraWidget::class);
		$context->registerSearchProvider(JiraSearchProvider::class);
	}

	public function boot(IBootContext $context): void {
	}
}

