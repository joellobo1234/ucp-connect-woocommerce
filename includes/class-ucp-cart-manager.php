<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * UCP Cart Manager.
 * Handles stateless cart management via tokens (WC_Session).
 */
class UCP_Cart_Manager
{
    private $auth_token = null;

    /**
     * Start/Resume a session from a token.
     *
     * @param string|null $token Existing cart token/Customer ID.
     * @return string The valid cart token.
     */
    public function start_session($token = null)
    {
        if (empty($token)) {
            $token = $this->generate_token();
        }

        $this->auth_token = $token;

        // Force WooCommerce to use this session
        if (!WC()->session) {
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        }

        // Trick: We hook into the cookie setting to override the ID
        // Since we are CLI/API, we manually set the customer ID in the session handler context if possible.
        // A robust way in "Headless" mode:
        // 1. Set the cookie global so WC thinks it exists.
        // 2. Init the session.

        $_COOKIE['wp_woocommerce_session_' . COOKIEHASH] = $token;
        $_COOKIE['woocommerce_items_in_cart'] = 1; // Fake to ensure it looks

        // This effectively loads the session from the DB matching our 'cookie' ($token)
        WC()->session->set_customer_session_cookie(true);

        // Ensure cart is loaded
        if (!WC()->cart) {
            wc_load_cart();
        }

        WC()->cart->get_cart_from_session();

        return $token;
    }

    /**
     * Clear current cart and add items.
     */
    public function set_items($items)
    {
        WC()->cart->empty_cart();

        foreach ($items as $item) {
            $product_id = isset($item['id']) ? absint($item['id']) : 0;
            $quantity = isset($item['quantity']) ? absint($item['quantity']) : 1;

            if ($product_id) {
                WC()->cart->add_to_cart($product_id, $quantity);
            }
        }
    }

    /**
     * Apply coupons.
     */
    public function set_coupons($codes)
    {
        // Remove all first
        foreach (WC()->cart->get_applied_coupons() as $code) {
            WC()->cart->remove_coupon($code);
        }

        foreach ($codes as $code) {
            WC()->cart->apply_coupon($code);
        }
    }

    /**
     * Convert the active cart to a real Order.
     * 
     * @return WC_Order
     */
    public function checkout()
    {
        $checkout = WC()->checkout();
        $order_id = $checkout->create_order(array());
        return wc_get_order($order_id);
    }

    /**
     * Get response data from current cart.
     */
    public function get_cart_response()
    {
        WC()->cart->calculate_totals();

        $total = WC()->cart->total; // Raw total string with formatting often, use numeric if needed

        return array(
            'id' => base64_encode($this->auth_token), // Encode token as ID
            'status' => 'cart', // Virtual status
            'currency' => get_woocommerce_currency(),
            'total' => (float) strip_tags(html_entity_decode(WC()->cart->get_total())), // Sanitize just in case
            'subtotal' => (float) WC()->cart->get_subtotal(),
            'payment_url' => '', // No payment URL for carts yet (until converted)
            'line_items' => $this->get_cart_items()
        );
    }

    private function get_cart_items()
    {
        $items = array();
        foreach (WC()->cart->get_cart() as $cart_item_key => $values) {
            $product = $values['data'];
            $items[] = array(
                'id' => (string) $product->get_id(),
                'name' => $product->get_name(),
                'quantity' => $values['quantity'],
                'total' => (float) $values['line_total'],
            );
        }
        return $items;
    }

    private function generate_token()
    {
        // Format: t_{timestamp}_{random}
        return 't_' . time() . '_' . wp_generate_password(16, false);
    }
}
