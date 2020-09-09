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

use OCP\App\IAppManager;
use OCP\Files\IAppData;
use OCP\AppFramework\Http\DataDisplayResponse;

use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IServerContainer;
use OCP\IL10N;
use OCP\ILogger;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;

use OCP\AppFramework\Http\ContentSecurityPolicy;

use OCP\IRequest;
use OCP\IDBConnection;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCP\Http\Client\IClientService;

use OCA\Jira\Service\JiraAPIService;
use OCA\Jira\AppInfo\Application;

class ConfigController extends Controller {


    private $userId;
    private $config;
    private $dbconnection;
    private $dbtype;

    public function __construct($AppName,
                                IRequest $request,
                                IServerContainer $serverContainer,
                                IConfig $config,
                                IAppManager $appManager,
                                IAppData $appData,
                                IDBConnection $dbconnection,
                                IURLGenerator $urlGenerator,
                                IL10N $l,
                                ILogger $logger,
                                IClientService $clientService,
                                JiraAPIService $jiraAPIService,
                                $userId) {
        parent::__construct($AppName, $request);
        $this->l = $l;
        $this->userId = $userId;
        $this->appData = $appData;
        $this->serverContainer = $serverContainer;
        $this->config = $config;
        $this->dbconnection = $dbconnection;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
        $this->clientService = $clientService;
        $this->jiraAPIService = $jiraAPIService;
    }

    /**
     * set config values
     * @NoAdminRequired
     */
    public function setConfig($values) {
        foreach ($values as $key => $value) {
            $this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
        }
        $response = new DataResponse(1);
        return $response;
    }

    /**
     * set admin config values
     */
    public function setAdminConfig($values) {
        foreach ($values as $key => $value) {
            $this->config->setAppValue(Application::APP_ID, $key, $value);
        }
        $response = new DataResponse(1);
        return $response;
    }

    /**
     * receive oauth code and get oauth access token
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function oauthRedirect($code, $state) {
        $configState = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_state', '');
        $clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
        $clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');

        // anyway, reset state
        $this->config->setUserValue($this->userId, Application::APP_ID, 'oauth_state', '');

        if ($clientID and $clientSecret and $configState !== '' and $configState === $state) {
            $redirect_uri = $this->urlGenerator->linkToRouteAbsolute('integration_jira.config.oauthRedirect');
            $result = $this->jiraAPIService->requestOAuthAccessToken([
                'client_id' => $clientID,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirect_uri,
                'grant_type' => 'authorization_code'
            ], 'POST');
            if (isset($result['access_token'])) {
                $accessToken = $result['access_token'];
                $this->config->setUserValue($this->userId, Application::APP_ID, 'token', $accessToken);
                $refreshToken = $result['refresh_token'];
                $this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $refreshToken);
                // get accessible resources
                $resources = $this->jiraAPIService->request($accessToken, $refreshToken, $clientID, $clientSecret, $this->userId, 'oauth/token/accessible-resources');
                if (!isset($resources['error'])) {
                    $encodedResources = json_encode($resources);
                    $this->config->setUserValue($this->userId, Application::APP_ID, 'resources', $encodedResources);
                    return new RedirectResponse(
                        $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'linked-accounts']) .
                        '?jiraToken=success'
                    );
                } else {
                    $result = $this->l->t('Error getting OAuth accessible resource list.') . ' ' . $resources['error'];
                }
            } else {
                $result = $this->l->t('Error getting OAuth access token.') . ' ' . $result['error'];
            }
        } else {
            $result = $this->l->t('Error during OAuth exchanges');
        }
        return new RedirectResponse(
            $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'linked-accounts']) .
            '?jiraToken=error&message=' . urlencode($result)
        );
    }

}
