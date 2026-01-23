### What's New vs v1.3.4
- **Fix: Token-Based Checkout**: Resolved a critical 404 error when updating carts or completing checkout. The API now correctly accepts alphanumeric Base64 tokens instead of expecting only numeric IDs.
- **Improved: Product Context**: The `search_products` tool now exposes product descriptions (stripped of HTML) to the AI agent. This allows agents to understand product details, usage, and care instructions even if specific dimension attributes are missing.
- **Fix: Coupon Visibility**: Hardened the `get_available_discounts` logic to forcefully suppress third-party filters, ensuring that published coupons (like 'plants10') are always visible to the agent.

### Installation
Download `ucp-connect-woocommerce-1.3.5.zip` and install/update via your WordPress Plugins dashboard.
