<?php
/**
 * Plugin Name: BOGO Same Product (Buy One Get One Free)
 * Plugin URI: https://virtualcode.co/virtualcode-bogo-same-product/
 * Description: Buy One Get One Free for selected WooCommerce products. When an eligible product is added to cart, its quantity is doubled (paid + free) and you only charge for the paid quantity. Works with simple, variable, and grouped products. Respects sale prices.
 * Version: 1.0.0
 * Author: Virtualcode
 * Author URI: https://virtualcode.co/
 * Text Domain: virtualcode-bogo-same-product
 * Requires at least: 5.9
 * Requires PHP: 8.0
 * Tested up to: 6.9
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Options:
 * - vc_bogo_enabled           : 'yes' / 'no'
 * - vc_bogo_selected_products : array of product IDs (ints)
 * - vc_bogo_scope             : 'all' or 'selected'
 */

/**
 * Activation: require WooCommerce.
 */
register_activation_hook( __FILE__, 'vc_bogo_activation_check' );
function vc_bogo_activation_check() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		if ( is_admin() ) {
			add_option( 'vc_bogo_activation_error', 'WooCommerce must be installed and active to use BOGO Same Product.' );
		}
	}
}

/**
 * Admin notices.
 */
add_action( 'admin_notices', 'vc_bogo_admin_notices' );
function vc_bogo_admin_notices() {
	$opt = get_option( 'vc_bogo_activation_error' );
	if ( $opt ) {
		echo '<div class="notice notice-error"><p><strong>BOGO Same Product:</strong> ' . esc_html( $opt ) . '</p></div>';
		delete_option( 'vc_bogo_activation_error' );
	}

	if ( is_plugin_active( plugin_basename( __FILE__ ) ) && ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="notice notice-error"><p><strong>BOGO Same Product:</strong> WooCommerce is not active. Please activate WooCommerce first.</p></div>';
	}
}

/**
 * Initialize plugin.
 */
add_action( 'plugins_loaded', 'vc_bogo_init', 20 );
function vc_bogo_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	// Core BOGO behaviour
	add_action( 'woocommerce_add_to_cart', 'vc_bogo_on_add_to_cart', 20, 6 );
	add_action( 'woocommerce_before_calculate_totals', 'vc_bogo_before_calculate_totals', 20 );
	add_action( 'woocommerce_after_cart_item_quantity_update', 'vc_bogo_on_cart_quantity_update', 20, 3 );
	add_filter( 'woocommerce_cart_item_name', 'vc_bogo_label_cart_item', 10, 3 );
	add_action( 'woocommerce_checkout_create_order_line_item', 'vc_bogo_copy_cart_meta_to_order_item', 10, 4 );

	// Settings UI
	add_action( 'admin_menu', 'vc_bogo_add_settings_page', 99 );
	add_action( 'admin_init', 'vc_bogo_register_settings' );

	// Dashboard widget
	add_action( 'wp_dashboard_setup', 'vc_bogo_add_dashboard_widget' );

	// Plugins page link
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'vc_bogo_plugin_action_links' );

	// Admin styles
	//add_action( 'admin_enqueue_scripts', 'vc_bogo_admin_styles' );
}

/**
 * Enqueue admin styles (external CSS)
 */

add_action( 'admin_enqueue_scripts', function() {
	wp_enqueue_style(
		'vc-bogo-admin',
		plugin_dir_url( __FILE__ ) . 'assets/admin.css',
		array( 'woocommerce_admin_styles' ),
		time()
	);
}, 100 );


/* =========================
   REST OF YOUR ORIGINAL FILE
   (Settings UI, Helpers, BOGO Logic, Display, etc.)
   — COMPLETELY UNCHANGED —
   ========================= */


/* -------------------------
 * Settings page - OPTIMIZED LAYOUT
 * ------------------------- */

function vc_bogo_add_settings_page() {
	add_submenu_page(
		'woocommerce',
		'BOGO Same Product',
		'BOGO Same Product',
		'manage_woocommerce',
		'vc-bogo-settings',
		'vc_bogo_render_settings_page'
	);
}

function vc_bogo_register_settings() {
	register_setting(
		'vc_bogo_settings_group',
		'vc_bogo_enabled',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'vc_bogo_sanitize_enabled',
			'default'           => 'no',
		)
	);

	register_setting(
		'vc_bogo_settings_group',
		'vc_bogo_selected_products',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'vc_bogo_sanitize_selected_products',
			'default'           => array(),
		)
	);

	register_setting(
		'vc_bogo_settings_group',
		'vc_bogo_scope',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'vc_bogo_sanitize_scope',
			'default'           => 'all',
		)
	);
}

function vc_bogo_sanitize_enabled( $val ) {
	return ( 'yes' === $val ) ? 'yes' : 'no';
}

function vc_bogo_sanitize_selected_products( $val ) {
	if ( empty( $val ) ) {
		return array();
	}
	if ( is_string( $val ) ) {
		$val = array_map( 'trim', explode( ',', $val ) );
	}
	$ids = array();
	foreach ( (array) $val as $v ) {
		$i = (int) $v;
		if ( $i > 0 ) {
			$ids[] = $i;
		}
	}
	return array_values( array_unique( $ids ) );
}

function vc_bogo_sanitize_scope( $val ) {
	return ( 'selected' === $val ) ? 'selected' : 'all';
}

function vc_bogo_get_scope(): string {
	$scope = get_option( 'vc_bogo_scope', 'all' );
	return ( 'selected' === $scope ) ? 'selected' : 'all';
}

function vc_bogo_render_settings_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	// WooCommerce product search UI
	if ( function_exists( 'wc_enqueue_js' ) ) {
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_script( 'wc-product-search' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
	}

	$enabled  = get_option( 'vc_bogo_enabled', 'no' );
	$scope    = vc_bogo_get_scope();
	$selected = get_option( 'vc_bogo_selected_products', array() );
	if ( ! is_array( $selected ) ) {
		$selected = (array) $selected;
	}
	$selected = array_map( 'intval', $selected );

	// Save handling
	if ( isset( $_POST['vc_bogo_settings_submit'] ) ) {
		check_admin_referer( 'vc_bogo_settings_save', 'vc_bogo_nonce' );

		$enabled_post = ( isset( $_POST['vc_bogo_enabled'] ) && 'yes' === $_POST['vc_bogo_enabled'] ) ? 'yes' : 'no';
		update_option( 'vc_bogo_enabled', $enabled_post );

		$scope_post = ( isset( $_POST['vc_bogo_scope'] ) && 'selected' === $_POST['vc_bogo_scope'] ) ? 'selected' : 'all';
		update_option( 'vc_bogo_scope', $scope_post );

		$selected_post = array();

		// From product search selector
		if ( isset( $_POST['vc_bogo_selected_products'] ) && is_array( $_POST['vc_bogo_selected_products'] ) ) {
			$selected_post = array_map( 'intval', $_POST['vc_bogo_selected_products'] );
		}

		update_option( 'vc_bogo_selected_products', $selected_post );

		$enabled  = $enabled_post;
		$scope    = $scope_post;
		$selected = $selected_post;

		echo '<div class="notice notice-success is-dismissible"><p>BOGO settings saved.</p></div>';
	}

	// Preload selected products for the selector display
	$selected_products_for_display = array();
	foreach ( $selected as $pid ) {
		$product = wc_get_product( $pid );
		if ( $product ) {
			$selected_products_for_display[ $pid ] = wp_kses_post( $product->get_formatted_name() ) . ' (ID:' . $pid . ')';
		}
	}

	// Inline JS for enhanced functionality
	if ( function_exists( 'wc_enqueue_js' ) ) {
		wc_enqueue_js(
			"
			jQuery(function($){
				// Tab navigation
				$('.vc-bogo-tab').on('click', function(e){
					e.preventDefault();
					var target = $(this).data('target');
					$('.vc-bogo-tab').removeClass('active');
					$(this).addClass('active');
					$('.vc-bogo-tab-content').removeClass('active');
					$('#' + target).addClass('active');
				});

				// Toggle product selection based on scope
				function toggleProductSelection() {
					var scope = $('input[name=\"vc_bogo_scope\"]:checked').val();
					if (scope === 'all') {
						$('#vc-bogo-product-selection').slideUp(300);
					} else {
						$('#vc-bogo-product-selection').slideDown(300);
					}
				}

				// Initial toggle
				toggleProductSelection();

				// Toggle on scope change
				$('input[name=\"vc_bogo_scope\"]').on('change', function(){
					toggleProductSelection();
				});

				// Clear all products
				$('#vc-bogo-clear-products').on('click', function(e){
					e.preventDefault();
					var \$select = $('#vc_bogo_selected_products');
					\$select.val(null).trigger('change');
				});

				// Enhanced product search to include IDs
				$('#vc_bogo_selected_products').select2({
					placeholder: 'Search by product name, SKU, or ID...',
					allowClear: true,
					minimumInputLength: 2,
					ajax: {
						url: '" . admin_url( 'admin-ajax.php' ) . "',
						dataType: 'json',
						delay: 250,
						data: function (params) {
							return {
								term: params.term,
								action: 'woocommerce_json_search_products_and_variations',
								security: '" . wp_create_nonce( 'search-products' ) . "'
							};
						},
						processResults: function (data) {
							var terms = [];
							if (data) {
								Object.keys(data).forEach(function(key) {
									terms.push({
										id: key,
										text: data[key]
									});
								});
							}
							return {
								results: terms
							};
						},
						cache: true
					}
				});
			});
		"
		);
	}

	?>
	<div class="wrap vc-bogo-wrap">
		<div class="vc-bogo-header">
			<div class="vc-bogo-header-content">
				<h1><span class="dashicons dashicons-tag"></span> BOGO Same Product</h1>
				<p class="vc-bogo-subtitle">Buy One Get One Free offers for WooCommerce</p>
			</div>
			<div class="vc-bogo-status-card">
				<div class="vc-bogo-status-item">
					<span class="vc-bogo-status-label">Status</span>
					<span class="vc-bogo-status-value status-<?php echo ( 'yes' === $enabled ) ? 'active' : 'inactive'; ?>">
						<?php echo ( 'yes' === $enabled ) ? 'ACTIVE' : 'INACTIVE'; ?>
					</span>
				</div>
				<div class="vc-bogo-status-item">
					<span class="vc-bogo-status-label">Scope</span>
					<span class="vc-bogo-status-value"><?php echo ( 'all' === $scope ) ? 'All Products' : 'Selected Products'; ?></span>
				</div>
				<div class="vc-bogo-status-item">
					<span class="vc-bogo-status-label">Selected Products</span>
					<span class="vc-bogo-status-value"><?php echo count( $selected ); ?></span>
				</div>
			</div>
		</div>

		<div class="vc-bogo-content-wrapper">
			<div class="vc-bogo-tabs">
				<nav class="vc-bogo-tab-nav">
					<a href="#" class="vc-bogo-tab active" data-target="general-settings">General Settings</a>
					<a href="#" class="vc-bogo-tab" data-target="quick-guide">Quick Guide</a>
				</nav>

				<form method="post" action="" class="vc-bogo-form">
					<?php wp_nonce_field( 'vc_bogo_settings_save', 'vc_bogo_nonce' ); ?>

					<!-- General Settings Tab -->
					<div id="general-settings" class="vc-bogo-tab-content active">
						<div class="vc-bogo-card">
							<h2 class="vc-bogo-card-title">General Settings</h2>

							<div class="vc-bogo-setting-group">
								<div class="vc-bogo-setting-row">
									<div class="vc-bogo-setting-label">
										<label for="vc_bogo_enabled">Enable BOGO</label>
										<p class="vc-bogo-description">Turn the Buy One Get One Free offer on or off globally</p>
									</div>
									<div class="vc-bogo-setting-field">
										<label class="vc-bogo-switch">
											<input type="checkbox" name="vc_bogo_enabled" value="yes" <?php checked( $enabled, 'yes' ); ?> />
											<span class="vc-bogo-slider"></span>
										</label>
										<span class="vc-bogo-switch-label"><?php echo ( 'yes' === $enabled ) ? 'Enabled' : 'Disabled'; ?></span>
									</div>
								</div>

								<div class="vc-bogo-setting-row">
									<div class="vc-bogo-setting-label">
										<label>Apply BOGO to</label>
										<p class="vc-bogo-description">Choose which products should get the BOGO offer</p>
									</div>
									<div class="vc-bogo-setting-field">
										<div class="vc-bogo-radio-group">
											<label class="vc-bogo-radio">
												<input type="radio" name="vc_bogo_scope" value="all" <?php checked( $scope, 'all' ); ?> />
												<span class="vc-bogo-radio-checkmark"></span>
												<div class="vc-bogo-radio-content">
													<div class="vc-bogo-radio-icon">
														<span class="dashicons dashicons-products"></span>
													</div>
													<div class="vc-bogo-radio-text">
														<strong>All Products</strong>
														<span>Apply BOGO to every product in your store</span>
													</div>
												</div>
											</label>

											<label class="vc-bogo-radio">
												<input type="radio" name="vc_bogo_scope" value="selected" <?php checked( $scope, 'selected' ); ?> />
												<span class="vc-bogo-radio-checkmark"></span>
												<div class="vc-bogo-radio-content">
													<div class="vc-bogo-radio-icon">
														<span class="dashicons dashicons-filter"></span>
													</div>
													<div class="vc-bogo-radio-text">
														<strong>Selected Products Only</strong>
														<span>Apply BOGO only to specific products/variations</span>
													</div>
												</div>
											</label>
										</div>
									</div>
								</div>

								<!-- Product Selection (Conditional) -->
								<div id="vc-bogo-product-selection" class="vc-bogo-setting-row" style="<?php echo ( 'all' === $scope ) ? 'display: none;' : ''; ?>">
									<div class="vc-bogo-setting-label">
										<label for="vc_bogo_selected_products">Select Products</label>
										<p class="vc-bogo-description">Search products by name, SKU, or ID</p>
									</div>
									<div class="vc-bogo-setting-field">
										<select
											class="wc-product-search"
											style="width: 100%; max-width: 500px;"
											id="vc_bogo_selected_products"
											name="vc_bogo_selected_products[]"
											multiple="multiple"
											data-placeholder="<?php esc_attr_e( 'Search by product name, SKU, or ID...', 'virtualcode-bogo-same-product' ); ?>"
											data-action="woocommerce_json_search_products_and_variations"
										>
											<?php
											if ( ! empty( $selected_products_for_display ) ) {
												foreach ( $selected_products_for_display as $pid => $label ) {
													echo '<option value="' . esc_attr( $pid ) . '" selected="selected">' . wp_kses_post( $label ) . '</option>';
												}
											}
											?>
										</select>
										<div class="vc-bogo-action-buttons">
											<button type="button" class="button button-secondary" id="vc-bogo-clear-products">
												<span class="dashicons dashicons-trash"></span>
												Clear All
											</button>
										</div>
										<p class="vc-bogo-field-description">
											Search for products by typing their name, SKU, or direct ID number
										</p>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Quick Guide Tab -->
					<div id="quick-guide" class="vc-bogo-tab-content">
						<div class="vc-bogo-card">
							<h2 class="vc-bogo-card-title">Quick Guide</h2>

							<div class="vc-bogo-guide-grid">
								<div class="vc-bogo-guide-item">
									<div class="vc-bogo-guide-icon">
										<span class="dashicons dashicons-admin-settings"></span>
									</div>
									<h3>1. Enable BOGO</h3>
									<p>Turn on the BOGO feature in General Settings and choose whether to apply it to all products or only selected ones.</p>
								</div>

								<div class="vc-bogo-guide-item">
									<div class="vc-bogo-guide-icon">
										<span class="dashicons dashicons-search"></span>
									</div>
									<h3>2. Select Products</h3>
									<p>Use the product search to select specific products or variations that should have the BOGO offer.</p>
								</div>

								<div class="vc-bogo-guide-item">
									<div class="vc-bogo-guide-icon">
										<span class="dashicons dashicons-cart"></span>
									</div>
									<h3>3. Test in Cart</h3>
									<p>Add eligible products to cart - quantities will automatically double with free items clearly labeled.</p>
								</div>

								<div class="vc-bogo-guide-item">
									<div class="vc-bogo-guide-icon">
										<span class="dashicons dashicons-money-alt"></span>
									</div>
									<h3>4. How Pricing Works</h3>
									<p>Customers pay for half the quantity in cart. If they add 2 items, they get 2 free (4 total) but pay for only 2.</p>
								</div>
							</div>

							<div class="vc-bogo-info-box">
								<h4>💡 Pro Tips</h4>
								<ul>
									<li>BOGO works with simple, variable, and grouped products</li>
									<li>Sale prices are automatically respected</li>
									<li>Cart quantities automatically adjust to maintain the 1:1 paid:free ratio</li>
									<li>Free items are clearly labeled in the cart and checkout</li>
									<li>When "All Products" is selected, the product selection field is hidden</li>
								</ul>
							</div>
						</div>
					</div>

					<div class="vc-bogo-form-actions">
						<input type="submit" name="vc_bogo_settings_submit" class="button button-primary button-large vc-bogo-save-btn" value="Save Changes">

						<div class="vc-bogo-preview">
							<span class="vc-bogo-preview-label">Current Status:</span>
							<span class="vc-bogo-preview-badge <?php echo ( 'yes' === $enabled ) ? 'preview-active' : 'preview-inactive'; ?>">
								<?php echo ( 'yes' === $enabled ) ? 'ACTIVE' : 'INACTIVE'; ?>
							</span>
						</div>
					</div>
				</form>
			</div>
		</div>
	</div>

	<?php
}


/* -------------------------
 * Dashboard widget & plugin link
 * ------------------------- */

function vc_bogo_add_dashboard_widget() {
	wp_add_dashboard_widget(
		'vc_bogo_dashboard_widget',
		'Bogo - Virtualcode',
		'vc_bogo_dashboard_widget_render'
	);
}

function vc_bogo_dashboard_widget_render() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		echo '<p>Insufficient permissions to view BOGO settings.</p>';
		return;
	}

	$enabled        = vc_bogo_is_enabled() ? 'ACTIVE' : 'INACTIVE';
	$scope          = vc_bogo_get_scope();
	$scope_label    = ( 'all' === $scope ) ? 'All products' : 'Selected products only';
	$selected       = vc_bogo_get_selected_products();
	$selected_count = count( $selected );
	$settings_url   = admin_url( 'admin.php?page=vc-bogo-settings' );

	echo '<div style="padding: 10px 0;">';
	echo '<p><strong>BOGO Same Product (Buy One Get One Free)</strong></p>';
	echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 15px 0;">';
	echo '<div><strong>Status:</strong><br><span style="color: ' . ( 'ACTIVE' === $enabled ? '#0978EE' : '#666666' ) . '; font-weight: bold;">' . esc_html( $enabled ) . '</span></div>';
	echo '<div><strong>Scope:</strong><br>' . esc_html( $scope_label ) . '</div>';
	echo '<div><strong>Selected Products:</strong><br>' . esc_html( $selected_count ) . '</div>';
	echo '</div>';
	echo '<p><a class="button button-primary" href="' . esc_url( $settings_url ) . '" style="width: 100%; text-align: center; display: block; background: #0978EE; border-color: #0978EE;">Open BOGO Settings</a></p>';
	echo '<p style="text-align: center; margin-top: 10px; color: #666;"><a href="https://virtualcode.co/" target="_blank" rel="noopener noreferrer">Virtualcode</a></p>';
	echo '</div>';
}

function vc_bogo_plugin_action_links( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=vc-bogo-settings' ) ),
		esc_html__( 'Settings', 'virtualcode-bogo-same-product' )
	);

	array_unshift( $links, $settings_link );

	return $links;
}

/* -------------------------
 * Helpers for eligibility
 * ------------------------- */

function vc_bogo_is_enabled(): bool {
	return ( 'yes' === get_option( 'vc_bogo_enabled', 'no' ) );
}

function vc_bogo_get_selected_products(): array {
	$selected = get_option( 'vc_bogo_selected_products', array() );
	if ( ! is_array( $selected ) ) {
		$selected = array();
	}
	return array_map( 'intval', $selected );
}

/**
 * Is product/variation eligible for BOGO?
 * Scope:
 * - scope = 'all'  -> all products eligible (if enabled)
 * - scope = 'selected' -> only IDs in selection (product, variation, or parent of variation)
 */
function vc_bogo_is_product_eligible( int $product_id = 0, int $variation_id = 0 ): bool {
	if ( ! vc_bogo_is_enabled() ) {
		return false;
	}

	$scope    = vc_bogo_get_scope();
	$selected = vc_bogo_get_selected_products();

	// All products
	if ( 'all' === $scope ) {
		return true;
	}

	// Selected only, but nothing selected => nothing eligible
	if ( empty( $selected ) ) {
		return false;
	}

	$candidates = array();

	if ( $product_id > 0 ) {
		$candidates[] = $product_id;
	}
	if ( $variation_id > 0 ) {
		$candidates[] = $variation_id;
	}

	// Parent of variation
	if ( $variation_id > 0 ) {
		$var = wc_get_product( $variation_id );
		if ( $var && $var->get_parent_id() ) {
			$candidates[] = (int) $var->get_parent_id();
		}
	}

	// If product itself is variation, also check its parent
	if ( $product_id > 0 ) {
		$p = wc_get_product( $product_id );
		if ( $p && $p->is_type( 'variation' ) && $p->get_parent_id() ) {
			$candidates[] = (int) $p->get_parent_id();
		}
	}

	$candidates = array_unique( array_filter( $candidates ) );

	foreach ( $candidates as $id ) {
		if ( in_array( $id, $selected, true ) ) {
			return true;
		}
	}

	return false;
}

/* -------------------------
 * BOGO core behaviour
 * ------------------------- */

/**
 * When product is added to cart:
 * - If eligible & not yet applied:
 *   - paid_qty = quantity
 *   - total_qty = paid_qty * 2
 *   - store vc_bogo_applied, vc_bogo_paid_qty, vc_bogo_original_price (current price, includes sale)
 *   - set cart quantity to total_qty
 */
function vc_bogo_on_add_to_cart( $cart_item_key, $product_id = 0, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
	if ( ! vc_bogo_is_product_eligible( (int) $product_id, (int) $variation_id ) ) {
		return;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->cart || $quantity <= 0 ) {
		return;
	}

	$cart_contents = WC()->cart->get_cart();
	if ( ! isset( $cart_contents[ $cart_item_key ] ) ) {
		return;
	}

	$item = $cart_contents[ $cart_item_key ];

	if ( isset( $item['vc_bogo_applied'] ) && $item['vc_bogo_applied'] ) {
		return;
	}

	$paid_qty  = max( 0, (int) $quantity );
	$total_qty = $paid_qty * 2;

	// Get effective price (sale-aware) as original price
	$original_price = 0.0;
	if ( isset( $item['data'] ) && is_object( $item['data'] ) ) {
		$original_price = (float) $item['data']->get_price();
	}

	WC()->cart->set_quantity( $cart_item_key, $total_qty, true );

	$cart_contents = WC()->cart->get_cart();
	if ( ! isset( $cart_contents[ $cart_item_key ] ) ) {
		return;
	}

	WC()->cart->cart_contents[ $cart_item_key ]['vc_bogo_applied']        = true;
	WC()->cart->cart_contents[ $cart_item_key ]['vc_bogo_paid_qty']       = $paid_qty;
	WC()->cart->cart_contents[ $cart_item_key ]['vc_bogo_original_price'] = $original_price;

	WC()->cart->set_session();
}

/**
 * Before totals:
 * - For BOGO lines, price per unit = original_price / 2
 *   (so total = (paid_qty * original_price) while showing quantity = 2 * paid_qty).
 */
function vc_bogo_before_calculate_totals( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	foreach ( WC()->cart->get_cart() as $cart_item_key => &$cart_item ) {

		if ( empty( $cart_item['vc_bogo_applied'] ) ) {
			continue;
		}

		$product_id   = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		$variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;

		if ( ! vc_bogo_is_product_eligible( $product_id, $variation_id ) ) {
			continue;
		}

		$orig_price = isset( $cart_item['vc_bogo_original_price'] ) ? (float) $cart_item['vc_bogo_original_price'] : 0.0;
		if ( $orig_price <= 0 && isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ) {
			$orig_price                           = (float) $cart_item['data']->get_price();
			$cart_item['vc_bogo_original_price'] = $orig_price;
		}

		if ( $orig_price > 0 && isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'set_price' ) ) {
			$new_price = $orig_price / 2.0;
			$cart_item['data']->set_price( $new_price );
		}
	}

	WC()->cart->set_session();
}

/**
 * When quantity is edited in cart:
 * - Visible qty is total (paid + free).
 * - We convert to paid_qty = ceil(visible / 2), total_qty = paid_qty * 2.
 */
function vc_bogo_on_cart_quantity_update( $cart_item_key, $quantity, $old_quantity ) {
	static $in_bogo_qty = false;
	if ( $in_bogo_qty ) {
		return;
	}

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	$cart_contents = WC()->cart->get_cart();
	if ( ! isset( $cart_contents[ $cart_item_key ] ) ) {
		return;
	}
	$item = $cart_contents[ $cart_item_key ];

	$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
	$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;

	if ( ! vc_bogo_is_product_eligible( $product_id, $variation_id ) ) {
		return;
	}

	$visible_qty = max( 0, (int) $quantity );
	$paid_qty    = (int) ceil( $visible_qty / 2 );
	$total_qty   = $paid_qty * 2;

	// If this line wasn't already BOGO, initialise meta (original_price from current price)
	if ( empty( $item['vc_bogo_applied'] ) ) {
		$original_price = 0.0;
		if ( isset( $item['data'] ) && is_object( $item['data'] ) ) {
			$original_price = (float) $item['data']->get_price();
		}

		WC()->cart->cart_contents[ $cart_item_key ]['vc_bogo_applied']        = true;
		WC()->cart->cart_contents[ $cart_item_key ]['vc_bogo_original_price'] = $original_price;
	}

	WC()->cart->cart_contents[ $cart_item_key ]['vc_bogo_paid_qty'] = $paid_qty;

	if ( $visible_qty !== $total_qty ) {
		$in_bogo_qty = true;
		WC()->cart->set_quantity( $cart_item_key, $total_qty, true );
		$in_bogo_qty = false;
	} else {
		WC()->cart->set_session();
	}
}

/* -------------------------
 * Display & order meta
 * ------------------------- */

function vc_bogo_label_cart_item( $product_name, $cart_item, $cart_item_key ) {
	if ( ! empty( $cart_item['vc_bogo_applied'] ) ) {

		$paid_qty = isset( $cart_item['vc_bogo_paid_qty'] ) ? (int) $cart_item['vc_bogo_paid_qty'] : 0;

		$html = sprintf(
			'<p class="vc-bogo-free-label" style="color:#0978EE;font-weight:600;margin:0.2em 0;font-size:13px;">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: paid quantity, 2: free quantity */
					__( 'Buy One Get One Free – you pay for %1$d and get %2$d free', 'virtualcode-bogo-same-product' ),
					$paid_qty,
					$paid_qty
				)
			)
		);

		$product_name .= wp_kses(
			$html,
			array(
				'p' => array(
					'class' => true,
					'style' => true,
				),
			)
		);
	}

	return $product_name;
}

function vc_bogo_copy_cart_meta_to_order_item( $item, $cart_item_key, $values, $order ) {
	if ( ! empty( $values['vc_bogo_applied'] ) ) {

		$paid = isset( $values['vc_bogo_paid_qty'] ) ? (int) $values['vc_bogo_paid_qty'] : 0;

		$item->add_meta_data( '_vc_bogo_paid_qty', $paid, true );
		$item->add_meta_data( '_vc_bogo_note', 'BOGO: paid qty = ' . $paid . ', free qty = ' . $paid, true );

		if ( isset( $values['vc_bogo_original_price'] ) ) {
			$item->add_meta_data(
				'_vc_bogo_original_price',
				wc_format_decimal( $values['vc_bogo_original_price'], wc_get_price_decimals() ),
				true
			);
		}
	}
}