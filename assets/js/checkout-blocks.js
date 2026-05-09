(function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	if (!registry) return;

	var settings = (window.wc && window.wc.wcSettings && window.wc.wcSettings.getSetting)
		? window.wc.wcSettings.getSetting('trondealer_data', {})
		: {};

	var el = window.wp.element.createElement;
	var Fragment = window.wp.element.Fragment;
	var __ = window.wp.i18n.__;

	function Content(props) {
		var combos = settings.combos || [];
		var setOpt = function (e) {
			if (props.eventRegistration && props.eventRegistration.onPaymentSetup) {
				// no-op; the assigned wallet is created server-side in process_payment
			}
		};
		return el(Fragment, null,
			el('p', null, settings.description || __('Pay with USDT or USDC.', 'trondealer-payments')),
			el('label', { htmlFor: 'tdp_network_choice' }, __('Choose network and asset', 'trondealer-payments')),
			el('select', { id: 'tdp_network_choice', name: 'tdp_network_choice', required: true, onChange: setOpt },
				combos.map(function (c) {
					return el('option', { value: c.id, key: c.id }, c.label);
				})
			)
		);
	}

	function Label() {
		return el('span', null, settings.title || __('Pay with Crypto', 'trondealer-payments'));
	}

	registry.registerPaymentMethod({
		name: 'trondealer',
		label: el(Label),
		content: el(Content),
		edit: el(Content),
		canMakePayment: function () { return (settings.combos || []).length > 0; },
		ariaLabel: settings.title || 'Trondealer Payments',
		supports: { features: ['products'] }
	});
})();
