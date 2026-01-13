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

        if (empty($query)) {
            return new WP_REST_Response(array('items' => array()), 200);
        }

        // Optimized Single Query: Searches Title OR Content OR Category
        // 1. Prepare Fuzzy Logic (Naive Singularization)
        $queries = array($query);
        if (substr($query, -1) === 's') {
            $queries[] = substr($query, 0, -1);
        }

        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'tax_query' => array(
                'relation' => 'OR',
            ),
            's' => $query, // Standard Search (Title/Content)
        );

        // Add Category Search into the mix
        // Note: standard 's' search in WP doesn't search taxonomy names by default.
        // To keep this performant and simple without writing raw SQL, we will rely on WC's search 
        // which matches Title/Content/Excerpt/SKU.
        // For distinct category matching, we'll append a tax_query.

        $args['tax_query'][] = array(
            'taxonomy' => 'product_cat',
            'field' => 'name',
            'terms' => $queries,
            'operator' => 'IN',
        );

        // However, WP_Query with 's' AND 'tax_query' does an intersection (AND). 
        // To do a true Union (OR), usage of raw filters or separate IDs is needed.
        // Given the constraints of "Simple but Fast", the most robust method for a plugin
        // without raw SQL injection is to fetch IDs for categories first (lightweight)
        // and add them to a keyword search.

        // Revised Strategy:
        // 1. Get IDs of products in matching categories (Lightweight)
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
        $search_args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            's' => $query,
            'fields' => 'ids',
        );
        $search_product_ids = get_posts($search_args);

        // 3. Merge IDs avoiding duplication
        $all_ids = array_unique(array_merge($cat_product_ids, $search_product_ids));

        // 4. Fetch final objects (limit to 10 for speed)
        $final_ids = array_slice($all_ids, 0, 10);

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
