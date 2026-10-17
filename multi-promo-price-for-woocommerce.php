<?php
/**
 * Plugin Name: Multi Promo Price for WooCommerce
 * Description: Permet de superposer une promotion secondaire (promo flash) avec date d'expiration obligatoire par-dessus une promotion standard sans date de fin.
 * Version: 1.0.0
 * Author: Vogel Sessien Paolo BASSO
 * Text Domain: multi-promo-price-for-woocommerce
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'MPPW_VERSION', '1.0.0' );
define( 'MPPW_PLUGIN_FILE', __FILE__ );
define( 'MPPW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

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
	$notices = get_transient( 'mppw_admin_notices_' . get_current_user_id() );
	if ( ! empty( $notices ) && is_array( $notices ) ) {
		foreach ( $notices as $notice ) {
			$class = ! empty( $notice['type'] ) ? $notice['type'] : 'error';
			?>
			<div class="notice notice-<?php echo esc_attr( $class ); ?> is-dismissible">
				<p><?php echo esc_html( $notice['message'] ); ?></p>
			</div>
			<?php
		}
		delete_transient( 'mppw_admin_notices_' . get_current_user_id() );
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
	$notices = get_transient( 'mppw_admin_notices_' . $user_id );
	if ( ! is_array( $notices ) ) {
		$notices = array();
	}
	$notices[] = array(
		'message' => $message,
		'type'    => $type,
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

	$formatted_date_from = ! empty( $date_from ) ? date_i18n( 'Y-m-d', $date_from ) : '';
	$formatted_date_to   = ! empty( $date_to ) ? date_i18n( 'Y-m-d', $date_to ) : '';

	echo '<div class="options_group mppw_pricing_group">';

	woocommerce_wp_text_input(
		array(
			'id'          => '_mppw_sale_price',
			'label'       => __( 'Prix promo secondaire / Flash (' . get_woocommerce_currency_symbol() . ')', 'multi-promo-price-for-woocommerce' ),
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

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
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

	$formatted_date_from = ! empty( $date_from ) ? date_i18n( 'Y-m-d', $date_from ) : '';
	$formatted_date_to   = ! empty( $date_to ) ? date_i18n( 'Y-m-d', $date_to ) : '';
	?>
	<div class="mppw_variation_pricing_group form-row form-row-full">
		<p class="form-row form-row-first">
			<label><?php echo esc_html__( 'Prix promo secondaire / Flash (' . get_woocommerce_currency_symbol() . ')', 'multi-promo-price-for-woocommerce' ); ?></label>
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
 * Save flash promo fields for variation products.
 *
 * @param int $variation_id
 * @param int $i
 */
function mppw_save_variation_pricing_fields( $variation_id, $i ) {
	if ( ! current_user_can( 'edit_post', $variation_id ) ) {
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
		if ( empty( $to_raw ) ) {
			mppw_add_admin_notice(
				sprintf(
					/* translators: %s: product title */
					__( 'Erreur pour "%s" : La date de fin est obligatoire si le prix promo secondaire est renseigné. Le prix n\'a pas été enregistré.', 'multi-promo-price-for-woocommerce' ),
					$label ? $label : '#' . $object_id
				),
				'error'
			);

			// Do not save secondary promo price if end date is missing.
			delete_post_meta( $object_id, '_mppw_sale_price' );
			delete_post_meta( $object_id, '_mppw_dates_from' );
			delete_post_meta( $object_id, '_mppw_dates_to' );
			return;
		}

		$timestamp_to = strtotime( $to_raw . ' 23:59:59' );
		update_post_meta( $object_id, '_mppw_sale_price', $clean_price );
		update_post_meta( $object_id, '_mppw_dates_to', $timestamp_to );

		if ( ! empty( $from_raw ) ) {
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
		return get_post_meta( $product_id, '_mppw_sale_price', true );
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
