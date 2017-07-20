<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Settings for Tigo Money Payment Gateway.
 */
return array(
  'enabled' => array(
    'title'   => __( 'Enable/Disable', 'woocommerce' ),
    'type'    => 'checkbox',
    'label'   => __( 'Enable Tigo Money Payment', 'woocommerce' ),
    'default' => 'yes'
  ),
  'title' => array(
    'title'       => __( 'Title', 'woocommerce' ),
    'type'        => 'text',
    'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
    'default'     => __( 'Tigo Money', 'woocommerce' ),
    'desc_tip'    => true,
  ),
  'description' => array(
    'title'       => __( 'Description', 'woocommerce' ),
    'type'        => 'text',
    'desc_tip'    => true,
    'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
    'default'     => __( 'Pay via Tigo Money', 'woocommerce' )
  ),
  'testmode' => array(
    'title'       => __( 'Tigo Money Sandbox', 'woocommerce' ),
    'type'        => 'checkbox',
    'label'       => __( 'Enable Tigo Money sandbox', 'woocommerce' ),
    'default'     => 'no',
    'description' => sprintf( __( 'Tigo sandbox can be used to test payments. Sign up for a developer account <a href="%s">here</a>.', 'woocommerce' ), '#' ),
  ),
  'debug' => array(
    'title'       => __( 'Debug Log', 'woocommerce' ),
    'type'        => 'checkbox',
    'label'       => __( 'Enable logging', 'woocommerce' ),
    'default'     => 'no',
    'description' => sprintf( __( 'Log PayPal events, such as IPN requests, inside <code>%s</code>', 'woocommerce' ), wc_get_log_file_path( 'tigo_money_payment_gateway' ) )
  ),
  'merchant_id' => array(
    'title'       => __( 'Tigo Merchant Account', 'woocommerce' ),
    'type'        => 'text',
    'description' => __( 'Account provided by Tigo Money', 'woocommerce' ),
    'default'     => '',
    'desc_tip'    => true,
    'placeholder' => ''
  ),
  'pin_number' => array(
    'title'       => __( 'Merchant PIN Number', 'woocommerce' ),
    'type'        => 'text',
    'description' => __( 'Merchant PIN Number provided by Tigo Money', 'woocommerce' ),
    'default'     => '',
    'desc_tip'    => true,
    'placeholder' => ''
  ),
  'consumer_key' => array(
    'title'       => __( 'Consumer Key', 'woocommerce' ),
    'type'        => 'text',
    'description' => __( 'Consumer Key by Tigo Money', 'woocommerce' ),
    'default'     => '',
    'desc_tip'    => true,
    'placeholder' => ''
  ),
  'consumer_secret' => array(
    'title'       => __( 'Consumer Secret', 'woocommerce' ),
    'type'        => 'text',
    'description' => __( 'Consumer Secret by Tigo Money', 'woocommerce' ),
    'default'     => '',
    'desc_tip'    => true,
    'placeholder' => ''
  ),
);
