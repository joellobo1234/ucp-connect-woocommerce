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
            'permission_callback' => '__return_true', // Public endpoint
        ));

        // Checkout Endpoint: Create
        register_rest_route(self::NAMESPACE , '/checkout', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_checkout'),
            'permission_callback' => '__return_true',
        ));

        // Checkout Endpoint: Update
        register_rest_route(self::NAMESPACE , '/checkout/(?P<id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_checkout'),
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array(
                    'validate_callback' => function ($param, $request, $key) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));

        // Checkout Endpoint: Complete
        register_rest_route(self::NAMESPACE , '/checkout/(?P<id>\d+)/complete', array(
            'methods' => 'POST',
            'callback' => array($this, 'complete_checkout'),
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array(
                    'validate_callback' => function ($param, $request, $key) {
                        return is_numeric($param);
                    }
                ),
            ),
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

        if (empty($query)) {
            // No query? Return all products.
            $final_ids = get_posts(array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
            ));
        } else {
            // Unified Search Strategy: Matches Title, Content, or Category
            // 1. Singularization: Handle plural queries (e.g. "plants" -> "plant")
            $queries = array($query);
            if (substr($query, -1) === 's') {
                $queries[] = substr($query, 0, -1);
            }

            // Execution Strategy:
            // We perform a two-step lookup (Category IDs + Keyword IDs) followed by a single hydration step
            // to maximize performance and ensure accurate "OR" logic across taxonomies and post content.

            // 1. Get IDs of products in matching categories (Lightweight)
            // Checks for BOTH singular ("hoodie") and plural ("hoodies") in category names
            $cat_product_ids = get_posts(array(
                'post_type' => 'product',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'tax_query' => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field' => 'name',
                        'terms' => $queries,
                    )
                )
            ));

            // 2. Main Search for Keywords (Title/Content/SKU)
            // Optimization: Prefer singular stem for broader matching
            // e.g. Query "hoodies" -> Search "hoodie". This matches "Red Hoodie" AND "Red Hoodies".
            $search_keyword = $query;
            if (count($queries) > 1) {
                $search_keyword = $queries[1]; // Use the singular version
            }

            $search_args = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => 10,
                's' => $search_keyword,
                'fields' => 'ids',
            );
            $search_product_ids = get_posts($search_args);

            // 3. Merge IDs avoiding duplication
            $all_ids = array_unique(array_merge($cat_product_ids, $search_product_ids));

            // 4. Fetch final objects (limit to 10 for speed)
            $final_ids = array_slice($all_ids, 0, 10);
        }

        if (empty($final_ids)) {
            return new WP_REST_Response(array('items' => array()), 200);
        }

        $products = wc_get_products(array(
            'include' => $final_ids,
            'limit' => -1, // We already sliced IDs
        ));

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
     * Creates a new cart session.
     */
    public function create_checkout($request)
    {
        $params = $request->get_json_params();
        $cart = new UCP_Cart_Manager();

        try {
            // Start fresh session logic
            $cart->start_session();

            if (!empty($params['items'])) {
                $cart->set_items($params['items']);
            }

            return new WP_REST_Response($cart->get_cart_response(), 201);

        } catch (Exception $e) {
            return new WP_Error('checkout_error', $e->getMessage(), array('status' => 500));
        }
    }

    /**
     * POST /checkout/{id}
     * Updates an existing cart session.
     */
    public function update_checkout($request)
    {
        $id_encoded = $request->get_param('id');
        $token = base64_decode($id_encoded, true);

        if (!$token) {
            return new WP_Error('invalid_id', 'Invalid Checkout ID format', array('status' => 400));
        }

        $params = $request->get_json_params();
        $cart = new UCP_Cart_Manager();

        try {
            $cart->start_session($token); // Rehydrate

            if (isset($params['items'])) {
                $cart->set_items($params['items']);
            }

            if (isset($params['discount_codes'])) {
                $cart->set_coupons($params['discount_codes']);
            }

            return new WP_REST_Response($cart->get_cart_response(), 200);

        } catch (Exception $e) {
            return new WP_Error('update_error', $e->getMessage(), array('status' => 500));
        }
    }

    /**
     * POST /checkout/{id}/complete
     * Converts cart to order and requests payment/escalation.
     */
    public function complete_checkout($request)
    {
        $id_encoded = $request->get_param('id');
        $token = base64_decode($id_encoded, true);

        if (!$token) {
            return new WP_Error('invalid_id', 'Invalid Checkout ID format', array('status' => 400));
        }

        $cart = new UCP_Cart_Manager();

        try {
            $cart->start_session($token);
            $order = $cart->checkout(); // Conversion happens here

            if (!$order) {
                return new WP_Error('order_failed', 'Failed to create order from cart', array('status' => 500));
            }

            // Return Escalation Action (Browser Redirect)
            return new WP_REST_Response(array(
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
            ), 200);

        } catch (Exception $e) {
            return new WP_Error('complete_error', $e->getMessage(), array('status' => 500));
        }
    }
}
