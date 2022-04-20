<?php
/**
 * Plugin Name:       Boundless Shipstation Utility
 * Description:
 * Version:           1.3
 * Author:            DarnGood
 * Text Domain:       boundless-shipstation-utility
 * Domain Path:       /languages
 */

defined('SSU_SS_BASEURL') || define('SSU_SS_BASEURL', 'https://ssapi6.shipstation.com');


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


// Add Options page
if( function_exists('acf_add_options_page') ) {

	acf_add_options_page(array(
		'page_title' 	=> 'Shipstation Utility Settings',
		'menu_title'	=> 'Shipstation Utility Settings',
		'menu_slug' 	=> 'shipstation-utility-settings',
		'capability'	=> 'edit_posts',
		'redirect'		=> false
	));
}


add_action('rest_api_init', function () {
    register_rest_route( 'shipstation-utility/v1', 'neworder', array(
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

    $batch_id = $url_params['importBatch'];
    $store_id = $url_params['storeID'];
    $store_data = ssu_get_store_data($store_id);

    // confirm no Batch Post already exists for the batch ID
    global $wpdb;
    $batch_post = $wpdb->get_results( $wpdb->prepare("SELECT * FROM $wpdb->posts WHERE  post_status = 'publish' AND post_title LIKE '%s'", '%'. $wpdb->esc_like( $batch_id ) .'%') );

    if (count($batch_post) > 0) {
        return 'This batch ID has already been processed';
    }

    // create post to save payload, with batch id as title
    $new_post = array(
        'post_title' => $url_params['importBatch'],
        'post_content' => json_encode($params),
        'post_type' => 'post',
    );
    $post_id = wp_insert_post($new_post);

    // Use the payload to GET order(s) data
    $orderData = ssu_get_batch_details($params['resource_url'], $store_data);

    // save acf field data
    update_field('new_orders_payload', json_encode($params), $post_id);
    update_field('new_orders_response', $orderData, $post_id);
    update_field('store_id', $store_id, $post_id);


    // check for Action Scheduler plugin
    if (function_exists( 'as_enqueue_async_action')) {
        // schedule task with action scheduler
        as_enqueue_async_action('ssu_handle_new_batch_of_orders', array($post_id, "action-scheduler-task"));
    } else {
        // schedule task with wp_cron jobs
        // TODO fix this, doesn't seem to be working
        wp_schedule_single_event( time() + 60, 'ssu_handle_new_batch_of_orders', array($post_id, "wp-cron-task") );
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

    // get all store data
    $stores = ssu_get_store_data();

    // loop thru all orders
    foreach ($orderDetails['orders'] as $order) {
        // get store ID from order
        $store_id = $order['advancedOptions']['storeId'];

        // confirm StoreID is one we care about
        if (!isset($stores[$store_id])) {
            continue;
        }

        // confirm no Order Post already exists for this ID
        global $wpdb;
        $order_post = $wpdb->get_results( $wpdb->prepare("SELECT * FROM $wpdb->posts WHERE  post_status = 'publish' AND post_title LIKE '%s'", '%'. $wpdb->esc_like( $order['orderId'] ) .'%') );

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
        update_field('store_id', $store_id, $post_id);
        update_field('original_order_details', json_encode($order), $post_id);

        // parse order data for any changes needed
        $response = ssu_parse_single_order_for_changes($post_id, $store_id);
    }
}


// get store data
function ssu_get_store_data($store_id = null) {
    $stores_data = get_field('shipstation_stores', 'option');

    // Set keys to store IDs
    $stores = array_reduce($stores_data, function ($result, $store) {
        $result[$store['store_id']] = $store;
        return $result;
    }, array());

    // only return specific store data if requested
    if ($store_id != null && isset($stores[$store_id])) {
        return $stores[$store_id];
    } else {
        return $stores;
    }
}

// Parses a single order for any needed changes
function ssu_parse_single_order_for_changes($post_id, $store_id) {
    $store = ssu_get_store_data($store_id);

    // get SKU patterns to look for
    $sku_patterns = $store['sku_patterns'];
    $sku_patterns = explode('|', $sku_patterns);

    // decode JSON data & prep arrays
    $order = json_decode(get_field('original_order_details', $post_id), true);
    $revised_order_items = array();
    $special_order_items = array();
    $order_status = '';

    // look for SKUs that start with
    foreach ($order['items'] as $key => $item) {
        // loop thru SKU patterns to find matches
        $is_special_item = false;
        foreach($sku_patterns as $pattern) {
            if (strpos($item['sku'], $pattern) !== false ) {
                $is_special_item = true;
                break;
            }
        }

        if ($is_special_item) {
            $special_order_items[] = $item;
        } else {
            $revised_order_items[] = $item;
        }
    }

    // check if there are less revised items, update the SS order
    // if there are zero revised items (only special products), delete the SS order
    if (empty($revised_order_items)) {
        // send delete request
        $delete_response = ssu_delete_ss_order($order['orderId'], $store);
        // save delete response
        update_field('updated_order_details', $delete_response, $post_id);
        $order_status = 'order deleted';
    } elseif (count($order['items']) > count($revised_order_items)) {
        // replace items for original order
        $order['items'] = $revised_order_items;
        // update original order at ss
        $update_response = ssu_update_ss_order($order, $store);
        // save updated order acf
        update_field('updated_order_details', $update_response, $post_id);
        $order_status = 'order updated';
    }

    // check if there were special items that need to be emailed
    if (!empty($special_order_items)) {
        $email_content = ssu_send_special_products_email($special_order_items, $order);
        update_field('email_content', $email_content, $post_id);
        $order_status .= ' and email sent';
    }

    return $order_status;
}

function ssu_send_special_products_email($items, $order) {
    $store_id = $order['advancedOptions']['storeId'];

    $store = ssu_get_store_data($store_id);
    $store_email = $store['notification_email'];
    $store_name = $store['store_name'];

    $order_num = $order['orderNumber'];
    $customer_address = $order['shipTo']['street1'] . ', ';
    $customer_address .= $order['shipTo']['street2'] ? $order['shipTo']['street2'] . ', ' : '';
    $customer_address .= $order['shipTo']['street3'] ? $order['shipTo']['street3'] . ', ' : '';
    $customer_address .= $order['shipTo']['city'] . ', ' . $order['shipTo']['state'] . ', ' . $order['shipTo']['postalCode'];


    $message = "<p>Order #$order_num for special item(s) has been received at $store_name.</p>"
        . "<p>Here is the info:</p>"
        . "<p>Customer: " . $order['shipTo']['name'] . "<br>"
        . "Address: " . $customer_address . "<br>"
        . "Phone: " . $order['shipTo']['phone']. "</p>"
        . "<p>The order includes the following item(s):</p>";

    // loop thru the items
    foreach ($items as $item) {
        $message .= "<p>"
            . "Item Name: " . $item['name'] . "<br>"
            . "Item SKU: " . $item['sku'] . "<br>"
            . "Quantity: " . $item['quantity'] . "<br>";
        foreach ($item['options'] as $option) {
            $message .= $option['name'] . ": " . $option['value'] . "<br>";
        }
        $message .= "</p>";
    }

    $subject = "Special order #$order_num from $store_name";
    $to = $store_name .' Special Order Manager <'.$store_email .'>';
    $headers = array('Content-Type: text/html; charset=UTF-8');
    wp_mail( $to, $subject, $message, $headers );

    return $message;
}


// Get Batch Orders (from payload's resource_url)
// https://www.shipstation.com/docs/api/orders/get-order/
function ssu_get_batch_details($url, $store_data) {
    $args = array(
        'httpversion' => '1.1',
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode( $store_data['api_key'] . ':' . $store_data['api_secret'] )
        )
    );
    $response = wp_remote_get(htmlentities($url), $args );
    $body = wp_remote_retrieve_body($response);
    return $body;
}



// Create or Update a Shipstation Order
// https://www.shipstation.com/docs/api/orders/create-update-order/
function ssu_update_ss_order($order_data, $store_data) {
    $url = SSU_SS_BASEURL . '/orders/createorder';

    // update original ss order
    $args = array(
        'httpversion' => '1.1',
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode( $store_data['api_key'] . ':' . $store_data['api_secret'] )
        ),
        'body' => wp_json_encode($order_data)
    );
    $response = wp_remote_post($url, $args);
    $body = wp_remote_retrieve_body($response);
    return $body;
}


// Delete a Shipstation Order
// https://www.shipstation.com/docs/api/orders/create-update-order/
function ssu_delete_ss_order($order_id, $store_data) {
    $url = SSU_SS_BASEURL . '/orders/' . $order_id;

    // update original ss order
    $args = array(
        'method' => 'DELETE',
        'httpversion' => '1.1',
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode( $store_data['api_key'] . ':' . $store_data['api_secret'] )
        ),
    );
    $response = wp_remote_request($url, $args);
    $body = wp_remote_retrieve_body($response);
    return $body;
}