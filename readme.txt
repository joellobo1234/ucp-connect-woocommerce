=== UCP Connect for WooCommerce ===
Contributors: joellobo, agentic-commerce
Tags: woocommerce, ucp, mcp, agents, ai
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.3.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes a WooCommerce store's inventory as a Universal Commerce Protocol (UCP) endpoint, enabling seamless integration with AI agents via MCP.

== Description ==

**UCP Connect for WooCommerce** transforms your store into an AI-ready commerce endpoint. It implements the Universal Commerce Protocol (UCP), allowing AI agents (like Claude, custom MCP clients, etc.) to search products, discover capabilities, and create checkout sessions directly.

It includes a native Model Context Protocol (MCP) server that agents can connect to, making your products discoverable and purchaseable by the next generation of AI assistants.

**Key Features:**
*   **Unified Product Search**: Smart search logic that handles fuzzy queries, singular/plural terms, and category matching to help agents find what they need.
*   **AI Checkout Integration**: Allows agents to build a cart and generate a secure checkout link for the user to complete purchase.
*   **MCP Server**: Built-in JSON-RPC 2.0 server compliant with the Model Context Protocol.
*   **WebMCP Support**: Includes a client-side bridge for browser-based agents.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/ucp-connect-woocommerce` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Ensure WooCommerce is installed and active.
4. Your MCP endpoint will be available at `/wp-json/ucp/v1/mcp`.

== Frequently Asked Questions ==

= What is UCP? =
The Universal Commerce Protocol (UCP) is a standard for exposing e-commerce capabilities to AI agents.

= Do I need an API Key? =
Currently, the endpoints are public to facilitate easy discovery by local agents. For production environments, you may wish to add authentication layers (planned for future releases).

= Does this modify my WooCommerce data? =
No. It only reads products and creates standard WooCommerce orders. It does not alter your catalog structure.

== Changelog ==

= 1.3.0 =
*   Enhanced "Unified Search" logic for better agent query understanding (singular/plural handling).
*   Dynamic MCP Server Identity (uses actual Site Name).
*   Performance optimizations for search queries.

= 1.2.0 =
*   Added native MCP Server support (JSON-RPC 2.0).

= 1.1.0 =
*   Initial UCP Conformance.

= 1.0.0 =
*   Initial Release.
