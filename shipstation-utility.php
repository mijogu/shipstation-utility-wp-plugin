<?php
/**
 * Plugin Name:       Boundless Shipstation Utility
 * Description:
 * Version:           0.1.1
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


// get store data
function ssu_get_store_data($store_id = null) {
    $stores = array(
        '385230' => array(
            'skuPattern' => 'DOD',
            'notificationEmail' => 'mike@darngood.io'
        )
    );

    if ($store_id != null && isset($stores[$store_id])) {
        return $stores[$store_id];
    } else {
        return $stores;
    }
}


// Processes a batch of orders received from shipstation resource_url
function ssu_process_batch_of_orders($batch_id, $type = null) {
    $orders = get_field('new_orders_response', $batch_id);

    // get orders from batch post's json
    $orderDetails = json_decode($orders, true);

    // loop thru all orders
    foreach ($orderDetails['orders'] as $order) {
        // get all store data
        $stores = ssu_get_store_data();

        // confirm StoreID is one we care about
        if (!isset($stores[$order['advancedOptions']['storeId']])) {
            continue;
        }

        // confirm no Order Post already exists for this ID
        $order_post = get_page_by_title($order['orderId']);
        if (count($order_post) > 0) {
            continue;
        }

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
        $response = ssu_parse_single_order_for_changes($post_id);
    }
}

// Parses a single order for any needed changes
function ssu_parse_single_order_for_changes($post_id) {
    $sku_match = SSU_SKU_SEARCHTERM;
    $order = json_decode(get_field('original_order_details', $post_id), true);
    $revised_order_items = array();
    $special_order_items = array();

    // look for SKUs that start with
    foreach ($order['items'] as $key => $item) {
        // check that SKU does NOT match the search term(s)
        if (strpos($item['sku'], $sku_match) !== false ) {
            $special_order_items[] = $item;
        } else {
            $revised_order_items[] = $item;
        }
    }

    // check if there are less revised items, update the SS order
    // if there are zero revised items (only special products), delete the SS order
    if (empty($revised_order_items)) {
        // send delete request
        $delete_response = ssu_delete_ss_order($order['orderId']);
        // save delete response
        update_field('updated_order_details', $delete_response, $post_id);
        return 'order deleted';
    } elseif (count($order['items']) > count($revised_order_items)) {
        // replace items for original order
        $order['items'] = $revised_order_items;
        // update original order at ss
        $update_response = ssu_update_ss_order($order);
        // save updated order acf
        update_field('updated_order_details', $update_response, $post_id);
        return 'order updated';
    }

    // check if there were special items that need to be emailed
    if (!empty($special_order_items)) {
        ssu_send_special_products_email($special_order_items, $order);
    }
}

function ssu_send_special_products_email($items, $original_order) {
    // TODO get the appropriate email address based on StoreID
    $toEmail = 'mike@darngood.io';
    $message = '';

    // loop thru the items
    foreach ($items as $item) {
        $message .= "<p>"
            . "OrderItemID: " . $item['orderItemId'] . "<br>"
            . "Item Name: " . $item['name'] . "<br>"
            . "Item SKU: " . $item['sku'] . "<br>"
            . "Quantity: " . $item['quantity'] . "<br>"
            . "</p>";
    }

    $subject = 'Boundless Utility: Special Products Ordered';
    $headers = array('Content-Type: text/html; charset=UTF-8');
    wp_mail( $toEmail, $subject, $message, $headers );
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
function ssu_update_ss_order($order_data) {
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


// Delete a Shipstation Order
// https://www.shipstation.com/docs/api/orders/create-update-order/
function ssu_delete_ss_order($order_id) {
    $url = SSU_SS_BASEURL . '/orders/' . $order_id;

    // update original ss order
    $args = array(
        'method' => 'DELETE',
        'httpversion' => '1.1',
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode( '9643ce8bd18945da93a3769a85c2b2b5' . ':' . '5fb3c382e9cb4dff9dc0c009987cc0ca' )
        ),
        'body' => wp_json_encode($order_data)
    );
    $response = wp_remote_request($url, $args);
    $body = wp_remote_retrieve_body($response);
    return $body;
}