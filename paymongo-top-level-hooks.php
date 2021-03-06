<?php
/**
 * PHP version 7
 * 
 * PayMongo - Top Level Hooks File
 * 
 * @category Plugin
 * @package  PayMongo
 * @author   PayMongo <devops@cynder.io>
 * @license  n/a (http://127.0.0.0)
 * @link     n/a
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

function cynder_paymongo_create_intent($orderId) {
    $pluginSettings = get_option('woocommerce_paymongo_settings');

    /** If the plugin isn't enabled, don't create a payment intent */
    if ($pluginSettings['enabled'] !== 'yes') return;

    $order = wc_get_order($orderId);
    $amount = floatval($order->get_total());

    if (!is_float($amount)) {
        $errorMessage = 'Invalid amount';
        wc_get_logger()->log('error', '[Create Payment Intent] ' . $errorMessage);
        throw new Exception(__($errorMessage, 'woocommerce'));
    }

    $secretKeyProp = $pluginSettings['testmode'] === 'yes' ? 'test_secret_key' : 'secret_key';
    $secretKey = $pluginSettings[$secretKeyProp];

    $payload = json_encode(
        array(
            'data' => array(
                'attributes' =>array(
                    'amount' => floatval($amount * 100),
                    'payment_method_allowed' => array('card'),
                    'currency' => 'PHP', // hard-coded for now
                    'description' => get_bloginfo('name') . ' - ' . $orderId
                ),
            ),
        )
    );

    $args = array(
        'body' => $payload,
        'method' => "POST",
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($secretKey),
            'accept' => 'application/json',
            'content-type' => 'application/json'
        ),
    );

    $response = wp_remote_post(
        CYNDER_PAYMONGO_BASE_URL . '/payment_intents',
        $args
    );

    /** Enable for debugging purposes */
    // wc_get_logger()->log('info', json_encode($response));

    $genericErrorMessage = 'Something went wrong with the payment. Please try another payment method. If issue persist, contact support.';

    if (!is_wp_error($response)) {
        $body = json_decode($response['body'], true);

        if ($body
            && array_key_exists('data', $body)
            && array_key_exists('attributes', $body['data'])
            && array_key_exists('status', $body['data']['attributes'])
            && $body['data']['attributes']['status'] == 'awaiting_payment_method'
        ) {
            $clientKey = $body['data']['attributes']['client_key'];
            $order->add_meta_data('paymongo_payment_intent_id', $body['data']['id']);
            $order->add_meta_data('paymongo_client_key', $clientKey);
            $order->save_meta_data();
        } else {
            wc_get_logger()->log('error', '[Create Payment Intent] ' . json_encode($body['errors']));
            throw new Exception(__($genericErrorMessage, 'woocommerce'));
        }
    } else {
        wc_get_logger()->log('error', '[Create Payment Intent] ' . json_encode($response->get_error_messages()));
        throw new Exception(__($genericErrorMessage, 'woocommerce'));
    }
}

add_action('woocommerce_checkout_order_processed', 'cynder_paymongo_create_intent');

function cynder_paymongo_catch_redirect() {
    global $woocommerce;

    wc_get_logger()->log('info', 'Params ' . json_encode($_GET));

    $paymentIntentId = $_GET['intent'];

    if (!isset($paymentIntentId)) {
        /** Check payment intent ID */
    }

    $paymentGatewaId = 'paymongo';
    $paymentGateways = WC_Payment_Gateways::instance();

    $paymongoGateway = $paymentGateways->payment_gateways()[$paymentGatewaId];
    $testMode = $paymongoGateway->get_option('testmode');
    $authOptionKey = $testMode === 'yes' ? 'test_secret_key' : 'secret_key';
    $authKey = $paymongoGateway->get_option($authOptionKey);

    $args = array(
        'method' => 'GET',
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($authKey),
            'accept' => 'application/json',
            'content-type' => 'application/json'
        ),
    );

    $response = wp_remote_get(
        CYNDER_PAYMONGO_BASE_URL . '/payment_intents/' . $paymentIntentId,
        $args
    );

    /** Enable for debugging */
    wc_get_logger()->log('info', '[Catch Redirect][Response] ' . json_encode($response));

    if (is_wp_error($response)) {
        /** Handle errors */
        return;
    }

    $body = json_decode($response['body'], true);

    $responseAttr = $body['data']['attributes'];
    $status = $responseAttr['status'];

    $orderId = $_GET['order'];
    $order = wc_get_order($orderId);

    if ($status === 'succeeded' || $status === 'processing') {
        // we received the payment
        $payments = $responseAttr['payments'];
        $order->payment_complete($payments[0]['id']);
        wc_reduce_stock_levels($orderId);

        // Sending invoice after successful payment
        $woocommerce->mailer()->emails['WC_Email_Customer_Invoice']->trigger($orderId);

        // Empty cart
        $woocommerce->cart->empty_cart();

        // Redirect to the thank you page
        wp_redirect($order->get_checkout_order_received_url());
    } else if ($status === 'awaiting_payment_method') {
        wc_add_notice('Something went wrong with the payment. Please try another payment method. If issue persist, contact support.', 'error');
        wp_redirect($order->get_checkout_payment_url());
    }
}

add_action(
    'woocommerce_api_cynder_paymongo_catch_redirect',
    'cynder_paymongo_catch_redirect'
);


function cynder_paymongo_catch_source_redirect() {
    $orderId = $_GET['order'];
    $status = $_GET['status'];

    $order = wc_get_order($orderId);

    if ($status === 'success') {
        wp_redirect($order->get_checkout_order_received_url());
    } else if ($status === 'failed') {
        wc_add_notice('Something went wrong with the payment. Please try another payment method. If issue persist, contact support.', 'error');
        wp_redirect($order->get_checkout_payment_url());
    }
}

add_action(
    'woocommerce_api_cynder_paymongo_catch_source_redirect',
    'cynder_paymongo_catch_source_redirect'
);

function add_webhook_settings($settings, $current_section) {
    if ($current_section === 'paymongo_gcash' || $current_section === 'paymongo_grab_pay') {
        $webhookUrl = add_query_arg(
            'wc-api',
            'cynder_paymongo',
            trailingslashit(get_home_url())
        );

        $settings_webhooks = array(
            array(
                'name' => 'Webhook Secret',
                'id' => 'paymongo_webhook_secret_key_title',
                'type' => 'title',
                'desc' => 'Provide a secret key to enable'	
                . ' <b>GCash</b> or <b>GrabPay</b> Payments.',
            ),
            array(
                'name' => 'Webhook Secret',
                'id' => 'paymongo_webhook_secret_key',
                'type' => 'text',
                'desc' => 'Provide a secret key to enable'	
                . ' <b>GCash</b> or <b>GrabPay</b> Payments<br>'	
                . '<a target="_blank" href="https://paymongo-webhook-tool.meeco.dev?url=' 	
                . $webhookUrl	
                . '">Click this to generate a webhook secret</a>'	
                . ' or use this URL: <b>'	
                . $webhookUrl,
            ),
            array(
                'name' => 'Test Webhook Secret',
                'id' => 'paymongo_test_webhook_secret_key',
                'type' => 'text',
                'desc' => 'Provide a secret key to enable'	
                . ' <b>GCash</b> or <b>GrabPay</b> Payments<br>'	
                . '<a target="_blank" href="https://paymongo-webhook-tool.meeco.dev?url=' 	
                . $webhookUrl	
                . '">Click this to generate a webhook secret</a>'	
                . ' or use this URL: <b>'	
                . $webhookUrl,
            ),
            array(
                'type' => 'sectionend',
                'id' => 'webhook_section_end',
            )
        );

        return $settings_webhooks;
    } else {
        return $settings;
    }
}

add_filter(
    'woocommerce_get_settings_checkout',
    'add_webhook_settings',
    10,
    2
);