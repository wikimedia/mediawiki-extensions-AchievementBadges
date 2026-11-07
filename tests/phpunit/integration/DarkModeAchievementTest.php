<?php

namespace MediaWiki\Extension\AchievementBadges\Tests\Integration;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\AchievementBadges\Achievement;
use MediaWiki\Extension\AchievementBadges\Constants;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @group AchievementBadges
 * @group Database
 *
 * @covers \MediaWiki\Extension\AchievementBadges\HookHandler\AchievementRegister
 */
class DarkModeAchievementTest extends MediaWikiIntegrationTestCase {

	/** @inheritDoc */
	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( Constants::CONFIG_KEY_ACHIEVEMENTS, [
			Constants::ACHV_KEY_DARK_MODE => [
				'type' => 'instant',
			],
		] );
	}

	private function countEarned( User $user ): int {
		$dbr = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase();
		$query = Achievement::getQueryInfo( $dbr );
		$query['conds'] = array_merge( $query['conds'], [
			'log_action' => Constants::ACHV_KEY_DARK_MODE,
			'log_actor' => $user->getActorId(),
		] );
		return $dbr->newSelectQueryBuilder()
			->queryInfo( $query )
			->caller( __METHOD__ )
			->fetchRowCount();
	}

	private function setDarkMode( User $user, string $value ): void {
		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
		$userOptionsManager->setOption( $user, Constants::PREF_KEY_DARK_MODE, $value );
		$userOptionsManager->saveOptions( $user );
		DeferredUpdates::doUpdates();
	}

	public function testTurningOnDarkModeIsAchieved() {
		$user = $this->getMutableTestUser()->getUser();

		$this->setDarkMode( $user, '1' );
		$this->assertSame( 1, $this->countEarned( $user ) );

		$this->setDarkMode( $user, '0' );
		$this->setDarkMode( $user, '1' );
		$this->assertSame( 1, $this->countEarned( $user ), 'The achievement is given only once' );
	}

	public function testTurningOffDarkModeIsNotAchieved() {
		$user = $this->getMutableTestUser()->getUser();

		$this->setDarkMode( $user, '0' );
		$this->assertSame( 0, $this->countEarned( $user ) );
	}
}
