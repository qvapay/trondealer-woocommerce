(function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	if (!registry) {
		console.warn('[Trondealer] wc-blocks-registry not available; gateway will not appear in Blocks checkout.');
		return;
	}

	var getSetting = (window.wc && window.wc.wcSettings && window.wc.wcSettings.getSetting)
		? window.wc.wcSettings.getSetting
		: function (_, fallback) { return fallback; };

	var settings = getSetting('trondealer_data', {});

	var el = window.wp.element.createElement;
	var Fragment = window.wp.element.Fragment;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var __ = (window.wp.i18n && window.wp.i18n.__) ? window.wp.i18n.__ : function (s) { return s; };

	function Content(props) {
		var combos = settings.combos || [];
		var initial = combos.length ? combos[0].id : '';
		var stateTuple = useState(initial);
		var choice = stateTuple[0];
		var setChoice = stateTuple[1];

		var eventRegistration = props.eventRegistration || {};
		var emitResponse = props.emitResponse || {};
		var responseTypes = (emitResponse && emitResponse.responseTypes) || { SUCCESS: 'success', ERROR: 'error' };

		useEffect(function () {
			if (!eventRegistration.onPaymentSetup) return;
			var unsubscribe = eventRegistration.onPaymentSetup(function () {
				if (!choice) {
					return {
						type: responseTypes.ERROR,
						message: __('Please choose a network and asset.', 'trondealer-payments')
					};
				}
				return {
					type: responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							tdp_network_choice: choice
						}
					}
				};
			});
			return unsubscribe;
		}, [choice]);

		if (!combos.length) {
			return el('p', null, __('No networks enabled. Please contact the merchant.', 'trondealer-payments'));
		}

		return el(Fragment, null,
			el('p', null, settings.description || __('Pay with USDT or USDC.', 'trondealer-payments')),
			el('p', { className: 'form-row form-row-wide' },
				el('label', { htmlFor: 'tdp_network_choice' },
					__('Choose network and asset', 'trondealer-payments')
				),
				el('select', {
					id: 'tdp_network_choice',
					name: 'tdp_network_choice',
					required: true,
					value: choice,
					onChange: function (e) { setChoice(e.target.value); }
				},
					combos.map(function (c) {
						return el('option', { value: c.id, key: c.id }, c.label);
					})
				)
			)
		);
	}

	function Label() {
		return el('span', null, settings.title || __('Pay with Crypto', 'trondealer-payments'));
	}

	registry.registerPaymentMethod({
		name: 'trondealer',
		label: el(Label, null),
		content: el(Content, null),
		edit: el(Content, null),
		canMakePayment: function () { return true; },
		ariaLabel: settings.title || 'Trondealer Payments',
		supports: { features: settings.supports || ['products'] }
	});
})();
