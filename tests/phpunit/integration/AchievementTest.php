<?php

namespace MediaWiki\Extension\AchievementBadges\Tests\Integration;

use InvalidArgumentException;
use MediaWiki\Extension\AchievementBadges\Achievement;
use MediaWiki\Extension\AchievementBadges\AchievementManager;
use MediaWiki\Extension\AchievementBadges\Constants;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWikiIntegrationTestCase;
use MWException;
use User;

/**
 * @group AchievementBadges
 * @group Database
 *
 * @covers \MediaWiki\Extension\AchievementBadges\Achievement
 * @covers \MediaWiki\Extension\AchievementBadges\AchievementManager
 */
class AchievementTest extends MediaWikiIntegrationTestCase {
	use TempUserTestTrait;

	/** @inheritDoc */
	protected function setUp(): void {
		parent::setUp();
	}

	private function getManager(): AchievementManager {
		return $this->getServiceContainer()->getService( 'AchievementBadgesAchievementManager' );
	}

	/**
	 * @covers \MediaWiki\Extension\AchievementBadges\AchievementManager::canEarn
	 */
	public function testCanEarn() {
		$this->overrideConfigValue( Constants::CONFIG_KEY_DISABLED_ACHIEVEMENTS, [
			Constants::ACHV_KEY_SIGN_UP, Constants::ACHV_KEY_ENABLE_ACHIEVEMENT_BADGES ] );

		$systemUser = User::newSystemUser( __METHOD__ );
		$anon = new User;
		$user = $this->getTestUser()->getUser();
		$this->assertFalse( $this->getManager()->canEarn( $systemUser ),
			'A system user cannot use AB' );

		$this->overrideConfigValue( Constants::CONFIG_KEY_ENABLE_BETA_FEATURE, false );
		$this->assertTrue( $this->getManager()->canEarn( $user ),
			'Every user can use AB where wiki uses AB as a default' );
		$this->assertTrue( $this->getManager()->canEarn( $anon ),
			'A anonymous user can use AB where wiki uses AB as a default' );

		$this->enableAutoCreateTempUser();
		$tempUser = $this->getServiceContainer()->getTempUserCreator()
			->create( null, new FauxRequest() )->getUser();
		$this->assertFalse( $this->getManager()->canEarn( $tempUser ),
			'A temporary account cannot use AB' );

		$this->overrideConfigValue( Constants::CONFIG_KEY_ENABLE_BETA_FEATURE, true );
		$this->assertFalse( $this->getManager()->canEarn( $user ),
			'A user which do not enable AB cannot use AB where wiki uses AB as a beta feature' );
		$this->assertFalse( $this->getManager()->canEarn( $anon ),
			'A anonymous user cannot use AB where wiki uses AB as a beta feature' );

		$user = $this->getMutableTestUser()->getUser();
		$manager = $this->getServiceContainer()->getUserOptionsManager();
		$manager->setOption( $user, Constants::PREF_KEY_ACHIEVEMENT_ENABLE, '1' );
		$user->saveSettings();
		$this->assertTrue( $this->getManager()->canEarn( $user ),
			'A user which enables AB can use AB where wiki uses AB as a beta feature' );
	}

	private function assertLogging( $user, $key, $num = null ) {
		$logs = [];
		if ( $num !== null ) {
			$params = [
				'4::key' => $key,
			];
			for ( $i = 0; $i < $num; $i++ ) {
				$logs[] = [
					Constants::LOG_TYPE,
					$key,
					serialize( array_merge( $params,
						[ '5::index' => $i ] ) )
				];
			}
		} else {
			$logs[] = [
				Constants::LOG_TYPE,
				$key,
				serialize( [ '4::key' => $key, ] )
			];
		}

		$dbr = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase();
		$query = Achievement::getQueryInfo( $dbr );
		$query['conds'] = array_merge( $query['conds'], [
			'log_action' => $key,
			'log_actor' => $user->getActorId(),
		] );
		unset( $query['fields'] );
		$this->newSelectQueryBuilder()
			->select( [ 'log_type', 'log_action', 'log_params' ] )
			->queryInfo( $query )
			->assertResultSet( $logs );
	}

	/** @return array */
	public static function provideStatsAchievements() {
		return [
			[
				// key
				'test-achievement-first',
				// thresholds
				[ 1, 10, 100 ],
				[
					// stats, expected earned number
					[ 1, 1 ],
					[ 2, 1 ],
					[ 9, 1 ],
					[ 10, 2 ],
					[ 100, 3 ],
				]
			],
			[
				'test-achievement-second',
				[ 1, 3 ],
				[
					[ 0, 0 ],
					[ 1, 1 ],
					[ 2, 1 ],
					[ 3, 2 ],
				]
			],
			[
				'test-achievement-third',
				[ 1, 10, 100 ],
				[
					[ 30, 2 ],
					[ 31, 2 ],
					[ 32, 2 ],
				]
			],
		];
	}

	/**
	 * @dataProvider provideStatsAchievements
	 * @covers \MediaWiki\Extension\AchievementBadges\AchievementManager::awardForStats
	 *
	 * @param string $key
	 * @param int[] $thresholds
	 * @param array $testSets
	 */
	public function testAwardForStats( $key, $thresholds, $testSets ) {
		$this->overrideConfigValue( Constants::CONFIG_KEY_ACHIEVEMENTS, [
			$key => [
				'type' => 'stats',
				'thresholds' => $thresholds,
			]
		] );
		$user = $this->getTestUser()->getUser();

		foreach ( $testSets as $set ) {
			[ $stats, $expected ] = $set;
			$this->getManager()->awardForStats( $key, $user, $stats );
			$this->assertLogging( $user, $key, $expected );
		}
	}

	/**
	 * @covers \MediaWiki\Extension\AchievementBadges\AchievementManager::awardForStats
	 */
	public function testAwardForStatsWithInstantAchievement() {
		$key = 'test-achievement-instant';
		$this->overrideConfigValue( Constants::CONFIG_KEY_ACHIEVEMENTS, [
			$key => [ 'type' => 'instant' ],
		] );
		$user = $this->getTestUser()->getUser();

		$this->expectException( InvalidArgumentException::class );
		$this->getManager()->awardForStats( $key, $user, 1 );
	}

	/**
	 * @covers \MediaWiki\Extension\AchievementBadges\AchievementManager::award
	 */
	public function testAward() {
		$key = 'test-achievement-instant';
		$this->overrideConfigValue( Constants::CONFIG_KEY_ACHIEVEMENTS, [
			$key => [ 'type' => 'instant' ],
		] );
		$user = $this->getMutableTestUser()->getUser();

		$this->getManager()->award( $key, $user );
		$this->assertLogging( $user, $key );

		// Awarding it again does not add another log entry.
		$this->getManager()->award( $key, $user );
		$this->assertLogging( $user, $key );
	}

	/**
	 * @covers \MediaWiki\Extension\AchievementBadges\AchievementManager::award
	 */
	public function testAwardWithStatsAchievement() {
		$key = 'test-achievement-stats';
		$this->overrideConfigValue( Constants::CONFIG_KEY_ACHIEVEMENTS, [
			$key => [ 'type' => 'stats', 'thresholds' => [ 1 ] ],
		] );
		$user = $this->getTestUser()->getUser();

		$this->expectException( InvalidArgumentException::class );
		$this->getManager()->award( $key, $user );
	}

	/**
	 * @covers \MediaWiki\Extension\AchievementBadges\Achievement::sendStats
	 */
	public function testSendStatsWithoutKey() {
		$user = $this->getTestUser()->getUser();

		$this->expectException( MWException::class,
			'Calls to sendStats without key should throw exception' );
		Achievement::sendStats( [ 'user' => $user, 'stats' => 1 ] );
	}

	/**
	 * @return array
	 */
	public static function provideImagePaths() {
		return [
			[ 'en', 'path/to/icon.svg', '/wiki/path/to/icon.svg' ],
			[
				'en',
				[
					'en' => 'path/to/icon.svg',
					'ko' => 'path/to/icon-ko.svg',
					'ru' => 'path/to/icon-ru.svg',
				],
				'/wiki/path/to/icon.svg'
			],
			[
				'ko',
				[
					'en' => 'path/to/icon.svg',
					'ko' => 'path/to/icon-ko.svg',
					'ru' => 'path/to/icon-ru.svg',
				],
				'/wiki/path/to/icon-ko.svg'
			],
			[
				'he',
				[
					'en' => 'path/to/icon.svg',
					'ko' => 'path/to/icon-ko.svg',
					'rtl' => 'path/to/icon-rtl.svg',
				],
				'/wiki/path/to/icon-rtl.svg'
			],
		];
	}

	/**
	 * @dataProvider provideImagePaths
	 * @covers \MediaWiki\Extension\AchievementBadges\Achievement::getImageForLanguage()
	 *
	 * @param string $langCode
	 * @param string|array $path
	 * @param string $expected
	 */
	public function testGetImageForLanguage( $langCode, $path, $expected ) {
		$this->overrideConfigValue( MainConfigNames::ScriptPath, '/wiki' );
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( $langCode );
		$this->assertEquals( $expected, Achievement::getAchievementIcon( $lang, $path ),
			"Should be $expected" );
	}

	/**
	 * @covers \MediaWiki\Extension\AchievementBadges\Achievement::getAchievementIcon()
	 */
	public function testGetAchievementIconFallback() {
		$this->overrideConfigValue( MainConfigNames::ScriptPath, '/wiki' );
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' );
		$this->overrideConfigValue( Constants::CONFIG_KEY_ACHIEVEMENT_FALLBACK_ICON, 'foo/bar.svg' );
		$this->assertEquals( '/wiki/foo/bar.svg', Achievement::getAchievementIcon( $lang ),
			'A call without any parameter falls back' );
	}

	/**
	 * @covers \MediaWiki\Extension\AchievementBadges\Achievement::getAchievementOgImage()
	 */
	public function testGetAchievementOgImage() {
		$this->overrideConfigValue( MainConfigNames::ScriptPath, '/wiki' );
		$lang = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'en' );
		$this->overrideConfigValue( Constants::CONFIG_KEY_ACHIEVEMENT_FALLBACK_OG_IMAGE, 'foo/bar.png' );
		$this->assertEquals( '/wiki/foo/bar.png', Achievement::getAchievementOgImage( $lang ),
			'A call without any parameter falls back' );
	}

	/**
	 * @covers \MediaWiki\Extension\AchievementBadges\Achievement::getIconPath()
	 */
	public function testGetIconPath() {
		$info = [ 'icon' => 'icon.svg', 'icons' => [ 'icon-0.svg', 'icon-1.svg' ] ];
		$this->assertSame( 'icon.svg', Achievement::getIconPath( $info ) );
		$this->assertSame( 'icon-1.svg', Achievement::getIconPath( $info, 1 ) );
		$this->assertSame( 'icon.svg', Achievement::getIconPath( $info, 2 ) );
		$this->assertNull( Achievement::getIconPath( [] ) );
	}

	/** @return array */
	public static function provideKeys() {
		return [
			[ 'foo', 'foo', 'foo', null ],
			[ 'foo-0', 'foo-0', 'foo', 0 ],
			[ 'foo-1', 'foo-1', 'foo', 1 ],
			[ 'foo-bar-1', 'foo-bar-1', 'foo-bar', 1 ],
		];
	}

	/**
	 * @dataProvider provideKeys
	 * @covers \MediaWiki\Extension\AchievementBadges\Achievement::extractKeySegments()
	 *
	 * @param string $key
	 * @param string $suffixedKey
	 * @param string $unsuffixedKey
	 * @param int|null $index
	 */
	public function testExtractKeySegments( $key, $suffixedKey, $unsuffixedKey, $index ) {
		$actual = Achievement::extractKeySegments( $key );
		$this->assertEquals( [ $suffixedKey, $unsuffixedKey, $index ], $actual );
	}
}
