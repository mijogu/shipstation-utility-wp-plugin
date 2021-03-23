<?php
/**
 * Plugin Name:       Boundless Shipstation Utility
 * Description:
 * Version:           0.1.0
 * Author:            DarnGood
 * Text Domain:       boundless-shipstation-utility
 * Domain Path:       /languages
 */

defined('SSU_SS_BASEURL') || define('SSU_SS_BASEURL', 'https://https://ssapi6.shipstation.com');
defined('SSU_SKU_SEARCHTERM') || define('SSU_SKU_SEARCHTERM', 'DOD');


add_action('rest_api_init', function () {
    register_rest_route( 'shipstation-utility/v1', 'neworder',array(
            'methods'  => 'POST',
            'callback' => 'ssu_receive_shipstation_webhook_payload'
    ));
});



function ssu_receive_shipstation_webhook_payload($request) {
    $params = $request->get_params();

    // check that we received the needed params
    if (!isset($params['resource_type']) || !isset($params['resource_url']) ) return;

    switch ($params['resource_type']) {
        case 'ORDER_NOTIFY':
            ssu_process_new_order($params);
        break;
        // for potential future use, these are Shipstation's other resource_type's
        // case 'ITEM_ORDER_NOTIFY': break;
        // case 'SHIP_NOTIFY': break;
        // case 'ITEM_SHIP_NOTIFY': break;
    }
}

function ssu_process_new_order($params) {
    $date = new DateTime();

    // break apart the resource_url into pieces
    $url_components = parse_url($params['resource_url']);
    parse_str($url_components['query'], $url_params);

    // create post to save payload, with batch id as title
    $new_post = array(
        'post_title' => $url_params['importBatch'],
        'post_content' => json_encode($params),
        'post_type' => 'post',
    );
    $post_id = wp_insert_post($new_post);

    // Use the payload to GET order(s) data
    $orderData = ssu_get_batch_details($params['resource_url']);

    // save acf field data
    update_field('new_orders_payload', json_encode($params), $post_id);
    update_field('new_orders_response', json_encode($orderData), $post_id);

    // kick off action that will process in background
    wp_schedule_single_event( time(), 'ssu_handle_new_batch_of_orders', array($post_id) );
    // wp_schedule_single_event( time() + 3600, 'ssu_parse_new_order', array() );

    return $post_id;
}


// Define action to be called with 'wp_schedule_single_event'
add_action( 'ssu_handle_new_batch_of_orders', 'ssu_process_batch_of_orders' );


// Processes a batch of orders received from shipstation resource_url
function ssu_process_batch_of_orders($batch_id) {
    // get orders from batch post's json
    $orderDetails = json_decode(get_field('original_order_details', $batch_id), true);

    // loop thru all orders
    foreach ($orderDetails['orders'] as $order) {
        // create new post
        $new_post = array(
            'post_title' => $order['orderId'],
            'post_content' => json_encode($order),
            'post_type' => 'post',
        );
        $post_id = wp_insert_post($new_post);

        // save initial acf data
        update_field('batch_post', $batch_id, $post_id);
        update_field('original_order_details', json_encode($order), $post_id);

        // parse order data for any changes needed
        $orderChanges = ssu_parse_single_order_for_changes($post_id);
    }
    // return $orderChanges;
}

// Parses a single order for any needed changes
function ssu_parse_single_order_for_changes($post_id) {
    $sku_match = SSU_SKU_SEARCHTERM;
    $changes = array();
    $new_order_items = array();
    $order = json_decode(get_field('original_order_details', $post_id), true);

    // if only 1 item, no need to do anything
    if (count($order['items']) <= 1) return $changes;

    // look for SKUs that start with
    foreach ($order['items'] as $key => $item) {
        // check if SKU meets criteria
        if (strpos($item['sku'], $sku_match) === false ) {
            $new_order_items[] = $item; // add item to new order
            unset($order['items'][$key]); // remove item from original order
        }
    }

    // return if no changes to make
    if (empty($new_order_items)) return $changes;

    // duplicate original, set items, unset order-unique fields
    // unset original fields
    $new_order = $order;
    $new_order['items'] = $new_order_items;
    unset($new_order['orderKey']);

    // encode json
    $order = json_encode($order);
    $new_order = json_encode($new_order);

    // update original order at ss
    ssu_update_ss_order_details($order);
    // save updated order acf
    update_field('updated_order_details', json_encode($order), $post_id);


    // create new order at ss
    ssu_update_ss_order_details($new_order);
    // save new order acf
    update_field('additional_order_details', json_encode($new_order), $post_id);

    return $changes;
}

// Get Batch Orders (from payload's resource_url)
// https://www.shipstation.com/docs/api/orders/get-order/
function ssu_get_batch_details($url = null) {
    $args = array(
        'httpversion' => '1.1',
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode( '9643ce8bd18945da93a3769a85c2b2b5' . ':' . '5fb3c382e9cb4dff9dc0c009987cc0ca' )
        )
    );
    $response = wp_remote_get( $url, $args );
    $body = wp_remote_retrieve_body($response);
    return $body;
}



// Create or Update a Shipstation Order
// https://www.shipstation.com/docs/api/orders/create-update-order/
function ssu_update_ss_order_details($order_data) {
    $url = SSU_SS_BASEURL . '/orders/createorder';

    // update original ss order
    $args = array(
        'httpversion' => '1.1',
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode( '9643ce8bd18945da93a3769a85c2b2b5' . ':' . '5fb3c382e9cb4dff9dc0c009987cc0ca' )
        ),
        'body' => wp_json_encode($order_data)
    );
    $response = wp_remote_post( $url, $args );
}





/*
API key/secret needs to be added to the store

API Key
9643ce8bd18945da93a3769a85c2b2b5

API Secret
5fb3c382e9cb4dff9dc0c009987cc0ca



You should be able to trigger a webhook when creating orders through
the open API. It is considered a new order within ShipStation so it
should behave the same way as a new order created within the UI

Use the CSV export for testing
*/