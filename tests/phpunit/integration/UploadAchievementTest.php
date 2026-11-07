<?php

namespace MediaWiki\Extension\AchievementBadges\Tests\Integration;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\AchievementBadges\Achievement;
use MediaWiki\Extension\AchievementBadges\Constants;
use MediaWiki\MainConfigNames;
use MediaWiki\Upload\UploadFromFile;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @group AchievementBadges
 * @group Database
 *
 * @covers \MediaWiki\Extension\AchievementBadges\HookHandler\AchievementRegister
 */
class UploadAchievementTest extends MediaWikiIntegrationTestCase {

	/** @inheritDoc */
	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			MainConfigNames::EnableUploads => true,
			Constants::CONFIG_KEY_ACHIEVEMENTS => [
				Constants::ACHV_KEY_UPLOAD_FILE => [
					'type' => 'instant',
				],
			],
		] );
	}

	private function countEarned( User $user ): int {
		$dbr = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase();
		$query = Achievement::getQueryInfo( $dbr );
		$query['conds'] = array_merge( $query['conds'], [
			'log_action' => Constants::ACHV_KEY_UPLOAD_FILE,
			'log_actor' => $user->getActorId(),
		] );
		return $dbr->newSelectQueryBuilder()
			->queryInfo( $query )
			->caller( __METHOD__ )
			->fetchRowCount();
	}

	private function upload( User $user, string $name ): void {
		$path = $this->getNewTempFile();
		$image = imagecreatetruecolor( 2, 2 );
		imagepng( $image, $path );

		$upload = new UploadFromFile();
		$upload->initializePathInfo( $name, $path, filesize( $path ), false );
		$this->assertSame( [ 'status' => UploadFromFile::OK ], $upload->verifyUpload() );
		$status = $upload->performUpload( '', '', false, $user );
		$this->assertStatusGood( $status );
		DeferredUpdates::doUpdates();
	}

	public function testUploadingAFileIsAchieved() {
		$user = $this->getMutableTestUser()->getUser();

		$this->upload( $user, 'Achievement upload test 1.png' );
		$this->assertSame( 1, $this->countEarned( $user ) );

		$this->upload( $user, 'Achievement upload test 2.png' );
		$this->assertSame( 1, $this->countEarned( $user ), 'The achievement is given only once' );
	}
}
