'use strict';

const assert = require( 'assert' ),
	AchievementsPage = require( '../pageobjects/achievements.page' ),
	UserLoginPage = require( 'wdio-mediawiki/LoginPage' );

describe( 'Special:Achievements', () => {
	it( 'shows a logged-in user hint of long-user-page', () => {
		UserLoginPage.login( browser.config.mwUser, browser.config.mwPwd );
		AchievementsPage.open();

		assert( AchievementsPage.longUserPageHint.isExisting() );
	} );
} );
