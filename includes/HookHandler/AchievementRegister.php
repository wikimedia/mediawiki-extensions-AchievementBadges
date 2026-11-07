<?php

namespace MediaWiki\Extension\AchievementBadges\HookHandler;

use ApiBase;
use Config;
use DeferredUpdates;
use ExtensionRegistry;
use MediaWiki\Extension\AchievementBadges\Achievement;
use MediaWiki\Extension\AchievementBadges\Constants;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserOptionsLookup;
use MWTimestamp;
use User;
use Wikimedia\Rdbms\IConnectionProvider;
use WikiPage;

class AchievementRegister implements
	\MediaWiki\Api\Hook\APIAfterExecuteHook,
	\MediaWiki\Auth\Hook\LocalUserCreatedHook,
	\MediaWiki\ChangeTags\Hook\ChangeTagsAfterUpdateTagsHook,
	\MediaWiki\Extension\AchievementBadges\Hooks\BeforeCreateAchievementHook,
	\MediaWiki\Extension\AchievementBadges\Hooks\SpecialAchievementsBeforeGetEarnedHook,
	\MediaWiki\Hook\UserEditCountUpdateHook,
	\MediaWiki\Storage\Hook\PageSaveCompleteHook,
	\MediaWiki\User\Options\Hook\SaveUserOptionsHook,
	\MediaWiki\User\Hook\UserSaveSettingsHook
{
	private Config $config;
	private IConnectionProvider $dbProvider;
	private RevisionStore $revisionStore;
	private UserFactory $userFactory;
	private UserOptionsLookup $userOptionsLookup;

	public function __construct(
		Config $config,
		IConnectionProvider $dbProvider,
		RevisionStore $revisionStore,
		UserFactory $userFactory,
		UserOptionsLookup $userOptionsLookup
	) {
		$this->config = $config;
		$this->dbProvider = $dbProvider;
		$this->revisionStore = $revisionStore;
		$this->userFactory = $userFactory;
		$this->userOptionsLookup = $userOptionsLookup;
	}

	private const WEEKDAYS = [
		Constants::ACHV_KEY_CONTRIBS_SUNDAY,
		Constants::ACHV_KEY_CONTRIBS_MONDAY,
		Constants::ACHV_KEY_CONTRIBS_TUESDAY,
		Constants::ACHV_KEY_CONTRIBS_WEDNESDAY,
		Constants::ACHV_KEY_CONTRIBS_THURSDAY,
		Constants::ACHV_KEY_CONTRIBS_FRIDAY,
		Constants::ACHV_KEY_CONTRIBS_SATURDAY,
	];

	/**
	 * @inheritDoc
	 */
	public function onBeforeCreateAchievement( array &$achievements ) {
		$config = $this->config;
		if ( $config->get( Constants::CONFIG_KEY_ENABLE_BETA_FEATURE )
			&& ExtensionRegistry::getInstance()->isLoaded( 'BetaFeatures' ) ) {
			$achievements[Constants::ACHV_KEY_ENABLE_ACHIEVEMENT_BADGES] = [
				'type' => 'instant',
				'priority' => 0,
			] + $this->getImages( Constants::ACHV_KEY_ENABLE_ACHIEVEMENT_BADGES );
		} elseif ( $config->get( Constants::CONFIG_KEY_REPLACE_ECHO_WELCOME ) ) {
			$achievements[Constants::ACHV_KEY_SIGN_UP] = [
				'type' => 'instant',
				'priority' => 0,
			] + $this->getImages( Constants::ACHV_KEY_SIGN_UP );
		}
		if ( $config->get( Constants::CONFIG_KEY_REPLACE_ECHO_THANK_YOU_EDIT ) ) {
			$achievements[Constants::ACHV_KEY_EDIT_PAGE] = [
				'type' => 'stats',
				'thresholds' => [ 1, 10, 100, 1000, 10000 ],
				'priority' => 200,
			] + $this->getImages( Constants::ACHV_KEY_EDIT_PAGE );
		}
		$achievements[Constants::ACHV_KEY_LONG_USER_PAGE] = [
			'type' => 'instant',
			'priority' => 100,
		] + $this->getImages( Constants::ACHV_KEY_LONG_USER_PAGE );
		$achievements[Constants::ACHV_KEY_EDIT_SIZE] = [
			'type' => 'stats',
			'thresholds' => [ 1000, 5000, 10000, 50000, 100000 ],
			'priority' => 300,
		] + $this->getImages( Constants::ACHV_KEY_EDIT_SIZE );
		$achievements[Constants::ACHV_KEY_CREATE_PAGE] = [
			'type' => 'stats',
			'thresholds' => [ 1, 5, 30, 100, 300, 1000 ],
			'priority' => 300,
		] + $this->getImages( Constants::ACHV_KEY_CREATE_PAGE );
		foreach ( self::WEEKDAYS as $weekday ) {
			$achievements[$weekday] = [
				'type' => 'instant',
				'priority' => 500,
			] + $this->getImages( $weekday );
		}
		if ( $this->isVisualEditorTagUsed() ) {
			$achievements[Constants::ACHV_KEY_VISUAL_EDIT] = [
				'type' => 'instant',
				'priority' => 400,
			] + $this->getImages( Constants::ACHV_KEY_VISUAL_EDIT );
		}
		if ( self::isThanksEnabled() ) {
			$achievements[Constants::ACHV_KEY_THANKS] = [
				'type' => 'instant',
				'priority' => 500,
			] + $this->getImages( Constants::ACHV_KEY_THANKS );
			$achievements[Constants::ACHV_KEY_BE_THANKED] = [
				'type' => 'instant',
				'priority' => 500,
			] + $this->getImages( Constants::ACHV_KEY_BE_THANKED );
		}
		$achievements[Constants::ACHV_KEY_WATCH_PAGE] = [
			'type' => 'instant',
			'priority' => 500,
		] + $this->getImages( Constants::ACHV_KEY_WATCH_PAGE );
		if ( ExtensionRegistry::getInstance()->isLoaded( 'DarkMode' ) ) {
			$achievements[Constants::ACHV_KEY_DARK_MODE] = [
				'type' => 'instant',
				'priority' => 500,
			] + $this->getImages( Constants::ACHV_KEY_DARK_MODE );
		}
	}

	/**
	 * Returns the images of a built-in achievement. Most social media do not show an SVG as
	 * a preview image, so the Open Graph image is the fallback one.
	 *
	 * @param string $key
	 * @return array
	 */
	private function getImages( string $key ): array {
		return [
			'icon' => "extensions/AchievementBadges/images/achievement-icon-$key.svg",
			'og-image' => $this->config->get( Constants::CONFIG_KEY_ACHIEVEMENT_FALLBACK_OG_IMAGE ),
		];
	}

	private function isVisualEditorTagUsed(): bool {
		return ExtensionRegistry::getInstance()->isLoaded( 'VisualEditor' )
			&& $this->config->get( 'VisualEditorUseChangeTagging' );
	}

	private static function isThanksEnabled(): bool {
		return ExtensionRegistry::getInstance()->isLoaded( 'Thanks' );
	}

	/**
	 * @inheritDoc
	 */
	public function onSpecialAchievementsBeforeGetEarned( User $user ) {
		if ( $user->isAnon() ) {
			return;
		}
		$config = $this->config;
		$registry = $config->get( Constants::CONFIG_KEY_ACHIEVEMENTS );
		if ( !isset( $registry[Constants::ACHV_KEY_SIGN_UP] ) ) {
			return;
		}
		Achievement::achieve( [ 'key' => Constants::ACHV_KEY_SIGN_UP, 'user' => $user ] );
	}

	/**
	 * @inheritDoc
	 */
	public function onLocalUserCreated( $user, $autocreated ) {
		if ( $autocreated ) {
			return;
		}
		$config = $this->config;
		$registry = $config->get( Constants::CONFIG_KEY_ACHIEVEMENTS );
		if ( !isset( $registry[Constants::ACHV_KEY_SIGN_UP] ) ) {
			return;
		}
		Achievement::achieve( [ 'key' => Constants::ACHV_KEY_SIGN_UP, 'user' => $user ] );
	}

	/**
	 * @inheritDoc
	 */
	public function onUserSaveSettings( $user ) {
		if ( !$this->config->get( Constants::CONFIG_KEY_ENABLE_BETA_FEATURE ) ) {
			return;
		}
		if ( $this->userOptionsLookup->getOption( $user, Constants::PREF_KEY_ACHIEVEMENT_ENABLE ) ) {
			Achievement::achieve( [
				'key' => Constants::ACHV_KEY_ENABLE_ACHIEVEMENT_BADGES,
				'user' => $user,
			] );
		}
	}

	/**
	 * Gives the dark mode achievement when the user turns on the dark mode of the DarkMode
	 * extension, from its toggle link or from Special:Preferences.
	 *
	 * @inheritDoc
	 */
	public function onSaveUserOptions( UserIdentity $user, array &$modifiedOptions, array $originalOptions ) {
		$registry = $this->config->get( Constants::CONFIG_KEY_ACHIEVEMENTS );
		if ( !isset( $registry[Constants::ACHV_KEY_DARK_MODE] ) ) {
			return;
		}
		if ( empty( $modifiedOptions[Constants::PREF_KEY_DARK_MODE] ) ||
			!empty( $originalOptions[Constants::PREF_KEY_DARK_MODE] )
		) {
			return;
		}
		$user = $this->userFactory->newFromUserIdentity( $user );
		DeferredUpdates::addCallableUpdate( static function () use ( $user ) {
			Achievement::achieve( [
				'key' => Constants::ACHV_KEY_DARK_MODE,
				'user' => $user,
			] );
		} );
	}

	/**
	 * @inheritDoc
	 */
	public function onUserEditCountUpdate( $infos ): void {
		foreach ( $infos as $info ) {
			$user = $this->userFactory->newFromUserIdentity( $info->getUser() );
			Achievement::sendStats( [
				'key' => Constants::ACHV_KEY_EDIT_PAGE,
				'user' => $user,
				'stats' => $user->getEditCount(),
			] );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onPageSaveComplete(
		$wikiPage,
		$user,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	) {
		if ( $editResult->isNullEdit() ) {
			LoggerFactory::getInstance( 'AchievementBadges' )->debug( 'null edit is ignored.' );
			return;
		} elseif ( $editResult->isRevert() ) {
			LoggerFactory::getInstance( 'AchievementBadges' )->debug( 'revert is ignored.' );
			return;
		}
		$user = $this->userFactory->newFromUserIdentity( $user );
		if ( $user->isAnon() ) {
			return;
		}

		DeferredUpdates::addCallableUpdate(
			function () use ( $wikiPage, $user, $revisionRecord, $editResult ) {
				$this->checkEditAchievements( $wikiPage, $user, $revisionRecord, $editResult );
			},
			DeferredUpdates::POSTSEND
		);
	}

	private function checkEditAchievements(
		WikiPage $wikiPage,
		User $user,
		RevisionRecord $revisionRecord,
		EditResult $editResult
	): void {
		if ( $wikiPage->getTitle()->equals( $user->getUserPage() ) &&
			$revisionRecord->getSize() > 500 ) {
				Achievement::achieve( [
					'key' => Constants::ACHV_KEY_LONG_USER_PAGE,
					'user' => $user,
				] );
		}
		// Set user's timezone!
		$userTimestamp = MWTimestamp::getInstance( $revisionRecord->getTimestamp() );
		$userTimestamp->offsetForUser( $user );
		$weekday = (int)$userTimestamp->format( 'w' );
		Achievement::achieve( [
			'key' => self::WEEKDAYS[$weekday],
			'user' => $user,
		] );

		$dbr = $this->dbProvider->getReplicaDatabase();

		if ( $editResult->isNew() ) {
			$newPages = $this->revisionStore->newSelectQueryBuilder( $dbr )
				->clearFields()
				->where( [
					'actor_user' => $user->getId(),
					'rev_parent_id' => 0,
					$dbr->bitAnd(
						'rev_deleted', RevisionRecord::DELETED_USER
						) . ' != ' . RevisionRecord::DELETED_USER,
				] )
				->limit( 1000 )
				->caller( __METHOD__ )
				->fetchRowCount();
			Achievement::sendStats( [
				'key' => Constants::ACHV_KEY_CREATE_PAGE,
				'user' => $user,
				'stats' => $newPages,
			] );
			Achievement::sendStats( [
				'key' => Constants::ACHV_KEY_EDIT_SIZE,
				'user' => $user,
				'stats' => $revisionRecord->getSize(),
			] );
		} else {
			$parentRevision = $revisionRecord->getParentId();
			$parentRevision = $this->revisionStore->getRevisionById( $parentRevision );
			$diff = $revisionRecord->getSize() - $parentRevision->getSize();
			if ( $diff > 0 ) {
				Achievement::sendStats( [
					'key' => Constants::ACHV_KEY_EDIT_SIZE,
					'user' => $user,
					'stats' => $diff,
				] );
			}
		}
	}

	/** @inheritDoc */
	public function onChangeTagsAfterUpdateTags( $addedTags, $removedTags,
		$prevTags, $rc_id, $rev_id, $log_id, $params, $rc, $user ) {
		if ( $this->isVisualEditorTagUsed() && in_array( 'visualeditor', $addedTags ) ) {
			// The given $user is empty when visual editing
			$user = $this->revisionStore->getRevisionById( $rev_id )->getUser();
			$user = $this->userFactory->newFromUserIdentity( $user );
			Achievement::achieve( [
				'key' => Constants::ACHV_KEY_VISUAL_EDIT,
				'user' => $user,
			] );
		}
	}

	/** @inheritDoc */
	public function onAPIAfterExecute( $module ) {
		$moduleName = $module->getModuleName();
		if ( $moduleName === 'watch' ) {
			$this->checkWatchAchievement( $module );
			return;
		}
		if ( $moduleName !== 'thank' ) {
			return;
		}
		$user = $module->getUser();
		$result = $module->getResult()->getResultData();
		if ( !isset( $result['result'] ) ) {
			return;
		}
		$result = $result['result'];
		if ( !isset( $result['recipient'] ) || !$result['success'] ) {
			return;
		}
		$recipient = $this->userFactory->newFromName( $result['recipient'] );
		LoggerFactory::getInstance( 'AchievementBadges' )->debug( "$user thanks to $recipient" );
		Achievement::achieve( [
			'key' => Constants::ACHV_KEY_THANKS,
			'user' => $user,
		] );
		Achievement::achieve( [
			'key' => Constants::ACHV_KEY_BE_THANKED,
			'user' => $recipient,
		] );
	}

	/**
	 * Watching a page from the watch star calls the watch API module. Pages watched
	 * because of an edit do not count, as an edit does not call this module.
	 *
	 * @param ApiBase $module
	 */
	private function checkWatchAchievement( ApiBase $module ): void {
		$user = $module->getUser();
		if ( !$user->isNamed() ) {
			return;
		}
		$results = $module->getResult()->getResultData( [ 'watch' ], [ 'Strip' => 'all' ] );
		if ( !is_array( $results ) ) {
			return;
		}
		foreach ( $results as $result ) {
			if ( is_array( $result ) && !empty( $result['watched'] ) ) {
				Achievement::achieve( [
					'key' => Constants::ACHV_KEY_WATCH_PAGE,
					'user' => $user,
				] );
				return;
			}
		}
	}
}
