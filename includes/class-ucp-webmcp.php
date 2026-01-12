<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WebMCP Frontend Integration using @mcp-b/global standard.
 * Exposes UCP tools to browser-based agents via navigator.modelContext.
 */
class UCP_WebMCP
{

    /**
     * Initialize WebMCP.
     */
    public function __construct()
    {
        // Only load on frontend
        if (!is_admin()) {
            add_action('wp_footer', array($this, 'inject_webmcp_bootstrap'), 5);
            add_action('wp_footer', array($this, 'inject_ucp_tools'), 10);
        }
    }

    /**
     * Inject @mcp-b/global polyfill from CDN.
     */
    public function inject_webmcp_bootstrap()
    {
        ?>
        <script>
            (function () {
                // Function to initialize or check for modelContext
                function initWebMCP() {
                    // Check if @mcp-b/global is already loaded
                    if (window.navigator?.modelContext) {
                        console.log('[UCP Connect] navigator.modelContext already available');
                        return;
                    }

                    console.log('[UCP Connect] Loading @mcp-b/global from CDN...');

                    // Load @mcp-b/global polyfill from unpkg
                    const script = document.createElement('script');
                    script.src = 'https://unpkg.com/@mcp-b/global@latest/dist/index.iife.js';
                    script.async = false; // Load synchronously to ensure it's ready
                    script.onload = function () {
                        console.log('[UCP Connect] @mcp-b/global loaded successfully');
                        window.dispatchEvent(new CustomEvent('ucp:webmcp-ready'));
                    };
                    script.onerror = function () {
                        console.error('[UCP Connect] Failed to load @mcp-b/global. WebMCP tools will not be available.');
                    };
                    document.head.appendChild(script);
                }

                // Wait for full page load to give extensions priority
                if (document.readyState === 'complete') {
                    initWebMCP();
                } else {
                    window.addEventListener('load', initWebMCP);
                }
            })();
        </script>
        <?php
    }

    /**
     * Inject UCP Commerce tools using @mcp-b/global standard.
     */
    public function inject_ucp_tools()
    {
        $rest_url = rest_url('ucp/v1');
        $nonce = wp_create_nonce('wp_rest');
        ?>
        <script>
            (function () {
                const restUrl = <?php echo wp_json_encode($rest_url); ?>;
                const nonce = <?php echo wp_json_encode($nonce); ?>;

                // Helper to call WordPress REST API
                async function callRestAPI(endpoint, method = 'POST', body = null) {
                    const options = {
                        method: method,
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': nonce
                        }
                    };

                    if (body) {
                        options.body = JSON.stringify(body);
                    }

                    const response = await fetch(restUrl + endpoint, options);
                    if (!response.ok) {
                        const errorText = await response.text();
                        throw new Error('API Error: ' + response.statusText + ' - ' + errorText);
                    }
                    return await response.json();
                }

                // Wait for @mcp-b/global to be ready
                function registerUCPTools() {
                    if (!window.navigator?.modelContext) {
                        console.log('[UCP Connect] Waiting for navigator.modelContext...');
                        setTimeout(registerUCPTools, 100);
                        return;
                    }

                    // Define UCP tools following @mcp-b/global standard
                    const ucpTools = [
                        {
                            name: 'ucp_search_products',
                            description: 'Search for products in this WooCommerce store. Returns a list of products matching the query.',
                            inputSchema: {
                                type: 'object',
                                properties: {
                                    query: {
                                        type: 'string',
                                        description: 'Search query (e.g., "running shoes", "leather jacket")'
                                    }
                                },
                                required: ['query']
                            },
                            outputSchema: {
                                type: 'object',
                                properties: {
                                    items: {
                                        type: 'array',
                                        items: {
                                            type: 'object',
                                            properties: {
                                                id: { type: 'string' },
                                                name: { type: 'string' },
                                                price: { type: 'object' },
                                                availability: { type: 'string' }
                                            }
                                        }
                                    }
                                }
                            },
                            execute: async function (args) {
                                try {
                                    const result = await callRestAPI('/search', 'POST', { query: args.query });
                                    return {
                                        content: [{
                                            type: 'text',
                                            text: `Found ${result.items?.length || 0} products for "${args.query}"`
                                        }],
                                        structuredContent: result,
                                        isError: false
                                    };
                                } catch (error) {
                                    return {
                                        content: [{
                                            type: 'text',
                                            text: 'Error searching products: ' + error.message
                                        }],
                                        isError: true
                                    };
                                }
                            }
                        },
                        {
                            name: 'ucp_create_checkout',
                            description: 'Create a new checkout session with selected products. Returns a checkout URL for completing the purchase.',
                            inputSchema: {
                                type: 'object',
                                properties: {
                                    items: {
                                        type: 'array',
                                        description: 'Products to add to checkout',
                                        items: {
                                            type: 'object',
                                            properties: {
                                                id: { type: 'integer', description: 'Product ID' },
                                                quantity: { type: 'integer', description: 'Quantity to purchase' }
                                            },
                                            required: ['id', 'quantity']
                                        }
                                    }
                                },
                                required: ['items']
                            },
                            outputSchema: {
                                type: 'object',
                                properties: {
                                    order_id: { type: 'integer' },
                                    payment_url: { type: 'string' },
                                    total: { type: 'string' },
                                    currency: { type: 'string' }
                                }
                            },
                            execute: async function (args) {
                                try {
                                    const result = await callRestAPI('/checkout', 'POST', { items: args.items });
                                    return {
                                        content: [{
                                            type: 'text',
                                            text: `Checkout created! Order ID: ${result.order_id}. Payment URL: ${result.payment_url}`
                                        }],
                                        structuredContent: result,
                                        isError: false
                                    };
                                } catch (error) {
                                    return {
                                        content: [{
                                            type: 'text',
                                            text: 'Error creating checkout: ' + error.message
                                        }],
                                        isError: true
                                    };
                                }
                            }
                        },
                        {
                            name: 'ucp_get_discovery',
                            description: 'Get store capabilities and information. Returns details about what this store supports.',
                            inputSchema: {
                                type: 'object',
                                properties: {}
                            },
                            outputSchema: {
                                type: 'object',
                                properties: {
                                    protocol: { type: 'string' },
                                    version: { type: 'string' },
                                    capabilities: { type: 'object' },
                                    store_info: { type: 'object' }
                                }
                            },
                            execute: async function (args) {
                                try {
                                    const result = await callRestAPI('/discovery', 'GET', null);
                                    return {
                                        content: [{
                                            type: 'text',
                                            text: `Store: ${result.store_info?.name || 'Unknown'}. Currency: ${result.store_info?.currency || 'N/A'}`
                                        }],
                                        structuredContent: result,
                                        isError: false
                                    };
                                } catch (error) {
                                    return {
                                        content: [{
                                            type: 'text',
                                            text: 'Error fetching discovery info: ' + error.message
                                        }],
                                        isError: true
                                    };
                                }
                            }
                        }
                    ];

                    // Register tools using @mcp-b/global standard API
                    // provideContext() is the "Bucket A" method - for app-level tools
                    window.navigator.modelContext.provideContext({
                        tools: ucpTools
                    });

                    console.log('[UCP Connect] Registered ' + ucpTools.length + ' UCP commerce tools via navigator.modelContext');

                    // Dispatch event for compatibility with other systems
                    window.dispatchEvent(new CustomEvent('ucp:tools-registered', {
                        detail: { source: 'ucp-connect-woocommerce', count: ucpTools.length }
                    }));
                }

                // Start registration once DOM is ready
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', registerUCPTools);
                } else {
                    registerUCPTools();
                }
            })();
        </script>
        <?php
    }
}
