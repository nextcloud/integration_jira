<?php
/**
 * Nextcloud - Jira
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Jira\AppInfo;

use OCP\IContainer;

use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\Notification\IManager as INotificationManager;
use OCP\IConfig;

use OCA\Jira\Controller\PageController;
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
		$this->container = $container;
		$manager = $container->query(INotificationManager::class);
		$manager->registerNotifierService(Notifier::class);
	}

	public function register(IRegistrationContext $context): void {
		$config = $this->container->query(IConfig::class);
		$clientId = $config->getAppValue(self::APP_ID, 'client_id', '');
		$clientSecret = $config->getAppValue(self::APP_ID, 'client_secret', '');
		if ($clientId !== '' && $clientSecret !== '') {
		    $context->registerDashboardWidget(JiraWidget::class);
			$context->registerSearchProvider(JiraSearchProvider::class);
		}
	}

	public function boot(IBootContext $context): void {
	}
}

