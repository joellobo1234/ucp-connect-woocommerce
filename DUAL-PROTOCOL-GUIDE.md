# Dual-Protocol Integration Guide

## Overview
The **UCP Connect for WooCommerce** plugin now supports **both** server-side and client-side agent communication protocols using **industry-standard implementations**:

1. **UCP MCP (Server-Side)**: JSON-RPC 2.0 endpoint at `/wp-json/ucp/v1/mcp`
2. **WebMCP (Client-Side)**: Browser-accessible tools via **`navigator.modelContext`** (using **`@mcp-b/global`**)

---

## Industry Standard: @mcp-b Packages

This plugin uses the **official @mcp-b packages** for WebMCP integration:

- **`@mcp-b/global`**: The core polyfill that implements the W3C Web Model Context API
- **API**: `window.navigator.modelContext.provideContext({ tools: [...] })`

### Why @mcp-b?
- ✅ **W3C Standard**: Aligns with the emerging Web Model Context API specification
- ✅ **Universal Compatibility**: Works with Claude, ChatGPT, Gemini, and all major AI agents
- ✅ **Chromium Native**: Has a bridge to Chromium's built-in AI capabilities
- ✅ **Auto-Discovery**: Agents automatically detect `navigator.modelContext` presence

---

## Scenario 1: Sites Already Having WebMCP

### What Happens?
If your WordPress site **already has `navigator.modelContext`** enabled (e.g., via another plugin using `@mcp-b/global`), our plugin **coexists peacefully** and **extends** the tool registry.

### How It Works:
1. **Detection**: The plugin checks if `window.navigator?.modelContext` already exists.
2. **Skip CDN Loading**: If detected, it skips loading the `@mcp-b/global` polyfill (no duplicate scripts).
3. **Tool Registration**: It calls `navigator.modelContext.provideContext({ tools: [...] })` to register UCP commerce tools.
4. **Smart Merging**: The `@mcp-b/global` library automatically handles tool merging—if a tool with the same name exists, it updates it; otherwise, it adds it.

### Example:
```javascript
// Existing tools from another @mcp-b implementation
navigator.modelContext.provideContext({
  tools: [{ name: 'custom_form_submit', ... }]
});

// After UCP Connect loads:
navigator.modelContext.provideContext({
  tools: [
    { name: 'ucp_search_products', ... },
    { name: 'ucp_create_checkout', ... },
    { name: 'ucp_get_discovery', ... }
  ]
});

// Result: Agents see all 4 tools (1 custom + 3 UCP)
```

---

## Scenario 2: Sites Without Existing WebMCP

### What Happens?
If your site does **not** have `navigator.modelContext` previously implemented, our plugin **initializes it from scratch** using the CDN-hosted `@mcp-b/global` library.

### How It Works:
1. **CDN Bootstrap**: The plugin injects:
   ```html
   <script src="https://unpkg.com/@mcp-b/global@latest/dist/index.iife.js"></script>
   ```
2. **Polyfill Initialization**: Once loaded, `navigator.modelContext` becomes available globally.
3. **Tool Registration**: The plugin registers the three UCP commerce tools using the standard API.
4. **Ready to Use**: Any browser-based agent (Chrome extensions, Claude, etc.) can now discover and call these tools.

### Example Agent Usage:
```javascript
// Agent discovers tools
const discovery = await navigator.modelContext.discoverTools();
console.log(discovery); // Shows UCP commerce tools

// Agent executes a search (internally calls the tool's execute function)
const results = await navigator.modelContext.callTool('ucp_search_products', { 
  query: 'leather jacket' 
});
```

---

## Architecture Diagram

```
┌─────────────────────────────────────────────┬─────────────────────────────────┐
│          SERVER-TO-SERVER AGENTS            │     BROWSER-BASED AGENTS        │
├─────────────────────────────────────────────┼─────────────────────────────────┤
│  Claude Desktop, Custom Bots, etc.          │   Chrome Extensions, Claude     │
│             ↓                                │   Web UI, Gemini, etc.         │
│   POST /wp-json/ucp/v1/mcp                  │             ↓                   │
│   (JSON-RPC 2.0)                            │   window.__MCP_CALL__()         │
│             ↓                                │   (JavaScript)                  │
├─────────────────────────────────────────────┼─────────────────────────────────┤
│         UCP_MCP_Server                      │        UCP_WebMCP               │
│     (class-ucp-mcp.php)                     │    (class-ucp-webmcp.php)       │
├─────────────────────────────────────────────┴─────────────────────────────────┤
│                         UCP_API (Backend)                                     │
│                      (class-ucp-api.php)                                      │
├───────────────────────────────────────────────────────────────────────────────┤
│                      WooCommerce Data Layer                                   │
└───────────────────────────────────────────────────────────────────────────────┘
```

---

## Key Technical Details

### WebMCP Tool Structure
Each tool exposed by the plugin follows this schema:
```javascript
{
  name: 'ucp_search_products',
  description: 'Search for products in this WooCommerce store',
  inputSchema: {
    type: 'object',
    properties: {
      query: { type: 'string', description: 'Search query' }
    },
    required: ['query']
  },
  handler: async function(args) {
    // Calls WordPress REST API internally
    return await fetch('/wp-json/ucp/v1/search', { ... });
  }
}
```

### Security Considerations
- **REST API Nonces**: All browser-initiated API calls include WordPress nonces for CSRF protection.
- **Public Endpoints**: Discovery and Search are public by default (as per UCP spec).
- **Checkout Authentication**: In production, you may want to add API key validation for checkout operations.

---

## Testing Both Scenarios

### Test Scenario 1 (Existing WebMCP):
1. Install a plugin that exposes WebMCP (or manually add `window.__MCP_TOOLS__` in your theme's footer).
2. Activate UCP Connect.
3. Open browser console and run: `console.log(window.__MCP_TOOLS__)`.
4. **Expected**: You should see both the existing tools and the new UCP tools.

### Test Scenario 2 (Fresh Install):
1. On a clean WooCommerce site, activate UCP Connect.
2. Open browser console and run: `console.log(window.__MCP_TOOLS__)`.
3. **Expected**: You should see exactly 3 UCP tools.
4. Test a tool: `await window.__MCP_CALL__('ucp_get_discovery', {})`.

---

## Conclusion

The plugin is designed to be **additive, not disruptive**. Whether your site already speaks the language of agentic commerce or is just getting started, UCP Connect seamlessly integrates both server-side and client-side protocols to make your WooCommerce store universally accessible to AI agents.
