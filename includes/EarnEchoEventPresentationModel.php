<?php

namespace MediaWiki\Extension\AchievementBadges;

use Language;
use MediaWiki\Extension\AchievementBadges\Special\SpecialAchievements;
use MediaWiki\Extension\AchievementBadges\Special\SpecialShareAchievement;
use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Extension\Notifications\Model\Event;
use Message;
use SpecialPage;
use User;

class EarnEchoEventPresentationModel extends EchoEventPresentationModel {
	private string $achievementKey;

	/** @inheritDoc */
	protected function __construct(
		Event $event,
		Language $language,
		User $user,
		$distributionType
	) {
		parent::__construct( $event, $language, $user, $distributionType );
		$this->achievementKey = $event->getExtraParam( 'key' );
	}

	/** @inheritDoc */
	public function canRender() {
		return true;
	}

	/** @inheritDoc */
	public function getIconType() {
		return Constants::EVENT_KEY_EARN;
	}

	/** @inheritDoc */
	public function getPrimaryLink() {
		if ( $this->isBundled() ) {
			return $this->getSpecialAchievementsLink();
		} else {
			if ( !$this->achievementKey ) {
				return false;
			}
			$agent = $this->event->getAgent()->getId();
			$key = $this->achievementKey;
			$title = SpecialPage::getTitleFor( SpecialShareAchievement::PAGE_NAME,
				base64_encode( "$agent/$key" ) );
			$link = $this->getPageLink( $title, '', true );
			return $link;
		}
	}

	private function getSpecialAchievementsLink(): array {
		$title = SpecialPage::getTitleFor( SpecialAchievements::PAGE_NAME );
		return [
			'url' => $title->getFullURL(),
			'label' => $this->msg( 'notification-link-text-all-achievements' )->plain(),
			'tooltip' => $title->getPrefixedText(),
			'icon' => 'article',
		];
	}

	/** @inheritDoc */
	public function getSecondaryLinks() {
		if ( $this->isBundled() ) {
			return [];
		} else {
			return [ $this->getSpecialAchievementsLink() ];
		}
	}

	/** @inheritDoc */
	public function getHeaderMessage(): Message {
		if ( $this->isBundled() ) {
			$msg = $this->getMessageWithAgent( 'notification-bundle-header-achievementbadges-earn' );
			$count = $this->getNotificationCountForOutput();
			$msg->numParams( $count );
			return $msg;
		} else {
			$msg = $this->getMessageWithAgent( 'notification-header-achievementbadges-earn' );
			$key = $this->achievementKey;
			$agent = $this->event->getAgent();
			$msg->params( $this->msg( "achievementbadges-achievement-name-$key", $agent->getName() ) );
			return $msg;
		}
	}

	/** @inheritDoc */
	public function getBodyMessage() {
		if ( $this->isBundled() ) {
			return false;
		} else {
			$key = $this->achievementKey;
			$agent = $this->event->getAgent();
			$msg = $this->msg( "achievementbadges-achievement-description-$key", $agent->getName() );
			return $msg;
		}
	}

	/** @inheritDoc */
	public function getCompactHeaderMessage() {
		$key = $this->achievementKey;
		$agent = $this->event->getAgent();
		return $this->msg( "achievementbadges-achievement-name-$key", $agent->getName() );
	}
}
