# UCP Connect for WooCommerce

This plugin exposes your WooCommerce store inventory and checkout capabilities to the **Universal Commerce Protocol (UCP)** network, allowing AI agents to discover, search, and purchase products from your site.

## key Features

*   **Discovery Endpoint**: `GET /wp-json/ucp/v1/discovery` - Lets agents know your store is UCP-compatible.
*   **Product Search**: `POST /wp-json/ucp/v1/search` - Allows agents to find products matches (supports exact match, category match, and fuzzy/stemming).
*   **Agentic Checkout**: `POST /wp-json/ucp/v1/checkout` - Enables agents to create orders programmatically.

## Installation

1.  Upload the `ucp-connect-woocommerce` folder to your `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Your UCP endpoints are now live under `/wp-json/ucp/v1/`.

## 🤖 Using for AI Agent Development

This plugin allows AI agents to "talk" to your WooCommerce store using the **Universal Commerce Protocol (UCP)**.

There are two ways to connect, depending on your agent type:

### **Scenario A: Browser Agents (Web-based)**
*Examples: Chrome Extensions, Web-based Chatbots, Custom Web UIs*

**It Just Works™**. No extra setup required!
1. Install and activate this plugin.
2. The plugin automatically injects the `navigator.modelContext` API into your store's frontend.
3. Your browser agent can immediately detect and use the tools:
   - `search_products`
   - `create_checkout`
   - `get_discovery`

---

### **Scenario B: Desktop Agents (Local)**
*Examples: Claude Desktop App, Cline (VS Code), Cursor*

Because desktop apps run on your computer and cannot securely "inject" into a remote website, you need a small **connector bridge** to link them to your store.

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

### 1.1.0
*   **Compliance:** Renamed WebMCP tools to `search_products`, `create_checkout`, `get_discovery` to match UCP standard.
*   **Discovery:** Enhanced `get_discovery` response with explicit Language ("English") and Protocol version ("UCP v0.1.0").
*   **Search:** Added Smart Search Fallback (Category match & Singularization) to handle natural language queries like "plants" better.

### 1.0.1
*   Fixed: Race condition with WebMCP initialization that caused "Tool dummyTool is already registered" errors when using browser extensions like AWL Tool. The polyfill injection is now delayed until the window load event.
