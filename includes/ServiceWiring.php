<?php
namespace MediaWiki\Extension\AchievementBadges;

use MediaWiki\Extension\AchievementBadges\Hooks\HookRunner;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'AchievementBadgesAchievementManager' => static function (
		MediaWikiServices $services
	): AchievementManager {
		return new AchievementManager(
			$services->getMainConfig(),
			$services->getConnectionProvider(),
			$services->getUserFactory(),
			LoggerFactory::getInstance( 'AchievementBadges' )
		);
	},
	'AchievementBadgesHookRunner' => static function ( MediaWikiServices $services ): HookRunner {
		return new HookRunner( $services->getHookContainer() );
	},
];
