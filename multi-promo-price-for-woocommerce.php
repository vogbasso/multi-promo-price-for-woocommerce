<?php
/**
 * Plugin Name: Multi Promo Price for WooCommerce
 * Description: Permet de superposer une promotion secondaire (promo flash) avec date d'expiration obligatoire par-dessus une promotion standard sans date de fin.
 * Version: 1.2.0
 * Author: Vogel Sessien Paolo BASSO
 * Text Domain: multi-promo-price-for-woocommerce
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'MPPW_VERSION', '1.2.0' );
define( 'MPPW_PLUGIN_FILE', __FILE__ );
define( 'MPPW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MPPW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Check if WooCommerce is active.
 *
 * @return bool
 */
function mppw_is_woocommerce_active() {
	return in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true )
		|| ( is_multisite() && array_key_exists( 'woocommerce/woocommerce.php', get_site_option( 'active_sitewide_plugins', array() ) ) );
}

/**
 * Initialize plugin hooks.
 */
function mppw_init() {
	if ( ! mppw_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'mppw_missing_wc_notice' );
		return;
	}

	// Admin product fields (Simple products).
	add_action( 'woocommerce_product_options_pricing', 'mppw_add_simple_product_pricing_fields' );
	add_action( 'woocommerce_process_product_meta', 'mppw_save_simple_product_pricing_fields' );

	// Admin product fields (Variable products).
	add_action( 'woocommerce_variation_options_pricing', 'mppw_add_variation_pricing_fields', 10, 3 );
	add_action( 'woocommerce_save_product_variation', 'mppw_save_variation_pricing_fields', 10, 2 );

	// Admin notices.
	add_action( 'admin_notices', 'mppw_display_admin_notices' );

	// Front-end & Cart price hooks.
	add_filter( 'woocommerce_product_get_price', 'mppw_filter_product_price', 20, 2 );
	add_filter( 'woocommerce_product_get_sale_price', 'mppw_filter_product_sale_price', 20, 2 );
	add_filter( 'woocommerce_product_variation_get_price', 'mppw_filter_product_price', 20, 2 );
	add_filter( 'woocommerce_product_variation_get_sale_price', 'mppw_filter_product_sale_price', 20, 2 );

	// Variation price hash & transients.
	add_filter( 'woocommerce_variation_prices_price', 'mppw_filter_variation_prices_price', 20, 3 );
	add_filter( 'woocommerce_variation_prices_sale_price', 'mppw_filter_variation_prices_sale_price', 20, 3 );

	// On sale check.
	add_filter( 'woocommerce_product_is_on_sale', 'mppw_filter_is_on_sale', 20, 2 );

	// Frontend countdown timer enqueue & display.
	add_action( 'wp_enqueue_scripts', 'mppw_enqueue_countdown_assets' );
	add_action( 'woocommerce_single_product_summary', 'mppw_display_countdown_timer', 15 );
	add_action( 'woocommerce_after_shop_loop_item_title', 'mppw_display_loop_countdown_timer', 15 );
	add_filter( 'woocommerce_available_variation', 'mppw_add_variation_countdown_data', 10, 3 );
}
add_action( 'plugins_loaded', 'mppw_init' );

/**
 * Admin notice if WooCommerce is not installed or active.
 */
function mppw_missing_wc_notice() {
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Multi Promo Price for WooCommerce requires WooCommerce to be installed and active.', 'multi-promo-price-for-woocommerce' ); ?></p>
	</div>
	<?php
}

/**
 * Display queued admin notices.
 */
function mppw_display_admin_notices() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return;
	}

	$notices = get_transient( 'mppw_admin_notices_' . $user_id );
	if ( ! empty( $notices ) && is_array( $notices ) ) {
		foreach ( $notices as $notice ) {
			$raw_class = ! empty( $notice['type'] ) ? $notice['type'] : 'error';
			$class     = sanitize_html_class( $raw_class );
			?>
			<div class="notice notice-<?php echo esc_attr( $class ); ?> is-dismissible">
				<p><?php echo esc_html( $notice['message'] ); ?></p>
			</div>
			<?php
		}
		delete_transient( 'mppw_admin_notices_' . $user_id );
	}
}

/**
 * Store an admin notice in transient.
 *
 * @param string $message
 * @param string $type
 */
function mppw_add_admin_notice( $message, $type = 'error' ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return;
	}

	$notices = get_transient( 'mppw_admin_notices_' . $user_id );
	if ( ! is_array( $notices ) ) {
		$notices = array();
	}
	$notices[] = array(
		'message' => $message,
		'type'    => sanitize_key( $type ),
	);
	set_transient( 'mppw_admin_notices_' . $user_id, $notices, 60 );
}

/**
 * Add flash promo fields to simple product pricing tab.
 */
function mppw_add_simple_product_pricing_fields() {
	global $post;

	wp_nonce_field( 'mppw_save_pricing_meta', 'mppw_pricing_nonce' );

	$sale_price = get_post_meta( $post->ID, '_mppw_sale_price', true );
	$date_from  = get_post_meta( $post->ID, '_mppw_dates_from', true );
	$date_to    = get_post_meta( $post->ID, '_mppw_dates_to', true );

	$formatted_date_from = ! empty( $date_from ) ? date_i18n( 'Y-m-d', (int) $date_from ) : '';
	$formatted_date_to   = ! empty( $date_to ) ? date_i18n( 'Y-m-d', (int) $date_to ) : '';

	echo '<div class="options_group mppw_pricing_group">';

	woocommerce_wp_text_input(
		array(
			'id'          => '_mppw_sale_price',
			'label'       => sprintf(
				/* translators: %s: Currency symbol */
				__( 'Prix promo secondaire / Flash (%s)', 'multi-promo-price-for-woocommerce' ),
				get_woocommerce_currency_symbol()
			),
			'placeholder' => '',
			'data_type'   => 'price',
			'value'       => $sale_price,
			'desc_tip'    => true,
			'description' => __( 'Prix temporaire prioritaire sur la promotion normale.', 'multi-promo-price-for-woocommerce' ),
		)
	);

	woocommerce_wp_text_input(
		array(
			'id'          => '_mppw_dates_from',
			'label'       => __( 'Date début promo secondaire', 'multi-promo-price-for-woocommerce' ),
			'placeholder' => _x( 'From&hellip;', 'placeholder', 'woocommerce' ) . ' YYYY-MM-DD',
			'value'       => $formatted_date_from,
			'class'       => 'date-picker',
			'desc_tip'    => true,
			'description' => __( 'Date de début optionnelle de la promo secondaire (à 00:00:00).', 'multi-promo-price-for-woocommerce' ),
		)
	);

	woocommerce_wp_text_input(
		array(
			'id'          => '_mppw_dates_to',
			'label'       => __( 'Date fin promo secondaire (Obligatoire si prix renseigné)', 'multi-promo-price-for-woocommerce' ),
			'placeholder' => _x( 'To&hellip;', 'placeholder', 'woocommerce' ) . ' YYYY-MM-DD',
			'value'       => $formatted_date_to,
			'class'       => 'date-picker',
			'desc_tip'    => true,
			'description' => __( 'Date d\'expiration obligatoire à 23:59:59 si le prix secondaire est renseigné.', 'multi-promo-price-for-woocommerce' ),
		)
	);

	echo '</div>';
}

/**
 * Save flash promo fields for simple products.
 *
 * @param int $post_id
 */
function mppw_save_simple_product_pricing_fields( $post_id ) {
	if ( ! isset( $_POST['mppw_pricing_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mppw_pricing_nonce'] ) ), 'mppw_save_pricing_meta' ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_product', $post_id ) ) {
		return;
	}

	$price_raw = isset( $_POST['_mppw_sale_price'] ) ? wc_clean( wp_unslash( $_POST['_mppw_sale_price'] ) ) : '';
	$from_raw  = isset( $_POST['_mppw_dates_from'] ) ? sanitize_text_field( wp_unslash( $_POST['_mppw_dates_from'] ) ) : '';
	$to_raw    = isset( $_POST['_mppw_dates_to'] ) ? sanitize_text_field( wp_unslash( $_POST['_mppw_dates_to'] ) ) : '';

	$product_title = get_the_title( $post_id );
	mppw_process_and_save_meta( $post_id, $price_raw, $from_raw, $to_raw, $product_title );

	wc_delete_product_transients( $post_id );
}

/**
 * Add flash promo fields to variation pricing tab.
 *
 * @param int     $loop
 * @param array   $variation_data
 * @param WP_Post $variation
 */
function mppw_add_variation_pricing_fields( $loop, $variation_data, $variation ) {
	$variation_id = $variation->ID;

	$sale_price = get_post_meta( $variation_id, '_mppw_sale_price', true );
	$date_from  = get_post_meta( $variation_id, '_mppw_dates_from', true );
	$date_to    = get_post_meta( $variation_id, '_mppw_dates_to', true );

	$formatted_date_from = ! empty( $date_from ) ? date_i18n( 'Y-m-d', (int) $date_from ) : '';
	$formatted_date_to   = ! empty( $date_to ) ? date_i18n( 'Y-m-d', (int) $date_to ) : '';
	?>
	<div class="mppw_variation_pricing_group form-row form-row-full">
		<p class="form-row form-row-first">
			<label><?php echo esc_html( sprintf( __( 'Prix promo secondaire / Flash (%s)', 'multi-promo-price-for-woocommerce' ), get_woocommerce_currency_symbol() ) ); ?></label>
			<input type="text" size="5" class="wc_input_price" name="_mppw_variable_sale_price[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( $sale_price ); ?>" />
		</p>
		<p class="form-row form-row-last">
			<label><?php echo esc_html__( 'Date début promo secondaire', 'multi-promo-price-for-woocommerce' ); ?></label>
			<input type="text" class="date-picker" name="_mppw_variable_dates_from[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( $formatted_date_from ); ?>" placeholder="<?php echo esc_attr_x( 'From&hellip;', 'placeholder', 'woocommerce' ); ?> YYYY-MM-DD" maxlength="10" pattern="<?php echo esc_attr( apply_filters( 'woocommerce_date_input_html_pattern', '[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])' ) ); ?>" />
		</p>
		<p class="form-row form-row-full">
			<label><?php echo esc_html__( 'Date fin promo secondaire (Obligatoire si prix renseigné)', 'multi-promo-price-for-woocommerce' ); ?></label>
			<input type="text" class="date-picker" name="_mppw_variable_dates_to[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( $formatted_date_to ); ?>" placeholder="<?php echo esc_attr_x( 'To&hellip;', 'placeholder', 'woocommerce' ); ?> YYYY-MM-DD" maxlength="10" pattern="<?php echo esc_attr( apply_filters( 'woocommerce_date_input_html_pattern', '[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])' ) ); ?>" />
		</p>
	</div>
	<?php
}

/**
 * Verify nonce for saving variation pricing fields.
 * Handles both standard WC meta box save and WC AJAX variation save.
 *
 * @return bool
 */
function mppw_verify_variation_save_nonce() {
	if ( isset( $_POST['security'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['security'] ) ), 'save-variations' ) ) {
		return true;
	}

	if ( isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
		return true;
	}

	if ( isset( $_POST['mppw_pricing_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mppw_pricing_nonce'] ) ), 'mppw_save_pricing_meta' ) ) {
		return true;
	}

	return false;
}

/**
 * Save flash promo fields for variation products.
 *
 * @param int $variation_id
 * @param int $i
 */
function mppw_save_variation_pricing_fields( $variation_id, $i ) {
	if ( ! mppw_verify_variation_save_nonce() ) {
		return;
	}

	if ( ! current_user_can( 'edit_product', $variation_id ) ) {
		return;
	}

	$price_raw = isset( $_POST['_mppw_variable_sale_price'][ $i ] ) ? wc_clean( wp_unslash( $_POST['_mppw_variable_sale_price'][ $i ] ) ) : '';
	$from_raw  = isset( $_POST['_mppw_variable_dates_from'][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST['_mppw_variable_dates_from'][ $i ] ) ) : '';
	$to_raw    = isset( $_POST['_mppw_variable_dates_to'][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST['_mppw_variable_dates_to'][ $i ] ) ) : '';

	$product_title = get_the_title( $variation_id );
	mppw_process_and_save_meta( $variation_id, $price_raw, $from_raw, $to_raw, $product_title );

	$parent_id = wp_get_post_parent_id( $variation_id );
	if ( $parent_id ) {
		wc_delete_product_transients( $parent_id );
	}
}

/**
 * Validate YYYY-MM-DD date string.
 *
 * @param string $date
 * @return bool
 */
function mppw_is_valid_date_format( $date ) {
	if ( ! is_string( $date ) || ! preg_match( '/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/', $date ) ) {
		return false;
	}
	return true;
}

/**
 * Process validation and save metadata for product or variation.
 *
 * @param int    $object_id
 * @param string $price_raw
 * @param string $from_raw
 * @param string $to_raw
 * @param string $label
 */
function mppw_process_and_save_meta( $object_id, $price_raw, $from_raw, $to_raw, $label = '' ) {
	$clean_price = ( '' !== $price_raw ) ? wc_format_decimal( $price_raw ) : '';

	if ( '' !== $clean_price ) {
		// Strict validation: End date is required if secondary sale price is set.
		if ( empty( $to_raw ) || ! mppw_is_valid_date_format( $to_raw ) ) {
			mppw_add_admin_notice(
				sprintf(
					/* translators: %s: product title */
					__( 'Erreur pour "%s" : La date de fin est obligatoire (format YYYY-MM-DD) si le prix promo secondaire est renseigné. Le prix n\'a pas été enregistré.', 'multi-promo-price-for-woocommerce' ),
					$label ? $label : '#' . $object_id
				),
				'error'
			);

			// Do not save secondary promo price if end date is missing or invalid.
			delete_post_meta( $object_id, '_mppw_sale_price' );
			delete_post_meta( $object_id, '_mppw_dates_from' );
			delete_post_meta( $object_id, '_mppw_dates_to' );
			return;
		}

		$timestamp_to = strtotime( $to_raw . ' 23:59:59' );
		update_post_meta( $object_id, '_mppw_sale_price', $clean_price );
		update_post_meta( $object_id, '_mppw_dates_to', $timestamp_to );

		if ( ! empty( $from_raw ) && mppw_is_valid_date_format( $from_raw ) ) {
			$timestamp_from = strtotime( $from_raw . ' 00:00:00' );
			update_post_meta( $object_id, '_mppw_dates_from', $timestamp_from );
		} else {
			delete_post_meta( $object_id, '_mppw_dates_from' );
		}
	} else {
		delete_post_meta( $object_id, '_mppw_sale_price' );
		delete_post_meta( $object_id, '_mppw_dates_from' );
		delete_post_meta( $object_id, '_mppw_dates_to' );
	}
}

/**
 * Check if the secondary promo is currently active for a given product or variation.
 *
 * @param int $product_id
 * @return bool
 */
function mppw_is_super_promo_active( $product_id ) {
	$product_id = absint( $product_id );
	if ( ! $product_id ) {
		return false;
	}

	$sale_price = get_post_meta( $product_id, '_mppw_sale_price', true );
	if ( '' === $sale_price || null === $sale_price || false === $sale_price ) {
		return false;
	}

	$date_to = get_post_meta( $product_id, '_mppw_dates_to', true );
	if ( empty( $date_to ) ) {
		return false;
	}

	$now       = current_time( 'timestamp' );
	$date_from = get_post_meta( $product_id, '_mppw_dates_from', true );

	// Promo must be started if start date is defined (00:00:00).
	if ( ! empty( $date_from ) && $now < (int) $date_from ) {
		return false;
	}

	// Promo must not have expired (23:59:59).
	if ( $now > (int) $date_to ) {
		return false;
	}

	return true;
}

/**
 * Get active secondary promo price for a product.
 *
 * @param int $product_id
 * @return string|false
 */
function mppw_get_active_super_promo_price( $product_id ) {
	if ( mppw_is_super_promo_active( $product_id ) ) {
		return get_post_meta( absint( $product_id ), '_mppw_sale_price', true );
	}
	return false;
}

/**
 * Filter product/variation price.
 *
 * @param string     $price
 * @param WC_Product $product
 * @return string
 */
function mppw_filter_product_price( $price, $product ) {
	if ( ! $product instanceof WC_Product ) {
		return $price;
	}

	$super_price = mppw_get_active_super_promo_price( $product->get_id() );
	if ( false !== $super_price && '' !== $super_price ) {
		return (string) $super_price;
	}

	return $price;
}

/**
 * Filter product/variation sale price.
 *
 * @param string     $sale_price
 * @param WC_Product $product
 * @return string
 */
function mppw_filter_product_sale_price( $sale_price, $product ) {
	if ( ! $product instanceof WC_Product ) {
		return $sale_price;
	}

	$super_price = mppw_get_active_super_promo_price( $product->get_id() );
	if ( false !== $super_price && '' !== $super_price ) {
		return (string) $super_price;
	}

	return $sale_price;
}

/**
 * Filter variation cached prices.
 *
 * @param string               $price
 * @param WC_Product_Variation $variation
 * @param WC_Product_Variable  $product
 * @return string
 */
function mppw_filter_variation_prices_price( $price, $variation, $product ) {
	if ( ! $variation instanceof WC_Product ) {
		return $price;
	}

	$super_price = mppw_get_active_super_promo_price( $variation->get_id() );
	if ( false !== $super_price && '' !== $super_price ) {
		return (string) $super_price;
	}

	return $price;
}

/**
 * Filter variation cached sale prices.
 *
 * @param string               $price
 * @param WC_Product_Variation $variation
 * @param WC_Product_Variable  $product
 * @return string
 */
function mppw_filter_variation_prices_sale_price( $price, $variation, $product ) {
	if ( ! $variation instanceof WC_Product ) {
		return $price;
	}

	$super_price = mppw_get_active_super_promo_price( $variation->get_id() );
	if ( false !== $super_price && '' !== $super_price ) {
		return (string) $super_price;
	}

	return $price;
}

/**
 * Filter is_on_sale to return true if secondary promo is currently active.
 *
 * @param bool       $is_on_sale
 * @param WC_Product $product
 * @return bool
 */
function mppw_filter_is_on_sale( $is_on_sale, $product ) {
	if ( ! $product instanceof WC_Product ) {
		return $is_on_sale;
	}

	if ( mppw_is_super_promo_active( $product->get_id() ) ) {
		return true;
	}

	return $is_on_sale;
}

/**
 * Retrieve end timestamp for standard WooCommerce sale if active.
 * Ensures the expiration ends at 23:59:59 of the day if no specific time is provided.
 *
 * @param WC_Product $product
 * @return int|false
 */
function mppw_get_standard_promo_end_timestamp( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return false;
	}

	$sale_price = $product->get_sale_price( 'edit' );
	if ( '' === $sale_price || null === $sale_price || false === $sale_price ) {
		return false;
	}

	$date_to = $product->get_date_on_sale_to( 'edit' );
	$timestamp_to = 0;

	if ( $date_to instanceof WC_DateTime ) {
		$timestamp_to = $date_to->getTimestamp();
	} else {
		$raw_to = get_post_meta( $product->get_id(), '_sale_price_dates_to', true );
		if ( ! empty( $raw_to ) ) {
			$timestamp_to = is_numeric( $raw_to ) ? (int) $raw_to : strtotime( $raw_to );
		}
	}

	if ( empty( $timestamp_to ) ) {
		return false;
	}

	// If the timestamp has no time component (midnight 00:00:00), adjust it to 23:59:59 of that day.
	if ( 0 === ( $timestamp_to % 86400 ) ) {
		$timestamp_to += 86399;
	}

	$now = current_time( 'timestamp' );

	// Check start date if present.
	$date_from = $product->get_date_on_sale_from( 'edit' );
	$timestamp_from = 0;
	if ( $date_from instanceof WC_DateTime ) {
		$timestamp_from = $date_from->getTimestamp();
	} else {
		$raw_from = get_post_meta( $product->get_id(), '_sale_price_dates_from', true );
		if ( ! empty( $raw_from ) ) {
			$timestamp_from = is_numeric( $raw_from ) ? (int) $raw_from : strtotime( $raw_from );
		}
	}

	if ( ! empty( $timestamp_from ) && $now < $timestamp_from ) {
		return false;
	}

	if ( $now > $timestamp_to ) {
		return false;
	}

	return $timestamp_to;
}

/**
 * Determine countdown information in cascading order:
 * 1. Super Promo Flash
 * 2. Standard WooCommerce Promo with end date
 * 3. None
 *
 * @param WC_Product|int $product_or_id
 * @return array|false Returns array with 'expire_timestamp' and 'title', or false.
 */
function mppw_get_active_countdown_info( $product_or_id ) {
	$product = is_numeric( $product_or_id ) ? wc_get_product( $product_or_id ) : $product_or_id;
	if ( ! $product instanceof WC_Product ) {
		return false;
	}

	$product_id = $product->get_id();

	// Case 1: Super Promo Flash active.
	if ( mppw_is_super_promo_active( $product_id ) ) {
		$flash_to = (int) get_post_meta( $product_id, '_mppw_dates_to', true );
		if ( $flash_to > current_time( 'timestamp' ) ) {
			return array(
				'type'             => 'flash',
				'expire_timestamp' => $flash_to,
				'title'            => __( 'Vente Flash se termine dans :', 'multi-promo-price-for-woocommerce' ),
			);
		}
	}

	// Case 2: Standard WooCommerce Promo active with scheduled end date.
	$standard_to = mppw_get_standard_promo_end_timestamp( $product );
	if ( ! empty( $standard_to ) && $standard_to > current_time( 'timestamp' ) ) {
		return array(
			'type'             => 'standard',
			'expire_timestamp' => $standard_to,
			'title'            => __( 'Offre promotionnelle se termine dans :', 'multi-promo-price-for-woocommerce' ),
		);
	}

	// Case 3: For variable products in catalog/loop contexts, cascade among active variations.
	if ( $product->is_type( 'variable' ) ) {
		$children = $product->get_children();
		if ( ! empty( $children ) ) {
			$best_info = false;
			foreach ( $children as $child_id ) {
				$child_info = mppw_get_active_countdown_info( $child_id );
				if ( ! $child_info ) {
					continue;
				}

				if ( ! $best_info ) {
					$best_info = $child_info;
				} elseif ( 'flash' === $child_info['type'] && 'standard' === $best_info['type'] ) {
					$best_info = $child_info;
				} elseif ( $child_info['type'] === $best_info['type'] && $child_info['expire_timestamp'] < $best_info['expire_timestamp'] ) {
					// Show the earliest expiring promo of the same tier.
					$best_info = $child_info;
				}
			}
			if ( $best_info ) {
				return $best_info;
			}
		}
	}

	// Case 4: No promotion with end date.
	return false;
}

/**
 * Check if the product or any of its variations has an active countdown (flash or standard).
 *
 * @param WC_Product $product
 * @return bool
 */
function mppw_product_or_children_has_active_promo( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return false;
	}

	return false !== mppw_get_active_countdown_info( $product );
}

/**
 * Enqueue countdown assets on WooCommerce pages (single product, shop, categories, search).
 */
function mppw_enqueue_countdown_assets() {
	if ( ! is_woocommerce() && ! is_product() && ! is_shop() && ! is_product_taxonomy() ) {
		return;
	}

	// On single product, only enqueue if product has an active promo.
	if ( is_product() ) {
		$product = wc_get_product( get_the_ID() );
		if ( ! $product || ! mppw_product_or_children_has_active_promo( $product ) ) {
			return;
		}
	}

	wp_enqueue_style(
		'mppw-countdown-css',
		MPPW_PLUGIN_URL . 'assets/css/countdown.css',
		array(),
		MPPW_VERSION
	);

	wp_enqueue_script(
		'mppw-countdown-js',
		MPPW_PLUGIN_URL . 'assets/js/countdown.js',
		array(),
		MPPW_VERSION,
		true
	);
}

/**
 * Pass variation countdown data (timestamp and title) to frontend JavaScript in cascade order.
 *
 * @param array                $variation_data
 * @param WC_Product_Variable  $product
 * @param WC_Product_Variation $variation
 * @return array
 */
function mppw_add_variation_countdown_data( $variation_data, $product, $variation ) {
	$countdown_info = mppw_get_active_countdown_info( $variation );

	if ( false !== $countdown_info ) {
		$variation_data['mppw_expire_timestamp'] = (int) $countdown_info['expire_timestamp'];
		$variation_data['mppw_countdown_title']  = sanitize_text_field( $countdown_info['title'] );
	} else {
		$variation_data['mppw_expire_timestamp'] = '';
		$variation_data['mppw_countdown_title']  = '';
	}

	return $variation_data;
}

/**
 * Render the countdown timer on single product pages.
 */
function mppw_display_countdown_timer() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$countdown_info = mppw_get_active_countdown_info( $product );
	$is_active      = ( false !== $countdown_info );
	$is_variable    = $product->is_type( 'variable' );

	// If not active on main product and not variable (or variable with no children promos), skip rendering.
	if ( ! $is_active && ( ! $is_variable || ! mppw_product_or_children_has_active_promo( $product ) ) ) {
		return;
	}

	$wrapper_style = ( ! $is_active && $is_variable ) ? 'display: none;' : '';
	$expire_val    = $is_active ? (int) $countdown_info['expire_timestamp'] : '';
	$title_val     = $is_active ? $countdown_info['title'] : __( 'Offre Flash se termine dans :', 'multi-promo-price-for-woocommerce' );
	?>
	<div class="mppw-countdown-wrapper" data-expire="<?php echo esc_attr( (string) $expire_val ); ?>" data-default-expire="<?php echo esc_attr( (string) $expire_val ); ?>" data-default-title="<?php echo esc_attr( $title_val ); ?>" style="<?php echo esc_attr( $wrapper_style ); ?>">
		<div class="mppw-countdown-header">
			<svg viewBox="0 0 24 24">
				<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.5-13H11v6l5.2 3.2.8-1.3-4.5-2.7V7z"/>
			</svg>
			<span class="mppw-countdown-title"><?php echo esc_html( $title_val ); ?></span>
		</div>
		<div class="mppw-countdown-timer">
			<div class="mppw-countdown-item">
				<span class="mppw-countdown-num" data-unit="days">00</span>
				<span class="mppw-countdown-label"><?php esc_html_e( 'Jours', 'multi-promo-price-for-woocommerce' ); ?></span>
			</div>
			<span class="mppw-countdown-sep">:</span>
			<div class="mppw-countdown-item">
				<span class="mppw-countdown-num" data-unit="hours">00</span>
				<span class="mppw-countdown-label"><?php esc_html_e( 'Heures', 'multi-promo-price-for-woocommerce' ); ?></span>
			</div>
			<span class="mppw-countdown-sep">:</span>
			<div class="mppw-countdown-item">
				<span class="mppw-countdown-num" data-unit="mins">00</span>
				<span class="mppw-countdown-label"><?php esc_html_e( 'Min', 'multi-promo-price-for-woocommerce' ); ?></span>
			</div>
			<span class="mppw-countdown-sep">:</span>
			<div class="mppw-countdown-item">
				<span class="mppw-countdown-num" data-unit="secs">00</span>
				<span class="mppw-countdown-label"><?php esc_html_e( 'Sec', 'multi-promo-price-for-woocommerce' ); ?></span>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Render compact countdown timer on product cards in shop loops (categories, shop, search).
 */
function mppw_display_loop_countdown_timer() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$countdown_info = mppw_get_active_countdown_info( $product );
	if ( false === $countdown_info || empty( $countdown_info['expire_timestamp'] ) ) {
		return;
	}

	$expire_val = (int) $countdown_info['expire_timestamp'];
	$title_val  = $countdown_info['title'];
	?>
	<div class="mppw-countdown-wrapper mppw-timer-loop" data-expire="<?php echo esc_attr( (string) $expire_val ); ?>">
		<div class="mppw-countdown-header">
			<svg viewBox="0 0 24 24">
				<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.5-13H11v6l5.2 3.2.8-1.3-4.5-2.7V7z"/>
			</svg>
			<span class="mppw-countdown-title"><?php echo esc_html( $title_val ); ?></span>
		</div>
		<div class="mppw-countdown-timer">
			<div class="mppw-countdown-item">
				<span class="mppw-countdown-num" data-unit="days">00</span>
				<span class="mppw-countdown-label"><?php esc_html_e( 'J', 'multi-promo-price-for-woocommerce' ); ?></span>
			</div>
			<span class="mppw-countdown-sep">:</span>
			<div class="mppw-countdown-item">
				<span class="mppw-countdown-num" data-unit="hours">00</span>
				<span class="mppw-countdown-label"><?php esc_html_e( 'H', 'multi-promo-price-for-woocommerce' ); ?></span>
			</div>
			<span class="mppw-countdown-sep">:</span>
			<div class="mppw-countdown-item">
				<span class="mppw-countdown-num" data-unit="mins">00</span>
				<span class="mppw-countdown-label"><?php esc_html_e( 'M', 'multi-promo-price-for-woocommerce' ); ?></span>
			</div>
			<span class="mppw-countdown-sep">:</span>
			<div class="mppw-countdown-item">
				<span class="mppw-countdown-num" data-unit="secs">00</span>
				<span class="mppw-countdown-label"><?php esc_html_e( 'S', 'multi-promo-price-for-woocommerce' ); ?></span>
			</div>
		</div>
	</div>
	<?php
}
