<?php
/**
 * Add Function to enque scripts on load WordPress
 * @param [type] $methods [description]
 */
function add_gateway_tigo_money_gateway_class( $methods ) {
  $methods[] = 'Tigo_Money_Payment_Gateway_Class'; 
  return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'add_gateway_tigo_money_gateway_class' );