<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * UCP API Handler.
 */
class UCP_API
{

    /**
     * Namespace for the API.
     */
    const NAMESPACE = 'ucp/v1';

    /**
     * Register API routes.
     */
    public function register_routes()
    {
        // Discovery Endpoint
        register_rest_route(self::NAMESPACE , '/discovery', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_discovery'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        // Product Search Endpoint
        register_rest_route(self::NAMESPACE , '/search', array(
            'methods' => 'POST',
            'callback' => array($this, 'search_products'),
            'permission_callback' => '__return_true', // Public for now, maybe require API key later
        ));

        // Checkout Endpoint
        register_rest_route(self::NAMESPACE , '/checkout', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_checkout'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * GET /discovery
     * Returns capabilities of this UCP endpoint.
     */
    public function get_discovery($request)
    {
        return new WP_REST_Response(array(
            'protocol' => 'ucp',
            'version' => '0.1.0',
            'capabilities' => array(
                'shopping.search' => array(
                    'version' => '2026-01-11',
                    'endpoint' => '/ucp/v1/search',
                    'schema' => 'https://ucp.dev/schemas/shopping/search.json',
                    'method' => 'POST',
                ),
                'shopping.checkout' => array(
                    'version' => '2026-01-11',
                    'endpoint' => '/ucp/v1/checkout',
                    'schema' => 'https://ucp.dev/schemas/shopping/checkout.json',
                    'method' => 'POST',
                ),
            ),
            'store_info' => array(
                'name' => get_bloginfo('name'),
                'currency' => get_woocommerce_currency(),
            ),
        ), 200);
    }

    /**
     * POST /search
     * Searches products and maps them to UCP LineItems.
     */
    public function search_products($request)
    {
        $params = $request->get_json_params();
        $query = isset($params['query']) ? sanitize_text_field($params['query']) : '';

        // Use standard WC product query
        // In a real implementation, we'd parse more complex UCP search filters
        $args = array(
            'status' => 'publish',
            'limit' => 10,
            's' => $query,
        );

        $products = wc_get_products($args);
        $mapper = new UCP_Mapper();

        $items = array();
        foreach ($products as $product) {
            $items[] = $mapper->map_product_to_item($product);
        }

        return new WP_REST_Response(array(
            'items' => $items,
        ), 200);
    }

    /**
     * POST /checkout
     * Creates a tentative order/checkout session.
     */
    public function create_checkout($request)
    {
        $params = $request->get_json_params();

        if (empty($params['items'])) {
            return new WP_Error('missing_items', 'No items provided in checkout request', array('status' => 400));
        }

        try {
            // Create a new order
            $order = wc_create_order();

            foreach ($params['items'] as $item) {
                $product_id = isset($item['id']) ? absint($item['id']) : 0;
                $quantity = isset($item['quantity']) ? absint($item['quantity']) : 1;

                if ($product_id) {
                    $order->add_product(wc_get_product($product_id), $quantity);
                }
            }

            // Set address if provided (simplified)
            if (!empty($params['customer'])) {
                // Map customer fields if available in UCP schema
                // $order->set_billing_first_name( ... );
            }

            $order->calculate_totals();
            $order->save();

            return new WP_REST_Response(array(
                'order_id' => $order->get_id(),
                'status' => $order->get_status(),
                'currency' => $order->get_currency(),
                'total' => $order->get_total(),
                'payment_url' => $order->get_checkout_payment_url(),
            ), 201);

        } catch (Exception $e) {
            return new WP_Error('checkout_error', $e->getMessage(), array('status' => 500));
        }
    }
}
