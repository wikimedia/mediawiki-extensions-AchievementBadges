<?php

namespace MediaWiki\Extension\AchievementBadges\HookHandler;

use Config;
use EchoEvent;
use Hooks;
use MediaWiki\Extension\AchievementBadges\Constants;
use MediaWiki\Extension\AchievementBadges\EarnEchoEventPresentationModel;
use MediaWiki\MediaWikiServices;
use User;

class Main {

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @param Config $config
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * @todo hide or disable echo-subscriptions-web-thank-you-edit option when replaced
	 */
	public static function initExtension() {
		global $wgAchievementBadgesAchievements, $wgNotifyTypeAvailabilityByCategory;

		Hooks::run( 'BeforeCreateAchievement', [ &$wgAchievementBadgesAchievements ] );

		// Overwrite echo's milestone if configured.
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if ( !$config->get( Constants::CONFIG_KEY_ENABLE_BETA_FEATURE ) &&
			$config->get( Constants::CONFIG_KEY_REPLACE_ECHO_THANK_YOU_EDIT ) ) {
				$wgNotifyTypeAvailabilityByCategory['thank-you-edit']['web'] = false;
		}
	}

	/**
	 * @param User $user
	 * @param array &$betaPrefs
	 */
	public static function onGetBetaFeaturePreferences( User $user, array &$betaPrefs ) {
		$extensionAssetsPath = MediaWikiServices::getInstance()
			->getMainConfig()
			->get( 'ExtensionAssetsPath' );
		$betaPrefs[Constants::PREF_KEY_ACHIEVEMENT_ENABLE] = [
			'label-message' => 'achievementbadges-achievement-enable-message',
			'desc-message' => 'achievementbadges-achievement-enable-description',
			'info-link' => 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:AchievementBadges',
			'discussion-link' => 'https://github.com/femiwiki/AchievementBadges/issues',
		];
	}

	/**
	 * Defining the events for this extension
	 *
	 * @param array &$notifs
	 * @param array &$categories
	 * @param array &$icons
	 */
	public static function onBeforeCreateEchoEvent( &$notifs, &$categories, &$icons ) {
		$categories[Constants::ECHO_EVENT_CATEGORY] = [
			'priority' => 9,
			'tooltip' => 'achievementbadges-pref-tooltip-achievement-badges',
		];

		$notifs[Constants::EVENT_KEY_EARN] = [
			'category' => Constants::ECHO_EVENT_CATEGORY,
			'group' => 'positive',
			'section' => 'message',
			'canNotifyAgent' => true,
			'presentation-model' => EarnEchoEventPresentationModel::class,
			'user-locators' => [ 'EchoUserLocator::locateEventAgent' ],
		];
	}

	/**
	 * @param EchoEvent $event
	 * @return bool
	 */
	public static function onBeforeEchoEventInsert( EchoEvent $event ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$agent = $event->getAgent();
		$type = $event->getType();

		if ( $type == 'thank-you-edit'
			&& $config->get( Constants::CONFIG_KEY_REPLACE_ECHO_THANK_YOU_EDIT ) ) {
			return false;
		}
		return true;
	}
}