<?php
/**
 * Order Handler
 *
 * Hooks into WooCommerce order events to:
 *  1. Generate PDF ticket files when an order is placed / completed.
 *  2. Attach the PDFs to the customer order confirmation email.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Storylab_Order_Handler {

	/** Order meta key that stores the generated ticket file paths. */
	const META_KEY = '_storylab_ticket_files';

	public function __construct() {
		// Generate tickets as soon as payment is accepted (processing or completed).
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed',  array( $this, 'handle_order' ), 10, 1 );

		// Attach generated PDF files to emails.
		add_filter( 'woocommerce_email_attachments', array( $this, 'attach_tickets_to_email' ), 10, 3 );

		// Admin: re-generate tickets action.
		add_action( 'woocommerce_order_actions', array( $this, 'add_order_action' ) );
		add_action( 'woocommerce_order_action_storylab_regen_tickets', array( $this, 'regen_tickets_action' ) );

		// Show ticket download links on the admin order page.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'admin_ticket_links' ) );
	}

	// =========================================================================
	// Ticket generation
	// =========================================================================

	/**
	 * Called when an order moves to 'processing' or 'completed'.
	 * Generates one PDF per show product in the order (quantity pages per PDF).
	 *
	 * @param int $order_id
	 */
	public function handle_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Only generate once (skip if already done).
		$existing = $order->get_meta( self::META_KEY );
		if ( ! empty( $existing ) ) {
			return;
		}

		$this->generate_tickets( $order );
	}

	/**
	 * Generate ticket PDFs for all show-ticket line items in the order.
	 *
	 * @param WC_Order $order
	 * @return string[]  Paths to generated PDF files.
	 */
	public function generate_tickets( WC_Order $order ) {
		$files = [];

		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product_id = $item->get_product_id();
			$quantity   = $item->get_quantity();

			// Only process show-ticket products (products linked to a show OR name-your-price enabled).
			// Skip the item only when BOTH conditions are false.
			$show = Storylab_Show_CPT::get_show_for_product( $product_id );
			if ( ! $show && ! Storylab_Ticket_Woo::is_nyp( $product_id ) ) {
				continue;
			}

			// Build ticket data.
			$show_name = $show ? $show['name'] : $item->get_name();
			$date      = '';
			$time      = '';
			$location  = '';

			if ( $show ) {
				if ( $show['date'] ) {
					$date = date_i18n( get_option( 'date_format' ), strtotime( $show['date'] ) );
				}
				$time     = $show['time'];
				$location = $show['location'];
			}

			$ticket_data = [
				'show_name'    => $show_name,
				'date'         => $date,
				'time'         => $time,
				'location'     => $location,
				'order_number' => $order->get_order_number(),
				'seq'          => 1,        // set per page inside generator
				'quantity'     => $quantity,
			];

			$path = Storylab_Ticket_Generator::generate( $ticket_data, $quantity );
			if ( $path ) {
				$files[] = $path;
			}
		}

		if ( ! empty( $files ) ) {
			$order->update_meta_data( self::META_KEY, $files );
			$order->save();
		}

		return $files;
	}

	// =========================================================================
	// Email attachments
	// =========================================================================

	/**
	 * Attach ticket PDFs to the relevant customer emails.
	 *
	 * @param array           $attachments
	 * @param string          $email_id
	 * @param WC_Order|mixed  $order
	 * @return array
	 */
	public function attach_tickets_to_email( $attachments, $email_id, $order ) {
		$target_emails = [
			'customer_processing_order',
			'customer_completed_order',
			'customer_on_hold_order',
		];

		if ( ! in_array( $email_id, $target_emails, true ) ) {
			return $attachments;
		}

		if ( ! ( $order instanceof WC_Order ) ) {
			return $attachments;
		}

		$files = $order->get_meta( self::META_KEY );

		// If tickets haven't been generated yet, generate them now.
		if ( empty( $files ) ) {
			$files = $this->generate_tickets( $order );
		}

		if ( ! empty( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_readable( $file ) ) {
					$attachments[] = $file;
				}
			}
		}

		return $attachments;
	}

	// =========================================================================
	// Admin helpers
	// =========================================================================

	public function add_order_action( $actions ) {
		$actions['storylab_regen_tickets'] = 'Re-generate Story Lab tickets';
		return $actions;
	}

	public function regen_tickets_action( WC_Order $order ) {
		// Clear existing files.
		$order->delete_meta_data( self::META_KEY );
		$order->save();
		$this->generate_tickets( $order );
	}

	public function admin_ticket_links( WC_Order $order ) {
		$files = $order->get_meta( self::META_KEY );
		if ( empty( $files ) ) {
			return;
		}

		$upload   = wp_upload_dir();
		$base_url = trailingslashit( $upload['baseurl'] ) . 'storylab-tickets/';

		echo '<div class="order_data_column" style="clear:both;padding-top:10px;">';
		echo '<h4>Story Lab Tickets</h4>';
		echo '<ul>';
		foreach ( $files as $i => $path ) {
			$filename = basename( $path );
			$url      = $base_url . $filename;
			echo '<li><a href="' . esc_url( $url ) . '" target="_blank">Download ticket #' . ( $i + 1 ) . '</a></li>';
		}
		echo '</ul></div>';
	}
}
