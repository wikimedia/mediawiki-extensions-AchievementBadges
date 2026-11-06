<?php

namespace MediaWiki\Extension\AchievementBadges;

use FatalError;
use Language;
use LogPage;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MWException;
use MWTimestamp;
use User;
use Wikimedia\Rdbms\IReadableDatabase;

class Achievement {
	/**
	 * You should not call the constructor.
	 * @return never
	 * @throws FatalError
	 */
	public function __construct() {
		throw new FatalError( "Cannot construct a utility class." );
	}

	/**
	 * @deprecated Use AchievementManager::award() instead
	 * @param array $info arguments:
	 * key:
	 * user: The user who earned the achievement.
	 */
	public static function achieve( $info ) {
		if ( !isset( $info['key'] ) ) {
			throw new MWException( "'key' parameter is mandatory" );
		}
		self::getManager()->award( $info['key'], $info['user'] );
	}

	/**
	 * @deprecated Use AchievementManager::awardForStats() instead
	 * @param array $info arguments:
	 * key:
	 * user: The user who earned the achievement.
	 * stats:
	 */
	public static function sendStats( $info ) {
		if ( !isset( $info['key'] ) ) {
			throw new MWException( "'key' parameter is mandatory" );
		}
		self::getManager()->awardForStats( $info['key'], $info['user'], $info['stats'] );
	}

	private static function getManager(): AchievementManager {
		return MediaWikiServices::getInstance()->getService( 'AchievementBadgesAchievementManager' );
	}

	/**
	 * @param IReadableDatabase $db
	 * @return array With four keys:
	 *   - tables: (string[]) to include in the `$table` to `IDatabase->select()`
	 *   - fields: (string[]) to include in the `$vars` to `IDatabase->select()`
	 *   - conds: (array) to include in the `$conds` to `IDatabase->select()`
	 *   - joins: (array) to include in the `$join_conds` to `IDatabase->select()`
	 */
	public static function getQueryInfo( IReadableDatabase $db ) {
		$queryInfo = [
			'tables' => [
				'logging',
			],
			'fields' => [ '*' ],
			'conds' => [
				'log_type' => Constants::LOG_TYPE,
				$db->bitAnd( 'log_deleted', LogPage::DELETED_ACTION | LogPage::DELETED_USER ) . ' = 0 ',
			],
			'joins' => [],
		];
		return $queryInfo;
	}

	/**
	 * @deprecated Use AchievementManager::canEarn() instead
	 * @param User $user
	 * @return bool
	 */
	public static function isAchievementBadgesAvailable( User $user ): bool {
		return self::getManager()->canEarn( $user );
	}

	/**
	 * Returns the icon path of an achievement, or of a threshold of a stats achievement when
	 * the achievement has the `icons` property.
	 *
	 * @param array $info An entry of $wgAchievementBadgesAchievements
	 * @param int|null $index Index of the threshold
	 * @return string|array|null
	 */
	public static function getIconPath( array $info, ?int $index = null ) {
		if ( $index !== null && isset( $info['icons'][$index] ) ) {
			return $info['icons'][$index];
		}
		return $info['icon'] ?? null;
	}

	/**
	 * @param Language $lang
	 * @param string|array|null $path
	 * @return string
	 */
	public static function getAchievementIcon( Language $lang, $path = null ): string {
		if ( $path === null ) {
			$config = MediaWikiServices::getInstance()->getMainConfig();
			$path = $config->get( Constants::CONFIG_KEY_ACHIEVEMENT_FALLBACK_ICON );
		}
		return self::getImageForLanguage( $lang, $path );
	}

	/**
	 * @param Language $lang
	 * @param string|array|null $path
	 * @return string
	 */
	public static function getAchievementOgImage( Language $lang, $path = null ): string {
		if ( $path === null ) {
			$config = MediaWikiServices::getInstance()->getMainConfig();
			$path = $config->get( Constants::CONFIG_KEY_ACHIEVEMENT_FALLBACK_OG_IMAGE );
		}
		return self::getImageForLanguage( $lang, $path );
	}

	/**
	 * @param Language $lang
	 * @param string|array $path
	 * @return string
	 */
	public static function getImageForLanguage( Language $lang, $path ): string {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if ( is_array( $path ) ) {
			if ( array_key_exists( $lang->getCode(), $path ) ) {
				$path = $path[$lang->getCode()];
			} else {
				$path = $path[$lang->getDir()];
			}
		}
		return $config->get( MainConfigNames::ScriptPath ) . "/$path";
	}

	/**
	 * @param Language $lang
	 * @param User $user
	 * @param string|null $timestamp
	 * @return array time text:
	 * 0: Shorten for display with a message (ex: "Earned at")
	 * 1: Full timestamp for title attribute of html
	 */
	public static function getHumanTimes( Language $lang, User $user, ?string $timestamp = null ): array {
		/** @var MWTimestamp */
		$timestamp = MWTimestamp::getInstance( $timestamp );

		$shortHumanTime = wfMessage( 'achievement-earned-at',
			$user->getName(),
			$lang->getHumanTimestamp(
				$timestamp,
				null,
				$user
			)
		)->text();

		$date = $lang->userDate( $timestamp, $user );
		$time = $lang->userTime( $timestamp, $user );
		$longHumanTime = wfMessage( 'achievement-earned-at-tooltip', $date, $time )->text();

		return [
			$shortHumanTime,
			$longHumanTime
		];
	}

	/**
	 * @param string $key An achievement key that could be either key with number for stats achievement
	 * or key without number for instant achievement.
	 * @return array|bool
	 * 0: Suffixed key (ex: 'edit-page-0')
	 * 1: Unsuffixed key (ex: 'edit-page')
	 * 2: index (ex: 0)
	 */
	public static function extractKeySegments( string $key ) {
		preg_match( '/(.+)\-(\d+)/', $key, $matches );
		if ( !$matches ) {
			return [ $key, $key, null ];
		} elseif ( count( $matches ) === 3 ) {
			array_shift( $matches );
			return [ $key, $matches[0], (int)$matches[1] ];
		} else {
			return false;
		}
	}
}
