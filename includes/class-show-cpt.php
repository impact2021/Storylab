<?php
/**
 * Show Custom Post Type
 *
 * Registers the `storylab_show` CPT and its admin meta boxes.
 * Each show stores: date, time, location, and a linked WooCommerce product ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Storylab_Show_CPT {

	const POST_TYPE = 'storylab_show';

	/** Default venue used when creating a new show. */
	const DEFAULT_LOCATION = '7:00pm at Crave Cafe, 6 Morningside Drive, Morningside';

	public function __construct() {
		add_action( 'init',                  array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes',        array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post',             array( $this, 'save_meta_boxes' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'admin_column_data' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// CPT registration
	// -------------------------------------------------------------------------

	public function register_post_type() {
		$labels = array(
			'name'               => 'Shows',
			'singular_name'      => 'Show',
			'add_new'            => 'Add New Show',
			'add_new_item'       => 'Add New Show',
			'edit_item'          => 'Edit Show',
			'new_item'           => 'New Show',
			'view_item'          => 'View Show',
			'search_items'       => 'Search Shows',
			'not_found'          => 'No shows found',
			'not_found_in_trash' => 'No shows found in Trash',
			'menu_name'          => 'Shows',
		);

		register_post_type( self::POST_TYPE, array(
			'labels'       => $labels,
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => 'storylab-tickets',
			'supports'     => array( 'title', 'editor', 'thumbnail' ),
			'menu_icon'    => 'dashicons-tickets-alt',
		) );
	}

	// -------------------------------------------------------------------------
	// Meta boxes
	// -------------------------------------------------------------------------

	public function add_meta_boxes() {
		add_meta_box(
			'storylab_show_details',
			'Show Details',
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'storylab_show_meta', 'storylab_show_nonce' );

		$date     = get_post_meta( $post->ID, '_show_date', true );
		$time     = get_post_meta( $post->ID, '_show_time', true );
		$location = get_post_meta( $post->ID, '_show_location', true );
		$product  = get_post_meta( $post->ID, '_show_product_id', true );

		if ( '' === $time ) {
			$time = '7:00 PM';
		}
		if ( '' === $location ) {
			$location = self::DEFAULT_LOCATION;
		}

		// Build product dropdown list.
		$products = wc_get_products( array( 'status' => 'publish', 'limit' => -1 ) );
		?>
		<table class="form-table">
			<tr>
				<th><label for="show_date">Date</label></th>
				<td>
					<input type="date" id="show_date" name="show_date"
					       value="<?php echo esc_attr( $date ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th><label for="show_time">Time</label></th>
				<td>
					<input type="text" id="show_time" name="show_time"
					       value="<?php echo esc_attr( $time ); ?>" class="regular-text"
					       placeholder="e.g. 7:00 PM" />
				</td>
			</tr>
			<tr>
				<th><label for="show_location">Venue / Location</label></th>
				<td>
					<input type="text" id="show_location" name="show_location"
					       value="<?php echo esc_attr( $location ); ?>" class="large-text"
					       placeholder="<?php echo esc_attr( self::DEFAULT_LOCATION ); ?>" />
					<p class="description">Full venue name and address.</p>
				</td>
			</tr>
			<tr>
				<th><label for="show_product_id">WooCommerce Product</label></th>
				<td>
					<select id="show_product_id" name="show_product_id" style="min-width:300px;">
						<option value="">— Select a product —</option>
						<?php foreach ( $products as $p ) : ?>
							<option value="<?php echo esc_attr( $p->get_id() ); ?>"
								<?php selected( $product, $p->get_id() ); ?>>
								<?php echo esc_html( $p->get_name() ); ?> (ID: <?php echo $p->get_id(); ?>)
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						The WooCommerce product customers will purchase to get a ticket for this show.
						Make sure "Enable Name-Your-Price" is ticked on that product.
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_meta_boxes( $post_id ) {
		if ( ! isset( $_POST['storylab_show_nonce'] )
		     || ! wp_verify_nonce( $_POST['storylab_show_nonce'], 'storylab_show_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array( 'show_date', 'show_time', 'show_location', 'show_product_id' );
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, '_' . $field, sanitize_text_field( $_POST[ $field ] ) );
			}
		}

		// Keep product ↔ show association on the product side too.
		$product_id = isset( $_POST['show_product_id'] ) ? absint( $_POST['show_product_id'] ) : 0;
		if ( $product_id ) {
			update_post_meta( $product_id, '_storylab_show_id', $post_id );
		}
	}

	// -------------------------------------------------------------------------
	// Admin list columns
	// -------------------------------------------------------------------------

	public function admin_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['show_date']    = 'Date';
				$new['show_time']    = 'Time';
				$new['show_product'] = 'Product';
			}
		}
		return $new;
	}

	public function admin_column_data( $column, $post_id ) {
		switch ( $column ) {
			case 'show_date':
				$d = get_post_meta( $post_id, '_show_date', true );
				echo $d ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $d ) ) ) : '—';
				break;
			case 'show_time':
				echo esc_html( get_post_meta( $post_id, '_show_time', true ) ?: '—' );
				break;
			case 'show_product':
				$pid = get_post_meta( $post_id, '_show_product_id', true );
				if ( $pid ) {
					$p = wc_get_product( $pid );
					if ( $p ) {
						echo '<a href="' . get_edit_post_link( $pid ) . '">' . esc_html( $p->get_name() ) . '</a>';
						break;
					}
				}
				echo '—';
				break;
		}
	}

	// -------------------------------------------------------------------------
	// Static helpers
	// -------------------------------------------------------------------------

	/**
	 * Get all show meta for a given show post ID.
	 *
	 * @param int $show_id
	 * @return array|false
	 */
	public static function get_show_data( $show_id ) {
		$post = get_post( $show_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}
		return array(
			'id'         => $post->ID,
			'name'       => $post->post_title,
			'date'       => get_post_meta( $post->ID, '_show_date', true ),
			'time'       => get_post_meta( $post->ID, '_show_time', true ) ?: '7:00 PM',
			'location'   => get_post_meta( $post->ID, '_show_location', true ) ?: self::DEFAULT_LOCATION,
			'product_id' => (int) get_post_meta( $post->ID, '_show_product_id', true ),
		);
	}

	/**
	 * Find the show linked to a given product ID.
	 *
	 * @param int $product_id
	 * @return array|false Show data array or false.
	 */
	public static function get_show_for_product( $product_id ) {
		$show_id = get_post_meta( $product_id, '_storylab_show_id', true );
		if ( ! $show_id ) {
			// Fallback: query by product meta on shows.
			$shows = get_posts( array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => 1,
				'meta_key'       => '_show_product_id',
				'meta_value'     => $product_id,
				'post_status'    => 'publish',
			) );
			if ( empty( $shows ) ) {
				return false;
			}
			$show_id = $shows[0]->ID;
		}
		return self::get_show_data( $show_id );
	}
}
