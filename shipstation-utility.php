<?php
/**
 * Plugin Name:       Boundless Shipstation Utility
 * Description:
 * Version:           0.1.0
 * Author:            DarnGood
 * Text Domain:       boundless-shipstation-utility
 * Domain Path:       /languages
 */

add_action('rest_api_init', function () {
    register_rest_route( 'shipstation-utility/v1', 'neworder',array(
            'methods'  => 'POST',
            'callback' => 'ssu_receive_shipstation_webhook_payload'
    ));
});

/*
* payload will include resource_url and resource_type
{
    "resource_url" : "https://ssapiX.shipstation.com/orders?storeID=123456&importBatch=1ab23c4d-12ab-1abc-a1bc-a12b12cdabcd",
    "resource_type":"ORDER_NOTIFY"
}
*/

function ssu_receive_shipstation_webhook_payload($request) {
    $params = $request->get_params();

    // check that we received the needed params
    if (!isset($params['resource_type']) || !isset($params['resource_url']) ) return;

    switch ($params['resource_type']) {
        case 'ORDER_NOTIFY':
            ssu_process_new_order($params);
        break;
        // case 'ITEM_ORDER_NOTIFY': break;
        // case 'SHIP_NOTIFY': break;
        // case 'ITEM_SHIP_NOTIFY': break;
    }
}

function ssu_process_new_order($params) {
    $date = new DateTime();

    // create post with payload
    $new_post = array(
        'post_title' => 'new order '. $date->format('Y m d T h m s'),
        'post_content' => json_encode($params),
        'post_type' => 'post',
    );
    $post_id = wp_insert_post($new_post);

    // Use the payload to GET order data
    $orderData = ssu_get_ss_order_details($params['resource_url']);

    // save acf field data
    update_field('new_order_payload', json_encode($params), $post_id);
    update_field('original_order_details', json_encode($orderData), $post_id);

    // parse order data for any changes needed
    $orderChanges = ssu_parse_order_for_changes($post_id);

    if (isset($orderChanges['update'])) {
        // change the original order

        update_field('updated_order_details', json_encode($orderChanges['update']), $post_id);
    }

    if (isset($orderChanges['new'])) {
        // create a new order
    }

    return $request->get_params();
}

function ssu_parse_order_for_changes($post_id) {
    $orderChanges = array();
    $orderDetails = get_field('original_order_details', $post_id);

    return $orderChanges;
}


//https://www.shipstation.com/docs/api/orders/get-order/
function ssu_get_ss_order_details($url) {

    // $url = 'https://ssapi.shipstation.com/orders/645887787';
    $args = array(
        'httpversion' => '1.1',
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode( '9643ce8bd18945da93a3769a85c2b2b5' . ':' . '5fb3c382e9cb4dff9dc0c009987cc0ca' )
        )
    );
    $response = wp_remote_get( $url, $args );

    return wp_remote_retrieve_body($response);
}



//https://www.shipstation.com/docs/api/orders/create-update-order/
function ssu_update_ss_order_details($data) {
    // update original ss order
    $args = array(
        'httpversion' => '1.1',
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode( '9643ce8bd18945da93a3769a85c2b2b5' . ':' . '5fb3c382e9cb4dff9dc0c009987cc0ca' )
        ),
        'body' => wp_json_encode($data)
    );
    $response = wp_remote_post( $url, $args );

}

function ssu_add_new_ss_order($data) {
    // create new ss order
}





/*
API key/secret needs to be added to the store

API Key
9643ce8bd18945da93a3769a85c2b2b5

API Secret
5fb3c382e9cb4dff9dc0c009987cc0ca


GET /orders/orderId HTTP/1.1
Host: ssapi.shipstation.com
Authorization: Basic 9643ce8bd18945da93a3769a85c2b2b5:5fb3c382e9cb4dff9dc0c009987cc0ca

645887787
177282875



You should be able to trigger a webhook when creating orders through
the open API. It is considered a new order within ShipStation so it
should behave the same way as a new order created within the UI


Use the CSV export for testing
*/