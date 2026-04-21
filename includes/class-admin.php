<?php
/**
 * Admin Settings & Menu
 *
 * Adds a "Storylab Tickets" top-level menu with:
 *  - Settings page (logo URL, default venue, currency/price options).
 *  - Quick-link to the Shows CPT list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Storylab_Admin {

	const OPTION_GROUP = 'storylab_settings';
	const OPTION_PAGE  = 'storylab-settings';

	public function __construct() {
		add_action( 'admin_menu',  array( $this, 'add_menu' ) );
		add_action( 'admin_init',  array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
	}

	// =========================================================================
	// Menu
	// =========================================================================

	public function add_menu() {
		add_menu_page(
			'Storylab Tickets',
			'Storylab Tickets',
			'manage_options',
			'storylab-tickets',
			array( $this, 'render_settings_page' ),
			'dashicons-tickets-alt',
			56
		);

		// Settings sub-page (same as the top-level page).
		add_submenu_page(
			'storylab-tickets',
			'Settings',
			'Settings',
			'manage_options',
			'storylab-tickets',
			array( $this, 'render_settings_page' )
		);

		// Shows sub-page (links to the CPT list).
		add_submenu_page(
			'storylab-tickets',
			'Shows',
			'Shows',
			'manage_options',
			'edit.php?post_type=' . Storylab_Show_CPT::POST_TYPE
		);
	}

	// =========================================================================
	// Settings
	// =========================================================================

	public function register_settings() {
		register_setting( self::OPTION_GROUP, 'storylab_logo_url',        array( 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( self::OPTION_GROUP, 'storylab_default_location', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::OPTION_GROUP, 'storylab_default_time',     array( 'sanitize_callback' => 'sanitize_text_field' ) );

		add_settings_section(
			'storylab_main',
			'General Settings',
			null,
			self::OPTION_PAGE
		);

		add_settings_field(
			'storylab_logo_url',
			'Logo URL',
			array( $this, 'field_logo_url' ),
			self::OPTION_PAGE,
			'storylab_main'
		);

		add_settings_field(
			'storylab_default_location',
			'Default Venue',
			array( $this, 'field_default_location' ),
			self::OPTION_PAGE,
			'storylab_main'
		);

		add_settings_field(
			'storylab_default_time',
			'Default Show Time',
			array( $this, 'field_default_time' ),
			self::OPTION_PAGE,
			'storylab_main'
		);
	}

	public function field_logo_url() {
		$val = get_option( 'storylab_logo_url',
			'https://www.actorslab.co.nz/storylab/wp-content/uploads/2025/11/NEW-Story-Lab-logo.jpg' );
		?>
		<input type="url" name="storylab_logo_url"
		       value="<?php echo esc_attr( $val ); ?>" class="large-text" />
		<p class="description">URL of the logo image shown on all tickets (JPEG or PNG).</p>
		<?php
		if ( $val ) {
			echo '<img src="' . esc_url( $val ) . '" alt="Logo preview" style="max-height:60px;display:block;margin-top:8px;" />';
		}
	}

	public function field_default_location() {
		$val = get_option( 'storylab_default_location', Storylab_Show_CPT::DEFAULT_VENUE );
		?>
		<input type="text" name="storylab_default_location"
		       value="<?php echo esc_attr( $val ); ?>" class="large-text" />
		<p class="description">Used when a show does not have a venue set.</p>
		<?php
	}

	public function field_default_time() {
		$val = get_option( 'storylab_default_time', '7:00 PM' );
		?>
		<input type="text" name="storylab_default_time"
		       value="<?php echo esc_attr( $val ); ?>" style="width:120px;" />
		<p class="description">Default show start time (e.g. "7:00 PM").</p>
		<?php
	}

	// =========================================================================
	// Settings page render
	// =========================================================================

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>
				<span style="color:#CD1214;">&#9679;</span> Storylab Tickets — Settings
			</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::OPTION_PAGE );
				submit_button( 'Save Settings' );
				?>
			</form>

			<hr />
			<h2>Quick Start Guide</h2>
			<ol>
				<li>
					Go to <strong>Shows → Add New Show</strong> and fill in the show name, date,
					time and venue.
				</li>
				<li>
					Create a WooCommerce <strong>Simple product</strong> for the show. Set its
					price to <em>0</em> (or any placeholder) — it will be overridden by the
					customer's chosen price.
				</li>
				<li>
					On the product edit page, open the <strong>Show Ticket</strong> tab, select
					the show you just created and tick <em>Enable Name-Your-Price</em>.
					Optionally set a minimum price.
				</li>
				<li>
					Link the same product back on the Show post using the
					<em>WooCommerce Product</em> dropdown.
				</li>
				<li>
					Publish both posts. Customers will now see show details and a price input
					on the product page. On checkout, PDF tickets are generated and emailed
					automatically — one page per ticket.
				</li>
			</ol>
		</div>
		<?php
	}

	// =========================================================================
	// Admin styles
	// =========================================================================

	public function enqueue_admin_styles( $hook ) {
		wp_enqueue_style(
			'storylab-admin',
			STORYLAB_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			STORYLAB_VERSION
		);
	}
}
