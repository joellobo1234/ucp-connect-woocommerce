### What's New vs v1.3.6
- **Fix: Complete Order Creation Rewrite**: Completely rewrote the order creation logic to manually build WooCommerce orders from cart data. This fixes the persistent payment link generation error by ensuring all cart items, addresses, coupons, and totals are properly transferred to the order.
- **Improved Reliability**: Orders are now created using `wc_create_order()` with explicit item addition, address setting, and coupon application, bypassing the problematic `WC_Checkout::create_order()` method.

### Installation
Download `ucp-connect-woocommerce-1.3.7.zip` and install/update via your WordPress Plugins dashboard.
