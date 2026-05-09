<?php
/**
 * Catalog of supported networks and assets.
 *
 * Hardcoded for v0.1.0; will be replaced by GET /api/v2/networks once that
 * endpoint exists in tron-dealer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TDP_Networks {

	const FAMILY_EVM  = 'evm';
	const FAMILY_TRON = 'tron';
	const FAMILY_SOL  = 'sol';

	public static function all() {
		return array(
			'tron'  => array(
				'family'        => self::FAMILY_TRON,
				'label'         => 'TRON',
				'network_param' => null,
				'confirm_eta'   => 60,
				'assets'        => array( 'USDT' ),
			),
			'bsc'   => array(
				'family'        => self::FAMILY_EVM,
				'label'         => 'BNB Smart Chain',
				'network_param' => 'bsc',
				'confirm_eta'   => 45,
				'assets'        => array( 'USDT', 'USDC' ),
			),
			'eth'   => array(
				'family'        => self::FAMILY_EVM,
				'label'         => 'Ethereum',
				'network_param' => 'eth',
				'confirm_eta'   => 240,
				'assets'        => array( 'USDT', 'USDC' ),
			),
			'pol'   => array(
				'family'        => self::FAMILY_EVM,
				'label'         => 'Polygon',
				'network_param' => 'pol',
				'confirm_eta'   => 30,
				'assets'        => array( 'USDT', 'USDC' ),
			),
			'arb'   => array(
				'family'        => self::FAMILY_EVM,
				'label'         => 'Arbitrum',
				'network_param' => 'arb',
				'confirm_eta'   => 10,
				'assets'        => array( 'USDT', 'USDC' ),
			),
			'base'  => array(
				'family'        => self::FAMILY_EVM,
				'label'         => 'Base',
				'network_param' => 'base',
				'confirm_eta'   => 30,
				'assets'        => array( 'USDT', 'USDC' ),
			),
			'opt'   => array(
				'family'        => self::FAMILY_EVM,
				'label'         => 'Optimism',
				'network_param' => 'opt',
				'confirm_eta'   => 30,
				'assets'        => array( 'USDT', 'USDC' ),
			),
			'avax'  => array(
				'family'        => self::FAMILY_EVM,
				'label'         => 'Avalanche',
				'network_param' => 'avax',
				'confirm_eta'   => 10,
				'assets'        => array( 'USDT', 'USDC' ),
			),
			'sol'   => array(
				'family'        => self::FAMILY_SOL,
				'label'         => 'Solana',
				'network_param' => null,
				'confirm_eta'   => 30,
				'assets'        => array( 'USDT', 'USDC' ),
			),
		);
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function exists( $key ) {
		return null !== self::get( $key );
	}

	public static function asset_supported( $network_key, $asset ) {
		$net = self::get( $network_key );
		if ( ! $net ) {
			return false;
		}
		return in_array( strtoupper( $asset ), $net['assets'], true );
	}

	public static function family( $network_key ) {
		$net = self::get( $network_key );
		return $net ? $net['family'] : null;
	}

	public static function combinations() {
		$out = array();
		foreach ( self::all() as $key => $net ) {
			foreach ( $net['assets'] as $asset ) {
				$out[] = array(
					'id'       => $key . ':' . $asset,
					'network'  => $key,
					'asset'    => $asset,
					'label'    => sprintf( '%s on %s', $asset, $net['label'] ),
					'eta_secs' => $net['confirm_eta'],
				);
			}
		}
		return $out;
	}

	public static function build_payment_uri( $network_key, $asset, $address, $amount ) {
		$family = self::family( $network_key );
		$amount_str = rtrim( rtrim( number_format( (float) $amount, 6, '.', '' ), '0' ), '.' );

		if ( self::FAMILY_SOL === $family ) {
			return sprintf(
				'solana:%s?amount=%s&label=%s',
				rawurlencode( $address ),
				rawurlencode( $amount_str ),
				rawurlencode( get_bloginfo( 'name' ) )
			);
		}

		if ( self::FAMILY_TRON === $family ) {
			return sprintf( 'tron:%s?amount=%s&token=%s', rawurlencode( $address ), rawurlencode( $amount_str ), rawurlencode( $asset ) );
		}

		return $address;
	}
}
