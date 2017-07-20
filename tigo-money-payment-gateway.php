<?php
/*
Plugin Name: Tigo Money Payment Gateway
Plugin URI:  
Description: Tigo Money Payment Gateway allows Tigo Money Partners to connect to our services.
Version:     0.0.1
Author:      Tin Ho Chu
Author URI:  https://tinho.co
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: tigo-money-payment-gateway
*/

/*
 Security Reasons
*/
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/*Define plugin main file*/
if ( !defined('TMPG_FILE') )
  define ( 'TMPG_FILE', __FILE__ );

/* Define BaseName */
if ( !defined('TMPG_BASENAME') )
  define ('TMPG_BASENAME',plugin_basename(TMPG_FILE));

/* Define internal path */
if ( !defined( 'TMPG_PATH' ) )
  define( 'TMPG_PATH', plugin_dir_path( TMPG_FILE ) );


/* Check if we're running WooCommerce */
if ( !in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) :
   if (is_admin()) :
    require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    deactivate_plugins( __FILE__ );
    wp_die('Tigo Money Payment Gateway for WooCommerce requires WooCommerce to be installed and activated. The plugin has now disabled itself.');
  endif;
endif;

/*
 Include Tigo Money Payment Gateway Class and Register Payment Gateway with WooCommerce
*/
function wp_tigo_money_payment_gateway_init() {
  require_once (TMPG_PATH.'lib/class-tigo-money-payment-gateway.php');
  require_once (TMPG_PATH.'lib/tigo-money-payment-gateway-helpers.php');
  global $tigo_money_pay;
  $tigo_money_pay = new Tigo_Money_Payment_Gateway_Class();
}
add_action( 'plugins_loaded', 'wp_tigo_money_payment_gateway_init', 0 );
?>