# UCP Connect for WooCommerce

This plugin exposes your WooCommerce store inventory and checkout capabilities to the **Universal Commerce Protocol (UCP)** network, allowing AI agents to discover, search, and purchase products from your site.

## Features

*   **Discovery Endpoint**: `GET /wp-json/ucp/v1/discovery` - Exposes UCP compatibility.
*   **Product Search**: `POST /wp-json/ucp/v1/search` - Finds products using a **Unified Search Strategy** that combines:
    *   Exact keyword matching ("Money Plant")
    *   Category matching (e.g., query "Plants" finds all items in Plants category)
    *   Fuzzy/Stemming matching (e.g., "Plants" also matches "Plant", "Planter", etc.)
*   **Checkout**: `POST /wp-json/ucp/v1/checkout` - Creates orders programmatically.

## Installation

1.  Upload the `ucp-connect-woocommerce` folder to your `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Your UCP endpoints are now live under `/wp-json/ucp/v1/`.

## Agent Integration

Connects WooCommerce to AI agents via two methods:

### Browser Agents (WebMCP)
*Target: Chrome Extensions, Web Chatbots*

**Automatic Setup**:
1. Install and activate this plugin.
2. The plugin injects the `navigator.modelContext` API (via `@mcp-b/global`).
3. Your browser agent can immediately detect and use the tools:
   - `search_products`
   - `create_checkout`
   - `get_discovery`

---

### Desktop Agents (MCP Server)
*Target: Claude Desktop, VS Code, Cursor*

Desktop apps require a local connector bridge.

We provide a ready-to-use connector in the `connectors/` folder.

#### **Quick Setup for Claude Desktop:**

1. **Locate the connector:**
   Inside this plugin's folder: `connectors/desktop-mcp-server/`

2. **Install dependencies (One-time):**
   ```bash
   cd connectors/desktop-mcp-server
   npm install
   ```

3. **Configure Claude Desktop:**
   Edit your config file (e.g., `~/Library/Application Support/Claude/claude_desktop_config.json`) and add:

   ```json
   {
     "mcpServers": {
       "my-wordpress-store": {
         "command": "node",
         "args": [
           "/ABSOLUTE/PATH/TO/connectors/desktop-mcp-server/index.js",
           "--url", "https://your-wordpress-site.com"
         ]
       }
     }
   }
   ```
   *(Replace `/ABSOLUTE/PATH/TO/...` with the real path on your machine)*

4. **Restart Claude.** 
   You can now ask Claude: *"Search for t-shirts on my store"*

### Standard HTTP MCP Clients
*Target: Custom MCP clients supporting JSON-RPC over HTTP*

This plugin exposes a **native MCP Server endpoint** at:
`POST /wp-json/ucp/v1/mcp`

Supported Methods:
*   `initialize`
*   `tools/list`
*   `tools/call`

## 📡 REST API Reference

For developers building custom integrations:

### Discovery
```http
GET /wp-json/ucp/v1/discovery
```

### Search
```http
POST /wp-json/ucp/v1/search
Content-Type: application/json

{
  "query": "hoodie"
}
```

### Checkout
```http
POST /wp-json/ucp/v1/checkout
Content-Type: application/json

{
  "items": [
    { "id": 123, "quantity": 1 }
  ]
}
```

## Verification Prompts

Use this sequence to verify the agent's capability to search and purchase products.

**1. Discovery**
> "Hello! Can you check `get_discovery` to tell me what protocol this store uses and what its capabilities are?"

*Success:* Agent confirms UCP protocol (v0.1.0) and lists capabilities (search, checkout).

**2. Search**
> "Please search for 'plants' and list the available options with their prices."

*Success:* Agent lists found products (e.g., "Money Plant - $20").

**3. Checkout**
> "I would like to buy 2 Money Plants. Please create a checkout session for me."

*Success:* Agent returns an Order ID, Total Cost, and a Payment Link.

## Privacy & External Services

This plugin provides functionality to expose your WooCommerce store data to AI agents and external systems via the Universal Commerce Protocol (UCP).

### Data Exposure
When this plugin is activated, the following data is made available via public REST API endpoints:
- Product catalog information (names, prices, descriptions, images)
- Store information (name, currency)
- Order creation capabilities

### External Service - WebMCP
To enable browser-based AI agents, this plugin loads the `@mcp-b/global` JavaScript library from unpkg.com CDN on your site's frontend. This is a standard polyfill that implements the Web Model Context API.

**What is loaded:** `https://unpkg.com/@mcp-b/global@latest/dist/index.iife.js`
**Purpose:** Exposes commerce tools to browser-based AI assistants
**Privacy:** The CDN provider (unpkg.com) may log HTTP requests for this file as part of normal CDN operation. No personal user data is transmitted to this service.

By activating this plugin, you consent to:
1. Making your product catalog publicly discoverable via UCP endpoints
2. Loading the @mcp-b/global polyfill from unpkg.com CDN
3. Allowing AI agents to interact with your store's commerce capabilities

For more information about UCP, visit: [https://ucp.dev](https://ucp.dev)

## Future Roadmap

*   Implement full UCP JSON Schema validation.
*   Add support for `fulfillment` and `payment` protocol messages.
*   Add API Key authentication for authorized agents.

## Changelog

### 1.3.0
*   **Search**: Implemented **Unified Search Strategy**. The `search_products` tool now executes Exact, Category, and Fuzzy searches in parallel and deduplicates results. This provides a natural, human-like discovery experience (e.g., searching for "Plants" finds the category, specific plant products, and singular variations all at once).

### 1.2.0
*   **MCP Server:** Added full support for standard MCP handshake (`initialize`, `notifications/initialized`) and standard method aliases (`tools/list`, `tools/call`). This enables direct compatibility with generic HTTP MCP clients.

### 1.1.0
*   **Compliance:** Renamed WebMCP tools to `search_products`, `create_checkout`, `get_discovery` to match UCP standard.
*   **Discovery:** Enhanced `get_discovery` response with explicit Language ("English") and Protocol version ("UCP v0.1.0").
*   **Search:** Added Smart Search Fallback (Category match & Singularization) to handle natural language queries like "plants" better.

### 1.0.1
*   Fixed: Race condition with WebMCP initialization that caused "Tool dummyTool is already registered" errors when using browser extensions like AWL Tool. The polyfill injection is now delayed until the window load event.
