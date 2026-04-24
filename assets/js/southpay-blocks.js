( function ( wc, wp ) {
	'use strict';

	var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting            = wc.wcSettings.getSetting;
	var createElement         = wp.element.createElement;

	var settings    = getSetting( 'soutpaygw_gateway_data', {} );
	var title       = settings.title || 'Crypto via SouthPay';
	var description = settings.description || '';
	var icon        = settings.icon || '';

	var Content = function () {
		return createElement( 'p', null, description );
	};

	var Label = function () {
		return createElement(
			'span',
			{ style: { display: 'flex', alignItems: 'center', gap: '8px' } },
			icon ? createElement( 'img', { src: icon, alt: '', style: { height: '24px', display: 'block' } } ) : null,
			title
		);
	};

	registerPaymentMethod( {
		name:           'soutpaygw_gateway',
		label:          createElement( Label, null ),
		content:        createElement( Content, null ),
		edit:           createElement( Content, null ),
		canMakePayment: function () { return true; },
		ariaLabel:      title,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )( window.wc, window.wp );
