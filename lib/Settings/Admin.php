<?php
namespace OCA\Zammad\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IL10N;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Util;
use OCP\IURLGenerator;
use OCP\IInitialStateService;

class Admin implements ISettings {

    private $request;
    private $config;
    private $dataDirPath;
    private $urlGenerator;
    private $l;

    public function __construct(
                        string $appName,
                        IL10N $l,
                        IRequest $request,
                        IConfig $config,
                        IURLGenerator $urlGenerator,
                        IInitialStateService $initialStateService,
                        $userId) {
        $this->appName = $appName;
        $this->urlGenerator = $urlGenerator;
        $this->request = $request;
        $this->l = $l;
        $this->config = $config;
        $this->initialStateService = $initialStateService;
        $this->userId = $userId;
    }

    /**
     * @return TemplateResponse
     */
    public function getForm() {
        $clientID = $this->config->getAppValue('zammad', 'client_id', '');
        $clientSecret = $this->config->getAppValue('zammad', 'client_secret', '');
        $oauthUrl = $this->config->getAppValue('zammad', 'oauth_instance_url', '');

        $adminConfig = [
            'client_id' => $clientID,
            'client_secret' => $clientSecret,
            'oauth_instance_url' => $oauthUrl
        ];
        $this->initialStateService->provideInitialState($this->appName, 'admin-config', $adminConfig);
        return new TemplateResponse('zammad', 'adminSettings');
    }

    public function getSection() {
        return 'linked-accounts';
    }

    public function getPriority() {
        return 10;
    }
}
