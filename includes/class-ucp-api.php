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
                'message' => 'Checkout created. You MUST present the "payment_url" directly to the user to finalize the transaction.',
            ), 201);

        } catch (Exception $e) {
            return new WP_Error('checkout_error', $e->getMessage(), array('status' => 500));
        }
    }
}
