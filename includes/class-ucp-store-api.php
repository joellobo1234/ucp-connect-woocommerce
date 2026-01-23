<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * UCP Store API Handler.
 * Uses WooCommerce Store API (/wc/store/v1) for stateless checkout.
 * Follows the Shopify UCP Proxy WooCommerce adapter pattern.
 */
class UCP_Store_API
{
    /**
     * Make a request to WooCommerce Store API.
     */
    private function store_api_request($endpoint, $method = 'GET', $body = null, $cart_token = null)
    {
        $url = rest_url('wc/store/v1' . $endpoint);

        $args = array(
            'method' => $method,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        );

        // Add Cart-Token header if provided
        if ($cart_token) {
            $args['headers']['Cart-Token'] = $cart_token;
        }

        if ($body && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new Exception('Store API request failed: ' . $response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        // Extract Cart-Token from response headers
        $response_cart_token = wp_remote_retrieve_header($response, 'Cart-Token');

        if ($status >= 400) {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            throw new Exception("Store API error ({$status}): {$error_message}");
        }

        return array(
            'data' => $data,
            'cart_token' => $response_cart_token ?: $cart_token,
        );
    }

    /**
     * Create a checkout session.
     * POST /ucp/v1/checkout
     */
    public function create_checkout($items)
    {
        $cart_token = null;

        // Add items to cart
        foreach ($items as $item) {
            $result = $this->store_api_request(
                '/cart/add-item',
                'POST',
                array(
                    'id' => $item['id'],
                    'quantity' => $item['quantity'],
                ),
                $cart_token
            );
            $cart_token = $result['cart_token'];
        }

        // Get checkout state
        $checkout_result = $this->store_api_request('/checkout', 'GET', null, $cart_token);

        return $this->format_checkout_response($checkout_result['data'], $cart_token);
    }

    /**
     * Update a checkout session.
     * POST /ucp/v1/checkout/{id}
     */
    public function update_checkout($checkout_id, $updates)
    {
        $cart_token = $this->extract_cart_token($checkout_id);

        // Update shipping address
        if (isset($updates['shipping_address'])) {
            $address = $updates['shipping_address'];
            $this->store_api_request(
                '/checkout',
                'POST',
                array(
                    'billing_address' => array(
                        'first_name' => $address['first_name'] ?? '',
                        'last_name' => $address['last_name'] ?? '',
                        'address_1' => $address['address_line1'] ?? '',
                        'city' => $address['city'] ?? '',
                        'state' => $address['region'] ?? '',
                        'postcode' => $address['postal_code'] ?? '',
                        'country' => $address['country'] ?? '',
                        'email' => 'guest@example.com', // Required by WC
                    ),
                    'shipping_address' => array(
                        'first_name' => $address['first_name'] ?? '',
                        'last_name' => $address['last_name'] ?? '',
                        'address_1' => $address['address_line1'] ?? '',
                        'city' => $address['city'] ?? '',
                        'state' => $address['region'] ?? '',
                        'postcode' => $address['postal_code'] ?? '',
                        'country' => $address['country'] ?? '',
                    ),
                ),
                $cart_token
            );
        }

        // Apply discount codes
        if (isset($updates['discounts']['codes'])) {
            foreach ($updates['discounts']['codes'] as $code) {
                $this->store_api_request(
                    '/cart/apply-coupon',
                    'POST',
                    array('code' => $code),
                    $cart_token
                );
            }
        }

        // Get updated checkout state
        $checkout_result = $this->store_api_request('/checkout', 'GET', null, $cart_token);

        return $this->format_checkout_response($checkout_result['data'], $cart_token);
    }

    /**
     * Complete a checkout session.
     * POST /ucp/v1/checkout/{id}/complete
     */
    public function complete_checkout($checkout_id)
    {
        $cart_token = $this->extract_cart_token($checkout_id);

        // Submit checkout (creates order)
        $result = $this->store_api_request(
            '/checkout',
            'POST',
            array(
                'payment_method' => 'bacs', // Bank transfer - requires manual payment
            ),
            $cart_token
        );

        $order_id = $result['data']['order_id'] ?? null;

        if (!$order_id) {
            throw new Exception('Failed to create order from checkout');
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            throw new Exception('Order created but could not be retrieved');
        }

        return array(
            'status' => 'requires_escalation',
            'continue_url' => $order->get_checkout_payment_url(),
            'messages' => array(
                array(
                    'type' => 'info',
                    'code' => 'ESCALATION_REQUIRED',
                    'content' => 'Payment requires browser checkout. Please follow the link to complete payment.',
                    'severity' => 'escalation'
                )
            )
        );
    }

    /**
     * Format checkout response to UCP format.
     */
    private function format_checkout_response($checkout_data, $cart_token)
    {
        $order_id = $checkout_data['order_id'] ?? 0;

        // Encode cart token in checkout ID (following Shopify UCP Proxy pattern)
        $checkout_id = base64_encode("{$order_id}:{$cart_token}");

        return array(
            'id' => $checkout_id,
            'status' => 'cart',
            'currency' => $checkout_data['currency_code'] ?? get_woocommerce_currency(),
            'total' => (float) ($checkout_data['totals']['total_price'] ?? 0) / 100, // WC Store API uses cents
            'subtotal' => (float) ($checkout_data['totals']['total_items'] ?? 0) / 100,
            'tax_total' => (float) ($checkout_data['totals']['total_tax'] ?? 0) / 100,
            'shipping_total' => (float) ($checkout_data['totals']['total_shipping'] ?? 0) / 100,
            'discount_total' => (float) ($checkout_data['totals']['total_discount'] ?? 0) / 100,
            'applied_coupons' => array_map(function ($coupon) {
                return $coupon['code'];
            }, $checkout_data['coupons'] ?? array()),
            'line_items' => array_map(function ($item) {
                return array(
                    'id' => (string) $item['id'],
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'total' => (float) $item['totals']['line_total'] / 100,
                );
            }, $checkout_data['items'] ?? array()),
        );
    }

    /**
     * Extract cart token from checkout ID.
     */
    private function extract_cart_token($checkout_id)
    {
        $decoded = base64_decode($checkout_id, true);

        if (!$decoded || strpos($decoded, ':') === false) {
            throw new Exception('Invalid checkout ID format');
        }

        list($order_id, $cart_token) = explode(':', $decoded, 2);

        return $cart_token;
    }
}
