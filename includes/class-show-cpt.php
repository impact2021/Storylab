<?php
/**
 * Show data helpers
 *
 * Show fields (date, time, location) are stored directly on the WooCommerce
 * product — there is no separate Show post type.  These static helpers give
 * the rest of the plugin a single place to read that data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Storylab_Show_CPT {

	/** Default venue address used as a placeholder on new products. */
	const DEFAULT_VENUE = 'Crave Cafe, 6 Morningside Drive, Morningside';

	/** Default show time used when a product has no time set. */
	const DEFAULT_TIME = '7:00 PM';

	// -------------------------------------------------------------------------
	// Static helpers
	// -------------------------------------------------------------------------

	/**
	 * Return show data for a given product ID, reading fields stored on the
	 * product itself.  Returns false when the product is not a ticket product.
	 *
	 * @param int $product_id
	 * @return array|false
	 */
	public static function get_show_for_product( $product_id ) {
		if ( 'yes' !== get_post_meta( $product_id, '_storylab_nyp', true ) ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}
		return array(
			'id'         => $product_id,
			'name'       => $product->get_name(),
			'date'       => get_post_meta( $product_id, '_show_date', true ),
			'time'       => get_post_meta( $product_id, '_show_time', true ) ?: self::DEFAULT_TIME,
			'location'   => get_post_meta( $product_id, '_show_location', true ) ?: self::DEFAULT_VENUE,
			'product_id' => $product_id,
		);
	}
}
