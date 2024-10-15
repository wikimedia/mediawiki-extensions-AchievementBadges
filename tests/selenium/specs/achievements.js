'use strict';

const AchievementsPage = require( '../pageobjects/achievements.page' );
const UserLoginPage = require( 'wdio-mediawiki/LoginPage' );

describe( 'Special:Achievements', () => {
	it( 'shows a logged-in user hint of long-user-page', async () => {
		await UserLoginPage.login( browser.config.mwUser, browser.config.mwPwd );
		await AchievementsPage.open();

		await expect( await AchievementsPage.longUserPageHint ).toExist();
	} );
} );
