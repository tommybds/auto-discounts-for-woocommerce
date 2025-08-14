<?php
/**
 * Plugin Name: Auto Discounts for WooCommerce
 * Plugin URI: https://github.com/tommybds/auto-discounts-for-woocommerce
 * Description: Système de remises automatiques basé sur l'âge des produits pour WooCommerce
 * Version: 1.0.2
 * Author: Tommy Bordas
 * Author URI: https://tommy-bordas.fr/
 * Text Domain: auto-discounts-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WooCommerce_Auto_Discounts
 */

if (!defined('ABSPATH')) {
    exit; // Sortie si accès direct
}

// S'assurer que WooCommerce est actif
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>' . esc_html__('Auto Discounts for WooCommerce nécessite WooCommerce pour fonctionner.', 'auto-discounts-for-woocommerce') . '</p></div>';
    });
    return;
}

// Définir le chemin du plugin
define('WCAD_PATH', plugin_dir_path(__FILE__));
define('WCAD_URL', plugin_dir_url(__FILE__));
define('WCAD_VERSION', '1.0.2');

/**
 * Charge les fichiers de traduction
 */
// Les traductions sont chargées automatiquement depuis WordPress.org; pas besoin de load_plugin_textdomain.

// Inclure la classe principale
require_once WCAD_PATH . 'includes/class-wcad-auto-discounts.php';

/**
 * Activer le cron lors de l'activation du plugin
 */
register_activation_hook(__FILE__, 'wcad_activate');
function wcad_activate() {
    if (!wp_next_scheduled('wcad_daily_discount_update')) {
        wp_schedule_event(strtotime('today midnight'), 'daily', 'wcad_daily_discount_update');
    }
    
    // Créer les options par défaut si elles n'existent pas
    if (false === get_option('wcad_discount_rules')) {
        add_option('wcad_discount_rules', array());
    }
    
    if (false === get_option('wcad_excluded_categories')) {
        add_option('wcad_excluded_categories', array());
    }
}

/**
 * Nettoyer le cron lors de la désactivation du plugin
 */
register_deactivation_hook(__FILE__, 'wcad_deactivate');
function wcad_deactivate() {
    $timestamp = wp_next_scheduled('wcad_daily_discount_update');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'wcad_daily_discount_update');
    }
}

/**
 * Initialise le plugin
 */
function wcad_init() {
    // Instancier la classe
    WCAD_Auto_Discounts::get_instance();
}
add_action('plugins_loaded', 'wcad_init', 20);

/**
 * Déclare la compatibilité avec les fonctionnalités WooCommerce
 */
function wcad_declare_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        // Déclarer la compatibilité avec High-Performance Order Storage (HPOS)
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
        
        // Déclarer la compatibilité avec Remote Logging
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'remote_logging',
            __FILE__,
            true
        );
    }
}
add_action('before_woocommerce_init', 'wcad_declare_compatibility'); 