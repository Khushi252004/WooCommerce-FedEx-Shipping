<?php
class FedEx_API {

    public static function get_rates($package, $settings) {

        // Get OAuth Token
        $token = FedEx_OAuth::get_token(
            $settings['api_key'],
            $settings['secret_key'],
            $settings['base_url']
        );

        if (!$token) {
            error_log("FedEx Token Failed");
            return false;
        }

        // Calculate total weight & max dimensions
        $total_weight = 0;
        $length = 0;
        $width  = 0;
        $height = 0;
        $missing_data = false;
        

        $weight_unit = get_option('woocommerce_weight_unit');

        if ($weight_unit === 'lbs') {
            $fedex_weight_unit = 'LB';
        } else {
            $fedex_weight_unit = 'KG';
        }
        
        $dimension_unit = get_option('woocommerce_dimension_unit');

        if ($dimension_unit === 'in') {
            $fedex_dimension_unit = 'IN';
        } else {
            $fedex_dimension_unit = 'CM';
        }

        foreach ($package['contents'] as $item) {
            $product = $item['data'];
            $qty = $item['quantity'];

            $w = (float) $product->get_weight();
            $l = (float) $product->get_length();
            $wd = (float) $product->get_width();
            $h = (float) $product->get_height();
       
            if (empty($w) || empty($l) || empty($wd) || empty($h)) {
                $missing_data = true;
                break;
            }

            $total_weight += ($w * $qty);

            // Take max box dimension
            $length = max($length, $l);
            $width  = max($width, $wd);
            $height = max($height, $h);
        }

        if ($missing_data) {
              return false;
        }

        // checkout
        $dest_postcode = $package['destination']['postcode'];
        $dest_country  = $package['destination']['country'];

        // Shipper (store address)
        $origin_postcode = get_option('woocommerce_store_postcode');
        $origin_country  = get_option('woocommerce_default_country');
        $origin_country  = explode(':', $origin_country)[0];
        
        // FedEx Rate API endpoint
            $url = $settings['base_url'] . '/rate/v1/rates/quotes';
        
        // Build request body 
        $body = array(
            "accountNumber" => array(
                "value" => $settings['account_number']
            ),
            "requestedShipment" => array(
                "serviceType"  => "FEDEX_GROUND",
                "shipper" => array(
                    "address" => array(
                        "postalCode" => $origin_postcode,
                        "countryCode" => $origin_country
                    )
                ),
                "recipient" => array(
                    "address" => array(
                        "postalCode" => $dest_postcode,
                        "countryCode" => $dest_country
                    )
                ),
                "pickupType" => "DROPOFF_AT_FEDEX_LOCATION",
                "rateRequestType" => array("ACCOUNT"),
                "requestedPackageLineItems" => array(
                    array(
                        "weight" => array(
                            "units" => $fedex_weight_unit,
                            "value" => $total_weight
                        ),
                        "dimensions" => array(
                            "length" => $length,
                            "width"  => $width,
                            "height" => $height,
                            "units"  => $fedex_dimension_unit
                        )
                    )
                )
            )
        );

        // Call FedEx API
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                 'X-locale'      => 'en_US'
            ),
            
            'body' => json_encode($body),
            'timeout' => 8
        ));
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($status_code != 200) {
        return false;
    }

        if (!empty($data['output']['rateReplyDetails'])) {

            $cost = null;
            foreach ($data['output']['rateReplyDetails'] as $rate) {
                $price = $rate['ratedShipmentDetails'][0]['totalNetCharge'] ?? null;

                if ($price && ($cost === null || $price < $cost)) {
                    $cost = $price;
                }
            }
            if ($cost !== null) {
                return ['cost' => $cost];
            }
        }
        else{
            return false;
        }
}

public static function create_shipment($order_id, $settings) {

    $order = wc_get_order($order_id);
    if (!$order) return false;

    $token = FedEx_OAuth::get_token(
        $settings['api_key'],
        $settings['secret_key'],
        $settings['base_url']
    );

    if (!$token) {
        error_log("FedEx Shipment Token Failed");
        return false;
    }

    $shipping = $order->get_address('shipping');

    // Store (Shipper) Details
    $origin_country  = explode(':', get_option('woocommerce_default_country'))[0];
    $origin_state    = explode(':', get_option('woocommerce_default_country'))[1] ?? '';
    $origin_postcode = get_option('woocommerce_store_postcode');
    $origin_city     = get_option('woocommerce_store_city');
    $origin_address  = get_option('woocommerce_store_address');

    // Calculate total weight from order
    $total_weight = 0;
    foreach ($order->get_items() as $item) {
        $product = wc_get_product($item['product_id']);
        if ($product && $product->get_weight()) {
            $total_weight += ((float)$product->get_weight() * $item->get_quantity());
        }
    }

    if ($total_weight <= 0) {
        $total_weight = 1; // fallback safety
    }

    $url = $settings['base_url'] . '/ship/v1/shipments';

    $body = [
        "labelResponseOptions" => "LABEL",
        "accountNumber" => [
            "value" => $settings['account_number']
        ],
        "requestedShipment" => [

            "shipDateStamp" => date('Y-m-d'),
            "pickupType"    => "DROPOFF_AT_FEDEX_LOCATION",
            "serviceType"   => "FEDEX_GROUND",
            "packagingType" => "YOUR_PACKAGING",

            // REQUIRED
            "shippingChargesPayment" => [
                "paymentType" => "SENDER",
                "payor" => [
                    "responsibleParty" => [
                        "accountNumber" => [
                            "value" => $settings['account_number']
                        ],
                        "countryCode" => $origin_country
                    ]
                ]
            ],
            "labelSpecification" => [
            "labelFormatType" => "COMMON2D",
            "imageType"       => "PDF",
            "labelStockType"  => "PAPER_4X6"
        ],

            // SHIPPER
            "shipper" => [
                "contact" => [
                    "personName"  => get_bloginfo('name'),
                    "phoneNumber" => "1234567890"
                ],
                "address" => [
                    "streetLines" => [$origin_address],
                    "city" => $origin_city, 
                    "stateOrProvinceCode" => $origin_state,
                    "postalCode" => $origin_postcode,
                    "countryCode" => $origin_country
                ]
            ],

            // RECIPIENT
            "recipients" => [[
                "contact" => [
                    "personName"  => $shipping['first_name'] . ' ' . $shipping['last_name'],
                    "phoneNumber" => $order->get_billing_phone()
                ],
                "address" => [
                    "streetLines" => [$shipping['address_1']],
                    "city" => $shipping['city'],
                    "stateOrProvinceCode" => $shipping['state'],
                    "postalCode" => $shipping['postcode'],
                    "countryCode" => $shipping['country']
                ]
            ]],

            // PACKAGE
            "requestedPackageLineItems" => [[
                "weight" => [
                    "units" => "KG",
                    "value" => $total_weight
                ]
            ]]
        ]
    ];

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'X-locale'      => 'en_US'
        ],
        'body'    => json_encode($body),
        'timeout' => 8
    ]);

    if (is_wp_error($response)) {
        error_log("FedEx Shipment WP Error: " . $response->get_error_message());
        return false;
    }

    $status = wp_remote_retrieve_response_code($response);
    $data   = json_decode(wp_remote_retrieve_body($response), true);

    if ($status != 200) return false;

    $tracking = $data['output']['transactionShipments'][0]['pieceResponses'][0]['trackingNumber'] ?? null;
    $label    = $data['output']['transactionShipments'][0]['pieceResponses'][0]['packageDocuments'][0]['encodedLabel'] ?? null;

    if (!$tracking) return false;

    return [
        'tracking_number' => $tracking,
        'label' => $label
    ];
}

}