<?php
/**
 * Plugin Name:       Boundless Shipstation Utility
 * Description:
 * Version:           0.1.0
 * Author:            DarnGood
 * Text Domain:       boundless-shipstation-utility
 * Domain Path:       /languages
 */

defined('SSU_SS_BASEURL') || define('SSU_SS_BASEURL', 'https://ssapi6.shipstation.com');
defined('SSU_SKU_SEARCHTERM') || define('SSU_SKU_SEARCHTERM', 'DOD');


if ( class_exists('ACF') ) {
    // note that the below code assumes there's an /acf-json/ directory
    // in the same directory the code is located.


    // Save ACF fields automatically
    add_filter( 'acf/settings/save_json', function() {
        return dirname(__FILE__) . '/acf-json';
    });

    // Load ACF fields automatically
    add_filter( 'acf/settings/load_json', function( $paths ) {
        $paths[] = dirname( __FILE__ ) . '/acf-json';
        return $paths;
    });
}


add_action('rest_api_init', function () {
    register_rest_route( 'shipstation-utility/v1', 'neworder',array(
            'methods'  => 'POST',
            'callback' => 'ssu_receive_shipstation_webhook_payload'
    ));
});


function ssu_receive_shipstation_webhook_payload($request) {
    $params = $request->get_params();
    $new_post_id = null;

    // check that we received the needed params
    if (!isset($params['resource_type']) || !isset($params['resource_url']) ) return;

    switch ($params['resource_type']) {
        case 'ORDER_NOTIFY':
            $new_post_id = ssu_process_new_order($params);
        break;
        // for potential future use, these are Shipstation's other resource_type's
        // case 'ITEM_ORDER_NOTIFY': break;
        // case 'SHIP_NOTIFY': break;
        // case 'ITEM_SHIP_NOTIFY': break;
    }

    return $new_post_id;
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
    // update_field('new_orders_response', json_encode($orderData), $post_id);
    update_field('new_orders_response', $orderData, $post_id);


    // check for Action Scheduler plugin
    if (function_exists( 'action_scheduler_register_3_dot_1_dot_6')) {
        // schedule task with action scheduler
        as_enqueue_async_action('ssu_handle_new_batch_of_orders', array($post_id, "action-scheduler-task"));
    } else {
        // schedule task with wp_cron jobs
        wp_schedule_single_event( time(), 'ssu_handle_new_batch_of_orders', array($post_id, "wp-cron-task") );
    }


    return $post_id;
}


// Define action to be called with 'wp_schedule_single_event'
add_action( 'ssu_handle_new_batch_of_orders', 'ssu_process_batch_of_orders', 10, 2);


// Processes a batch of orders received from shipstation resource_url
function ssu_process_batch_of_orders($batch_id, $type = null) {
    $orders = get_field('new_orders_response', $batch_id);

    // get orders from batch post's json
    $orderDetails = json_decode($orders, true);

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
}

// Parses a single order for any needed changes
function ssu_parse_single_order_for_changes($post_id) {
    $sku_match = SSU_SKU_SEARCHTERM;
    $changes = array();
    $order = json_decode(get_field('original_order_details', $post_id), true);
    $original_order_items = array();
    $new_order_items = array();

    // if only 1 item, no need to do anything
    if (count($order['items']) <= 1) return $changes;

    // look for SKUs that start with
    foreach ($order['items'] as $key => $item) {
        // check if SKU meets criteria
        if (strpos($item['sku'], $sku_match) !== false ) {
            $new_order_items[] = $item; // add item to new order
        } else {
            $original_order_items[] = $item;
        }
    }

    // check that we need to split the order (ie: both arrays need item content)
    if (empty($new_order_items) || empty($original_order_items)) return $changes;

    // duplicate original, set items, unset order-unique fields
    $new_order = $order;
    $new_order['items'] = $new_order_items;
    unset($new_order['orderKey']);

    // replace items for original order
    $order['items'] = $original_order_items;

    // update original order at ss
    $update_response = ssu_update_ss_order_details($order);
    // save updated order acf
    update_field('updated_order_details', $update_response, $post_id);

    // create new order at ss
    $create_response = ssu_update_ss_order_details($new_order);
    // save new order acf
    update_field('additional_order_details', $create_response, $post_id);

    $changes['update'] = $update_response;
    $changes['create'] = $create_response;
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
    $response = wp_remote_get(htmlentities($url), $args );
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
    $response = wp_remote_post($url, $args);
    $body = wp_remote_retrieve_body($response);
    return $body;
}