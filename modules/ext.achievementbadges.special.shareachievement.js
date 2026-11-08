const share = require( 'mediawiki.page.ready' ).share;
const link = document.getElementById( 'share-achievement-share' );

if ( link && share ) {
	link.addEventListener( 'click', ( e ) => {
		e.preventDefault();
		share( {
			url: link.href,
			title: document.title,
			text: link.dataset.shareText
		} );
	} );
}
