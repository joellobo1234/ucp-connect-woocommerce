<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * UCP Data Mapper.
 * Maps WooCommerce objects to UCP schemas.
 */
class UCP_Mapper
{

    /**
     * Map a WC_Product to a UCP Item.
     *
     * @param WC_Product $product WooCommerce product.
     * @return array UCP Item schema.
     */
    public function map_product_to_item($product)
    {
        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_url($image_id) : '';

        return array(
            'id' => (string) $product->get_id(),
            'name' => $product->get_name(),
            'description' => $product->get_short_description() ?: $product->get_description(),
            'sku' => $product->get_sku(),
            'url' => $product->get_permalink(),
            'price' => array(
                'value' => (float) $product->get_price(),
                'currency' => get_woocommerce_currency(),
            ),
            'images' => $image_url ? array($image_url) : array(),
            'availability' => $product->is_in_stock() ? 'IN_STOCK' : 'OUT_OF_STOCK',
            'attributes' => $this->get_attributes($product),
        );
    }

    /**
     * Get product attributes in a simplified format.
     *
     * @param WC_Product $product
     * @return array
     */
    private function get_attributes($product)
    {
        $attributes = array();
        foreach ($product->get_attributes() as $attribute) {
            // This is a basic extraction, might need refining for variations
            $attributes[$attribute->get_name()] = $attribute->get_options();
        }
        return $attributes;
    }
}
