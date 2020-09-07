<?php
/**
 * Nextcloud - Zammad
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Zammad\AppInfo;

use OCP\IContainer;

use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;

use OCA\Zammad\Controller\PageController;
use OCA\Zammad\Dashboard\ZammadWidget;
use OCA\Zammad\Search\ZammadSearchProvider;

/**
 * Class Application
 *
 * @package OCA\Zammad\AppInfo
 */
class Application extends App implements IBootstrap {

    public const APP_ID = 'integration_zammad';

    /**
     * Constructor
     *
     * @param array $urlParams
     */
    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);

        $container = $this->getContainer();
    }

    public function register(IRegistrationContext $context): void {
        $context->registerDashboardWidget(ZammadWidget::class);
        $context->registerSearchProvider(ZammadSearchProvider::class);
    }

    public function boot(IBootContext $context): void {
    }
}

