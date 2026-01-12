<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * UCP MCP Server.
 * Implements a JSON-RPC 2.0 endpoint for agentic tools.
 */
class UCP_MCP_Server
{

    /**
     * Register routes.
     */
    public function register_routes()
    {
        register_rest_route('ucp/v1', '/mcp', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_request'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Handle JSON-RPC request.
     */
    public function handle_request($request)
    {
        $body = $request->get_json_params();

        // Basic JSON-RPC validation
        if (empty($body['jsonrpc']) || $body['jsonrpc'] !== '2.0' || empty($body['method'])) {
            return new WP_Error('invalid_request', 'Invalid JSON-RPC request', array('status' => 400));
        }

        $method = $body['method'];
        $params = isset($body['params']) ? $body['params'] : array();
        $id = isset($body['id']) ? $body['id'] : null;

        $result = null;
        $error = null;

        try {
            switch ($method) {
                case 'list_tools':
                    $result = $this->list_tools();
                    break;
                case 'call_tool': // MCP standard
                case 'tools/call': // Anthropic/OpenAI style sometimes
                    $result = $this->call_tool($params);
                    break;
                default:
                    $error = array('code' => -32601, 'message' => 'Method not found');
            }
        } catch (Exception $e) {
            $error = array('code' => -32000, 'message' => $e->getMessage());
        }

        if ($error) {
            return array(
                'jsonrpc' => '2.0',
                'error' => $error,
                'id' => $id,
            );
        }

        return array(
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id,
        );
    }

    /**
     * List available tools (MCP capability).
     */
    private function list_tools()
    {
        return array(
            'tools' => array(
                array(
                    'name' => 'search_products',
                    'description' => 'Search for products in the catalog.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'query' => array('type' => 'string'),
                        ),
                        'required' => array('query'),
                    ),
                ),
                array(
                    'name' => 'create_checkout',
                    'description' => 'Initialize a new checkout session.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'items' => array(
                                'type' => 'array',
                                'items' => array(
                                    'type' => 'object',
                                    'properties' => array(
                                        'id' => array('type' => 'integer'),
                                        'quantity' => array('type' => 'integer'),
                                    ),
                                ),
                            ),
                        ),
                        'required' => array('items'),
                    ),
                ),
                array(
                    'name' => 'update_checkout',
                    'description' => 'Update an existing checkout with buyer info.',
                    'inputSchema' => array(
                        'type' => 'object',
                        'properties' => array(
                            'checkout_id' => array('type' => 'string'),
                            'email' => array('type' => 'string'),
                        ),
                        'required' => array('checkout_id'),
                    ),
                ),
            ),
        );
    }

    /**
     * Execute a tool.
     */
    private function call_tool($params)
    {
        $tool_name = isset($params['name']) ? $params['name'] : '';
        $args = isset($params['arguments']) ? $params['arguments'] : array();

        switch ($tool_name) {
            case 'search_products':
                // Reuse REST logic logic
                $api = new UCP_API();
                $req = new WP_REST_Request();
                $req->set_body_params($args);
                $res = $api->search_products($req);
                return $res->get_data();

            case 'create_checkout':
                // Handle checkout creation
                $api = new UCP_API();
                $req = new WP_REST_Request();
                $req->set_body_params($args);
                $res = $api->create_checkout($req);
                return $res->get_data();

            case 'update_checkout':
                // Simulate updating checkout (e.g., adding billing email to an order)
                if (empty($args['checkout_id'])) {
                    throw new Exception('Missing checkout_id');
                }
                $order = wc_get_order($args['checkout_id']);
                if (!$order) {
                    throw new Exception('Invalid checkout_id');
                }
                if (!empty($args['email'])) {
                    $order->set_billing_email(sanitize_email($args['email']));
                    $order->save();
                }
                return array(
                    'status' => 'updated',
                    'checkout_id' => $args['checkout_id'],
                    'total' => $order->get_total(),
                );

            default:
                throw new Exception('Tool not found: ' . $tool_name);
        }
    }
}
