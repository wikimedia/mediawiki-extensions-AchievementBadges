<?php

namespace MediaWiki\Extension\AchievementBadges\Special;

use Language;
use MediaWiki\Extension\AchievementBadges\Achievement;
use MediaWiki\Extension\AchievementBadges\Constants;
use MediaWiki\Extension\BetaFeatures\BetaFeatures;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\Linker\Linker;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\User\UserOptionsLookup;
use Message;
use NamespaceInfo;
use Psr\Log\LoggerInterface;
use SpecialPage;
use User;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Special page
 *
 * @file
 */

class SpecialShareAchievement extends SpecialPage {

	public const PAGE_NAME = 'ShareAchievement';

	private TemplateParser $templateParser;
	private LoggerInterface $logger;
	private string $base64subPage;
	private User $obtainer;
	private Language $obtainerLang;
	private User $viewer;
	private string $suffixedKey;
	private string $unsuffixedKey;
	private string $achievementType;
	private array $registry;
	private Message $achvNameMsg;
	private LanguageFactory $languageFactory;
	private ILoadBalancer $loadBalancer;
	private UserOptionsLookup $userOptionsLookup;

	public function __construct(
		LanguageFactory $languageFactory,
		ILoadBalancer $loadBalancer,
		UserOptionsLookup $userOptionsLookup
	) {
		parent::__construct( self::PAGE_NAME );
		$this->languageFactory = $languageFactory;
		$this->loadBalancer = $loadBalancer;
		$this->userOptionsLookup = $userOptionsLookup;
		$this->templateParser = new TemplateParser( __DIR__ . '/../templates' );
		$this->logger = LoggerFactory::getInstance( 'AchievementBadges' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $subPage ) {
		$this->addHelpLink( 'Extension:AchievementBadges' );

		$viewer = $this->viewer = $this->getUser();
		$out = $this->getOutput();
		$out->addModuleStyles( 'ext.achievementbadges.special.shareachievement.styles' );
		$out->addModules( [ 'ext.achievementbadges.special.shareachievement' ] );
		$config = $this->getConfig();

		$this->base64subPage = $subPage;
		$subPage = base64_decode( $subPage );
		$split = explode( '/', $subPage, 2 );
		if ( count( $split ) !== 2 ) {
			$out->addWikiTextAsInterface( $this->msg( 'special-shareachievement-invalid' )->parse() );
			return;
		}

		[ $obtainerId, $key ] = $split;
		$this->obtainer = User::newFromId( (int)$obtainerId );
		if ( !$this->obtainer ) {
			$out->addWikiTextAsInterface( $this->msg( 'special-shareachievement-invalid-username' )->parse() );
			return;
		}
		[ $this->suffixedKey, $this->unsuffixedKey, ] = Achievement::extractKeySegments( $key );
		$this->achievementType = $this->suffixedKey === $this->unsuffixedKey ? 'instant' : 'stats';
		$this->registry = $config->get( Constants::CONFIG_KEY_ACHIEVEMENTS );
		if ( !array_key_exists( $this->unsuffixedKey, $this->registry ) ) {
			$out->addWikiTextAsInterface( $this->msg( 'special-shareachievement-invalid-achievement-name' )
				->parse() );
			return;
		}

		$obtainerLang = $this->languageFactory->getLanguage(
			$this->userOptionsLookup->getOption( $this->obtainer, 'language' )
		);
		$this->obtainerLang = $obtainerLang;
		$this->achvNameMsg = $this->msg( 'achievementbadges-achievement-name-' . ( $this->suffixedKey ),
			$this->obtainer->getName() );
		$achvName = $this->achvNameMsg->text();

		$pageHeader = $this->msg( 'special-shareachievement-title', $this->obtainer->getName(), $achvName )
			->parse();
		$out->setHTMLTitle( $this->msg( 'pagetitle' )->plaintextParams( $pageHeader )->text() );
		if ( !$viewer->isAnon() ) {
			$out->setSubTitle( '< ' . Linker::link( SpecialPage::getTitleFor( SpecialAchievements::PAGE_NAME ),
				$this->msg( 'special-achievements' )->escaped() ) );
		}

		$data = $this->getBadgeData();
		if ( !$data ) {
			$out->addWikiTextAsInterface( $this->msg( 'special-shareachievement-invalid' )->parse() );
			return;
		}

		$data['text-share-header'] = $this->msg( 'special-shareachievement-header-share',
			$this->obtainer->getName() );
		$data['data-share'] = $this->getSnsShareData();

		$betaConfigEnabled = $config->get( Constants::CONFIG_KEY_ENABLE_BETA_FEATURE );
		$userBetaEnabled = $betaConfigEnabled && BetaFeatures::isFeatureEnabled( $viewer,
				Constants::PREF_KEY_ACHIEVEMENT_ENABLE );
		if ( $viewer->isAnon() ) {
			$data['text-suggestion'] = $this->msg( 'special-shareachievement-suggestion-sign-up' )->parse();
		} elseif ( $betaConfigEnabled && !$userBetaEnabled ) {
			$data['text-suggestion'] = $this->msg( 'special-shareachievement-suggestion-beta',
				$viewer->getName() )->parse();
		}

		$this->getOutput()->addHTML( $this->templateParser->processTemplate( 'SpecialShareAchievement', $data ) );
		$this->addMeta();
	}

	/**
	 * @return array|bool
	 */
	private function getBadgeData() {
		$config = $this->getConfig();
		$out = $this->getOutput();

		$registry = $this->registry[$this->unsuffixedKey];
		if ( $registry['type'] !== $this->achievementType ) {
			return false;
		}
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$query = Achievement::getQueryInfo( $dbr );
		$query['conds'] = array_merge( $query['conds'], [
			'log_action' => $this->unsuffixedKey,
			'log_actor' => $this->obtainer->getActorId(),
		] );
		$row = $dbr->selectRow(
			$query['tables'],
			[
				'log_timestamp',
			],
			$query['conds'],
			__METHOD__,
			[],
			$query['joins']
		);
		if ( !$row ) {
			return false;
		}
		$iconPath = Achievement::getAchievementIcon( $this->obtainerLang, $registry['icon'] ?? null );
		$achvName = $this->achvNameMsg->text();
		$obtainerText = $this->obtainer->getName();
		$description = $this->msg( 'achievementbadges-achievement-description-' . $this->suffixedKey )
			->plaintextParams( $obtainerText );
		$topMessage = $this->obtainer->equals( $this->viewer ) ? $this->msg( 'special-shareachievement-message' )
			: $this->msg( 'special-shareachievement-message-other' );
		$topMessage->params( $obtainerText );

		return [
			'text-message' => $topMessage->parse(),
			'text-name' => $achvName,
			'text-description' => $description,
			'text-obtainer' => $obtainerText,
			'text-icon' => $iconPath,
		];
	}

	/** @return array */
	private function getSnsShareData() {
		$config = $this->getConfig();
		$obtainer = $this->obtainer;

		$titleUrl = $this->getUrlForShare();

		$share = [];
		$facebookAppId = $config->get( Constants::CONFIG_KEY_FACEBOOK_APP_ID );
		if ( $facebookAppId ) {
			$url = 'https://www.facebook.com/dialog/share?' .
				"app_id=$facebookAppId" .
				'&display=popup' .
				'&href=' . urlencode( $titleUrl );
			$share[] = [
				'text-id' => 'share-achievement-facebook',
				'text-text' => $this->msg( 'special-shareachievement-item-facebook' ),
				'text-url' => $url,
			];
		}
		$tweet = $obtainer->equals( $this->viewer )
			? 'special-shareachievement-tweet'
			: 'special-shareachievement-tweet-viewer';

		$tweet = $this->msg( $tweet );
		$tweet = $tweet->plaintextParams( $obtainer->getName() )
			->plaintextParams( $this->achvNameMsg->text() )
			->plaintextParams( $titleUrl )
			->parse();
		$tweetUrl = 'https://twitter.com/intent/tweet?text=' . urlencode( $tweet );
		$share[] = [
			'text-id' => 'share-achievement-twitter',
			'text-text' => $this->msg( 'special-shareachievement-item-twitter' ),
			'text-url' => $tweetUrl,
		];

		return $share;
	}

	/**
	 * Returns english url to avoid very long url which is built by urlencode() with other languages than english.
	 * @return string
	 */
	private function getUrlForShare() {
		$titleText = NamespaceInfo::CANONICAL_NAMES[NS_SPECIAL] . ':' . self::PAGE_NAME . '/' . $this->base64subPage;
		$articlePath = $this->getConfig()->get( 'ArticlePath' );
		$localUrl = str_replace( '$1', $titleText, $articlePath );
		return wfExpandUrl( $localUrl );
	}

	private function addMeta() {
		$sitename = $this->getConfig()->get( 'Sitename' );
		$obtainerLang = $this->obtainerLang;
		$achvNameMsg = $this->msg( 'achievementbadges-achievement-name-' . ( $this->suffixedKey ),
			$this->obtainer->getName() );
		$achvName = $achvNameMsg->inLanguage( $obtainerLang )->text();
		$registry = $this->registry;
		$ogImagePath = $registry['og-image'] ?? $registry['icon'] ?? null;
		$ogImagePath = Achievement::getAchievementOgImage( $obtainerLang, $ogImagePath );

		$meta = [];

		$meta['title'] = $this->msg( 'special-shareachievement-title',
			$this->obtainer->getName(), $achvName )->inLanguage( $obtainerLang )->text();
		$meta['og:type'] = 'article';
		$meta['og:site_name'] = $sitename;
		$meta['og:title'] = $meta['title'];
		$meta['og:description'] =
			$this->msg( 'special-shareachievement-external-description' )
				->plaintextParams( $this->obtainer->getName() )
				->plaintextParams( $achvName )
				->inLanguage( $obtainerLang )->text();
		$meta['description'] = $meta['og:description'];
		$meta['og:image'] = wfExpandUrl( $ogImagePath );

		$out = $this->getOutput();
		foreach ( $meta as $property => $value ) {
			$out->addMeta( $property, $value );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( 'special-shareachievement' );
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'users';
	}
}
