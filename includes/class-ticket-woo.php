<?php
/**
 * WooCommerce Integration — Name-Your-Price Tickets
 *
 * Adds show details (date, time, venue) and a "name your price" input to
 * products that are ticket products.  All show data is stored directly on
 * the product — no separate Show post type is required.
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

		// Front-end: show details + price input + name fields.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'output_price_field' ), 5 );

		// Enqueue frontend assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Cart: store custom price and ticket names in item data.
		add_filter( 'woocommerce_add_cart_item_data',    array( $this, 'store_price_in_cart' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_price' ), 10, 3 );

		// Apply the stored price every time the cart recalculates.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_custom_price' ), 20 );

		// Ensure the cart item Price and Subtotal columns show the entered amount.
		add_filter( 'woocommerce_cart_item_price',    array( $this, 'filter_cart_item_price_display' ),    10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'filter_cart_item_subtotal_display' ), 10, 3 );

		// Display the chosen price and ticket names in the cart/checkout line item.
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_custom_price_in_cart' ), 10, 2 );

		// Persist the chosen price and names on the order line item.
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

		$nyp       = get_post_meta( $post->ID, '_storylab_nyp', true );
		$min_price = get_post_meta( $post->ID, '_storylab_min_price', true );
		$date      = get_post_meta( $post->ID, '_show_date', true );
		$time      = get_post_meta( $post->ID, '_show_time', true );
		$location  = get_post_meta( $post->ID, '_show_location', true );

		if ( '' === $time )     { $time     = Storylab_Show_CPT::DEFAULT_TIME; }
		if ( '' === $location ) { $location = Storylab_Show_CPT::DEFAULT_VENUE; }
		?>
		<div id="storylab_ticket_data" class="panel woocommerce_options_panel">
			<div class="options_group">

				<p class="form-field">
					<label for="show_date">Show Date</label>
					<input type="date" id="show_date" name="show_date"
					       value="<?php echo esc_attr( $date ); ?>" />
				</p>

				<p class="form-field">
					<label for="show_time">Show Time</label>
					<input type="text" id="show_time" name="show_time"
					       value="<?php echo esc_attr( $time ); ?>"
					       placeholder="e.g. 7:00 PM" style="width:120px;" />
				</p>

				<p class="form-field">
					<label for="show_location">Venue</label>
					<input type="text" id="show_location" name="show_location"
					       value="<?php echo esc_attr( $location ); ?>" style="width:50%"
					       placeholder="<?php echo esc_attr( Storylab_Show_CPT::DEFAULT_VENUE ); ?>" />
					<span class="description">Full venue name and address.</span>
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
					<span class="description">Leave blank or 0 for no minimum.</span>
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

		$nyp       = isset( $_POST['storylab_nyp'] ) && 'yes' === $_POST['storylab_nyp'] ? 'yes' : 'no';
		$min_price = isset( $_POST['storylab_min_price'] ) ? wc_format_decimal( $_POST['storylab_min_price'] ) : '';
		$date      = isset( $_POST['show_date'] )     ? sanitize_text_field( $_POST['show_date'] )     : '';
		$time      = isset( $_POST['show_time'] )     ? sanitize_text_field( $_POST['show_time'] )     : '';
		$location  = isset( $_POST['show_location'] ) ? sanitize_text_field( $_POST['show_location'] ) : '';

		update_post_meta( $product_id, '_storylab_nyp',       $nyp );
		update_post_meta( $product_id, '_storylab_min_price', $min_price );
		update_post_meta( $product_id, '_show_date',          $date );
		update_post_meta( $product_id, '_show_time',          $time );
		update_post_meta( $product_id, '_show_location',      $location );
	}

	// -------------------------------------------------------------------------
	// Front-end: price input, show info, and name fields
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

		<div id="storylab-names-wrap" class="storylab-names-wrap">
			<?php /* Name fields are injected by frontend.js based on the chosen quantity. */ ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Cart: price and name persistence
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

		// Validate that a name has been supplied for every ticket.
		$names = isset( $_POST['storylab_ticket_names'] ) ? (array) $_POST['storylab_ticket_names'] : array();
		for ( $i = 0; $i < $quantity; $i++ ) {
			if ( empty( trim( $names[ $i ] ?? '' ) ) ) {
				wc_add_notice(
					1 === $quantity
						? __( 'Please enter a name for your ticket.', 'storylab-tickets' )
						/* translators: %d = ticket number */
						: sprintf( __( 'Please enter a name for ticket %d.', 'storylab-tickets' ), $i + 1 ),
					'error'
				);
				return false;
			}
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
				$cart_item_data['storylab_price']  = $price;
				// Unique key so multiple different-price tickets are stored separately.
				$cart_item_data['storylab_unique'] = md5( $price . microtime() );
			}
		}
		if ( isset( $_POST['storylab_ticket_names'] ) && is_array( $_POST['storylab_ticket_names'] ) ) {
			$cart_item_data['storylab_ticket_names'] = array_map( 'sanitize_text_field', $_POST['storylab_ticket_names'] );
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

	/**
	 * Ensure the cart "Price" column shows the entered name-your-price amount
	 * rather than the product's original listed price.
	 */
	public function filter_cart_item_price_display( $price_html, $cart_item, $cart_item_key ) {
		if ( isset( $cart_item['storylab_price'] ) ) {
			return wc_price( $cart_item['storylab_price'] );
		}
		return $price_html;
	}

	public function filter_cart_item_subtotal_display( $subtotal, $cart_item, $cart_item_key ) {
		if ( isset( $cart_item['storylab_price'] ) ) {
			return wc_price( $cart_item['storylab_price'] * $cart_item['quantity'] );
		}
		return $subtotal;
	}

	public function display_custom_price_in_cart( $item_data, $cart_item ) {
		if ( isset( $cart_item['storylab_price'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Your price', 'storylab-tickets' ),
				'value' => wc_price( $cart_item['storylab_price'] ),
			);
		}
		if ( isset( $cart_item['storylab_ticket_names'] ) && is_array( $cart_item['storylab_ticket_names'] ) ) {
			$names = array_filter( array_map( 'trim', $cart_item['storylab_ticket_names'] ) );
			if ( ! empty( $names ) ) {
				$item_data[] = array(
					'key'   => __( 'Ticket names', 'storylab-tickets' ),
					'value' => implode( ', ', $names ),
				);
			}
		}
		return $item_data;
	}

	public function save_price_to_order_item( $item, $cart_item_key, $values, $order ) {
		if ( isset( $values['storylab_price'] ) ) {
			$item->add_meta_data( __( 'Ticket Price', 'storylab-tickets' ), wc_price( $values['storylab_price'] ), true );
		}
		if ( isset( $values['storylab_ticket_names'] ) && is_array( $values['storylab_ticket_names'] ) ) {
			// Store with underscore prefix so it is hidden from the order display but
			// accessible programmatically for ticket generation.
			$item->add_meta_data( '_storylab_ticket_names', $values['storylab_ticket_names'], true );
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
