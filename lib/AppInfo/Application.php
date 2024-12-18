<?php
/**
 * Nextcloud - Zammad
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Zammad\AppInfo;

use Closure;
use OCA\Zammad\Dashboard\ZammadWidget;
use OCA\Zammad\Listener\ZammadReferenceListener;
use OCA\Zammad\Notification\Notifier;
use OCA\Zammad\Reference\ZammadReferenceProvider;
use OCA\Zammad\Search\ZammadSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\IConfig;
use OCP\IL10N;
use OCP\INavigationManager;

use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;

class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_zammad';
	private IConfig $config;

	public static $contextChatEnabled = false;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();
		$this->config = $container->get(IConfig::class);

		$manager = $container->get(INotificationManager::class);
		$manager->registerNotifierService(Notifier::class);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(ZammadWidget::class);
		$context->registerSearchProvider(ZammadSearchProvider::class);

		$context->registerReferenceProvider(ZammadReferenceProvider::class);
		$context->registerEventListener(RenderReferenceEvent::class, ZammadReferenceListener::class);
		if (class_exists('\OCA\ContextChat\Public\IContentProvider')) {
			self::$contextChatEnabled = true;
			$context->registerEventListener(\OCA\ContextChat\Event\ContentProviderRegisterEvent::class, \OCA\Zammad\ContextChat\ContentProvider::class);
		}
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(Closure::fromCallable([$this, 'registerNavigation']));
	}

	public function registerNavigation(IUserSession $userSession): void {
		$user = $userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
			$container = $this->getContainer();

			if ($this->config->getUserValue($userId, self::APP_ID, 'navigation_enabled', '0') === '1') {
				$zammadUrl = $this->config->getUserValue($userId, self::APP_ID, 'url', '');
				if ($zammadUrl !== '') {
					$container->get(INavigationManager::class)->add(function () use ($container, $zammadUrl) {
						$urlGenerator = $container->get(IURLGenerator::class);
						$l10n = $container->get(IL10N::class);
						return [
							'id' => self::APP_ID,

							'order' => 10,

							// the route that will be shown on startup
							'href' => $zammadUrl,

							// the icon that will be shown in the navigation
							// this file needs to exist in img/
							'icon' => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),

							// the title of your application. This will be used in the
							// navigation or on the settings page of your app
							'name' => $l10n->t('Zammad'),
						];
					});
				}
			}
		}
	}
}
