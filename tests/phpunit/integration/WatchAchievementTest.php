<?php

namespace MediaWiki\Extension\AchievementBadges\Tests\Integration;

use MediaWiki\Extension\AchievementBadges\Achievement;
use MediaWiki\Extension\AchievementBadges\Constants;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;

/**
 * @group AchievementBadges
 * @group API
 * @group Database
 *
 * @covers \MediaWiki\Extension\AchievementBadges\HookHandler\AchievementRegister
 */
class WatchAchievementTest extends ApiTestCase {

	/** @inheritDoc */
	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( Constants::CONFIG_KEY_ACHIEVEMENTS, [
			Constants::ACHV_KEY_WATCH_PAGE => [
				'type' => 'instant',
			],
		] );
	}

	private function countEarned( User $user ): int {
		$dbr = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase();
		$query = Achievement::getQueryInfo( $dbr );
		$query['conds'] = array_merge( $query['conds'], [
			'log_action' => Constants::ACHV_KEY_WATCH_PAGE,
			'log_actor' => $user->getActorId(),
		] );
		return $dbr->newSelectQueryBuilder()
			->queryInfo( $query )
			->caller( __METHOD__ )
			->fetchRowCount();
	}

	public function testWatchingAPageIsAchieved() {
		$user = $this->getMutableTestUser()->getUser();
		$this->editPage( 'Watch test', 'lorem' );

		$this->doApiRequestWithToken( [
			'action' => 'watch',
			'titles' => 'Watch test',
		], null, $user );
		$this->assertSame( 1, $this->countEarned( $user ) );

		$this->doApiRequestWithToken( [
			'action' => 'watch',
			'titles' => 'Watch test',
			'unwatch' => true,
		], null, $user );
		$this->doApiRequestWithToken( [
			'action' => 'watch',
			'titles' => 'Watch test',
		], null, $user );
		$this->assertSame( 1, $this->countEarned( $user ), 'The achievement is given only once' );
	}

	public function testUnwatchingAPageIsNotAchieved() {
		$user = $this->getMutableTestUser()->getUser();
		$this->editPage( 'Unwatch test', 'lorem' );

		$this->doApiRequestWithToken( [
			'action' => 'watch',
			'titles' => 'Unwatch test',
			'unwatch' => true,
		], null, $user );
		$this->assertSame( 0, $this->countEarned( $user ) );
	}

	public function testEditingWithWatchIsNotAchieved() {
		$user = $this->getMutableTestUser()->getUser();

		$this->doApiRequestWithToken( [
			'action' => 'edit',
			'title' => 'Edit and watch test',
			'text' => 'lorem',
			'watchlist' => 'watch',
		], null, $user );
		$this->assertSame( 0, $this->countEarned( $user ) );
	}
}
