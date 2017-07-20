<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates requests to send to tigo.
 */
class Tigo_Money_Payment_Gateway_Class_Request {

	/**
	 * Stores line items to send to tigo.
	 * @var array
	 */
	protected $line_items = array();

	/**
	 * Pointer to gateway making the request.
	 * @var Tigo_Money_Payment_Gateway_Class
	 */
	protected $gateway;

	/**
	 * Endpoint for requests from tigo.
	 * @var string
	 */
	protected $notify_url;

	/**
	 * URL to make test
	 * @var string
	 */
	protected $test_url = 'https://securesandbox.tigo.com/';

	/**
	 * URL to make Live
	 * @var string
	 */
	protected $live_url = 'https://securesandbox.tigo.com/';

	/**
	 * Constructor.
	 * @param Tigo_Money_Payment_Gateway_Class $gateway
	 */
	public function __construct( $gateway ) {
		$this->gateway    = $gateway;
		$this->notify_url = WC()->api_request_url( 'wc_tigo_money_payment_gateway' );
	}

	/**
	 * Get the tigo request URL for an order.
	 * @param  WC_Order $order
	 * @param  bool     $sandbox
	 * @return string
	 */
	public function get_request_url( $order, $sandbox = false ) {

		$token = $this->get_bearer_token( $order, $sandbox );

		if( $token ) {
			if( $this->gateway->debug ){
				Tigo_Money_Payment_Gateway_Class::log( 'Got Token! --> '. $token );
			}
			
			$order->add_order_note( 'Got Token: '. $token  );

			if( $sandbox ){
				$url_payment_authorization_request = $this->test_url.'v2/tigo/mfs/payments/authorizations';
			} else {
				
			}

			$response_authorization_payment_request = wp_safe_remote_post(
																									$url_payment_authorization_request,
																									array(
																										'headers' => array(
																																	'cache-control' => 'no-cache',
																																  'content-type' => 'application/json',
																																  'authorization' => 'Bearer '. $token
																																	),
																										'body' 		=> json_encode( $this->get_tigo_args( $order, $token ) )
																										)
																									);

			$body_payment = wp_remote_retrieve_body( $response_authorization_payment_request );
			$body_payment = json_decode( $body_payment, true );

			if(( !array_key_exists('error', $body_payment ) && ( !array_key_exists('fault', $body_payment ) ) )) {
				if ( $body_payment['redirectUrl'] ) {
					return $body_payment['redirectUrl'];
				} else {
					throw new Exception( $body_payment['error_description'] );
				}
			} else {
				if( array_key_exists('fault', $body_payment ) ) {
					throw new Exception( $body_payment['fault']['detail']['errorcode'] );

				} else if( array_key_exists('error', $body_payment ) ){
					throw new Exception( $body_payment['error_description'] );
				}
			}
		}
	}

	public function get_bearer_token( $order = false, $sandbox = false ){

		if( $this->gateway->debug ){
			Tigo_Money_Payment_Gateway_Class::log( 'Generate URL to create a POST to get the Token' );
		}
		
		if ( $sandbox ) {
			$url = $this->test_url.'v1/oauth/mfs/payments/tokens';			
		} else {
			$url = '';
		}

		$accesstoken = $this->get_access_token($this->gateway->get_option('consumer_key'),$this->gateway->get_option('consumer_secret'), $order );

		$response = wp_safe_remote_post( $url, array(
																						'headers' => array(
																													'cache-control' => 'no-cache',
																													'content-type'  => 'application/x-www-form-urlencoded',
																													'authorization' => 'Basic '. $accesstoken
																													),
																						'body' => array( 'grant_type' => 'client_credentials')
																					)
		);
		
		if ( is_wp_error( $response ) ) 
			throw new Exception( 'We are currently experiencing problems trying to connect to this payment gateway. Sorry for the inconvenience.' );

		if ( $response['response']['code'] != 200 )
			throw new Exception( 'Oops! Something Bad Happen, check the Consumer Key and Consumer Secret and try Again.' );

		$body = wp_remote_retrieve_body( $response );

		$body = json_decode( $body, true );

		// TOKEN
		return $body['accessToken'];	
	}
	public function get_access_token( $consumer_key = false, $consumer_secret = false, $order = false ) {

		if( $order != false){
			if( $this->gateway->debug ){
				Tigo_Money_Payment_Gateway_Class::log( 'Generating Access Token from order: ' . $order->get_order_number() );
			}
		}

		if ( !$consumer_key || !$consumer_secret ) {
			throw new Exception( 'Oops! Something Bad Happen, check the Consumer Key and Consumer Secret and try Again.' );
		}
		$st_key_secret = $consumer_key.':'.$consumer_secret;
		if( $this->gateway->debug ){
			Tigo_Money_Payment_Gateway_Class::log( 'String to encode: '. $st_key_secret );
		}
		return $this->encode_string( $st_key_secret, $order );
	}

	/**
	 * POST Token Request
	 * @param  WC_Order $order
	 * @return array
	 */
	protected function encode_string( $st_key_secret, $order = false ){
		if ($order != false) {
			if($this->gateway->debug){
				Tigo_Money_Payment_Gateway_Class::log( 'Encoding consumer Key and Secret for Order: ' . $order->get_order_number() );
			}
		}

		$encryptedstring = base64_encode($st_key_secret);

		if ($order != false) {
			if($this->gateway->debug){
				Tigo_Money_Payment_Gateway_Class::log( 'Encrypt for Order: '. $order->get_order_number() .' '.$encryptedstring );
			}
		}
		return $encryptedstring;
	}

	/**
	 * Get tigo Args for passing to PP.
	 * @param  WC_Order $order
	 * @return array
	 */
	protected function get_tigo_args( $order, $token ) {
		

		update_post_meta( $order->get_order_number(), 'merchantTransactionId', 'WC'.$this->gateway->get_option('merchant_id').'Order'. $order->get_order_number() . current_time( 'timestamp', true ) );

		$body_built = $this->build_body_auth( $order, $token );
		if( $this->gateway->debug ){
			Tigo_Money_Payment_Gateway_Class::log( json_encode($body_built) );
		}
		return $body_built;
	}

	protected function build_body_auth( $order, $token ){
		return array_merge(
			array(
				'MasterMerchant' 	=> 	array(
																'account' =>	$this->gateway->get_option('merchant_id'),
																'pin'	 		=>	$this->gateway->get_option('pin_number'),
																'id'			=>	'WooCommerce_'.$this->gateway->get_option('merchant_id')
															),
				'Merchant'				=>	array(
																'reference' 		=>	get_option( 'blogname' ),
																'fee'						=>	'0.00',
																'currencyCode' 	=>	get_woocommerce_currency()
															),
				'Subscriber'			=>	array(
																'account'			=>	$order->billing_phone,
																'countryCode' =>	'503',
																'country'	 		=>	$this->convert_country_code( $order->billing_country ),
																'firstName'		=>	$order->billing_first_name,
																'firstName'		=>	$order->billing_first_name,
																'lastName'		=>	$order->billing_last_name,
																'emailId'			=>	$order->billing_email	
															),
				'redirectUri'			=>	$this->notify_url,
				'callbackUri'			=>	$this->notify_url,
				'language'			  =>	'spa',
				'terminalId'			=>	'1' ,
				'OriginPayment'		=>	array(
																'amount'				=>	number_format( WC()->cart->total , 2, '.', ''),
																'currencyCode'	=>	get_woocommerce_currency(),
																'tax'						=>	'0.00',
																'fee'						=>	'0.00'
															),
				'exchangeRate'		=>	'1.00',
				'LocalPayment'		=>	array(
																'amount'				=>	number_format( WC()->cart->total , 2, '.', ''),
																'currencyCode'	=>	get_woocommerce_currency()
															),
				'merchantTransactionId' => 'WC'.$this->gateway->get_option('merchant_id').'Order'. $order->get_order_number(). current_time( 'timestamp', true )
			));
	}
	/**
	 * Get phone number args for tigo request.
	 * @param  WC_Order $order
	 * @return array
	 */
	protected function get_phone_number_args( $order ) {
		if ( in_array( $order->billing_country, array( 'US','CA' ) ) ) {
			$phone_number = str_replace( array( '(', '-', ' ', ')', '.' ), '', $order->billing_phone );
			$phone_number = ltrim( $phone_number, '+1' );
			$phone_args   = array(
				'night_phone_a' => substr( $phone_number, 0, 3 ),
				'night_phone_b' => substr( $phone_number, 3, 3 ),
				'night_phone_c' => substr( $phone_number, 6, 4 ),
				'day_phone_a' 	=> substr( $phone_number, 0, 3 ),
				'day_phone_b' 	=> substr( $phone_number, 3, 3 ),
				'day_phone_c' 	=> substr( $phone_number, 6, 4 )
			);
		} else {
			$phone_args = array(
				'night_phone_b' => $order->billing_phone,
				'day_phone_b' 	=> $order->billing_phone
			);
		}
		return $phone_args;
	}

	/**
	 * Get shipping args for tigo request.
	 * @param  WC_Order $order
	 * @return array
	 */
	protected function get_shipping_args( $order ) {
		$shipping_args = array();

		if ( 'yes' == $this->gateway->get_option( 'send_shipping' ) ) {
			$shipping_args['address_override'] = $this->gateway->get_option( 'address_override' ) === 'yes' ? 1 : 0;
			$shipping_args['no_shipping']      = 0;

			// If we are sending shipping, send shipping address instead of billing
			$shipping_args['first_name']       = $order->shipping_first_name;
			$shipping_args['last_name']        = $order->shipping_last_name;
			$shipping_args['company']          = $order->shipping_company;
			$shipping_args['address1']         = $order->shipping_address_1;
			$shipping_args['address2']         = $order->shipping_address_2;
			$shipping_args['city']             = $order->shipping_city;
			$shipping_args['state']            = $this->get_tigo_state( $order->shipping_country, $order->shipping_state );
			$shipping_args['country']          = $order->shipping_country;
			$shipping_args['zip']              = $order->shipping_postcode;
		} else {
			$shipping_args['no_shipping']      = 1;
		}

		return $shipping_args;
	}

	/**
	 * Get line item args for tigo request.
	 * @param  WC_Order $order
	 * @return array
	 */
	protected function get_line_item_args( $order ) {

		/**
		 * Try passing a line item per product if supported.
		 */
		if ( ( ! wc_tax_enabled() || ! wc_prices_include_tax() ) && $this->prepare_line_items( $order ) ) {

			$line_item_args             = array();
			$line_item_args['tax_cart'] = $this->number_format( $order->get_total_tax(), $order );

			if ( $order->get_total_discount() > 0 ) {
				$line_item_args['discount_amount_cart'] = $this->number_format( $this->round( $order->get_total_discount(), $order ), $order );
			}

			// Add shipping costs. tigo ignores anything over 5 digits (999.99 is the max).
			// We also check that shipping is not the **only** cost as tigo won't allow payment
			// if the items have no cost.
			if ( $order->get_total_shipping() > 0 && $order->get_total_shipping() < 999.99 && $this->number_format( $order->get_total_shipping() + $order->get_shipping_tax(), $order ) !== $this->number_format( $order->get_total(), $order ) ) {
				$line_item_args['shipping_1'] = $this->number_format( $order->get_total_shipping(), $order );
			} elseif ( $order->get_total_shipping() > 0 ) {
				$this->add_line_item( sprintf( __( 'Shipping via %s', 'woocommerce' ), $order->get_shipping_method() ), 1, $this->number_format( $order->get_total_shipping(), $order ) );
			}

			$line_item_args = array_merge( $line_item_args, $this->get_line_items() );

		/**
		 * Send order as a single item.
		 *
		 * For shipping, we longer use shipping_1 because tigo ignores it if *any* shipping rules are within tigo, and tigo ignores anything over 5 digits (999.99 is the max).
		 */
		} else {

			$this->delete_line_items();

			$line_item_args = array();
			$all_items_name = $this->get_order_item_names( $order );
			$this->add_line_item( $all_items_name ? $all_items_name : __( 'Order', 'woocommerce' ), 1, $this->number_format( $order->get_total() - $this->round( $order->get_total_shipping() + $order->get_shipping_tax(), $order ), $order ), $order->get_order_number() );

			// Add shipping costs. tigo ignores anything over 5 digits (999.99 is the max).
			// We also check that shipping is not the **only** cost as tigo won't allow payment
			// if the items have no cost.
			if ( $order->get_total_shipping() > 0 && $order->get_total_shipping() < 999.99 && $this->number_format( $order->get_total_shipping() + $order->get_shipping_tax(), $order ) !== $this->number_format( $order->get_total(), $order ) ) {
				$line_item_args['shipping_1'] = $this->number_format( $order->get_total_shipping() + $order->get_shipping_tax(), $order );
			} elseif ( $order->get_total_shipping() > 0 ) {
				$this->add_line_item( sprintf( __( 'Shipping via %s', 'woocommerce' ), $order->get_shipping_method() ), 1, $this->number_format( $order->get_total_shipping() + $order->get_shipping_tax(), $order ) );
			}

			$line_item_args = array_merge( $line_item_args, $this->get_line_items() );
		}

		return $line_item_args;
	}

	/**
	 * Get order item names as a string.
	 * @param  WC_Order $order
	 * @return string
	 */
	protected function get_order_item_names( $order ) {
		$item_names = array();

		foreach ( $order->get_items() as $item ) {
			$item_names[] = $item['name'] . ' x ' . $item['qty'];
		}

		return implode( ', ', $item_names );
	}

	/**
	 * Get order item names as a string.
	 * @param  WC_Order $order
	 * @param  array $item
	 * @return string
	 */
	protected function get_order_item_name( $order, $item ) {
		$item_name = $item['name'];
		$item_meta = new WC_Order_Item_Meta( $item );

		if ( $meta = $item_meta->display( true, true ) ) {
			$item_name .= ' ( ' . $meta . ' )';
		}

		return $item_name;
	}

	/**
	 * Return all line items.
	 */
	protected function get_line_items() {
		return $this->line_items;
	}

	/**
	 * Remove all line items.
	 */
	protected function delete_line_items() {
		$this->line_items = array();
	}

	/**
	 * Get line items to send to tigo.
	 * @param  WC_Order $order
	 * @return bool
	 */
	protected function prepare_line_items( $order ) {
		$this->delete_line_items();
		$calculated_total = 0;

		// Products
		foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
			if ( 'fee' === $item['type'] ) {
				$item_line_total  = $this->number_format( $item['line_total'], $order );
				$line_item        = $this->add_line_item( $item['name'], 1, $item_line_total );
				$calculated_total += $item_line_total;
			} else {
				$product          = $order->get_product_from_item( $item );
				$sku              = $product ? $product->get_sku() : '';
				$item_line_total  = $this->number_format( $order->get_item_subtotal( $item, false ), $order );
				$line_item        = $this->add_line_item( $this->get_order_item_name( $order, $item ), $item['qty'], $item_line_total, $sku );
				$calculated_total += $item_line_total * $item['qty'];
			}

			if ( ! $line_item ) {
				return false;
			}
		}

		// Check for mismatched totals.
		if ( $this->number_format( $calculated_total + $order->get_total_tax() + $this->round( $order->get_total_shipping(), $order ) - $this->round( $order->get_total_discount(), $order ), $order ) != $this->number_format( $order->get_total(), $order ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Add tigo Line Item.
	 * @param  string  $item_name
	 * @param  int     $quantity
	 * @param  float   $amount
	 * @param  string  $item_number
	 * @return bool successfully added or not
	 */
	protected function add_line_item( $item_name, $quantity = 1, $amount = 0, $item_number = '' ) {
		$index = ( sizeof( $this->line_items ) / 4 ) + 1;

		if ( $amount < 0 || $index > 9 ) {
			return false;
		}

		$this->line_items[ 'item_name_' . $index ]   = html_entity_decode( wc_trim_string( $item_name ? $item_name : __( 'Item', 'woocommerce' ), 127 ), ENT_NOQUOTES, 'UTF-8' );
		$this->line_items[ 'quantity_' . $index ]    = (int) $quantity;
		$this->line_items[ 'amount_' . $index ]      = (float) $amount;
		$this->line_items[ 'item_number_' . $index ] = $item_number;

		return true;
	}

	/**
	 * Get the state to send to tigo.
	 * @param  string $cc
	 * @param  string $state
	 * @return string
	 */
	protected function get_tigo_state( $cc, $state ) {
		if ( 'US' === $cc ) {
			return $state;
		}

		$states = WC()->countries->get_states( $cc );

		if ( isset( $states[ $state ] ) ) {
			return $states[ $state ];
		}

		return $state;
	}

	/**
	 * Check if currency has decimals.
	 * @param  string $currency
	 * @return bool
	 */
	protected function currency_has_decimals( $currency ) {
		if ( in_array( $currency, array( 'HUF', 'JPY', 'TWD' ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Round prices.
	 * @param  double $price
	 * @param  WC_Order $order
	 * @return double
	 */
	protected function round( $price, $order ) {
		$precision = 2;

		if ( ! $this->currency_has_decimals( $order->get_order_currency() ) ) {
			$precision = 0;
		}

		return round( $price, $precision );
	}

	/**
	 * Format prices.
	 * @param  float|int $price
	 * @param  WC_Order $order
	 * @return string
	 */
	protected function number_format( $price, $order ) {
		$decimals = 2;

		if ( ! $this->currency_has_decimals( $order->get_order_currency() ) ) {
			$decimals = 0;
		}

		return number_format( $price, $decimals, '.', '' );
	}
	/**
	 * Convert Country from A2 into A3
	 */
	public function convert_country_code( $country ) {
      $countries = array(
            'AF' => 'AFG', //Afghanistan
            'AX' => 'ALA', //&#197;land Islands
            'AL' => 'ALB', //Albania
            'DZ' => 'DZA', //Algeria
            'AS' => 'ASM', //American Samoa
            'AD' => 'AND', //Andorra
            'AO' => 'AGO', //Angola
            'AI' => 'AIA', //Anguilla
            'AQ' => 'ATA', //Antarctica
            'AG' => 'ATG', //Antigua and Barbuda
            'AR' => 'ARG', //Argentina
            'AM' => 'ARM', //Armenia
            'AW' => 'ABW', //Aruba
            'AU' => 'AUS', //Australia
            'AT' => 'AUT', //Austria
            'AZ' => 'AZE', //Azerbaijan
            'BS' => 'BHS', //Bahamas
            'BH' => 'BHR', //Bahrain
            'BD' => 'BGD', //Bangladesh
            'BB' => 'BRB', //Barbados
            'BY' => 'BLR', //Belarus
            'BE' => 'BEL', //Belgium
            'BZ' => 'BLZ', //Belize
            'BJ' => 'BEN', //Benin
            'BM' => 'BMU', //Bermuda
            'BT' => 'BTN', //Bhutan
            'BO' => 'BOL', //Bolivia
            'BQ' => 'BES', //Bonaire, Saint Estatius and Saba
            'BA' => 'BIH', //Bosnia and Herzegovina
            'BW' => 'BWA', //Botswana
            'BV' => 'BVT', //Bouvet Islands
            'BR' => 'BRA', //Brazil
            'IO' => 'IOT', //British Indian Ocean Territory
            'BN' => 'BRN', //Brunei
            'BG' => 'BGR', //Bulgaria
            'BF' => 'BFA', //Burkina Faso
            'BI' => 'BDI', //Burundi
            'KH' => 'KHM', //Cambodia
            'CM' => 'CMR', //Cameroon
            'CA' => 'CAN', //Canada
            'CV' => 'CPV', //Cape Verde
            'KY' => 'CYM', //Cayman Islands
            'CF' => 'CAF', //Central African Republic
            'TD' => 'TCD', //Chad
            'CL' => 'CHL', //Chile
            'CN' => 'CHN', //China
            'CX' => 'CXR', //Christmas Island
            'CC' => 'CCK', //Cocos (Keeling) Islands
            'CO' => 'COL', //Colombia
            'KM' => 'COM', //Comoros
            'CG' => 'COG', //Congo
            'CD' => 'COD', //Congo, Democratic Republic of the
            'CK' => 'COK', //Cook Islands
            'CR' => 'CRI', //Costa Rica
            'CI' => 'CIV', //Côte d\'Ivoire
            'HR' => 'HRV', //Croatia
            'CU' => 'CUB', //Cuba
            'CW' => 'CUW', //Curaçao
            'CY' => 'CYP', //Cyprus
            'CZ' => 'CZE', //Czech Republic
            'DK' => 'DNK', //Denmark
            'DJ' => 'DJI', //Djibouti
            'DM' => 'DMA', //Dominica
            'DO' => 'DOM', //Dominican Republic
            'EC' => 'ECU', //Ecuador
            'EG' => 'EGY', //Egypt
            'SV' => 'SLV', //El Salvador
            'GQ' => 'GNQ', //Equatorial Guinea
            'ER' => 'ERI', //Eritrea
            'EE' => 'EST', //Estonia
            'ET' => 'ETH', //Ethiopia
            'FK' => 'FLK', //Falkland Islands
            'FO' => 'FRO', //Faroe Islands
            'FJ' => 'FIJ', //Fiji
            'FI' => 'FIN', //Finland
            'FR' => 'FRA', //France
            'GF' => 'GUF', //French Guiana
            'PF' => 'PYF', //French Polynesia
            'TF' => 'ATF', //French Southern Territories
            'GA' => 'GAB', //Gabon
            'GM' => 'GMB', //Gambia
            'GE' => 'GEO', //Georgia
            'DE' => 'DEU', //Germany
            'GH' => 'GHA', //Ghana
            'GI' => 'GIB', //Gibraltar
            'GR' => 'GRC', //Greece
            'GL' => 'GRL', //Greenland
            'GD' => 'GRD', //Grenada
            'GP' => 'GLP', //Guadeloupe
            'GU' => 'GUM', //Guam
            'GT' => 'GTM', //Guatemala
            'GG' => 'GGY', //Guernsey
            'GN' => 'GIN', //Guinea
            'GW' => 'GNB', //Guinea-Bissau
            'GY' => 'GUY', //Guyana
            'HT' => 'HTI', //Haiti
            'HM' => 'HMD', //Heard Island and McDonald Islands
            'VA' => 'VAT', //Holy See (Vatican City State)
            'HN' => 'HND', //Honduras
            'HK' => 'HKG', //Hong Kong
            'HU' => 'HUN', //Hungary
            'IS' => 'ISL', //Iceland
            'IN' => 'IND', //India
            'ID' => 'IDN', //Indonesia
            'IR' => 'IRN', //Iran
            'IQ' => 'IRQ', //Iraq
            'IE' => 'IRL', //Republic of Ireland
            'IM' => 'IMN', //Isle of Man
            'IL' => 'ISR', //Israel
            'IT' => 'ITA', //Italy
            'JM' => 'JAM', //Jamaica
            'JP' => 'JPN', //Japan
            'JE' => 'JEY', //Jersey
            'JO' => 'JOR', //Jordan
            'KZ' => 'KAZ', //Kazakhstan
            'KE' => 'KEN', //Kenya
            'KI' => 'KIR', //Kiribati
            'KP' => 'PRK', //Korea, Democratic People\'s Republic of
            'KR' => 'KOR', //Korea, Republic of (South)
            'KW' => 'KWT', //Kuwait
            'KG' => 'KGZ', //Kyrgyzstan
            'LA' => 'LAO', //Laos
            'LV' => 'LVA', //Latvia
            'LB' => 'LBN', //Lebanon
            'LS' => 'LSO', //Lesotho
            'LR' => 'LBR', //Liberia
            'LY' => 'LBY', //Libya
            'LI' => 'LIE', //Liechtenstein
            'LT' => 'LTU', //Lithuania
            'LU' => 'LUX', //Luxembourg
            'MO' => 'MAC', //Macao S.A.R., China
            'MK' => 'MKD', //Macedonia
            'MG' => 'MDG', //Madagascar
            'MW' => 'MWI', //Malawi
            'MY' => 'MYS', //Malaysia
            'MV' => 'MDV', //Maldives
            'ML' => 'MLI', //Mali
            'MT' => 'MLT', //Malta
            'MH' => 'MHL', //Marshall Islands
            'MQ' => 'MTQ', //Martinique
            'MR' => 'MRT', //Mauritania
            'MU' => 'MUS', //Mauritius
            'YT' => 'MYT', //Mayotte
            'MX' => 'MEX', //Mexico
            'FM' => 'FSM', //Micronesia
            'MD' => 'MDA', //Moldova
            'MC' => 'MCO', //Monaco
            'MN' => 'MNG', //Mongolia
            'ME' => 'MNE', //Montenegro
            'MS' => 'MSR', //Montserrat
            'MA' => 'MAR', //Morocco
            'MZ' => 'MOZ', //Mozambique
            'MM' => 'MMR', //Myanmar
            'NA' => 'NAM', //Namibia
            'NR' => 'NRU', //Nauru
            'NP' => 'NPL', //Nepal
            'NL' => 'NLD', //Netherlands
            'AN' => 'ANT', //Netherlands Antilles
            'NC' => 'NCL', //New Caledonia
            'NZ' => 'NZL', //New Zealand
            'NI' => 'NIC', //Nicaragua
            'NE' => 'NER', //Niger
            'NG' => 'NGA', //Nigeria
            'NU' => 'NIU', //Niue
            'NF' => 'NFK', //Norfolk Island
            'MP' => 'MNP', //Northern Mariana Islands
            'NO' => 'NOR', //Norway
            'OM' => 'OMN', //Oman
            'PK' => 'PAK', //Pakistan
            'PW' => 'PLW', //Palau
            'PS' => 'PSE', //Palestinian Territory
            'PA' => 'PAN', //Panama
            'PG' => 'PNG', //Papua New Guinea
            'PY' => 'PRY', //Paraguay
            'PE' => 'PER', //Peru
            'PH' => 'PHL', //Philippines
            'PN' => 'PCN', //Pitcairn
            'PL' => 'POL', //Poland
            'PT' => 'PRT', //Portugal
            'PR' => 'PRI', //Puerto Rico
            'QA' => 'QAT', //Qatar
            'RE' => 'REU', //Reunion
            'RO' => 'ROU', //Romania
            'RU' => 'RUS', //Russia
            'RW' => 'RWA', //Rwanda
            'BL' => 'BLM', //Saint Barth&eacute;lemy
            'SH' => 'SHN', //Saint Helena
            'KN' => 'KNA', //Saint Kitts and Nevis
            'LC' => 'LCA', //Saint Lucia
            'MF' => 'MAF', //Saint Martin (French part)
            'SX' => 'SXM', //Sint Maarten / Saint Matin (Dutch part)
            'PM' => 'SPM', //Saint Pierre and Miquelon
            'VC' => 'VCT', //Saint Vincent and the Grenadines
            'WS' => 'WSM', //Samoa
            'SM' => 'SMR', //San Marino
            'ST' => 'STP', //S&atilde;o Tom&eacute; and Pr&iacute;ncipe
            'SA' => 'SAU', //Saudi Arabia
            'SN' => 'SEN', //Senegal
            'RS' => 'SRB', //Serbia
            'SC' => 'SYC', //Seychelles
            'SL' => 'SLE', //Sierra Leone
            'SG' => 'SGP', //Singapore
            'SK' => 'SVK', //Slovakia
            'SI' => 'SVN', //Slovenia
            'SB' => 'SLB', //Solomon Islands
            'SO' => 'SOM', //Somalia
            'ZA' => 'ZAF', //South Africa
            'GS' => 'SGS', //South Georgia/Sandwich Islands
            'SS' => 'SSD', //South Sudan
            'ES' => 'ESP', //Spain
            'LK' => 'LKA', //Sri Lanka
            'SD' => 'SDN', //Sudan
            'SR' => 'SUR', //Suriname
            'SJ' => 'SJM', //Svalbard and Jan Mayen
            'SZ' => 'SWZ', //Swaziland
            'SE' => 'SWE', //Sweden
            'CH' => 'CHE', //Switzerland
            'SY' => 'SYR', //Syria
            'TW' => 'TWN', //Taiwan
            'TJ' => 'TJK', //Tajikistan
            'TZ' => 'TZA', //Tanzania
            'TH' => 'THA', //Thailand    
            'TL' => 'TLS', //Timor-Leste
            'TG' => 'TGO', //Togo
            'TK' => 'TKL', //Tokelau
            'TO' => 'TON', //Tonga
            'TT' => 'TTO', //Trinidad and Tobago
            'TN' => 'TUN', //Tunisia
            'TR' => 'TUR', //Turkey
            'TM' => 'TKM', //Turkmenistan
            'TC' => 'TCA', //Turks and Caicos Islands
            'TV' => 'TUV', //Tuvalu     
            'UG' => 'UGA', //Uganda
            'UA' => 'UKR', //Ukraine
            'AE' => 'ARE', //United Arab Emirates
            'GB' => 'GBR', //United Kingdom
            'US' => 'USA', //United States
            'UM' => 'UMI', //United States Minor Outlying Islands
            'UY' => 'URY', //Uruguay
            'UZ' => 'UZB', //Uzbekistan
            'VU' => 'VUT', //Vanuatu
            'VE' => 'VEN', //Venezuela
            'VN' => 'VNM', //Vietnam
            'VG' => 'VGB', //Virgin Islands, British
            'VI' => 'VIR', //Virgin Island, U.S.
            'WF' => 'WLF', //Wallis and Futuna
            'EH' => 'ESH', //Western Sahara
            'YE' => 'YEM', //Yemen
            'ZM' => 'ZMB', //Zambia
            'ZW' => 'ZWE', //Zimbabwe
      );
      $iso_code = isset( $countries[$country] ) ? $countries[$country] : $country;
      return $iso_code;
	}
}
