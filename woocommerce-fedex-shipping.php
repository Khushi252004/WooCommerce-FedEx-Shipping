<?php
/**
 * Plugin Name: WooCommerce FedEx Shipping
 * Plugin URI:  https://example.com
 * Description: Custom FedEx shipping method for WooCommerce.
 * Version:     1.0
 * Author:      Khushi Kukadiya
 * License:     GPL v2 or later
 */


if (!defined('ABSPATH')) exit;

define('WC_FEDEX_PATH', plugin_dir_path(__FILE__));
define('WC_FEDEX_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', 'wc_fedex_init', 11);

function wc_fedex_init() {
    
    // Check WooCommerce active
    if (!class_exists('WC_Shipping_Method')) return;
 
    //  IMPORTANT: initialize shipping class
    add_action('woocommerce_shipping_init', 'wc_fedex_shipping_method_init');    

    // Register shipping method
    add_filter('woocommerce_shipping_methods', 'wc_add_fedex_shipping_method');
}
function wc_fedex_shipping_method_init() {
    require_once WC_FEDEX_PATH . 'includes/class-wc-fedex-shipping-method.php';
    require_once WC_FEDEX_PATH . 'includes/class-fedex-api.php';
    require_once WC_FEDEX_PATH . 'includes/class-fedex-oauth.php';
}

function wc_add_fedex_shipping_method($methods) {
    $methods['fedex_shipping'] = 'WC_FedEx_Shipping_Method';
    return $methods;
}

add_action('woocommerce_admin_order_data_after_shipping_address', function($order){

    $tracking = $order->get_meta('_fedex_tracking_number');

    if ($tracking) {
        echo '<p><strong>FedEx Tracking:</strong> ' . esc_html($tracking) . '</p>';
    }

});


add_action('woocommerce_order_status_processing', 'fedex_create_shipment_auto');

function fedex_create_shipment_auto($order_id) {

    $order = wc_get_order($order_id);
    if (!$order) return;

    // Prevent duplicate shipment
    if ($order->get_meta('_fedex_tracking_number')) {
        return;
    }

    // Get shipping method used in order
    $shipping_methods = $order->get_shipping_methods();
    $settings = [];

    foreach ($shipping_methods as $shipping_method) {

        // Only run if FedEx shipping method was selected
        if ($shipping_method->get_method_id() === 'fedex_shipping') {

            $instance_id = $shipping_method->get_instance_id();

            // Shipping zone instance settings option name
            $option_name = 'woocommerce_fedex_shipping_' . $instance_id . '_settings';

            $instance_settings = get_option($option_name);

            if (!empty($instance_settings)) {

                $settings = [
                    'api_key'        => $instance_settings['api_key'] ?? '',
                    'secret_key'     => $instance_settings['secret_key'] ?? '',
                    'account_number' => $instance_settings['account_number'] ?? '',
                    'base_url'       => $instance_settings['base_url'] ?? '',
                ];
            }

            break;
        }
    }

    //  If no FedEx shipping used OR settings missing
    if (empty($settings) || empty($settings['api_key'])) {
        error_log('FedEx shipment skipped - No valid settings found.');
        return;
    }

    // Create shipment
    $response = FedEx_API::create_shipment($order_id, $settings);

    if ($response && !empty($response['tracking_number'])) {

        // Save tracking number
        $order->update_meta_data('_fedex_tracking_number', $response['tracking_number']);
        $order->save();

        // Add order note
        $order->add_order_note(
            'FedEx Shipment Created. Tracking Number: ' . $response['tracking_number']
        );

        // Save label PDF (optional)
        if (!empty($response['label'])) {

            $upload_dir = wp_upload_dir();
            $file = trailingslashit($upload_dir['path']) . 'fedex-label-' . $order_id . '.pdf';

            file_put_contents($file, base64_decode($response['label']));
        }

        error_log('FedEx Shipment Success - Tracking: ' . $response['tracking_number']);

    } else {
        error_log('FedEx Shipment Failed for Order: ' . $order_id);
    }
}


//Show Tracking Number To Customer
add_action('woocommerce_order_details_after_order_table', 'fedex_show_tracking_to_customer');

function fedex_show_tracking_to_customer($order) {

    if (is_admin()) return;

    $tracking = $order->get_meta('_fedex_tracking_number');

    if (!$tracking) return;

    // Sandbox tracking link
    $tracking_url = 'https://www.fedex.com/fedextrack/?tracknumbers=' . $tracking;

    echo '<section class="woocommerce-order-tracking" style="margin-top:20px;">';
    echo '<h2>Shipment Tracking</h2>';
    echo '<p><strong>Carrier:</strong> FedEx</p>';
    echo '<p><strong>Tracking Number:</strong> 
            <a href="'. esc_url($tracking_url) .'" target="_blank">
                '. esc_html($tracking) .'
            </a>
          </p>';
    echo '</section>';
}