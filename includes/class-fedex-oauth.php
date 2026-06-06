<?php

class FedEx_OAuth {

    public static function get_token($api_key, $secret_key,$base) {

        $url = $base . '/oauth/token';
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => http_build_query(array(
                'grant_type' => 'client_credentials',
                'client_id' => $api_key,
                'client_secret' => $secret_key
            )),
            'timeout' => 20
        ));
         if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['access_token'])) {
             return $body['access_token'];
        }

        return false;
    }
}
