<?php

if (!defined('ABSPATH')) exit;

class WC_FedEx_Shipping_Method extends WC_Shipping_Method {

    public function __construct($instance_id = 0) {

        $this->id                 = 'fedex_shipping';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = 'FedEx Shipping';
        $this->method_description = 'FedEx Rates';

        $this->supports = array(
            'shipping-zones',
            'instance-settings',
        );

        $this->init();
    }

    public function init() {

        // IMPORTANT: instance fields
        $this->init_instance_form_fields();
        $this->init_settings();

        $this->title   = $this->get_option('title', 'FedEx Shipping');
        $this->enabled = $this->get_option('enabled', 'yes');

        add_action('admin_footer', array($this, 'admin_shipping_script'));
    }

    // Correct for shipping zones 
    public function init_instance_form_fields() {

        $this->instance_form_fields = array(

            'enabled' => array(
                'title'   => 'Enable',
                'type'    => 'checkbox',
                'default' => 'yes'
            ),

            'title' => array(
                'title'   => 'Title',
                'type'    => 'text',
                'default' => 'FedEx Shipping'
            ),

            'rate_type' => array(
                'title'   => 'Shipping Type',
                'type'    => 'select',
                'options' => array(
                    'fixed' => 'Fixed Rate',
                    'api'   => 'FedEx API Rate'
                ),
                'default' => 'fixed'
            ),

            'fixed_rate' => array(
                'title'    => 'Fixed Rate Amount',
                'type'     => 'number',
                'default'  => '50',
                'desc_tip' => 'Used only when Fixed Rate selected'
            ),

            'api_key' => array(
                'title' => 'API Key <span style="color:red">*</span>',
                'type'  => 'text',
            ),

            'secret_key' => array(
                'title' => 'Secret Key <span style="color:red">*</span>',
                'type'  => 'password',
            ),

            'account_number' => array(
                'title' => 'Account Number <span style="color:red">*</span>',
                'type'  => 'text',
            ),

            'base_url' => array(
                'title'       => 'FedEx API URL <span style="color:red">*</span>',
                'type'        => 'text',
                'description' => 'Add snandbox or production API URL',
                'default'     => 'https://apis-sandbox.fedex.com',
                'desc_tip'    => true,
            ),
        );
    }


    public function calculate_shipping($package = array()) {

        if ($this->enabled !== 'yes') {
            return;
        }

        $rate_type = $this->get_option('rate_type', 'fixed');
        $cost = 0;

        $valid_items = array();
        $missing_products = array();

        foreach ($package['contents'] as $item) {

            $product = $item['data'];

            if (
                !empty($product->get_weight()) &&
                !empty($product->get_length()) &&
                !empty($product->get_width())  &&
                !empty($product->get_height())
            ) {
                $valid_items[] = $item;
            } else {
                $missing_products[] = $product->get_name();
            }
        }

        $label = $this->title;
        $current_rate_id = $this->id . '_' . $this->instance_id;

        // If NO valid products
        if (empty($valid_items)) {

            // Show method with 0 cost
            $this->add_rate(array(
                'id'    => $current_rate_id,
                'label' => $label,
                'cost'  => 0,
            ));

            return;
        }

        // FIXED RATE
        if ($rate_type === 'fixed') {

            $fixed_rate = (float) $this->get_option('fixed_rate', 0);
            $quantity = 0;

            foreach ($valid_items as $values) {
                $quantity += $values['quantity'];
            }

            $cost = $fixed_rate * $quantity;
        }

        //API RATE
        elseif ($rate_type === 'api') {
            
            $valid_package = $package;
            $valid_package['contents'] = $valid_items;
            $settings = array(
                'api_key'        => $this->get_option('api_key'),
                'secret_key'     => $this->get_option('secret_key'),
                'account_number' => $this->get_option('account_number'),
                'base_url'       => $this->get_option('base_url'),
            );
            try{
            $response = FedEx_API::get_rates($valid_package,$settings);
            error_log('FedEx Rate Raw Response: ' . print_r($response, true));

            if ($response !== false && isset($response['cost']) && $response['cost'] > 0) {
                $cost = (float) $response['cost'];
                
            } else {
                $cost = (float) $this->get_option('fixed_rate', 40);
            }
             } catch (Exception $e) {

        error_log('FedEx API Exception: ' . $e->getMessage());

        // API crashed → fallback to fixed
        $cost = (float) $this->get_option('fixed_rate', 40);
    }

        }
        $this->add_rate(array(
            'id'    => $current_rate_id,
            'label' => $label,
            'cost'  => $cost,
        ));
    }

    public function admin_shipping_script() {

        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-settings') return;
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'shipping') return;
        ?>
        <script>
        jQuery(function($){

            var rateType  = $('#woocommerce_<?php echo $this->id; ?>_rate_type');
            var fixedRate = $('#woocommerce_<?php echo $this->id; ?>_fixed_rate');
            var apiKey    = $('#woocommerce_<?php echo $this->id; ?>_api_key');
            var secretKey = $('#woocommerce_<?php echo $this->id; ?>_secret_key');
            var accountNo = $('#woocommerce_<?php echo $this->id; ?>_account_number');
            var baseUrl   = $('#woocommerce_<?php echo $this->id; ?>_base_url');
        
            function toggleFixedRateField() {

            if (!rateType.length || !fixedRate.length) return;

                var row = fixedRate.closest('tr');

                if (rateType.val() === 'fixed') {
                    row.show();
                } else {
                    row.hide();
                }
            }

            // Initial load
            toggleFixedRateField();

            // On change
            rateType.on('change', function(){
                toggleFixedRateField();
            });

            function showError(field, message) {

                var row = field.closest('tr');

                row.removeClass('woocommerce-validated');
                row.addClass('woocommerce-invalid');

                row.find('.fedex-error-msg').remove();

                field.after('<p class="fedex-error-msg" style="color:red; margin:5px 0 0;">'+ message +'</p>');
            }

            function clearError(field) {
                var row = field.closest('tr');
                row.removeClass('woocommerce-invalid');
                row.addClass('woocommerce-validated');
                row.find('.fedex-error-msg').remove();
            }

            $('form').on('submit', function(e){

                var hasError = false;

                $('.fedex-error-msg').remove();

                if (rateType.val() === 'api') {

                    if (apiKey.val().trim() === '') {
                        showError(apiKey, 'API Key is required.');
                        hasError = true;
                    } else {
                        clearError(apiKey);
                    }

                    if (secretKey.val().trim() === '') {
                        showError(secretKey, 'Secret Key is required.');
                        hasError = true;
                    } else {
                        clearError(secretKey);
                    }

                    if (accountNo.val().trim() === '') {
                        showError(accountNo, 'Account Number is required.');
                        hasError = true;
                    } else {
                        clearError(accountNo);
                    }

                    if (baseUrl.val().trim() === '') {
                        showError(baseUrl, 'FedEx API URL is required.');
                        hasError = true;
                    } else if (!/^https?:\/\/.+/.test(baseUrl.val())) {
                        showError(baseUrl, 'Please enter a valid URL.');
                        hasError = true;
                    } else {
                        clearError(baseUrl);
                    }
                }

                if (hasError) {
                    e.preventDefault();
                    return false;
                }
            });

        });
        </script>
        <?php
    }
}


add_action('woocommerce_after_shipping_rate', function($method, $index) {

    if (strpos($method->id, 'fedex_shipping') === false) {
        return;
    }

    $valid_found = false;
    $missing_products = array();

    foreach (WC()->cart->get_cart() as $item) {

        $product = $item['data'];

        if (
            !empty($product->get_weight()) &&
            !empty($product->get_length()) &&
            !empty($product->get_width())  &&
            !empty($product->get_height())
        ) {
            $valid_found = true;
        } else {
            $missing_products[] = $product->get_name();
        }
    }

    // ONLY show error if ALL products are missing
    if (!$valid_found && !empty($missing_products)) {

        $product_list = implode(', ', array_unique($missing_products));

        echo '<div class="fedex-error" style="display:none; color:red; margin-top:5px;">
        FedEx cannot calculate shipping because the following product(s) are missing weight or dimensions: 
        <strong>' . esc_html($product_list) . '</strong>.
        </div>';
    }

}, 10, 2);

add_action('wp_footer', function() {
    if (!is_checkout()) return;
    ?>
    <script>
    jQuery(function($){

        function toggleFedexError() {

            var selected = $('input[name^="shipping_method"]:checked').val();

            if (selected && selected.includes('fedex_shipping')) {
                $('.fedex-error').show();
            } else {
                $('.fedex-error').hide();
            }
        }

        // Run on page load
        toggleFedexError();

        // Run when shipping method changes
        $(document).on('change', 'input[name^="shipping_method"]', function(){
            toggleFedexError();
        });

        // Run after WooCommerce updates checkout via AJAX
        $(document.body).on('updated_checkout', function(){
            toggleFedexError();
        });

    });
    </script>
    <?php
});