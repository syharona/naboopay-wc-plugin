<?php
/**
 * Plugin Name: Naboopay Payment Gateway
 * Plugin URI: https://example.com/
 * Description: A custom payment gateway for WooCommerce integrating Naboopay API.
 * Version: 1.0.2
 * Author: Harouna Sy
 * Author URI: https://www.linkedin.com/in/harouna-sy-154344168/
 * License: GPL-2.0+
 * Text Domain: naboopay-gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    function my_custom_gateway_init() {
        if (!class_exists('WC_Payment_Gateway')) return;

        class WC_Gateway_My_Custom extends WC_Payment_Gateway {

            public function __construct() {
                $this->id = 'my_custom_gateway';
                $this->icon = ''; // URL of the gateway icon
                $this->has_fields = true;
                $this->method_title = __('Naboopay Gateway', 'naboopay-gateway');
                $this->method_description = __('Custom Payment Gateway for WooCommerce integrating Naboopay.', 'naboopay-gateway');

                $this->init_form_fields();
                $this->init_settings();

                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');
                $this->enabled = $this->get_option('enabled');
                $this->api_token = $this->get_option('api_token');
                $this->webhook_url = $this->get_option('webhook_url');
                $this->secret_key = $this->get_option('secret_key');  // Added secret key

                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            }

            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'naboopay-gateway'),
                        'type' => 'checkbox',
                        'label' => __('Enable Naboopay Payment', 'naboopay-gateway'),
                        'default' => 'yes'
                    ),
                    'title' => array(
                        'title' => __('Title', 'naboopay-gateway'),
                        'type' => 'text',
                        'description' => __('Title shown during checkout.', 'naboopay-gateway'),
                        'default' => __('Naboopay', 'naboopay-gateway'),
                        'desc_tip' => true,
                    ),
                    'description' => array(
                        'title' => __('Description', 'naboopay-gateway'),
                        'type' => 'textarea',
                        'description' => __('Description shown during checkout.', 'naboopay-gateway'),
                        'default' => __('Payez via WAVE, ORANGE MONEY et FREE MONEY en toute sécurité', 'naboopay-gateway'),
                    ),
                    'api_token' => array(
                        'title' => __('API Token', 'naboopay-gateway'),
                        'type' => 'text',
                        'description' => __('Your Naboopay API Token.', 'naboopay-gateway'),
                        'default' => ''
                    ),
                    'webhook_url' => array(
                        'title' => __('Webhook URL', 'naboopay-gateway'),
                        'type' => 'text',
                        'description' => __('URL for handling Naboopay webhook notifications.', 'naboopay-gateway'),
                        'default' => ''
                    ),
                    'secret_key' => array(  // New field for secret key
                        'title' => __('Webhook Secret Key', 'naboopay-gateway'),
                        'type' => 'text',
                        'description' => __('Your secret key for verifying webhook signatures.', 'naboopay-gateway'),
                        'default' => ''
                    ),
                );
            }

            public function process_payment($order_id) {
                $order = wc_get_order($order_id);
            
                // Prepare the product data for Naboopay
                $products = array();
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                    $products[] = array(
                        'name' => $product->get_name(),
                        'category' => 'General',
                        'amount' => $product->get_price(),
                        'quantity' => $item->get_quantity(),
                        'description' => $product->get_description(),
                    );
                }
            
                // Prepare Naboopay transaction request data
                $payment_data = array(
                    'method_of_payment' => array('WAVE'),  // Customize based on user choice
                    'products' => $products,
                    'is_escrow' => false,
                    'success_url' => $this->get_return_url($order),  // WooCommerce success URL
                    'error_url' => wc_get_checkout_url() . '?payment_error=true',  // URL for errors
                );
            
                // Send API request to Naboopay to create a transaction
                $response = $this->create_naboopay_transaction($payment_data);
            
                if ($response && isset($response->checkout_url)) {
                    // A custom order field naboo_order_id must exist first
                    $order->update_meta_data( 'naboo_order_id', $response->order_id );
                    $order->save();
                    // Redirect the user to the Naboopay checkout page
                    return array(
                        'result' => 'success',
                        'redirect' => $response->checkout_url,
                    );
                } else {
                    // Handle the error if the request to Naboopay fails
                    wc_add_notice(__('Payment error:', 'naboopay-gateway') . ' ' . $response->message, 'error');
                    return array('result' => 'fail');
                }
            }

            private function create_naboopay_transaction($payment_data) {
                $api_url = 'https://api.naboopay.com/api/v1/transaction/create-transaction';
                $api_token = $this->api_token;

                $args = array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $api_token,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ),
                    'body' => json_encode($payment_data),
                    'method' => 'PUT',
                );

                $response = wp_remote_request($api_url, $args);
                if (is_wp_error($response)) {
                    return false;
                }

                return json_decode(wp_remote_retrieve_body($response));
            }
        }
    }

    add_filter('woocommerce_payment_gateways', 'add_my_custom_gateway');

    function add_my_custom_gateway($gateways) {
        $gateways[] = 'WC_Gateway_My_Custom';
        return $gateways;
    }

    add_action('plugins_loaded', 'my_custom_gateway_init', 11);

    // Webhook handler
    add_action('rest_api_init', function() {
        register_rest_route('naboopayorders/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => 'naboopayorders_handle_webhook',
        ));
    });

    function naboopayorders_handle_webhook(WP_REST_Request $request) {
        // Get the raw request body
        $request_body = $request->get_body();
        // $data = json_decode($request_body, true);
        // $normalized_json = json_encode($data, JSON_PRETTY_PRINT);

        // Get the signature from the headers (assuming Naboopay uses a signature header, e.g., X-Naboopay-Signature)
        $received_signature = (string) $request->get_header('x_signature');

        // Retrieve the secret key from the plugin options
        $secret_key = "qh2spgnIj4xZboZWQmTAr6DMwgUCul9p";

        // Generate the expected signature using HMAC-SHA256
        $expected_signature = hash_hmac('sha256', $request_body, $secret_key);

        // Verify if the received signature matches the expected signature
        if (!hash_equals($expected_signature, $received_signature)) {
            return new WP_REST_Response("Invalid signature ". $secret_key . " e " . $expected_signature . " b " . $request_body ." r " .$received_signature, 403);  // Return 403 Forbidden if the signature doesn't match
        }

        // If signature is valid, proceed with webhook
        $params = $request->get_json_params();

        $order_id = $params['order_id'];
        $status = $params['transaction_status'];
        
        // Custom Query to get wc_order_id using naboopay order id
        $args = array(
            'meta_key'      => 'naboo_order_id',
            'meta_value'    => $order_id,
            'meta_compare'  => '=',
            'return'        => 'ids' 
        );
        
        // Get order ids
        $orders = wc_get_orders( $args );

        if (!$orders) {
            return new WP_REST_Response('Order not found', 404);
        }
        
        foreach ( $orders as $order ) {
            // Fetch the order using its id
            $item = wc_get_order($order);

            switch ($status) {
                case 'paid':
                    $item->payment_complete();
                    $item->add_order_note(__('Payment completed via Naboopay.', 'naboopay-gateway'));
                    break;
                case 'cancel':
                    $item->update_status('cancelled', __('Payment cancelled via Naboopay.', 'naboopay-gateway'));
                    break;
                case 'pending':
                    $item->update_status('pending', __('Payment pending via Naboopay.', 'naboopay-gateway'));
                    break;
                case 'part_paid':
                    $item->update_status('on-hold', __('Payment partially paid via Naboopay.', 'naboopay-gateway'));
                    break;
            }
    
            return new WP_REST_Response('Webhook received', 200);
        }

        
    }
}
