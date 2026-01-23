### What's New vs v1.3.2
- **Stateless Cart Architecture**: Implemented a secure `Cart-Token` system. Carts are now managed via stateless sessions (`includes/class-ucp-cart-manager.php`) instead of creating premature "Pending Orders".
- **Interactive Checkout**: The API now supports `create`, `update` (add items/coupons), and `complete` (convert to order) endpoints.
- **Enhanced Data Mapping**: Products now expose full image galleries, global/custom attributes, and physical dimensions.
- **Security**: "Order Spam" in the admin dashboard is eliminated; orders are only created upon final checkout intent.

### Installation
Download `ucp-connect-woocommerce-1.3.3.zip` and install/update via your WordPress Plugins dashboard.
