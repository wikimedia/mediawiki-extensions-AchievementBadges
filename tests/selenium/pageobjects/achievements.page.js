import Page from 'wdio-mediawiki/Page';

class AchievementsPage extends Page {
	get longUserPageHint() {
		return $( '#achievement-long-user-page .achievement-hint' );
	}

	async open() {
		return super.openTitle( 'Special:Achievements' );
	}
}

export default new AchievementsPage();
