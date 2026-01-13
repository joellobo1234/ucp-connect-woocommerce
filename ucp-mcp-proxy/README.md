# UCP MCP Proxy

A simple proxy connector that allows local AI agents (Claude Desktop, Cline, etc.) to communicate with UCP-enabled WordPress sites via the Model Context Protocol (MCP).

## 🚀 How to Use

You do not need to install this globally. You can run it directly using `npx`.

### 1. Configure Your Agent

Add the following to your MCP configuration file (e.g., `claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "my-store": {
      "command": "npx",
      "args": [
        "-y",
        "ucp-mcp-proxy",
        "https://your-wordpress-site.com/wp-json/ucp/v1/mcp"
      ]
    }
  }
}
```

Replace `https://your-wordpress-site.com/wp-json/ucp/v1/mcp` with your actual UCP endpoint.

### 2. Debugging

If you are having connection issues, you can append the `--debug` flag. The logs will be printed to your agent's stderr logs.

```json
      "args": [
        "-y",
        "ucp-mcp-proxy",
        "https://your-wordpress-site.com/wp-json/ucp/v1/mcp",
        "--debug"
      ]
```

## 📦 For Developers

To publish this package:

```bash
npm publish --access public
```
