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

	function formatEta(secs) {
		if (secs < 60) return secs + 's';
		return Math.max(1, Math.round(secs / 60)) + 'm';
	}

	function NetworkIcon(props) {
		if (props.url) {
			return el('img', {
				src: props.url,
				alt: '',
				width: 28,
				height: 28,
				className: 'tdp-icon tdp-icon--network'
			});
		}
		return el('span', {
			className: 'tdp-icon tdp-icon--fallback',
			'aria-hidden': 'true'
		}, (props.label || '?').slice(0, 2).toUpperCase());
	}

	function AssetPill(props) {
		var classes = ['tdp-asset-pill'];
		if (props.selected) classes.push('is-selected');
		return el('button', {
			type: 'button',
			className: classes.join(' '),
			onClick: function (e) { e.preventDefault(); props.onSelect(props.id); },
			'aria-pressed': props.selected ? 'true' : 'false'
		},
			props.icon ? el('img', { src: props.icon, alt: '', width: 16, height: 16, className: 'tdp-asset-pill__icon' }) : null,
			el('span', { className: 'tdp-asset-pill__symbol' }, props.symbol)
		);
	}

	function NetworkCard(props) {
		var net = props.network;
		var selectedNet = props.selected ? props.selected.split(':')[0] : null;
		var selectedAsset = props.selected ? props.selected.split(':')[1] : null;
		var isActive = selectedNet === net.key;

		return el('div', {
			className: 'tdp-network-card' + (isActive ? ' is-active' : ''),
			'data-network': net.key
		},
			el('div', { className: 'tdp-network-card__header' },
				el(NetworkIcon, { url: net.icon, label: net.label }),
				el('div', { className: 'tdp-network-card__meta' },
					el('span', { className: 'tdp-network-card__label' }, net.label),
					el('span', { className: 'tdp-network-card__eta' }, '~' + formatEta(net.eta_secs))
				)
			),
			el('div', { className: 'tdp-network-card__assets' },
				net.assets.map(function (a) {
					return el(AssetPill, {
						key: a.id,
						id: a.id,
						symbol: a.symbol,
						icon: a.icon,
						selected: isActive && selectedAsset === a.symbol,
						onSelect: props.onSelect
					});
				})
			)
		);
	}

	function Content(props) {
		var networks = settings.networks || [];
		var initial = '';
		if (networks.length && networks[0].assets.length) {
			initial = networks[0].assets[0].id;
		}
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

		if (!networks.length) {
			return el('p', null, __('No networks enabled. Please contact the merchant.', 'trondealer-payments'));
		}

		return el(Fragment, null,
			settings.description
				? el('p', { className: 'tdp-blocks-description' }, settings.description)
				: null,
			el('div', { className: 'tdp-network-grid' },
				networks.map(function (n) {
					return el(NetworkCard, {
						key: n.key,
						network: n,
						selected: choice,
						onSelect: setChoice
					});
				})
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
