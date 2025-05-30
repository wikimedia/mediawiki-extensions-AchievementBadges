<?php

namespace MediaWiki\Extension\AchievementBadges\HookHandler;

use Config;
use MediaWiki\Extension\AchievementBadges\Constants;
use MediaWiki\Extension\AchievementBadges\EarnEchoEventPresentationModel;
use MediaWiki\Extension\AchievementBadges\Hooks\HookRunner;
use MediaWiki\Extension\AchievementBadges\Special\SpecialAchievements;
use MediaWiki\Extension\BetaFeatures\BetaFeatures;
use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\Notifications\UserLocator;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use SpecialPage;
use User;

class Main implements
	\MediaWiki\ResourceLoader\Hook\ResourceLoaderGetConfigVarsHook,
	\MediaWiki\Hook\ContributionsToolLinksHook
{
	private Config $config;
	private HookRunner $hookRunner;

	public function __construct( Config $config, HookRunner $hookRunner ) {
		$this->config = $config;
		$this->hookRunner = $hookRunner;
	}

	public static function onGetBetaFeaturePreferences( User $user, array &$betaPrefs ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if ( !$config->get( Constants::CONFIG_KEY_ENABLE_BETA_FEATURE ) ) {
			return;
		}
		$extensionAssetsPath = $config->get( 'ExtensionAssetsPath' );
		$betaPrefs[Constants::PREF_KEY_ACHIEVEMENT_ENABLE] = [
			'label-message' => 'achievementbadges-beta-feature-achievement-enable-message',
			'desc-message' => 'achievementbadges-beta-feature-achievement-enable-description',
			'screenshot' =>
				"$extensionAssetsPath/AchievementBadges/images/betafeatures-icon-AchievementBadges.svg",
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
			'bundle' => [
				'web' => true,
				'email' => true,
				'expandable' => true,
			],
			AttributeManager::ATTR_LOCATORS => [
				UserLocator::class . '::locateEventAgent',
			],
		];
		$icons[Constants::EVENT_KEY_EARN] = [
			'path' => 'AchievementBadges/images/medal.svg',
		];
	}

	/**
	 * @param Event $event
	 * @param string &$bundleString
	 */
	public static function onEchoGetBundleRules( $event, &$bundleString ) {
		if ( $event->getType() === Constants::EVENT_KEY_EARN ) {
			$bundleString = Constants::EVENT_KEY_EARN;
		}
	}

	/**
	 * @param Event $event
	 * @return bool
	 */
	public static function onBeforeEchoEventInsert( Event $event ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$type = $event->getType();

		if ( $type === 'thank-you-edit'
			&& $config->get( Constants::CONFIG_KEY_REPLACE_ECHO_THANK_YOU_EDIT ) ) {
			return false;
		}
		if ( $type === 'welcome'
			&& $config->get( Constants::CONFIG_KEY_REPLACE_ECHO_WELCOME )
			&& !$config->get( Constants::CONFIG_KEY_ENABLE_BETA_FEATURE ) ) {
			// the welcome notification is replaced with 'sign-up' achievement.
			return false;
		}
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onResourceLoaderGetConfigVars( array &$vars, $skin, Config $config ): void {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$vars['wg' . Constants::CONFIG_KEY_FACEBOOK_APP_ID] = $config->get( Constants::CONFIG_KEY_FACEBOOK_APP_ID );
	}

	/**
	 * @inheritDoc
	 */
	public function onContributionsToolLinks(
		$id,
		Title $title,
		array &$tools,
		SpecialPage $specialPage
	) {
		$target = User::newFromId( $id );
		if ( $target->isAnon() ) {
			return;
		}
		$linkTarget = SpecialPage::getTitleFor( SpecialAchievements::PAGE_NAME, $target->getName() );
		$msg = wfMessage( 'achievementbadges-link-on-user-contributes' )->text();
		$linkRenderer = $specialPage->getLinkRenderer();
		$betaPeriod = $this->config->get( Constants::CONFIG_KEY_ENABLE_BETA_FEATURE );
		$userOptionEnabled = $betaPeriod &&
			BetaFeatures::isFeatureEnabled( $target, Constants::PREF_KEY_ACHIEVEMENT_ENABLE );

		if ( $betaPeriod && !$userOptionEnabled ) {
			$tools['achievementbadges'] = $linkRenderer->makeBrokenLink( $linkTarget, $msg );
		} else {
			$tools['achievementbadges'] = $linkRenderer->makeKnownLink( $linkTarget, $msg );
		}
	}
}
