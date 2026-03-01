( function () {
	'use strict';
	// https://developers.facebook.com/docs/plugins/share-button/
	( function ( d, s, id ) {
		const fjs = d.getElementsByTagName( s )[ 0 ];
		if ( d.getElementById( id ) ) {
			return;
		}
		const js = d.createElement( s );
		js.id = id;
		js.src = 'https://connect.facebook.net/en_US/sdk.js#xfbml=1&version=v3.0';
		fjs.parentNode.insertBefore( js, fjs );
	}( document, 'script', 'facebook-jssdk' ) );

	// Initialize Facebook SDK
	const facebookAppId = mw.config.get( 'wgAchievementBadgesFacebookAppId' );
	window.fbAsyncInit = function () {
		FB.init( {
			appId: facebookAppId,
			status: true,
			xfbml: true,
			version: 'v2.7'
		} );
	};

	const button = window.document.getElementById( 'share-achievement-facebook' );
	button.addEventListener( 'click', ( e ) => {
		FB.ui(
			{
				method: 'share',
				href: window.location.href
			},
			() => {}
		);
		e.preventDefault();
	} );
}() );
