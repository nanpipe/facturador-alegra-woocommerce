<?php
/**
 * Plugin Name: WooCommerce Alegra Invoice Generator (Modular)
 * Description: Genera facturas en Alegra con un clic, modularizado para mejor mantenimiento.
 * Version: 2.1
 * Author: Pipe & Gemini
 * Text Domain: wc-alegra-invoices
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. Definir constantes
define( 'WC_ALEGRA_PLUGIN_FILE', __FILE__ );
define( 'WC_ALEGRA_PLUGIN_PATH', plugin_dir_path( WC_ALEGRA_PLUGIN_FILE ) );
define( 'WC_ALEGRA_PLUGIN_URL', plugin_dir_url( WC_ALEGRA_PLUGIN_FILE ) );

// 2. Compatibilidad con HPOS (High-Performance Order Storage)
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WC_ALEGRA_PLUGIN_FILE, true );
    }
} );

// 3. Incluir archivos de clases
function wc_alegra_include_classes() {
    // La secuencia de inclusión es importante por las dependencias
    require_once WC_ALEGRA_PLUGIN_PATH . 'includes/class-alegra-api-client.php';
    require_once WC_ALEGRA_PLUGIN_PATH . 'includes/class-alegra-data-mapper.php';
    require_once WC_ALEGRA_PLUGIN_PATH . 'includes/class-wc-alegra-admin.php';

    // 4. Inicializar el plugin (solo la clase Admin que maneja los hooks de WP)
    new WC_Alegra_Admin();
}

add_action( 'plugins_loaded', 'wc_alegra_include_classes' );