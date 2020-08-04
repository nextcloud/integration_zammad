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

/**
 * Class Application
 *
 * @package OCA\Zammad\AppInfo
 */
class Application extends App implements IBootstrap {

    /**
     * Constructor
     *
     * @param array $urlParams
     */
    public function __construct(array $urlParams = []) {
        parent::__construct('zammad', $urlParams);

        $container = $this->getContainer();
    }

    public function register(IRegistrationContext $context): void {
        $context->registerDashboardWidget(ZammadWidget::class);
    }

    public function boot(IBootContext $context): void {
    }
}

