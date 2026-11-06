<?php

namespace MediaWiki\Extension\AchievementBadges;

use ExtensionRegistry;
use InvalidArgumentException;
use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\Extension\AchievementBadges\Special\SpecialAchievements;
use MediaWiki\Extension\BetaFeatures\BetaFeatures;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Awards achievements to users.
 *
 * Other extensions can get this service as "AchievementBadgesAchievementManager"
 * to award achievements they register with the BeforeCreateAchievement hook.
 */
class AchievementManager {
	private Config $config;
	private IConnectionProvider $dbProvider;
	private UserFactory $userFactory;
	private LoggerInterface $logger;

	public function __construct(
		Config $config,
		IConnectionProvider $dbProvider,
		UserFactory $userFactory,
		LoggerInterface $logger
	) {
		$this->config = $config;
		$this->dbProvider = $dbProvider;
		$this->userFactory = $userFactory;
		$this->logger = $logger;
	}

	/**
	 * Award an instant achievement to the user unless the user already has it.
	 *
	 * @param string $key Key of an achievement with the type 'instant'
	 * @param UserIdentity $user The user who earned the achievement
	 * @throws InvalidArgumentException If the achievement is not an instant achievement
	 */
	public function award( string $key, UserIdentity $user ): void {
		$user = $this->userFactory->newFromUserIdentity( $user );
		if ( !$this->canEarn( $user ) ) {
			return;
		}
		$info = $this->getAchievementInfo( $key );
		if ( $info === null ) {
			return;
		}
		$type = $info['type'] ?? 'instant';
		if ( $type !== 'instant' ) {
			throw new InvalidArgumentException( "$key is not an instant achievement" );
		}

		if ( $this->selectLogCount( $key, $user ) > 0 ) {
			$this->logger->debug( "User $user satisfied the condition of achievement $key," .
				' but ignored because already achieved it.' );
			return;
		}
		$this->logger->debug( "User $user satisfied the condition of achievement $key" );

		$this->awardInternal( $key, $user );
	}

	/**
	 * Award every level of a stats achievement whose threshold the given value reaches.
	 *
	 * @param string $key Key of an achievement with the type 'stats'
	 * @param UserIdentity $user The user who earned the achievement
	 * @param int $stats The user's current value for the achievement
	 * @throws InvalidArgumentException If the achievement is not a stats achievement
	 */
	public function awardForStats( string $key, UserIdentity $user, int $stats ): void {
		$user = $this->userFactory->newFromUserIdentity( $user );
		if ( !$this->canEarn( $user ) ) {
			$this->logger->debug( 'The user cannot use AchievementBadges' );
			return;
		}
		$info = $this->getAchievementInfo( $key );
		if ( $info === null ) {
			return;
		}
		if ( ( $info['type'] ?? null ) !== 'stats' ) {
			throw new InvalidArgumentException( "$key is not a stats achievement" );
		}
		$this->logger->debug( $user->getName() . " has a stats with a value $stats at $key" );
		$thresholds = $info['thresholds'];
		if ( $stats < $thresholds[0] ) {
			return;
		}
		$numThresholds = count( $thresholds );

		$earned = $this->selectLogCount( $key, $user );
		$this->logger->debug( $user->getName() . " has $earned achieved achievements at $key" );

		for ( $i = $earned; $i < $numThresholds; $i++ ) {
			if ( $stats >= $thresholds[$i] ) {
				$this->logger->debug( $user->getName() . " exceeds {$i}-index threshold of $key" );
				$this->awardInternal( $key, $user, $i );
			} else {
				break;
			}
		}
	}

	/**
	 * Whether the user can earn achievements.
	 *
	 * @param UserIdentity $user
	 * @return bool
	 */
	public function canEarn( UserIdentity $user ): bool {
		$user = $this->userFactory->newFromUserIdentity( $user );
		if ( $user->isSystemUser() ) {
			return false;
		}
		if ( !$this->config->get( Constants::CONFIG_KEY_ENABLE_BETA_FEATURE ) ) {
			// If AchievementBadges is not a beta feature, it is available to everyone.
			return true;
		}
		// If AchievementBadges is a beta feature, only a registered user who enables the feature
		// can use it.
		return $user->isRegistered() &&
			BetaFeatures::isFeatureEnabled( $user, Constants::PREF_KEY_ACHIEVEMENT_ENABLE );
	}

	/**
	 * @param string $key
	 * @return array|null The registered achievement, or null if it is unknown or disabled
	 */
	private function getAchievementInfo( string $key ): ?array {
		$registry = $this->config->get( Constants::CONFIG_KEY_ACHIEVEMENTS );
		$disabled = $this->config->get( Constants::CONFIG_KEY_DISABLED_ACHIEVEMENTS );

		if ( in_array( $key, $disabled ) ) {
			return null;
		}
		if ( !isset( $registry[$key] ) ) {
			$this->logger->warning( "Unknown achievement key: {$key}" );
			return null;
		}
		return $registry[$key];
	}

	private function awardInternal( string $key, User $user, ?int $index = null ): void {
		$logEntry = new ManualLogEntry( Constants::LOG_TYPE, $key );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( SpecialPage::getTitleFor( SpecialAchievements::PAGE_NAME ) );
		$params = [
			'4::key' => $key,
		];
		if ( $index !== null ) {
			$params['5::index'] = $index;
		}
		$logEntry->setParameters( $params );

		$logEntry->insert();
		$this->logger->debug( 'A log is inserted with param: ' .
			str_replace( "\n", ' ', print_r( $params, true ) ) );

		$suffixedKey = $key;
		if ( $index !== null ) {
			$suffixedKey .= "-$index";
		}
		if ( ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) ) {
			Event::create( [
				'type' => Constants::EVENT_KEY_EARN,
				'agent' => $user,
				'extra' => [ 'key' => $suffixedKey ],
			] );
			$this->logger->debug( "A echo event of achievement $suffixedKey is created for $user " );
		}
	}

	private function selectLogCount( string $key, User $user ): int {
		$dbr = $this->dbProvider->getReplicaDatabase();
		$query = Achievement::getQueryInfo( $dbr );
		$query['fields'] = '*';
		$query['conds'] = array_merge( $query['conds'], [
			'log_action' => $key,
			'log_actor' => $user->getActorId(),
		] );
		return $dbr->newSelectQueryBuilder()
			->queryInfo( $query )
			->caller( __METHOD__ )
			->fetchRowCount();
	}
}
