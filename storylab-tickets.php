<?php
/**
 * Plugin Name: Storylab Tickets
 * Plugin URI:  https://github.com/impact2021/Storylab
 * Description: Name-your-price WooCommerce ticket sales with PDF ticket generation for Story Lab shows.
 * Version:     1.0.0
 * Author:      Story Lab
 * Author URI:  https://www.actorslab.co.nz
 * License:     GPL-2.0+
 * Text Domain: storylab-tickets
 *
 * Requires WooCommerce to be active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STORYLAB_VERSION',    '1.0.0' );
define( 'STORYLAB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'STORYLAB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Check WooCommerce is active before loading anything.
 */
add_action( 'plugins_loaded', 'storylab_init', 20 );

function storylab_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Storylab Tickets</strong> requires WooCommerce to be installed and active.</p></div>';
		} );
		return;
	}

	require_once STORYLAB_PLUGIN_DIR . 'includes/class-show-cpt.php';
	require_once STORYLAB_PLUGIN_DIR . 'includes/class-ticket-woo.php';
	require_once STORYLAB_PLUGIN_DIR . 'includes/class-pdf-writer.php';
	require_once STORYLAB_PLUGIN_DIR . 'includes/class-ticket-generator.php';
	require_once STORYLAB_PLUGIN_DIR . 'includes/class-order-handler.php';
	require_once STORYLAB_PLUGIN_DIR . 'includes/class-admin.php';

	new Storylab_Ticket_Woo();
	new Storylab_Order_Handler();
	new Storylab_Admin();
}

/**
 * Create the tickets upload directory on activation.
 */
register_activation_hook( __FILE__, 'storylab_activate' );
function storylab_activate() {
	$upload = wp_upload_dir();
	$dir    = $upload['basedir'] . '/storylab-tickets';
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
		// Protect the directory from direct listing.
		file_put_contents( $dir . '/index.html', '' );
	}
}
