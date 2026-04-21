<?php
/**
 * WooCommerce Integration — Name-Your-Price Tickets
 *
 * Adds a "name your price" input to products that are linked to a show,
 * persists the custom price through the cart/checkout flow, and exposes
 * show details on the product page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Storylab_Ticket_Woo {

	public function __construct() {
		// Product admin tab.
		add_filter( 'woocommerce_product_data_tabs',   array( $this, 'add_product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_tab' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_tab' ) );

		// Front-end: show details + price input.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'output_price_field' ), 5 );

		// Enqueue frontend assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Cart: store custom price in item data.
		add_filter( 'woocommerce_add_cart_item_data',    array( $this, 'store_price_in_cart' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_price' ), 10, 3 );

		// Apply the stored price every time the cart recalculates.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_custom_price' ), 20 );

		// Display the chosen price in the cart/checkout line item.
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_custom_price_in_cart' ), 10, 2 );

		// Persist the chosen price on the order line item.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_price_to_order_item' ), 10, 4 );
	}

	// -------------------------------------------------------------------------
	// Product admin tab
	// -------------------------------------------------------------------------

	public function add_product_tab( $tabs ) {
		$tabs['storylab_ticket'] = array(
			'label'    => 'Show Ticket',
			'target'   => 'storylab_ticket_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 80,
		);
		return $tabs;
	}

	public function render_product_tab() {
		global $post;
		wp_nonce_field( 'storylab_product_meta', 'storylab_product_nonce' );

		$show_id  = get_post_meta( $post->ID, '_storylab_show_id', true );
		$nyp      = get_post_meta( $post->ID, '_storylab_nyp', true );
		$min_price = get_post_meta( $post->ID, '_storylab_min_price', true );

		// Fetch all published shows.
		$shows = get_posts( array(
			'post_type'      => Storylab_Show_CPT::POST_TYPE,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		?>
		<div id="storylab_ticket_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<p class="form-field">
					<label for="storylab_show_id">Linked Show</label>
					<select id="storylab_show_id" name="storylab_show_id" style="width:50%">
						<option value="">— None —</option>
						<?php foreach ( $shows as $show ) : ?>
							<option value="<?php echo esc_attr( $show->ID ); ?>"
								<?php selected( $show_id, $show->ID ); ?>>
								<?php echo esc_html( $show->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="description">Select the show this product sells tickets for.</span>
				</p>
				<p class="form-field">
					<label for="storylab_nyp">
						<input type="checkbox" id="storylab_nyp" name="storylab_nyp"
						       value="yes" <?php checked( $nyp, 'yes' ); ?> />
						Enable Name-Your-Price
					</label>
					<span class="description">Customers enter their own price at checkout.</span>
				</p>
				<p class="form-field">
					<label for="storylab_min_price">Minimum Price (<?php echo get_woocommerce_currency_symbol(); ?>)</label>
					<input type="number" id="storylab_min_price" name="storylab_min_price"
					       value="<?php echo esc_attr( $min_price ); ?>"
					       min="0" step="0.01" style="width:80px" />
					<span class="description">Leave blank or 0 for no minimum (pure gift).</span>
				</p>
			</div>
		</div>
		<?php
	}

	public function save_product_tab( $product_id ) {
		if ( ! isset( $_POST['storylab_product_nonce'] )
		     || ! wp_verify_nonce( $_POST['storylab_product_nonce'], 'storylab_product_meta' ) ) {
			return;
		}

		$show_id   = isset( $_POST['storylab_show_id'] ) ? absint( $_POST['storylab_show_id'] ) : 0;
		$nyp       = isset( $_POST['storylab_nyp'] ) && 'yes' === $_POST['storylab_nyp'] ? 'yes' : 'no';
		$min_price = isset( $_POST['storylab_min_price'] ) ? wc_format_decimal( $_POST['storylab_min_price'] ) : '';

		update_post_meta( $product_id, '_storylab_show_id',  $show_id );
		update_post_meta( $product_id, '_storylab_nyp',      $nyp );
		update_post_meta( $product_id, '_storylab_min_price', $min_price );

		// Keep the reverse link on the show post.
		if ( $show_id ) {
			update_post_meta( $show_id, '_show_product_id', $product_id );
		}
	}

	// -------------------------------------------------------------------------
	// Front-end: price input and show info
	// -------------------------------------------------------------------------

	public function enqueue_scripts() {
		if ( ! is_product() ) {
			return;
		}
		global $post;
		$nyp = get_post_meta( $post->ID, '_storylab_nyp', true );
		if ( 'yes' !== $nyp ) {
			return;
		}
		wp_enqueue_style(
			'storylab-frontend',
			STORYLAB_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			STORYLAB_VERSION
		);
		wp_enqueue_script(
			'storylab-frontend',
			STORYLAB_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			STORYLAB_VERSION,
			true
		);
		$min_price = (float) get_post_meta( $post->ID, '_storylab_min_price', true );
		wp_localize_script( 'storylab-frontend', 'storylabData', array(
			'currency'  => get_woocommerce_currency_symbol(),
			'minPrice'  => $min_price,
			'minMsg'    => sprintf(
				/* translators: %s = formatted minimum price */
				__( 'Please enter at least %s.', 'storylab-tickets' ),
				wc_price( $min_price )
			),
		) );
	}

	public function output_price_field() {
		global $post;
		if ( ! $post ) {
			return;
		}
		$nyp = get_post_meta( $post->ID, '_storylab_nyp', true );
		if ( 'yes' !== $nyp ) {
			return;
		}

		$show = Storylab_Show_CPT::get_show_for_product( $post->ID );
		$min  = (float) get_post_meta( $post->ID, '_storylab_min_price', true );
		$sym  = get_woocommerce_currency_symbol();
		?>
		<?php if ( $show ) : ?>
		<div class="storylab-show-info">
			<?php if ( $show['date'] ) : ?>
				<div class="storylab-show-meta">
					<span class="label">Date:</span>
					<span class="value"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $show['date'] ) ) ); ?></span>
				</div>
			<?php endif; ?>
			<div class="storylab-show-meta">
				<span class="label">Time:</span>
				<span class="value"><?php echo esc_html( $show['time'] ); ?></span>
			</div>
			<div class="storylab-show-meta">
				<span class="label">Venue:</span>
				<span class="value"><?php echo esc_html( $show['location'] ); ?></span>
			</div>
		</div>
		<?php endif; ?>

		<div class="storylab-nyp-wrap">
			<label for="storylab_price" class="storylab-nyp-label">
				<?php esc_html_e( 'Name your price', 'storylab-tickets' ); ?>
				<?php if ( $min > 0 ) : ?>
					<small>(<?php printf( esc_html__( 'minimum %s', 'storylab-tickets' ), esc_html( $sym . number_format( $min, 2 ) ) ); ?>)</small>
				<?php endif; ?>
			</label>
			<div class="storylab-nyp-input-wrap">
				<span class="storylab-currency-symbol"><?php echo esc_html( $sym ); ?></span>
				<input type="number"
				       id="storylab_price"
				       name="storylab_price"
				       class="storylab-price-input"
				       min="<?php echo esc_attr( $min > 0 ? $min : '0' ); ?>"
				       step="1"
				       placeholder="<?php echo $min > 0 ? esc_attr( $min ) : '0'; ?>"
				       required />
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Cart: price persistence
	// -------------------------------------------------------------------------

	public function validate_price( $passed, $product_id, $quantity ) {
		$nyp = get_post_meta( $product_id, '_storylab_nyp', true );
		if ( 'yes' !== $nyp ) {
			return $passed;
		}
		if ( ! isset( $_POST['storylab_price'] ) ) {
			return $passed;
		}
		$price     = (float) $_POST['storylab_price'];
		$min_price = (float) get_post_meta( $product_id, '_storylab_min_price', true );

		if ( $price <= 0 ) {
			wc_add_notice( __( 'Please enter a price for your ticket.', 'storylab-tickets' ), 'error' );
			return false;
		}
		if ( $min_price > 0 && $price < $min_price ) {
			/* translators: %s = formatted minimum price */
			wc_add_notice( sprintf( __( 'The minimum price for this ticket is %s.', 'storylab-tickets' ), wc_price( $min_price ) ), 'error' );
			return false;
		}
		return $passed;
	}

	public function store_price_in_cart( $cart_item_data, $product_id, $variation_id ) {
		$nyp = get_post_meta( $product_id, '_storylab_nyp', true );
		if ( 'yes' !== $nyp ) {
			return $cart_item_data;
		}
		if ( isset( $_POST['storylab_price'] ) && is_numeric( $_POST['storylab_price'] ) ) {
			$price = abs( (float) $_POST['storylab_price'] );
			if ( $price > 0 ) {
				$cart_item_data['storylab_price'] = $price;
				// Unique key so multiple different-price tickets are stored separately.
				$cart_item_data['storylab_unique'] = md5( $price . microtime() );
			}
		}
		return $cart_item_data;
	}

	public function apply_custom_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['storylab_price'] ) ) {
				$item['data']->set_price( $item['storylab_price'] );
			}
		}
	}

	public function display_custom_price_in_cart( $item_data, $cart_item ) {
		if ( isset( $cart_item['storylab_price'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Your price', 'storylab-tickets' ),
				'value' => wc_price( $cart_item['storylab_price'] ),
			);
		}
		return $item_data;
	}

	public function save_price_to_order_item( $item, $cart_item_key, $values, $order ) {
		if ( isset( $values['storylab_price'] ) ) {
			$item->add_meta_data( __( 'Ticket Price', 'storylab-tickets' ), wc_price( $values['storylab_price'] ), true );
		}
	}

	// -------------------------------------------------------------------------
	// Static helper
	// -------------------------------------------------------------------------

	/**
	 * Is name-your-price enabled for a product?
	 *
	 * @param int $product_id
	 * @return bool
	 */
	public static function is_nyp( $product_id ) {
		return 'yes' === get_post_meta( $product_id, '_storylab_nyp', true );
	}
}
