<?php

namespace WordCamp\Utilities;
use WordCamp\Logger;
use Exception;

defined( 'WPINC' ) || die();

/**
 * A lean Stripe client.
 *
 * This is favorable over the official `stripe-php` library, because we don't have to worry about keeping it
 * updated, it only has the functionality we're actually using, we won't end up with unit tests, etc inside
 * a publicly accessible folder, etc.
 *
 * TODO Refactor this to use the API_Client base class.
 */
class Stripe_Client {
	const API_URL = 'https://api.stripe.com';
	const AMOUNT_MAX = 99999999;
	protected $secret_key;

	/**
	 * Constructor
	 *
	 * @param $secret_key
	 */
	public function __construct( $secret_key ) {
		$this->secret_key = $secret_key;
	}

	/**
	 * Create a new Session for a charge.
	 *
	 * @see https://stripe.com/docs/api/checkout/sessions/create
	 *
	 * @param array $args The arguements for the checkout session.
	 * @return object
	 *
	 * @throws Exception
	 */
	public function create_session( $args ) {
		$amount   = array_sum( wp_list_pluck( wp_list_pluck( $args['line_items'] ?? array(), 'price_data' ), 'unit_amount' ) );
		$currency = wp_list_pluck( wp_list_pluck( $args['line_items'] ?? array(), 'price_data' ), 'currency' )[0] ?? '';

		/**
		 * Stripe doesn't allow amounts larger than `AMOUNT_MAX`, even in currencies where that's the equivalent of less than $5k USD.
		 *
		 * The amount in the error message is converted back to the base unit, to avoid confusing the user.
		 *
		 * See https://stripe.com/docs/currencies#minimum-and-maximum-charge-amounts.
		 */
		if ( $amount > self::AMOUNT_MAX ) {
			throw new Exception( sprintf(
				// translators: Do _not_ translate "USD" to your locale; it is meant to stay as "USD" exactly.
				__( "We're sorry, but we can't accept amounts larger than %s. Please send the equivalent in USD, or break it up into several smaller payments. Feel free to email <a href='mailto:%s'>%s</a> with any questions.", 'wordcamporg' ),
				number_format( self::AMOUNT_MAX / self::get_fractional_unit_multiplier( $currency ), 2 ),
				EMAIL_CENTRAL_SUPPORT,
				EMAIL_CENTRAL_SUPPORT
			) );
		}

		$headers = array(
			'Authorization'  => 'Bearer ' . $this->secret_key,
			'Stripe-Version' => '2023-10-16',
		);

		$request_args = array(
			'user-agent' => 'WordCamp.org :: ' . __CLASS__,
			'body'       => $args,
			'headers'    => $headers,
		);

		$response = wp_remote_post( self::API_URL . '/v1/checkout/sessions', $request_args );

		if ( is_wp_error( $response ) ) {
			Logger\log( 'response_error', compact( 'response' ) );
			throw new Exception( sprintf(
				__( "We're sorry, but we encountered an error trying to process that transaction. Please email <a href='mailto:%s'>%s</a> for help.", 'wordcamporg' ),
				EMAIL_CENTRAL_SUPPORT,
				EMAIL_CENTRAL_SUPPORT
			) );
		}

		return json_decode( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Retrieve the session for a charge.
	 *
	 * @see https://stripe.com/docs/api/checkout/sessions/retrieve
	 *
	 * @param string $session_id The session ID.
	 * @return object
	 *
	 * @throws Exception
	 */
	public function retrieve_session( $session_id ) {
		$headers = array(
			'Authorization'  => 'Bearer ' . $this->secret_key,
			'Stripe-Version' => '2023-10-16',
		);

		$request_args = array(
			'user-agent' => 'WordCamp.org :: ' . __CLASS__,
			'headers'    => $headers,
		);

		$response = wp_remote_get(
			self::API_URL . '/v1/checkout/sessions/' . urlencode( $session_id ),
			$request_args
		);

		if ( is_wp_error( $response ) ) {
			Logger\log( 'response_error', compact( 'response' ) );
			throw new Exception( sprintf(
				__( "We're sorry, but we encountered an error trying to process that transaction. Please email <a href='mailto:%s'>%s</a> for help.", 'wordcamporg' ),
				EMAIL_CENTRAL_SUPPORT,
				EMAIL_CENTRAL_SUPPORT
			) );
		}

		return json_decode( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Get the multiplier needed to convert a currency's base unit to its equivalent fractional unit.
	 *
	 * Stripe wants amounts in the fractional unit (e.g., pennies), not the base unit (e.g., dollars). Zero-decimal
	 * currencies are not included here, because they're not supported at all yet.
	 *
	 * The data here comes from https://en.wikipedia.org/wiki/List_of_circulating_currencies.
	 *
	 * @param string $order_currency
	 *
	 * @return int
	 *
	 * @throws Exception
	 */
	public static function get_fractional_unit_multiplier( $order_currency ) {
		$match = null;

		$currency_multipliers = array(
			5    => array( 'MRO', 'MRU' ),
			100  => array(
				'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN',
				'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CNY', 'COP',
				'CRC', 'CUC', 'CUP', 'CVE', 'CZK', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP',
				'GBP', 'GEL', 'GGP', 'GHS', 'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK', 'HTG', 'HUF', 'IDR',
				'ILS', 'IMP', 'INR', 'IRR', 'ISK', 'JEP', 'JMD', 'JOD', 'KES', 'KGS', 'KHR', 'KPW', 'KYD', 'KZT',
				'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MKD', 'MMK', 'MNT', 'MOP', 'MUR', 'MVR', 'MWK',
				'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR',
				'PLN', 'PRB', 'QAR', 'RON', 'RSD', 'RUB', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLL',
				'SOS', 'SRD', 'SSP', 'STD', 'SYP', 'SZL', 'THB', 'TJS', 'TMT', 'TOP', 'TRY', 'TTD', 'TVD', 'TWD',
				'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VEF', 'WST', 'XCD', 'YER', 'ZAR', 'ZMW',
			),
			1000 => array( 'BHD', 'IQD', 'KWD', 'LYD', 'OMR', 'TND' ),
		);

		foreach ( $currency_multipliers as $multiplier => $currencies ) {
			if ( in_array( $order_currency, $currencies, true ) ) {
				$match = $multiplier;
			}
		}

		if ( is_null( $match ) ) {
			throw new Exception( "Unknown currency multiplier for $order_currency." );
		}

		return $match;
	}

	/**
	 * Convert an amount in the currency's base unit to its equivalent fractional unit.
	 *
	 * Stripe wants amounts in the fractional unit (e.g., pennies), not the base unit (e.g., dollars).  The data
	 * here comes from https://stripe.com/docs/currencies.
	 *
	 * @todo This uses different data than `get_fractional_unit_multiplier` above, these have different data
	 * sources. These should be reconciled in the future.
	 *
	 * @param string $order_currency
	 * @param int    $base_unit_amount
	 *
	 * @return int
	 * @throws Exception
	 */
	public static function get_fractional_unit_amount( $order_currency, $base_unit_amount ) {
		$fractional_amount = null;

		$currency_multipliers = array(
			// Zero-decimal currencies.
			1   => array(
				'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF',
				'XOF', 'XPF',
			),
			100 => array(
				'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN',
				'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BZD', 'CAD', 'CDF', 'CHF', 'CNY', 'COP',
				'CRC', 'CVE', 'CZK', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP',
				'GBP', 'GEL', 'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK', 'HTG', 'HUF', 'IDR',
				'ILS', 'INR', 'ISK', 'JMD', 'KES', 'KGS', 'KHR', 'KYD', 'KZT',
				'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MKD', 'MMK', 'MNT', 'MOP', 'MRO', 'MUR', 'MVR', 'MWK',
				'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR',
				'PLN', 'QAR', 'RON', 'RSD', 'RUB', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLL',
				'SOS', 'SRD', 'STD', 'SZL', 'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD',
				'TZS', 'UAH', 'USD', 'UYU', 'UZS', 'WST', 'XCD', 'YER', 'ZAR', 'ZMW',
			),
		);

		foreach ( $currency_multipliers as $multiplier => $currencies ) {
			if ( in_array( $order_currency, $currencies, true ) ) {
				$fractional_amount = floatval( $base_unit_amount ) * $multiplier;
				break;
			}
		}

		if ( is_null( $fractional_amount ) ) {
			throw new Exception( "Unknown currency multiplier for $order_currency." );
		}

		return intval( $fractional_amount );
	}
}
