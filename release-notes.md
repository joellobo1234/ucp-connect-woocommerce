### What's New in v2.0.0 🎉

**MAJOR REFACTOR**: Complete rewrite to use WooCommerce's official Store API (`/wc/store/v1`), following the exact pattern from [Shopify's UCP Proxy WooCommerce adapter](https://github.com/Shopify/ucp-proxy/blob/main/docs/woocommerce.md).

#### Breaking Changes:
- Replaced custom `UCP_Cart_Manager` (WC_Session-based) with `UCP_Store_API` (stateless)
- Checkout IDs now use the format `{order_id}:{cart_token}` (base64 encoded)
- Cart state is maintained via `Cart-Token` header instead of PHP sessions

#### Why This Matters:
- ✅ **Stateless**: No more session hacks or cookie dependencies
- ✅ **Official API**: Uses WooCommerce's headless commerce endpoints
- ✅ **Aligned with Shopify**: Matches the reference UCP Proxy implementation
- ✅ **More Reliable**: Proper cart token flow eliminates checkout errors

#### Technical Details:
The new implementation uses these WooCommerce Store API endpoints:
- `/wc/store/v1/cart/add-item` - Add products to cart
- `/wc/store/v1/cart/apply-coupon` - Apply discount codes
- `/wc/store/v1/checkout` - Get/update checkout state and create orders

This is the **correct** way to build headless WooCommerce integrations.

### Installation
Download `ucp-connect-woocommerce-2.0.0.zip` and install/update via your WordPress Plugins dashboard.

**Note**: This is a major version bump because the internal architecture changed significantly, though the UCP API contract remains the same.
