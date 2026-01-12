#!/usr/bin/env node

/**
 * 🌉 UCP Connect - Desktop MCP Bridge
 * 
 * Bridges a remote WordPress site (running UCP Connect) with local AI agents (Claude Desktop, etc).
 * 
 * Usage:
 *   node index.js --url "https://mystore.com"
 */

const http = require('http');
const https = require('https');
const readline = require('readline');
const parseArgs = require('minimist');

// --- Configuration ---

const args = parseArgs(process.argv.slice(2));
const WP_SITE_URL = args.url || process.env.WP_SITE_URL || 'http://localhost:8080';

// Normalize URL (remove trailing slash)
const BASE_URL = WP_SITE_URL.replace(/\/$/, "");

// We try two common endpoint patterns automatically
const ENDPOINTS = [
    `${BASE_URL}/wp-json/ucp/v1/mcp`,                    // Pretty Permalinks
    `${BASE_URL}/index.php?rest_route=/ucp/v1/mcp`       // Ugly Permalinks fallbacks
];

// --- Helpers ---

function log(...messages) {
    // We MUST output logs to stderr, because stdout is reserved for the MCP protocol JSON
    console.error(`[UCP-Bridge]`, ...messages);
}

function makeRequest(endpoint, data) {
    return new Promise((resolve, reject) => {
        const urlObj = new URL(endpoint);
        const lib = urlObj.protocol === 'https:' ? https : http;

        const options = {
            hostname: urlObj.hostname,
            port: urlObj.port || (urlObj.protocol === 'https:' ? 443 : 80),
            path: urlObj.pathname + urlObj.search,
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'User-Agent': 'UCP-Desktop-Connector/1.0'
            },
            timeout: 10000 // 10s timeout
        };

        const req = lib.request(options, (res) => {
            let body = '';
            res.on('data', chunk => body += chunk);
            res.on('end', () => {
                if (res.statusCode !== 200) {
                    reject(new Error(`HTTP ${res.statusCode}: ${body.substring(0, 200)}`));
                    return;
                }
                try {
                    resolve(JSON.parse(body));
                } catch {
                    reject(new Error(`Invalid JSON response`));
                }
            });
        });

        req.on('error', reject);
        req.on('timeout', () => {
            req.destroy();
            reject(new Error('Request timed out'));
        });

        // Write request body
        if (data) {
            req.write(JSON.stringify(data));
        }
        req.end();
    });
}

// Helper: Try endpoints until one works
async function callWordPress(requestData) {
    // We try endpoints sequentially
    for (const endpoint of ENDPOINTS) {
        try {
            const response = await makeRequest(endpoint, requestData);
            return response; // Success
        } catch (e) {
            // log(`Failed connecting to ${endpoint}: ${e.message}`);
            // Continue to next endpoint...
        }
    }
    throw new Error(`Could not connect to WordPress at ${BASE_URL}. Is the UCP Plugin activated?`);
}

// --- Protocol Handler ---

async function handleMCPRequest(request) {
    const { jsonrpc, id, method } = request;

    // 1. Initialize (Handshake)
    if (method === 'initialize') {
        return {
            jsonrpc: '2.0',
            id,
            result: {
                protocolVersion: '2024-11-05',
                serverInfo: {
                    name: 'ucp-wordpress-bridge',
                    version: '1.0.0'
                },
                capabilities: {
                    tools: {
                        listChanged: true
                    }
                }
            }
        };
    }

    // 2. Initialized Notification
    if (method === 'notifications/initialized') {
        return null; // No response needed
    }

    // 3. List Tools
    if (method === 'tools/list') {
        try {
            const wpResponse = await callWordPress({
                jsonrpc: '2.0',
                method: 'list_tools',
                id: 1
            });

            if (wpResponse.result && wpResponse.result.tools) {
                return {
                    jsonrpc: '2.0',
                    id,
                    result: {
                        tools: wpResponse.result.tools
                    }
                };
            }
        } catch (e) {
            log("Error fetching tools:", e.message);
            // Fallback: Return empty list rather than crashing connection
            return { jsonrpc: '2.0', id, result: { tools: [] } };
        }
    }

    // 4. Call Tool
    if (method === 'tools/call') {
        const { name, arguments: args } = request.params;
        log(`Calling Tool: ${name}`);

        try {
            const wpResponse = await callWordPress({
                jsonrpc: '2.0',
                method: 'call_tool',
                params: { name, arguments: args },
                id: 1
            });

            if (wpResponse.result) {
                return {
                    jsonrpc: '2.0',
                    id,
                    result: {
                        content: [{ type: 'text', text: JSON.stringify(wpResponse.result, null, 2) }]
                    }
                };
            } else if (wpResponse.error) {
                throw new Error(wpResponse.error.message);
            }
            throw new Error("Empty response from server");

        } catch (e) {
            log(`Tool Error: ${e.message}`);
            return {
                jsonrpc: '2.0',
                id,
                isError: true,
                result: {
                    content: [{ type: 'text', text: `Error: ${e.message}` }]
                }
            };
        }
    }

    // Unknown Method
    return {
        jsonrpc: '2.0',
        id,
        error: {
            code: -32601,
            message: `Method not found: ${method}`
        }
    };
}

// --- Main Loop ---

async function main() {
    log(`Starting Bridge for: ${BASE_URL}`);

    // Use unbuffered stdout for JSON-RPC
    process.stdout.setDefaultEncoding('utf8');

    const rl = readline.createInterface({
        input: process.stdin,
        output: process.stdout,
        terminal: false,
        crlfDelay: Infinity
    });

    rl.on('line', async (line) => {
        if (!line.trim()) return;

        try {
            const request = JSON.parse(line);
            const response = await handleMCPRequest(request);

            if (response) {
                process.stdout.write(JSON.stringify(response) + '\n');
            }
        } catch (e) {
            log('Fatal Protocol Error:', e.message);
        }
    });

    // Keep alive
    rl.on('close', () => process.exit(0));
}

main();
