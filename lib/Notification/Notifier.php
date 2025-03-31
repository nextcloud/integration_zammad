<?php

/**
 * Nextcloud - zammad
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2019
 */

namespace OCA\Zammad\Notification;

use InvalidArgumentException;
use OCA\Zammad\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {

	public function __construct(
		private IFactory $factory,
		private IUserManager $userManager,
		private INotificationManager $notificationManager,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * Identifier of the notifier, only use [a-z0-9_]
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getID(): string {
		return 'integration_zammad';
	}
	/**
	 * Human-readable name describing the notifier
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getName(): string {
		return $this->factory->get('integration_zammad')->t('Zammad');
	}

	/**
	 * @param INotification $notification
	 * @param string $languageCode The code of the language that should be used to prepare the notification
	 * @return INotification
	 * @throws InvalidArgumentException When the notification was not prepared by a notifier
	 * @since 9.0.0
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'integration_zammad') {
			// Not my app => throw
			throw new InvalidArgumentException();
		}

		$l = $this->factory->get('integration_zammad', $languageCode);

		switch ($notification->getSubject()) {
			case 'new_open_tickets':
				$p = $notification->getSubjectParameters();
				$nbOpen = (int)($p['nbOpen'] ?? 0);
				$content = $l->n('You have %n open ticket in Zammad.', 'You have %n open tickets in Zammad.', $nbOpen);

				//$theme = $this->config->getUserValue($userId, 'accessibility', 'theme', '');
				//$iconUrl = ($theme === 'dark')
				//	? $this->url->imagePath(Application::APP_ID, 'app.svg')
				//	: $this->url->imagePath(Application::APP_ID, 'app-dark.svg');

				$notification->setParsedSubject($content)
					->setLink($p['link'] ?? '')
					->setIcon(
						$this->urlGenerator->getAbsoluteURL(
							$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
						)
					);
				//->setIcon($this->url->getAbsoluteURL($iconUrl));
				return $notification;

			default:
				// Unknown subject => Unknown notification => throw
				throw new InvalidArgumentException();
		}
	}
}
