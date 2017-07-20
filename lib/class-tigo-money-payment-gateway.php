<?php

if(!class_exists('WC_Payment_Gateway')) return;

class Tigo_Money_Payment_Gateway_Class extends WC_Payment_Gateway {

  /** @var bool Whether or not logging is enabled */
  public static $log_enabled = false;

  /** @var WC_Logger Logger instance */
  public static $log = false;

  public static $alreadyEnqueued = false;

  /**
   * Constructor
   */
  public function __construct() {
    $this->id           = 'tigo_money_payment_gateway';
    $this->icon = plugins_url('images/woocommerce-tigomoney.png', plugin_dir_path(__FILE__));
    $this->has_fields     = false;
    $this->method_title     = 'Tigo Money Payment Gateway';
    $this->method_description = 'Tigo Money Payment Gateway for Woocommerce<br><br>'.'<b>redirectUri</b> '.WC()->api_request_url( 'wc_tigo_money_payment_gateway' ).'<br><br>'.'<b>callbackUri</b> '.WC()->api_request_url( 'wc_tigo_money_payment_gateway' );

    $this->init_form_fields();
    $this->init_settings();

    // Define user set variables.
    $this->title             = $this->get_option( 'title' );
    $this->description       = $this->get_option( 'description' );
    $this->testmode          = 'yes' === $this->get_option( 'testmode', 'no' );
    $this->debug             = 'yes' === $this->get_option( 'debug', 'no' );
    $this->merchant_id       = $this->get_option('merchant_id');
    $this->pin_number        = $this->get_option('pin_number');
    $this->consumer_key      = $this->get_option('consumer_key');
    $this->consumer_secret   = $this->get_option('consumer_secret');


    self::$log_enabled    = $this->debug;
    
    /* Add hook for saving options */
    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    
    // WooCommerce API hook. 
    add_action('woocommerce_api_wc_tigo_money_payment_gateway', array($this, 'return_handler'));
    
    // WC Actions Buttons on the Orders List
    add_filter( 'woocommerce_admin_order_actions', array($this,'wc_tigo_reverse_transaction'), 10, 2 );

    // Little CSS hack to show the "refund" Icon
    add_action( 'admin_head', array($this,'add_refund_order_actions_button_css') );

    // AJAX Call to make the refund of an order
    add_action( 'wp_ajax_tigo_wc_made_refund', array($this, 'tigo_wc_made_refund') );

    // add Function to save Order
    add_action( 'woocommerce_order_status_changed', array($this, 'edit_order_detail' ), 10, 1);

    // Check Updates
    add_action('wp', array($this, 'check_updates_from_wp'));
    add_action('wp', array($this, 'check_updates_from_wc'));
  }

  /**
   * init_form_fields()
   * @return return the form fields on the admin dashboard
   */
  public function init_form_fields() {
    $this->form_fields = include( 'settings-tigo-money-payment-gateway.php' );
  }

  /**
   * Logging method.
   * @param string $message
   */
  public static function log( $message ) {
    if ( self::$log_enabled ) {
      if ( empty( self::$log ) ) {
        self::$log = new WC_Logger();
      }
      self::$log->add( 'tigo_money_payment_gateway', $message );
    }
  }

  /**
   * Process the payment and return the result.
   * @param  int $order_id
   * @return array
   */
  public function process_payment( $order_id ) {

    include_once( 'class-wc-gateway-tigo-request.php' );

    $order          = wc_get_order( $order_id );
    $tigo_request = new Tigo_Money_Payment_Gateway_Class_Request( $this );
    
    return array(
      'result'   => 'success',
      'redirect' => $tigo_request->get_request_url( $order, $this->testmode )
    );
  }

  /**
   * Handler of the Callback/ Redirect From Tigo Servers
   * @return function Redirect to Cart/thank you page
   */
  public function return_handler() {
    @ob_clean();
    header('HTTP/1.1 200 OK');


    // Verify if GET Request isset() not empty
    if ( !empty($_GET) ) {

      // Check if the Transaction is fail
      if( strtolower( $_GET['transactionStatus'] ) === 'fail' ) {
        wp_redirect(wc_get_page_permalink('cart'));
        exit();
      }
      // Get the Order ID:
      $args = array(
        'posts_per_page'   => 1,
        'post_type'        => 'shop_order',
        'meta_key'         => 'merchantTransactionId',
        'meta_value'       => $_GET['merchantTransactionId'],
        'post_status' => array_keys( wc_get_order_statuses() )
      );
      $order = get_posts( $args );

      //Check first if we have any order with that transaction ID
      if( !empty( $order ) ) {
        $wc_order = wc_get_order( $order[0]->ID);

        // Case One: User Cancel the Order.
        if( strtolower( $_GET['transactionStatus'] ) === 'cancel' ) {

          $wc_order->update_status( 'cancelled', 'Order: '.$wc_order->ID.' Cancelled with the TxID: '. $_GET['merchantTransactionId'] );
          wp_redirect(wc_get_page_permalink('cart'));

          exit();
        }

        // Case Two: User Pay the Order.
        else if( strtolower( $_GET['transactionStatus'] ) === 'success' ){

          $wc_order->add_order_note( 'Payment Complete: TxID: '. $_GET['mfsTransactionId'] );
          $wc_order->payment_complete( $_GET['mfsTransactionId'] );

          // Add mfsTransactionId to Order to place a Success in Case refund Option:
          update_post_meta( $wc_order->get_order_number(), 'mfsTransactionId', $_GET['mfsTransactionId'] );

          wp_redirect(wc_get_page_permalink('checkout').'order-received/'.$wc_order->get_order_number().'/?key='.$wc_order->order_key);
          // WC()->cart->empty_cart();
          // wp_redirect();

          exit();
        }
      } else {

        // if not post found to check, go to cart:
        wp_redirect(wc_get_page_permalink('cart'));

        exit();
      }
    }
    exit();
  }

  /**
   * Actions buttons to add on the admin Dashboard
   * @param  Array $actions
   * @param  Int   $order   
   * @return Array          
   */
  function wc_tigo_reverse_transaction( $actions, $order ) {

    if ( $order->has_status( array( 'processing', 'completed' ) ) ) { // if order is not cancelled yet...
        $actions['refund'] = array(
            'url'       => wp_nonce_url( admin_url( 'admin-ajax.php?action=tigo_wc_made_refund&order_id=' . $order->id ) ),
            'name'      => __( 'Refund', 'woocommerce' ),
            'action'    => "view refund", // setting "view" for proper button CSS
        );
    }
    return $actions;
  }
  
  /**
   * Style for the CSS on the Refund Button
   */
  function add_refund_order_actions_button_css() {
      echo '<style>.view.refund::after { content: "\f531" !important; }</style>';
  }
  
  /**
   * Function to make the refund against the Server through the DEL HTTP API
   * @return function
   */
  public function tigo_wc_made_refund($order = ''){
    ob_clean();
    $this->log($order);
    include_once( 'class-wc-gateway-tigo-request.php' );
    $tigo_request = new Tigo_Money_Payment_Gateway_Class_Request( $this );

    if( $order ){
      $wc_order = wc_get_order( $order );
    } else {
      $wc_order = wc_get_order( $_GET['order_id'] );
    }

    // GOT TOKEN!
    $token = $tigo_request->get_bearer_token( $wc_order , $this->debug );
    if( $this->debug ){
        $this->log('TOKEN DEL HTTP API: '.$token );
      }
    // Get TxID
    $tx_id = get_post_meta($wc_order->get_order_number(), 'merchantTransactionId', true );

    //buildind the arguments
    $args = array(
      'headers' => array(
        'cache-control' => 'no-cache',
        'content-type' => 'application/json',
        'authorization' => 'Bearer '. $token
        ),
      'method' => 'DELETE'
    );

    // URL
    if ( $this->testmode ) {
      $url = 'https://securesandbox.tigo.com/v2/tigo/mfs/payments/transactions/'. $tigo_request->convert_country_code( $wc_order->billing_country ).'/'. get_post_meta($wc_order->get_order_number(), 'mfsTransactionId', true ) .'/'. ( !empty($tx_id) ? $tx_id : 'null');
      if( $this->debug ){
        $this->log('DEL HTTP API: '.$url);
      }
    } else {
      $url = '';
    }
    // DEL HTTP
    $response = wp_remote_request( $url , $args );
    $body = wp_remote_retrieve_body( $response );
    if( $this->debug ){
      $this->log('Response Body: '.$body);
    }
    $body = json_decode( $body, true );

    //var_dump($body);
    //
    // Validacion de DEL para hacer el REFUND en WooCommerce
    if( strtolower( $body['status'] ) === 'error' ){
      if( strtolower( $body['error_description'] ) === 'transaction already reversed') {
        $wc_order->update_status( 'refunded', 'Order: '.$wc_order->ID.' Already Refunded');
      }
    } else {
      $wc_order->update_status( 'refunded', $body['Transaction']['message']);
      update_post_meta( $wc_order->get_order_number() , 'mfsReverseTransactionId', $body['Transaction']['mfsReverseTransactionId'] );
    }

    wp_redirect( admin_url( 'edit.php?post_type=shop_order' ) );
    wp_die();
  }

  /**
   * email to admin to check latest Core Update
   * @return [type] [description]
   */
  function check_updates_from_wp() {
    global $woocommerce;
    /**
     * Get Variables
     * @var [type]
     */
    $options = array();
    $options = get_option('tigo_checkers');

    // current Version of WP
    $wp_version = get_bloginfo('version');
    
    /**
     * Check if New Version are available
     */
    $url_checker = 'https://api.wordpress.org/core/version-check/1.7/';
    $response = wp_remote_post(
                  $url_checker
                );
    $body = wp_remote_retrieve_body( $response );
    $body = json_decode( $body, true );
    
    $wp_checker = $body['offers'][0]['version'];

    /**
     * Checking version current vs remote version
     */
    if( $options['wp_core_checker'] == false ) {

      if( version_compare( $wp_version, $wp_checker, '<' ) ) {
        /**
         * Friendly email to: Admin knowing that needs to update.
         */
        wp_mail(
          get_option('admin_email'),
          'Tigo Money: WordPress Sites Core Update',
          'Howdy!<br>This is a friendly reminder that your WordPress Sites Runs a '. $wp_version .'.<a href="'. admin_url( 'update-core.php' ) .'">Click Here</a> to update Your Core.<br><br>Tigo Money Team.',
          array('Content-Type: text/html; charset=UTF-8')
          );

        $options['wp_core_checker'] = true;
        update_option('tigo_checkers', $options );

      } else if( version_compare( $wp_version, $wp_checker, '>=' ) ) {
        $options['wp_core_checker'] = false;
        update_option('tigo_checkers', $options );
      }
    } else {
      if($this->debug){
        $this->log('already sent Email Regarding WP CORE UPDATE');
      }
    }
  }

  /**
   * email to admin to check WooCommerce
   * @return [type] [description]
   */
  function check_updates_from_wc() {
    global $woocommerce;
    /**
     * Get Variables
     * @var [type]
     */
    $options = array();
    $options = get_option('tigo_checkers');

    // current Version of WooCommerce
    $wc_version = $woocommerce->version;



    /**
     * Check if New Version are available
     */
    require_once( ABSPATH . 'wp-admin/includes/plugin-install.php' );
    // set the arguments to get latest info from repository via API ##
    $args = array(
        'slug' => 'woocommerce',
        'fields' => array(
            'version' => true,
        )
    );
    /** Prepare our query */
    $call_api = plugins_api( 'plugin_information', $args );
    
    /** Check for Errors & Display the results */
    if ( is_wp_error( $call_api ) ) {

        $api_error = $call_api->get_error_message();

    } else {
        //echo $call_api; // everything ##
        if ( ! empty( $call_api->version ) ) {
            $version_latest = $call_api->version;
        }
    }

    /**
     * Checking version current vs remote version
     */
    if( $options['wc_core_checker'] == false ) {

      if( version_compare( $wc_version, $version_latest, '<' ) ) {
        /**
         * Friendly email to: Admin knowing that needs to update.
         */
        wp_mail(
          get_option('admin_email'),
          'Tigo Money: WooCommerce Plugin Update',
          'Howdy!<br>This is a friendly reminder that your WordPress Sites Runs a '. $wc_version .'.<a href="'. admin_url( 'update-core.php' ) .'">Click Here</a> to update Your WooCommerce.<br><br>Tigo Money Team.',
          array('Content-Type: text/html; charset=UTF-8')
          );

        $options['wc_core_checker'] = true;
        update_option('tigo_checkers', $options );

      } else if( version_compare( $wc_version, $version_latest, '>=' ) ) {
        $options['wc_core_checker'] = false;
        update_option('tigo_checkers', $options );
      }
    } else {
      if($this->debug){
        $this->log('already sent Email Regarding WC Plugin Update');
      }
    }
  }

  function edit_order_detail() {
    if( $_POST['order_status'] === 'wc-refunded' ) {
      $wc_order = wc_get_order( $_POST['post_ID'] );
      $wc_order->add_order_note( 'Edit Order Details '. $_POST['post_ID'] );
      //do_action('tigo_wc_made_refund', $wc_order->get_order_number() );
      $this->tigo_wc_made_refund($wc_order->get_order_number());
    }
  }
  
}