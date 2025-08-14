<?php
/**
 * Classe principale du plugin Auto Discounts
 *
 * @package WooCommerce_Auto_Discounts
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Sortie si accès direct
}

/**
 * Classe principale qui gère les remises automatiques
 */
class WCAD_Auto_Discounts {
    /**
     * Instance unique de la classe
     *
     * @var WCAD_Auto_Discounts
     */
    private static $instance = null;
    
    /**
     * Cache des prix pour éviter les calculs répétés
     *
     * @var array
     */
    private $price_cache = [];
    
    /**
     * Nom du hook de cron
     */
    const CRON_HOOK = 'wcad_daily_discount_update';

    /**
     * Récupère l'instance unique de la classe
     *
     * @return WCAD_Auto_Discounts
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructeur
     */
    private function __construct() {
        // Hooks d'administration
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Cron setup
        add_action('init', [$this, 'setup_cron']);
        add_action(self::CRON_HOOK, [$this, 'update_all_products_discounts']);

        // Initialise les hooks AJAX
        $this->init_ajax();
        
        // Ajouter le champ dans les produits
        add_action('woocommerce_product_options_pricing', [$this, 'add_exclude_from_discounts_field']);
        add_action('woocommerce_process_product_meta', [$this, 'save_exclude_from_discounts_field']);
        
        // Support pour les variations
        add_action('woocommerce_variation_options', [$this, 'add_exclude_from_discounts_field_to_variation'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'save_exclude_from_discounts_field_variation'], 10, 2);
        
        // Ajouter la colonne à la liste des produits
        add_filter('manage_edit-product_columns', [$this, 'add_product_column']);
        add_action('manage_product_posts_custom_column', [$this, 'show_product_column'], 10, 2);
        
        // Support pour les actions en masse
        add_filter('bulk_actions-edit-product', [$this, 'register_bulk_actions']);
        add_filter('handle_bulk_actions-edit-product', [$this, 'handle_bulk_actions'], 10, 3);

        // Ajout du filtre rapide pour les produits exclus
        add_filter('woocommerce_product_filters', [$this, 'add_products_filter']);
        add_filter('request', [$this, 'filter_products_by_exclusion']);
        
        // Message d'admin sur le statut d'exclusion sur la page produit
        add_action('admin_notices', [$this, 'show_exclusion_status_notice']);
    }

    /**
     * Initialise les hooks AJAX
     */
    private function init_ajax() {
        add_action('wp_ajax_wcad_preview_rule', [$this, 'ajax_preview_rule']);
    }

    /**
     * Traite la requête AJAX pour prévisualiser une règle
     */
    public function ajax_preview_rule() {
        check_ajax_referer('wcad_preview_rule', 'security');
        
        $min_age = isset($_POST['min_age']) ? absint($_POST['min_age']) : 0;
        $respect_manual = isset($_POST['respect_manual']) ? (bool)$_POST['respect_manual'] : false;
        
        // Récupérer les catégories exclues
        $excluded_categories = get_option('wcad_excluded_categories', []);
        
        // Compter les produits qui seraient affectés
        $args = [
            'status' => 'publish',
            'limit' => -1,
            'stock_status' => 'instock',
            'return' => 'ids',
        ];
        
        $product_ids = wc_get_products($args);
        $affected_products = [];
        $total_value = 0;
        
        foreach ($product_ids as $product_id) {
            // Vérifier si le produit est dans une catégorie exclue
            $product_cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
            if (!empty($excluded_categories) && array_intersect($product_cats, $excluded_categories)) {
                continue;
            }
            
            // Vérifier si le produit est marqué comme exclu des remises
            $product = wc_get_product($product_id);
            if ($this->is_product_excluded($product_id, $product)) {
                continue;
            }
            
            // Vérifier l'âge du produit
            $product_age = $this->get_product_age($product_id);
            if ($product_age < $min_age) {
                continue;
            }
            
            // Vérifier si le produit a déjà une remise manuelle
            if ($respect_manual && $this->has_manual_discount($product_id, $product)) {
                continue;
            }
            
            // Ajouter le produit à la liste des produits affectés
            $affected_products[] = [
                'id' => $product_id,
                'name' => $product->get_name(),
                'price' => $product->get_regular_price(),
                'link' => get_edit_post_link($product_id)
            ];
            
            $total_value += floatval($product->get_regular_price());
        }
        
        $response = [
            'count' => count($affected_products),
            'total_value' => $total_value,
            'products' => array_slice($affected_products, 0, 10) // Limiter à 10 produits pour l'aperçu
        ];
        
        wp_send_json_success($response);
    }

    /**
     * Ajoute le menu d'administration
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Règles de remise auto', 'auto-discounts-for-woocommerce'),
            __('Remises auto', 'auto-discounts-for-woocommerce'),
            'manage_options',
            'wcad-auto-discounts',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Enregistre les paramètres du plugin
     */
    public function register_settings() {
        register_setting('wcad_auto_discounts', 'wcad_discount_rules', [
            'type' => 'array',
            'default' => [],
            'sanitize_callback' => [$this, 'sanitize_and_apply_rules']
        ]);
        register_setting('wcad_auto_discounts', 'wcad_excluded_categories', [
            'type' => 'array',
            'default' => [],
            'sanitize_callback' => [$this, 'sanitize_and_apply_categories']
        ]);
        
        // Migration des données depuis l'ancien système si nécessaire
        $this->maybe_migrate_data();
        
        // Garder le bouton manuel pour les cas où l'on veut juste forcer une mise à jour
        if (isset($_POST['run_discount_update']) && check_admin_referer('wcad_run_discount_update')) {
            $this->update_all_products_discounts();
            add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Remises automatiques appliquées avec succès.', 'auto-discounts-for-woocommerce') . '</p></div>';
            });
        }
    }
    
    /**
     * Migre les données depuis l'ancien système si nécessaire
     */
    private function maybe_migrate_data() {
        $old_rules_wc = get_option('wc_discount_rules', false);
        $old_rules_bdo = get_option('bdo_discount_rules', false);
        $old_categories_wc = get_option('wc_excluded_categories', false);
        $old_categories_bdo = get_option('bdo_excluded_categories', false);
        
        if (($old_rules_wc !== false || $old_rules_bdo !== false) && empty(get_option('wcad_discount_rules', []))) {
            $rules = $old_rules_wc !== false ? $old_rules_wc : $old_rules_bdo;
            update_option('wcad_discount_rules', $rules);
        }
        
        if (($old_categories_wc !== false || $old_categories_bdo !== false) && empty(get_option('wcad_excluded_categories', []))) {
            $categories = $old_categories_wc !== false ? $old_categories_wc : $old_categories_bdo;
            update_option('wcad_excluded_categories', $categories);
        }
    }

    /**
     * Sanitize et applique les règles de remise
     *
     * @param array $rules Les règles à sanitizer
     * @return array Les règles sanitizées
     */
    public function sanitize_and_apply_rules($rules) {
        $sanitized_rules = [];
        
        if (is_array($rules)) {
            foreach ($rules as $index => $rule) {
                $sanitized_rules[$index] = [
                    'priority' => isset($rule['priority']) ? absint($rule['priority']) : 0,
                    'min_age' => isset($rule['min_age']) ? absint($rule['min_age']) : 0,
                    'discount' => isset($rule['discount']) ? floatval($rule['discount']) : 0,
                    'active' => isset($rule['active']) ? (bool) $rule['active'] : false,
                    'respect_manual' => isset($rule['respect_manual']) ? (bool) $rule['respect_manual'] : false
                ];
            }
        }
        
        // Planifier la mise à jour des prix après l'enregistrement des options
        add_action('shutdown', [$this, 'update_all_products_discounts']);
        
        // Ajouter un message de succès
        add_action('admin_notices', function() {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('Règles enregistrées. Les remises sont en cours d\'application...', 'auto-discounts-for-woocommerce') . '</p></div>';
        });
        
        return $sanitized_rules;
    }
    
    /**
     * Sanitize et applique les catégories exclues
     *
     * @param array $categories Les catégories à sanitizer
     * @return array Les catégories sanitizées
     */
    public function sanitize_and_apply_categories($categories) {
        $sanitized_categories = [];
        
        if (is_array($categories)) {
            foreach ($categories as $category_id) {
                $sanitized_categories[] = absint($category_id);
            }
        }
        
        // Planifier la mise à jour des prix après l'enregistrement des options
        add_action('shutdown', [$this, 'update_all_products_discounts']);
        
        return $sanitized_categories;
    }

    /**
     * Charge les scripts JavaScript et CSS pour l'administration
     *
     * @param string $hook Le hook de la page courante
     */
    public function enqueue_admin_scripts($hook) {
        // Charger nos styles sur toutes les pages produit
        $screen = get_current_screen();
        if ($hook === 'woocommerce_page_wcad-auto-discounts' || 
            ($screen && $screen->post_type === 'product')) {
            
            wp_enqueue_style('wcad-admin-style', plugins_url('/assets/css/admin-style.css', dirname(__FILE__)), [], filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/css/admin-style.css'));
            
            // Le script JavaScript doit être chargé uniquement sur la page de paramètres
            if ($hook === 'woocommerce_page_wcad-auto-discounts') {
                wp_enqueue_script('wcad-auto-discounts', plugins_url('/assets/js/auto-discounts.js', dirname(__FILE__)), ['jquery'], filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/js/auto-discounts.js'), true);
                
                // Ajouter les données pour le JavaScript
                wp_localize_script('wcad-auto-discounts', 'wcadData', [
                    'preview_nonce' => wp_create_nonce('wcad_preview_rule'),
                    'currency_symbol' => get_woocommerce_currency_symbol(),
                    'i18n' => [
                        'loading' => __('Chargement...', 'auto-discounts-for-woocommerce'),
                        'affected_products' => __('Produits affectés', 'auto-discounts-for-woocommerce'),
                        'total_value' => __('Valeur totale', 'auto-discounts-for-woocommerce'),
                        'sample_products' => __('Échantillon de produits', 'auto-discounts-for-woocommerce'),
                        /* translators: %d: number of additional products */
                        'and_more' => __('et %d autres...', 'auto-discounts-for-woocommerce'),
                        'error' => __('Une erreur est survenue lors de la prévisualisation.', 'auto-discounts-for-woocommerce'),
                        'remove_rule' => __('Supprimer', 'auto-discounts-for-woocommerce')
                    ]
                ]);
            }
        }
    }

    /**
     * Configure le cron pour les mises à jour quotidiennes
     */
    public function setup_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(strtotime('today midnight'), 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Calcule l'âge d'un produit en jours
     *
     * @param int $product_id ID du produit
     * @return int Âge du produit en jours
     */
    private function get_product_age($product_id) {
        $creation_date = get_post_meta($product_id, '_product_creation_date', true);
        if (!$creation_date) {
            $creation_date = get_the_date('Y-m-d', $product_id);
            update_post_meta($product_id, '_product_creation_date', $creation_date);
        }
        $creation = new DateTime($creation_date);
        $now = new DateTime();
        return $creation->diff($now)->days;
    }

    /**
     * Met à jour les remises pour tous les produits
     */
    public function update_all_products_discounts() {
        $rules = get_option('wcad_discount_rules', []);
        $excluded_categories = get_option('wcad_excluded_categories', []);
        
        if (empty($rules)) {
            return;
        }
        
        // Trier les règles par priorité (du plus petit au plus grand)
        usort($rules, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });
        
        // Traiter les produits par lots de 50
        $batch_size = 50;
        $offset = 0;
        
        do {
            // Ne récupérer que les produits en stock
            $products = wc_get_products([
                'limit' => $batch_size,
                'offset' => $offset,
                'status' => 'publish',
                'stock_status' => 'instock', // Uniquement les produits en stock
            ]);
            
            if (empty($products)) {
                break;
            }
            
            foreach ($products as $product) {
                $product_id = $product->get_id();
                
                // Vérifier si le produit est dans une catégorie exclue globalement
                $product_cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
                if (!empty($excluded_categories) && is_array($excluded_categories) && array_intersect($product_cats, $excluded_categories)) {
                    // Supprimer les remises automatiques si le produit est maintenant exclu
                    $this->maybe_remove_auto_discount($product_id, $product);
                    continue;
                }
                
                // Vérifier si le produit est marqué comme exclu des remises
                if ($this->is_product_excluded($product_id, $product)) {
                    // Supprimer les remises automatiques si le produit est marqué comme exclu
                    $this->maybe_remove_auto_discount($product_id, $product);
                    continue;
                }
                
                // Récupérer le prix régulier
                $regular_price = $product->get_regular_price();
                if (empty($regular_price)) {
                    continue;
                }
                
                // Vérifier si le produit a déjà une remise manuelle
                $has_manual_discount = $this->has_manual_discount($product_id, $product);
                
                // Calculer l'âge du produit
                $product_age = $this->get_product_age($product_id);
                
                $applied_discount = false;
                
                // Appliquer la première règle correspondante
                foreach ($rules as $rule) {
                    if (!isset($rule['active']) || !$rule['active']) {
                        continue;
                    }
                    
                    // Si la règle respecte les remises manuelles et que le produit a déjà une remise manuelle, passer
                    if (isset($rule['respect_manual']) && $rule['respect_manual'] && $has_manual_discount) {
                        continue;
                    }
                    
                    if ($product_age >= $rule['min_age']) {
                        $discount_amount = $regular_price * ($rule['discount'] / 100);
                        $sale_price = round($regular_price - $discount_amount, 2);
                        
                        // Mettre à jour le prix de vente
                        update_post_meta($product_id, '_sale_price', $sale_price);
                        update_post_meta($product_id, '_price', $sale_price);
                        
                        // Stocker la règle appliquée pour référence
                        update_post_meta($product_id, '_wcad_applied_discount_rule', [
                            'rule_id' => $rule['priority'],
                            'discount_percent' => $rule['discount'],
                            'applied_date' => current_time('mysql')
                        ]);
                        
                        // Supprimer les anciennes métadonnées
                        delete_post_meta($product_id, '_wc_applied_discount_rule');
                        delete_post_meta($product_id, '_bdo_applied_discount_rule');
                        
                        $applied_discount = true;
                        break;
                    }
                }
                
                // Si aucune règle ne s'applique, supprimer le prix de vente automatique
                if (!$applied_discount) {
                    $this->maybe_remove_auto_discount($product_id, $product);
                }
                
                // Nettoyer le cache WooCommerce
                wc_delete_product_transients($product_id);
            }
            
            // Libérer la mémoire
            wp_cache_flush();
            
            // Passer au lot suivant
            $offset += $batch_size;
            
        } while (count($products) === $batch_size);
        
        // Supprimer les remises des produits qui ne sont plus en stock
        $this->remove_discounts_from_out_of_stock();
    }
    
    /**
     * Vérifie si un produit est marqué comme exclu des remises automatiques
     *
     * @param int $product_id ID du produit
     * @param WC_Product $product Objet produit
     * @return bool True si le produit est exclu
     */
    private function is_product_excluded($product_id, $product) {
        $exclude = get_post_meta($product_id, '_wcad_exclude_from_discounts', true);
        return $exclude === 'yes';
    }

    /**
     * Vérifie si un produit a une remise manuelle (non appliquée par notre plugin)
     *
     * @param int $product_id ID du produit
     * @param WC_Product $product Objet produit
     * @return bool True si le produit a une remise manuelle
     */
    private function has_manual_discount($product_id, $product) {
        // Vérifier si le produit a un prix de vente
        $sale_price = $product->get_sale_price();
        if (empty($sale_price)) {
            return false;
        }
        
        // Vérifier si ce prix de vente a été défini par notre plugin
        $auto_discount = get_post_meta($product_id, '_wcad_applied_discount_rule', true);
        $old_auto_discount_wc = get_post_meta($product_id, '_wc_applied_discount_rule', true);
        $old_auto_discount_bdo = get_post_meta($product_id, '_bdo_applied_discount_rule', true);
        
        // Si aucune de nos métadonnées n'est présente, c'est une remise manuelle
        return empty($auto_discount) && empty($old_auto_discount_wc) && empty($old_auto_discount_bdo);
    }

    /**
     * Supprime une remise automatique si nécessaire
     *
     * @param int $product_id ID du produit
     * @param WC_Product $product Objet produit
     */
    private function maybe_remove_auto_discount($product_id, $product) {
        // Vérifier si c'était une remise automatique (tous systèmes confondus)
        $applied_rule = get_post_meta($product_id, '_wcad_applied_discount_rule', true);
        $old_applied_rule_wc = get_post_meta($product_id, '_wc_applied_discount_rule', true);
        $old_applied_rule_bdo = get_post_meta($product_id, '_bdo_applied_discount_rule', true);
        
        if (!empty($applied_rule) || !empty($old_applied_rule_wc) || !empty($old_applied_rule_bdo)) {
            // C'était une remise automatique, on la supprime
            delete_post_meta($product_id, '_sale_price');
            update_post_meta($product_id, '_price', $product->get_regular_price());
            delete_post_meta($product_id, '_wcad_applied_discount_rule');
            delete_post_meta($product_id, '_wc_applied_discount_rule');
            delete_post_meta($product_id, '_bdo_applied_discount_rule');
        }
    }

    /**
     * Supprime les remises des produits qui ne sont plus en stock
     */
    private function remove_discounts_from_out_of_stock() {
        global $wpdb;
        
        // Récupérer tous les produits hors stock qui ont une remise automatique (tous systèmes confondus)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Requête agrégée admin-only, aucune entrée utilisateur, pas d'équivalent API performant
        $out_of_stock_products = $wpdb->get_col("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_stock_status' AND pm1.meta_value = 'outofstock'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wcad_applied_discount_rule'
            LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_wc_applied_discount_rule'
            LEFT JOIN {$wpdb->postmeta} pm4 ON p.ID = pm4.post_id AND pm4.meta_key = '_bdo_applied_discount_rule'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND (pm2.meta_value IS NOT NULL OR pm3.meta_value IS NOT NULL OR pm4.meta_value IS NOT NULL)
        ");
        
        if (empty($out_of_stock_products)) {
            return;
        }
        
        foreach ($out_of_stock_products as $product_id) {
            // Supprimer la remise
            delete_post_meta($product_id, '_sale_price');
            
            // Récupérer le prix régulier
            $regular_price = get_post_meta($product_id, '_regular_price', true);
            if (!empty($regular_price)) {
                update_post_meta($product_id, '_price', $regular_price);
            }
            
            // Supprimer les références aux règles appliquées (tous systèmes confondus)
            delete_post_meta($product_id, '_wcad_applied_discount_rule');
            delete_post_meta($product_id, '_wc_applied_discount_rule');
            delete_post_meta($product_id, '_bdo_applied_discount_rule');
            
            // Nettoyer le cache
            wc_delete_product_transients($product_id);
        }
    }

    /**
     * Récupère les statistiques des remises
     * 
     * @return array Statistiques des remises
     */
    private function get_discount_stats()
    {
        global $wpdb;

        $stats = [
            'total_products' => 0,
            'discounted_products' => 0,
            'excluded_products' => 0,
            'total_discount_amount' => 0,
            'average_discount' => 0,
            'rules_usage' => []
        ];

        // Nombre total de produits en stock avec cache objet
        $cache_key_total = 'wcad_stats_total_products';
        $cached_total = wp_cache_get($cache_key_total, 'wcad');
        if ($cached_total !== false) {
            $stats['total_products'] = (int) $cached_total;
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Requête agrégée admin-only; entrée non utilisateur; cache objet ajouté
            $stats['total_products'] = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_stock_status' AND pm.meta_value = 'instock'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
        ");
            wp_cache_set($cache_key_total, $stats['total_products'], 'wcad', 300);
        }

        // Produits exclus manuellement ET en stock avec cache objet
        $cache_key_excluded = 'wcad_stats_excluded_products';
        $cached_excluded = wp_cache_get($cache_key_excluded, 'wcad');
        if ($cached_excluded !== false) {
            $stats['excluded_products'] = (int) $cached_excluded;
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Requête agrégée admin-only; entrée non utilisateur; cache objet ajouté
            $stats['excluded_products'] = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm_exclude ON p.ID = pm_exclude.post_id AND pm_exclude.meta_key = '_wcad_exclude_from_discounts' AND pm_exclude.meta_value = 'yes'
            JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status' AND pm_stock.meta_value = 'instock'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
        ");
            wp_cache_set($cache_key_excluded, $stats['excluded_products'], 'wcad', 300);
        }

        // Produits avec remise automatique ET en stock avec cache objet
        $cache_key_discounted = 'wcad_stats_discounted_products';
        $discounted_products = wp_cache_get($cache_key_discounted, 'wcad');
        if ($discounted_products === false) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Requête agrégée admin-only; entrée non utilisateur; cache objet ajouté
            $discounted_products = $wpdb->get_results("
            SELECT p.ID, pm_rule.meta_value as rule_data, pm_reg.meta_value as regular_price, pm_sale.meta_value as sale_price
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm_rule ON p.ID = pm_rule.post_id AND pm_rule.meta_key = '_wcad_applied_discount_rule'
            JOIN {$wpdb->postmeta} pm_reg ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price'
            JOIN {$wpdb->postmeta} pm_sale ON p.ID = pm_sale.post_id AND pm_sale.meta_key = '_sale_price'
            JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status' AND pm_stock.meta_value = 'instock'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
        ");
            wp_cache_set($cache_key_discounted, $discounted_products, 'wcad', 300);
        }

        $stats['discounted_products'] = count($discounted_products);

        // Le reste de la fonction pour calculer les totaux et moyennes est inchangé
        if (!empty($discounted_products)) {
            $total_discount = 0;
            $rules_usage = [];

            foreach ($discounted_products as $product) {
                $rule_data = maybe_unserialize($product->rule_data);
                $regular_price = floatval($product->regular_price);
                $sale_price = floatval($product->sale_price);
                $discount_amount = $regular_price - $sale_price;

                $total_discount += $discount_amount;

                // Comptabiliser l'utilisation des règles
                if (isset($rule_data['rule_id'])) {
                    $rule_id = $rule_data['rule_id'];
                    if (!isset($rules_usage[$rule_id])) {
                        $rules_usage[$rule_id] = [
                            'count' => 0,
                            'discount_percent' => isset($rule_data['discount_percent']) ? $rule_data['discount_percent'] : 0
                        ];
                    }
                    $rules_usage[$rule_id]['count']++;
                }
            }

            $stats['total_discount_amount'] = $total_discount;
            $stats['average_discount'] = $stats['discounted_products'] > 0 ? $total_discount / $stats['discounted_products'] : 0;
            $stats['rules_usage'] = $rules_usage;
        }

        return $stats;
    }

    /**
     * Affiche la page d'administration
     */
    public function render_admin_page() {
        $rules = get_option('wcad_discount_rules', []);
        $excluded_categories = get_option('wcad_excluded_categories', []);
        $stats = $this->get_discount_stats();
        ?>
        <div class="wrap wcad-admin-container">
            <div class="wcad-admin-header">
                <h1><?php echo esc_html__('Règles de remise automatiques', 'auto-discounts-for-woocommerce'); ?></h1>
            </div>
            
            <div class="wcad-section wcad-info-section">
                <h2><?php echo esc_html__('Fonctionnement du plugin', 'auto-discounts-for-woocommerce'); ?> <span class="dashicons dashicons-info"></span></h2>
                
                <div class="wcad-info-content">
                    <p><?php echo esc_html__('Ce plugin applique automatiquement des remises sur les produits selon leur ancienneté en stock et les règles que vous définissez.', 'auto-discounts-for-woocommerce'); ?></p>
                    
                    <h4><?php echo esc_html__('Fonctionnement automatique', 'auto-discounts-for-woocommerce'); ?></h4>
                    <ul class="wcad-info-list">
                        <li><span class="dashicons dashicons-clock"></span> <?php echo esc_html__('Les remises sont appliquées quotidiennement à minuit via une tâche planifiée (cron WordPress).', 'auto-discounts-for-woocommerce'); ?></li>
                        <li><span class="dashicons dashicons-update"></span> <?php echo esc_html__('Les prix sont automatiquement mis à jour en fonction de l\'ancienneté des produits et des règles configurées.', 'auto-discounts-for-woocommerce'); ?></li>
                        <li><span class="dashicons dashicons-admin-settings"></span> <?php echo esc_html__('Vous pouvez également appliquer manuellement les remises à tout moment en utilisant le bouton "Appliquer maintenant".', 'auto-discounts-for-woocommerce'); ?></li>
                        <li><span class="dashicons dashicons-filter"></span> <?php echo esc_html__('Les produits peuvent être exclus individuellement ou par catégorie entière.', 'auto-discounts-for-woocommerce'); ?></li>
                    </ul>
                    
                    <p class="wcad-tip"><span class="dashicons dashicons-lightbulb"></span> <?php echo esc_html__('Conseil : Utilisez la section "Prévisualisation" en bas de page pour tester l\'impact d\'une règle avant de l\'appliquer.', 'auto-discounts-for-woocommerce'); ?></p>
                </div>
            </div>
            
            <div class="wcad-dashboard wcad-section">
                <h2><?php echo esc_html__('Tableau de bord des remises', 'auto-discounts-for-woocommerce'); ?> <span class="dashicons dashicons-chart-bar"></span></h2>
                
                <div class="wcad-stats-grid">
                    <div class="wcad-stat-box">
                        <h3><?php echo esc_html__('Produits avec remise', 'auto-discounts-for-woocommerce'); ?></h3>
                        <div class="wcad-stat-value"><?php echo esc_html($stats['discounted_products']); ?> / <?php echo esc_html($stats['total_products']); ?></div>
                        <div class="wcad-stat-description">
                            <?php 
                            $percentage = $stats['total_products'] > 0 ? round(($stats['discounted_products'] / $stats['total_products']) * 100, 1) : 0;
                            /* translators: %s: percentage of discounted products */
                            echo esc_html(sprintf(__('%s%% des produits en stock', 'auto-discounts-for-woocommerce'), $percentage)); 
                            ?>
                        </div>
                    </div>
                    
                    <div class="wcad-stat-box">
                        <h3><?php echo esc_html__('Produits exclus', 'auto-discounts-for-woocommerce'); ?></h3>
                        <div class="wcad-stat-value"><?php echo esc_html($stats['excluded_products']); ?></div>
                        <div class="wcad-stat-description">
                            <?php 
                            $excluded_percentage = $stats['total_products'] > 0 ? round(($stats['excluded_products'] / $stats['total_products']) * 100, 1) : 0;
                            /* translators: %s: percentage of excluded products */
                            echo esc_html(sprintf(__('%s%% des produits en stock', 'auto-discounts-for-woocommerce'), $excluded_percentage)); 
                            ?>
                        </div>
                    </div>
                    
                    <div class="wcad-stat-box">
                        <h3><?php echo esc_html__('Remise moyenne', 'auto-discounts-for-woocommerce'); ?></h3>
                        <div class="wcad-stat-value"><?php echo wp_kses_post(wc_price($stats['average_discount'])); ?></div>
                        <div class="wcad-stat-description">
                            <?php echo esc_html__('Par produit', 'auto-discounts-for-woocommerce'); ?>
                        </div>
                    </div>
                    
                    <div class="wcad-stat-box">
                        <h3><?php echo esc_html__('Remise totale', 'auto-discounts-for-woocommerce'); ?></h3>
                        <div class="wcad-stat-value"><?php echo wp_kses_post(wc_price($stats['total_discount_amount'])); ?></div>
                        <div class="wcad-stat-description">
                            <?php echo esc_html__('Sur tous les produits', 'auto-discounts-for-woocommerce'); ?>
                        </div>
                    </div>
                    
                    <div class="wcad-stat-box">
                        <h3><?php echo esc_html__('Règles actives', 'auto-discounts-for-woocommerce'); ?></h3>
                        <div class="wcad-stat-value">
                            <?php 
                            $active_rules = 0;
                            foreach ($rules as $rule) {
                                if (isset($rule['active']) && $rule['active']) {
                                    $active_rules++;
                                }
                            }
                            echo esc_html($active_rules); 
                            ?>
                        </div>
                        <div class="wcad-stat-description">
                            <?php 
                            /* translators: %d: number of configured rules */
                            echo esc_html(sprintf(__('Sur %d règles configurées', 'auto-discounts-for-woocommerce'), count($rules))); 
                            ?>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($stats['rules_usage'])): ?>
                <div class="wcad-rules-usage">
                    <h3><?php echo esc_html__('Utilisation des règles', 'auto-discounts-for-woocommerce'); ?></h3>
                    <table class="widefat wcad-usage-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Priorité', 'auto-discounts-for-woocommerce'); ?></th>
                                <th><?php echo esc_html__('Remise', 'auto-discounts-for-woocommerce'); ?></th>
                                <th><?php echo esc_html__('Produits affectés', 'auto-discounts-for-woocommerce'); ?></th>
                                <th><?php echo esc_html__('Pourcentage', 'auto-discounts-for-woocommerce'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['rules_usage'] as $rule_id => $usage): ?>
                            <tr>
                                <td><?php echo esc_html($rule_id); ?></td>
                                <td><?php echo esc_html($usage['discount_percent']); ?>%</td>
                                <td><?php echo esc_html($usage['count']); ?></td>
                                <td>
                                    <?php 
                            $usage_percent = $stats['discounted_products'] > 0 ? round(($usage['count'] / $stats['discounted_products']) * 100, 1) : 0;
                            echo esc_html($usage_percent . '%'); 
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('wcad_auto_discounts'); ?>
                
                <div class="wcad-section">
                    <h2>
                        <?php echo esc_html__('Configuration des règles', 'auto-discounts-for-woocommerce'); ?>
                        <span class="wcad-tooltip" data-tooltip="<?php echo esc_attr__('Configurez ici vos règles de remise automatique. Les règles sont appliquées par ordre de priorité (du plus petit au plus grand).', 'auto-discounts-for-woocommerce'); ?>">
                            <span class="dashicons dashicons-editor-help"></span>
                        </span>
                    </h2>
                    <table class="wp-list-table widefat fixed striped wcad-rules-table">
                        <thead>
                            <tr>
                                <th>
                                    <?php echo esc_html__('Priorité', 'auto-discounts-for-woocommerce'); ?>
                                    <span class="wcad-tooltip" data-tooltip="<?php echo esc_attr__('Ordre d\'application des règles. La règle avec la priorité la plus basse sera appliquée en premier.', 'auto-discounts-for-woocommerce'); ?>">
                                        <span class="dashicons dashicons-editor-help"></span>
                                    </span>
                                </th>
                                <th>
                                    <?php echo esc_html__('À partir de (jours)', 'auto-discounts-for-woocommerce'); ?>
                                    <span class="wcad-tooltip" data-tooltip="<?php echo esc_attr__('Âge minimum du produit en jours pour que la règle s\'applique.', 'auto-discounts-for-woocommerce'); ?>">
                                        <span class="dashicons dashicons-editor-help"></span>
                                    </span>
                                </th>
                                <th>
                                    <?php echo esc_html__('Remise (%)', 'auto-discounts-for-woocommerce'); ?>
                                    <span class="wcad-tooltip" data-tooltip="<?php echo esc_attr__('Pourcentage de remise à appliquer sur le prix régulier.', 'auto-discounts-for-woocommerce'); ?>">
                                        <span class="dashicons dashicons-editor-help"></span>
                                    </span>
                                </th>
                                <th>
                                    <?php echo esc_html__('Actif', 'auto-discounts-for-woocommerce'); ?>
                                    <span class="wcad-tooltip" data-tooltip="<?php echo esc_attr__('Activer ou désactiver cette règle.', 'auto-discounts-for-woocommerce'); ?>">
                                        <span class="dashicons dashicons-editor-help"></span>
                                    </span>
                                </th>
                                <th>
                                    <?php echo esc_html__('Respecter remises manuelles', 'auto-discounts-for-woocommerce'); ?>
                                    <span class="wcad-tooltip" data-tooltip="<?php echo esc_attr__('Si activé, cette règle ne s\'appliquera pas aux produits qui ont déjà une remise manuelle.', 'auto-discounts-for-woocommerce'); ?>">
                                        <span class="dashicons dashicons-editor-help"></span>
                                    </span>
                                </th>
                                <th><?php echo esc_html__('Actions', 'auto-discounts-for-woocommerce'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="rules-container">
                            <?php foreach ($rules as $index => $rule) : ?>
                            <tr>
                                <td><input type="number" name="wcad_discount_rules[<?php echo esc_attr($index); ?>][priority]" value="<?php echo esc_attr($rule['priority']); ?>"></td>
                                <td><input type="number" name="wcad_discount_rules[<?php echo esc_attr($index); ?>][min_age]" value="<?php echo esc_attr($rule['min_age']); ?>"></td>
                                <td><input type="number" name="wcad_discount_rules[<?php echo esc_attr($index); ?>][discount]" value="<?php echo esc_attr($rule['discount']); ?>" step="0.01"></td>
                                <td><input type="checkbox" name="wcad_discount_rules[<?php echo esc_attr($index); ?>][active]" value="1" <?php checked(isset($rule['active']) && $rule['active']); ?>></td>
                                <td><input type="checkbox" name="wcad_discount_rules[<?php echo esc_attr($index); ?>][respect_manual]" value="1" <?php checked(isset($rule['respect_manual']) && $rule['respect_manual']); ?>></td>
                                <td><button type="button" class="button remove-rule"><?php echo esc_html__('Supprimer', 'auto-discounts-for-woocommerce'); ?></button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><button type="button" id="add-rule" class="button wcad-add-rule"><?php echo esc_html__('Ajouter une règle', 'auto-discounts-for-woocommerce'); ?></button></p>
                </div>
                
                <div class="wcad-section">
                    <h2><?php echo esc_html__('Catégories exclues globalement', 'auto-discounts-for-woocommerce'); ?></h2>
                    <div class="wcad-categories-container">
                        <?php
                        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
                        foreach ($categories as $category) {
                            $is_checked = in_array($category->term_id, $excluded_categories);
                            ?>
                            <div class="wcad-category-item">
                                <label>
                                    <input type="checkbox" name="wcad_excluded_categories[]" value="<?php echo esc_attr($category->term_id); ?>" <?php checked($is_checked); ?>>
                                    <?php echo esc_html($category->name); ?>
                                </label>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                    
                    <?php submit_button(__('Enregistrer les règles', 'auto-discounts-for-woocommerce')); ?>
                </div>
            </form>
            
            <div class="wcad-manual-update">
                <h2><?php echo esc_html__('Appliquer les remises maintenant', 'auto-discounts-for-woocommerce'); ?></h2>
                <p><?php echo esc_html__('Cliquez sur ce bouton pour appliquer immédiatement les règles de remise à tous les produits.', 'auto-discounts-for-woocommerce'); ?></p>
                <form method="post">
                    <?php wp_nonce_field('wcad_run_discount_update'); ?>
                    <input type="hidden" name="run_discount_update" value="1">
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Appliquer maintenant', 'auto-discounts-for-woocommerce'); ?></button>
                </form>
            </div>
            
            <div class="wcad-section">
                <h2>
                    <?php echo esc_html__('Prévisualisation', 'auto-discounts-for-woocommerce'); ?>
                    <span class="wcad-tooltip" data-tooltip="<?php echo esc_attr__('Simulez l\'application d\'une règle pour voir combien de produits seraient affectés.', 'auto-discounts-for-woocommerce'); ?>">
                        <span class="dashicons dashicons-editor-help"></span>
                    </span>
                </h2>
                
                <p><?php echo esc_html__('Cet outil vous permet de simuler l\'application d\'une règle sans l\'enregistrer. Vous pourrez voir combien de produits seraient affectés et quels produits recevraient une remise.', 'auto-discounts-for-woocommerce'); ?></p>
                
                <div class="wcad-preview-form">
                    <div class="wcad-preview-field">
                        <label for="preview-min-age"><?php echo esc_html__('À partir de (jours)', 'auto-discounts-for-woocommerce'); ?></label>
                        <input type="number" id="preview-min-age" value="30" min="0">
                    </div>
                    
                    <div class="wcad-preview-field">
                        <label for="preview-respect-manual"><?php echo esc_html__('Respecter remises manuelles', 'auto-discounts-for-woocommerce'); ?></label>
                        <input type="checkbox" id="preview-respect-manual" value="1">
                    </div>
                    
                    <div class="wcad-preview-actions">
                        <button type="button" id="preview-rule" class="button button-secondary"><?php echo esc_html__('Prévisualiser', 'auto-discounts-for-woocommerce'); ?></button>
                    </div>
                </div>
                
                <div class="wcad-preview-container" style="display: none;">
                    <div class="wcad-preview-header">
                        <h3><?php echo esc_html__('Résultats de la prévisualisation', 'auto-discounts-for-woocommerce'); ?></h3>
                        <a href="#" class="wcad-preview-close">×</a>
                    </div>
                    <div class="wcad-preview-results"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Ajoute le champ d'exclusion des remises dans l'onglet Général des produits
     */
    public function add_exclude_from_discounts_field() {
        global $post;
        
        echo '<div class="options_group show_if_simple show_if_external show_if_variable wcad-exclude-field-container">';
        
        // Ajout d'un titre pour cette section
        echo '<h4 style="margin: 10px 12px; color: #23282d; font-size: 14px;">' . esc_html__('Remises automatiques', 'auto-discounts-for-woocommerce') . '</h4>';
        // Nonce pour sécuriser l'enregistrement
        wp_nonce_field('wcad_save_product', 'wcad_product_nonce');
        
        woocommerce_wp_checkbox([
            'id'          => '_wcad_exclude_from_discounts',
            'label'       => __('Exclure des remises auto', 'auto-discounts-for-woocommerce'),
            'description' => __('Cochez cette case pour exclure ce produit des remises automatiques.', 'auto-discounts-for-woocommerce'),
            'desc_tip'    => true,
            'wrapper_class' => 'wcad-field-wrapper',
        ]);
        
        // Ajout d'un lien vers la page des remises auto
        echo '<p style="margin: 0 12px;"><a href="' . esc_url(admin_url('admin.php?page=wcad-auto-discounts')) . '">' .
             esc_html__('Gérer les remises automatiques', 'auto-discounts-for-woocommerce') . '</a></p>';
        
        echo '</div>';
        
        // S'assurer que le CSS est chargé
        wp_enqueue_style('wcad-admin-style', plugins_url('/assets/css/admin-style.css', dirname(__FILE__)), [], filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/css/admin-style.css'));
    }

    /**
     * Enregistre la valeur du champ d'exclusion
     *
     * @param int $post_id ID du produit
     */
    public function save_exclude_from_discounts_field($post_id) {
        // Vérification du nonce et des capacités
        if (! isset($_POST['wcad_product_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wcad_product_nonce'])), 'wcad_save_product')) {
            return;
        }
        if (! current_user_can('edit_post', $post_id)) {
            return;
        }
        $exclude = isset($_POST['_wcad_exclude_from_discounts']) ? 'yes' : 'no';
        update_post_meta($post_id, '_wcad_exclude_from_discounts', $exclude);
        
        // Si on exclut un produit qui avait une remise auto, supprimer la remise
        if ($exclude === 'yes') {
            $product = wc_get_product($post_id);
            if ($product) {
                $this->maybe_remove_auto_discount($post_id, $product);
            }
        }
    }

    /**
     * Ajoute le champ d'exclusion aux variations
     *
     * @param int $loop Index de la variation
     * @param array $variation_data Données de la variation
     * @param WP_Post $variation Objet de la variation
     */
    public function add_exclude_from_discounts_field_to_variation($loop, $variation_data, $variation) {
        $variation_id = $variation->ID;
        $exclude = get_post_meta($variation_id, '_wcad_exclude_from_discounts', true);
        
        echo '<div class="wcad-variation-field" style="background: #f7f7f7; padding: 8px; margin: 8px 0; border-left: 3px solid #f44336;">';
        echo '<strong style="display: block; margin-bottom: 5px;">' . esc_html__('Remises automatiques', 'auto-discounts-for-woocommerce') . '</strong>';
        
        woocommerce_wp_checkbox([
            'id'            => '_wcad_exclude_from_discounts_' . $loop,
            'name'          => '_wcad_exclude_from_discounts[' . $loop . ']',
            'label'         => __('Exclure des remises auto', 'auto-discounts-for-woocommerce'),
            'description'   => __('Exclure cette variation des remises automatiques', 'auto-discounts-for-woocommerce'),
            'value'         => $exclude === 'yes' ? 'yes' : 'no',
            'wrapper_class' => 'form-row form-row-full',
        ]);
        
        echo '</div>';
    }

    /**
     * Enregistre la valeur du champ d'exclusion pour les variations
     *
     * @param int $variation_id ID de la variation
     * @param int $i Index de la variation
     */
    public function save_exclude_from_discounts_field_variation($variation_id, $i) {
        // Vérifier le nonce WooCommerce standard présent sur la page produit
        if (! isset($_POST['woocommerce_meta_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woocommerce_meta_nonce'])), 'woocommerce_save_data')) {
            return;
        }
        if (! current_user_can('edit_post', $variation_id)) {
            return;
        }
        $exclude = isset($_POST['_wcad_exclude_from_discounts'][$i]) ? 'yes' : 'no';
        update_post_meta($variation_id, '_wcad_exclude_from_discounts', $exclude);
        
        // Si on exclut une variation qui avait une remise auto, supprimer la remise
        if ($exclude === 'yes') {
            $variation = wc_get_product($variation_id);
            if ($variation) {
                $this->maybe_remove_auto_discount($variation_id, $variation);
            }
        }
    }

    /**
     * Ajoute une colonne à la liste des produits
     *
     * @param array $columns Colonnes existantes
     * @return array Colonnes modifiées
     */
    public function add_product_column($columns) {
        $new_columns = [];
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Ajouter notre colonne après la colonne prix
            if ($key === 'price') {
                $new_columns['exclude_from_discounts'] = __('Exclu des remises auto', 'auto-discounts-for-woocommerce');
            }
        }
        
        return $new_columns;
    }

    /**
     * Affiche le contenu de la colonne personnalisée
     *
     * @param string $column Nom de la colonne
     * @param int $post_id ID du produit
     */
    public function show_product_column($column, $post_id) {
        if ($column === 'exclude_from_discounts') {
            $exclude = get_post_meta($post_id, '_wcad_exclude_from_discounts', true);
            
            if ($exclude === 'yes') {
                echo '<span class="dashicons dashicons-yes" title="' . esc_attr__('Exclu des remises automatiques', 'auto-discounts-for-woocommerce') . '"></span>';
            } else {
                echo '<span class="dashicons dashicons-no" title="' . esc_attr__('Inclus dans les remises automatiques', 'auto-discounts-for-woocommerce') . '"></span>';
            }
        }
    }

    /**
     * Enregistre les actions en masse
     *
     * @param array $actions Actions existantes
     * @return array Actions modifiées
     */
    public function register_bulk_actions($actions) {
        $actions['wcad_exclude_from_discounts'] = __('Exclure des remises auto', 'auto-discounts-for-woocommerce');
        $actions['wcad_include_in_discounts'] = __('Inclure dans les remises auto', 'auto-discounts-for-woocommerce');
        
        return $actions;
    }

    /**
     * Gère les actions en masse
     *
     * @param string $redirect_to URL de redirection
     * @param string $action Action effectuée
     * @param array $post_ids IDs des produits
     * @return string URL de redirection
     */
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if ($action !== 'wcad_exclude_from_discounts' && $action !== 'wcad_include_in_discounts') {
            return $redirect_to;
        }
        
        $excluded = 0;
        $included = 0;
        
        foreach ($post_ids as $post_id) {
            if ($action === 'wcad_exclude_from_discounts') {
                update_post_meta($post_id, '_wcad_exclude_from_discounts', 'yes');
                
                // Supprimer la remise auto si nécessaire
                $product = wc_get_product($post_id);
                if ($product) {
                    $this->maybe_remove_auto_discount($post_id, $product);
                }
                
                $excluded++;
            } else {
                update_post_meta($post_id, '_wcad_exclude_from_discounts', 'no');
                $included++;
            }
        }
        
        if ($action === 'wcad_exclude_from_discounts') {
            $redirect_to = add_query_arg('wcad_excluded', $excluded, $redirect_to);
        } else {
            $redirect_to = add_query_arg('wcad_included', $included, $redirect_to);
        }
        
        return $redirect_to;
    }

    /**
     * Ajoute un filtre rapide pour les produits exclus
     *
     * @param string $output Le contenu HTML existant
     * @return string Le contenu HTML modifié
     */
    public function add_products_filter($output) {
        global $wpdb;
        
        // Calculer le nombre de produits exclus (admin-only) avec cache objet
        $cache_key_excluded_count = 'wcad_filter_excluded_count';
        $cached_count = wp_cache_get($cache_key_excluded_count, 'wcad');
        if ($cached_count !== false) {
            $count = (int) $cached_count;
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compteur admin-only; entrée non utilisateur; cache objet ajouté
            $count = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wcad_exclude_from_discounts' AND pm.meta_value = 'yes'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
        ");
            wp_cache_set($cache_key_excluded_count, $count, 'wcad', 300);
        }
        
        // Récupérer le filtre actif avec vérification de nonce pour satisfaire le linter
        $excluded_filter = '';
        if (isset($_GET['wcad_filter_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['wcad_filter_nonce'])), 'wcad_filter_products')) {
            $excluded_filter = isset($_GET['wcad_excluded']) ? sanitize_text_field(wp_unslash($_GET['wcad_excluded'])) : '';
        }
        
        // Ajouter notre filtre
        $output .= '<select name="wcad_excluded" id="dropdown_wcad_excluded">
            <option value="">' . __('Filtrer par exclusion', 'auto-discounts-for-woocommerce') . '</option>
            <option value="yes" ' . selected($excluded_filter, 'yes', false) . '>' . __('Exclus des remises auto', 'auto-discounts-for-woocommerce') . ' (' . $count . ')</option>
        </select>';
        // Ajoute un nonce dans le formulaire de filtres
        $output .= wp_nonce_field('wcad_filter_products', 'wcad_filter_nonce', true, false);
        
        return $output;
    }

    /**
     * Filtre les produits par exclusion
     *
     * @param array $query_vars Les variables de requête
     * @return array Les variables de requête modifiées
     */
    public function filter_products_by_exclusion($query_vars) {
        if (isset($query_vars['meta_query'])) {
            $meta_query = $query_vars['meta_query'];
            
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Ajout d'un simple filtre admin-only; acceptable et non lié à une entrée utilisateur
            $meta_query[] = [
                'key' => '_wcad_exclude_from_discounts',
                'value' => 'yes',
                'compare' => '='
            ];
            
            $query_vars['meta_query'] = $meta_query;
        }
        
        return $query_vars;
    }

    /**
     * Affiche un message d'avertissement sur la page produit si le produit est exclu
     */
    public function show_exclusion_status_notice() {
        // Notice informatif en admin; on assainit sans exiger de nonce
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lecture de paramètres GET assainis et vérification de capacité; aucune écriture de données
        if (isset($_GET['post']) && isset($_GET['action']) && sanitize_text_field(wp_unslash($_GET['action'])) === 'edit') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lecture GET assainie; aucune écriture; contrôle de capacité juste après
            $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
            if (! $post_id || ! current_user_can('edit_post', $post_id)) {
                return;
            }
            $exclude = get_post_meta($post_id, '_wcad_exclude_from_discounts', true);
            
            if ($exclude === 'yes') {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Ce produit est exclu des remises automatiques.', 'auto-discounts-for-woocommerce') . '</p></div>';
            }
        }
    }
} 