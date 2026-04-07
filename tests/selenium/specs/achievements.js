import AchievementsPage from '../pageobjects/achievements.page.js';
import LoginPage from 'wdio-mediawiki/LoginPage';

describe( 'Special:Achievements', () => {
	it( 'shows a logged-in user hint of long-user-page', async () => {
		await LoginPage.loginAdmin();
		await AchievementsPage.open();

		await expect( AchievementsPage.longUserPageHint ).toExist();
	} );
} );
