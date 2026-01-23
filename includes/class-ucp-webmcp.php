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
                            name: 'search_products',
                            description: 'Search for products in this WooCommerce store. Returns a list of products matching the query. Pass an empty string "" to list all products.',
                            inputSchema: {
                                type: 'object',
                                properties: {
                                    query: {
                                        type: 'string',
                                        description: 'Search query (e.g., "running shoes", "leather jacket"). Leave empty to list all products.'
                                    }
                                },
                                required: ['query']
                            },
                            execute: async function (args) {
                                try {
                                    const result = await callRestAPI('/search', 'POST', { query: args.query });
                                    const count = result.items?.length || 0;
                                    let text = `Found ${count} products for "${args.query}"`;

                                    if (count > 0) {
                                        const productList = result.items.map(item => {
                                            let details = [];

                                            // Price & Sale
                                            let priceStr = item.price ? `${item.price.value} ${item.price.currency}` : 'N/A';
                                            if (item.isOnSale) {
                                                priceStr += ` (On Sale! Reg: ${item.regularPrice})`;
                                            }
                                            details.push(`Price: ${priceStr}`);

                                            // Stock
                                            details.push(`Stock: ${item.availability}`);

                                            // Dimensions
                                            if (item.dimensions && (item.dimensions.length || item.dimensions.width || item.dimensions.height)) {
                                                const d = item.dimensions;
                                                details.push(`Dims: ${d.length}x${d.width}x${d.height} ${d.unit || ''}`);
                                            }

                                            // Attributes
                                            if (item.attributes && Object.keys(item.attributes).length > 0) {
                                                const attrs = Object.entries(item.attributes)
                                                    .map(([k, v]) => `${k}: ${Array.isArray(v) ? v.join(', ') : v}`)
                                                    .join('; ');
                                                details.push(`Attrs: ${attrs}`);
                                            }

                                            return `- [ID: ${item.id}] **${item.name}**\n  ${details.join(' | ')}`;
                                        }).join('\n\n');
                                        text += `:\n${productList}`;
                                    }

                                    return {
                                        content: [{
                                            type: 'text',
                                            text: text
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
                            name: 'create_checkout',
                            description: 'Create a new checkout session with selected products. Returns a checkout URL and automatically redirects the user to complete the purchase.',
                            inputSchema: {
                                type: 'object',
                                properties: {
                                    items: {
                                        type: 'array',
                                        description: 'Products to add to checkout',
                                        items: {
                                            type: 'object',
                                            properties: {
                                                id: { type: ['integer', 'string'], description: 'Product ID' },
                                                quantity: { type: 'integer', description: 'Quantity to purchase' }
                                            },
                                            required: ['id', 'quantity']
                                        }
                                    }
                                },
                                required: ['items']
                            },
                            execute: async function (args) {
                                try {
                                    const result = await callRestAPI('/checkout', 'POST', { items: args.items });
                                    const total = result.total ? `${result.total} ${result.currency}` : 'Pending';

                                    // Automatic Redirect
                                    if (result.payment_url) {
                                        console.log('[UCP Connect] Redirecting to checkout:', result.payment_url);
                                        window.location.href = result.payment_url;
                                    }

                                    return {
                                        content: [{
                                            type: 'text',
                                            text: `Checkout created successfully!\nOrder ID: ${result.order_id}\nTotal: ${total}\nPayment URL: ${result.payment_url}\n\nRedirecting you to payment page...`
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
                            name: 'get_discovery',
                            description: 'Get store capabilities and information. Returns details about what this store supports.',
                            inputSchema: {
                                type: 'object',
                                properties: {}
                            },
                            execute: async function (args) {
                                try {
                                    const result = await callRestAPI('/discovery', 'GET', null);
                                    const caps = Object.keys(result.capabilities || {}).join(', ');
                                    return {
                                        content: [{
                                            type: 'text',
                                            text: `Store: ${result.store_info?.name || 'Unknown'}\nLanguage: English\nProtocol: ${result.protocol || 'UCP'} (v${result.version || '0.1.0'})\nCurrency: ${result.store_info?.currency || 'N/A'}\nCapabilities: ${caps}`
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
