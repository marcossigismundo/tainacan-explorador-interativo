<?php
/**
 * Plugin Name: Explorador Interativo para Tainacan
 * Plugin URI: https://github.com/seu-usuario/tainacan-explorador-interativo
 * Description: Plugin modular para criar visualizações interativas (mapas, linhas do tempo e storytelling) com base nas coleções do Tainacan
 * Version: 1.0.0
 * Author: Seu Nome
 * Author URI: https://seu-site.com
 * License: GPL v3 or later
 * Text Domain: tainacan-explorador
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * 
 * @package TainacanExplorador
 */

// Previne acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Define constantes do plugin
define('TEI_VERSION', '1.0.0');
define('TEI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TEI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TEI_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Carrega TODAS as classes imediatamente para evitar problemas de dependência
require_once TEI_PLUGIN_DIR . 'includes/class-metadata-mapper.php';
require_once TEI_PLUGIN_DIR . 'includes/class-cache-manager.php';
require_once TEI_PLUGIN_DIR . 'includes/class-api-handler.php';
require_once TEI_PLUGIN_DIR . 'includes/class-sanitizer.php';

// Carrega classes admin SEMPRE (não apenas em is_admin())
// porque AJAX roda em contexto separado
require_once TEI_PLUGIN_DIR . 'admin/admin-page.php';
require_once TEI_PLUGIN_DIR . 'admin/class-ajax-handler.php';

// Carrega shortcodes
require_once TEI_PLUGIN_DIR . 'shortcodes/mapa.php';
require_once TEI_PLUGIN_DIR . 'shortcodes/timeline.php';
require_once TEI_PLUGIN_DIR . 'shortcodes/story.php';

// Carrega API
require_once TEI_PLUGIN_DIR . 'api/class-api-endpoints.php';

/**
 * Classe principal do plugin
 */
class TainacanExploradorInterativo {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Internacionalização
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        
        // Admin
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        }
        
        // AJAX - IMPORTANTE: não verificar is_admin() aqui!
        $this->register_ajax_handlers();
        
        // Frontend
        add_action('wp_enqueue_scripts', [$this, 'conditionally_enqueue_assets']);
        
        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Shortcodes
        add_action('init', [$this, 'register_shortcodes']);
    }
    
    public function load_textdomain() {
        load_plugin_textdomain(
            'tainacan-explorador',
            false,
            dirname(TEI_PLUGIN_BASENAME) . '/languages/'
        );
    }
    
    public function add_admin_menu() {
        if (class_exists('TEI_Admin_Page')) {
            $admin = new TEI_Admin_Page();
            $admin->add_menu_page();
        }
    }
    
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'tainacan-explorador') === false) {
            return;
        }
        
        if (class_exists('TEI_Admin_Page')) {
            $admin = new TEI_Admin_Page();
            $admin->enqueue_admin_assets($hook);
        }
    }
    
    private function register_ajax_handlers() {
        if (!class_exists('TEI_Ajax_Handler')) {
            return;
        }
        
        $ajax = new TEI_Ajax_Handler();
        
        // Registra handlers para usuários logados
        add_action('wp_ajax_tei_get_collections', [$ajax, 'get_collections']);
        add_action('wp_ajax_tei_get_metadata', [$ajax, 'get_metadata']);
        add_action('wp_ajax_tei_save_mapping', [$ajax, 'save_mapping']);
        add_action('wp_ajax_tei_delete_mapping', [$ajax, 'delete_mapping']);
        add_action('wp_ajax_tei_get_all_mappings', [$ajax, 'get_all_mappings']);
    }
    
    public function register_rest_routes() {
        if (class_exists('TEI_API_Endpoints')) {
            $api = new TEI_API_Endpoints();
            $api->register_routes();
        }
    }
    
    public function register_shortcodes() {
        if (class_exists('TEI_Mapa_Shortcode')) {
            add_shortcode('tainacan_explorador_mapa', [new TEI_Mapa_Shortcode(), 'render']);
        }
        if (class_exists('TEI_Timeline_Shortcode')) {
            add_shortcode('tainacan_explorador_timeline', [new TEI_Timeline_Shortcode(), 'render']);
        }
        if (class_exists('TEI_Story_Shortcode')) {
            add_shortcode('tainacan_explorador_story', [new TEI_Story_Shortcode(), 'render']);
        }
    }
    
    public function conditionally_enqueue_assets() {
        global $post;
        
        if (!is_a($post, 'WP_Post')) {
            return;
        }
        
        $has_map = has_shortcode($post->post_content, 'tainacan_explorador_mapa');
        $has_timeline = has_shortcode($post->post_content, 'tainacan_explorador_timeline');
        $has_story = has_shortcode($post->post_content, 'tainacan_explorador_story');
        
        if ($has_map || $has_timeline || $has_story) {
            wp_enqueue_style('tei-common', TEI_PLUGIN_URL . 'assets/css/common.css', [], TEI_VERSION);
            wp_enqueue_script('tei-common', TEI_PLUGIN_URL . 'assets/js/common.js', ['wp-api-fetch'], TEI_VERSION, true);
            
            wp_localize_script('tei-common', 'teiConfig', [
                'apiUrl' => home_url('/wp-json/tainacan-explorador/v1/'),
                'nonce' => wp_create_nonce('wp_rest'),
                'ajaxUrl' => admin_url('admin-ajax.php')
            ]);
        }
        
        if ($has_map) {
            wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
            wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
            wp_enqueue_script('tei-map', TEI_PLUGIN_URL . 'assets/js/visualizations/map.js', ['leaflet', 'tei-common'], TEI_VERSION, true);
        }
        
        if ($has_timeline) {
            wp_enqueue_style('timeline-js', 'https://cdn.knightlab.com/libs/timeline3/latest/css/timeline.css', [], '3.8.0');
            wp_enqueue_script('timeline-js', 'https://cdn.knightlab.com/libs/timeline3/latest/js/timeline.js', [], '3.8.0', true);
            wp_enqueue_script('tei-timeline', TEI_PLUGIN_URL . 'assets/js/visualizations/timeline.js', ['timeline-js', 'tei-common'], TEI_VERSION, true);
        }
        
        if ($has_story) {
            wp_enqueue_script('scrollama', 'https://unpkg.com/scrollama@3.2.0/build/scrollama.min.js', [], '3.2.0', true);
            wp_enqueue_script('tei-story', TEI_PLUGIN_URL . 'assets/js/visualizations/story.js', ['scrollama', 'tei-common'], TEI_VERSION, true);
            wp_enqueue_style('tei-story', TEI_PLUGIN_URL . 'assets/css/story.css', [], TEI_VERSION);
        }
    }
    
    public static function activate() {
        TEI_Metadata_Mapper::create_tables();
        
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('manage_tainacan_explorer');
        }
        
        flush_rewrite_rules();
        update_option('tei_version', TEI_VERSION);
        
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/tainacan-explorer-cache';
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }
    }
    
    public static function deactivate() {
        TEI_Cache_Manager::clear_all();
        flush_rewrite_rules();
    }
    
    public static function uninstall() {
        TEI_Metadata_Mapper::drop_tables();
        delete_option('tei_version');
        delete_option('tei_settings');
        
        $role = get_role('administrator');
        if ($role) {
            $role->remove_cap('manage_tainacan_explorer');
        }
    }
}

// Hooks de ativação/desativação
register_activation_hook(__FILE__, ['TainacanExploradorInterativo', 'activate']);
register_deactivation_hook(__FILE__, ['TainacanExploradorInterativo', 'deactivate']);
register_uninstall_hook(__FILE__, ['TainacanExploradorInterativo', 'uninstall']);

// Inicializa o plugin
add_action('plugins_loaded', function() {
    TainacanExploradorInterativo::get_instance();
});

// Link de configurações
add_filter('plugin_action_links_' . TEI_PLUGIN_BASENAME, function($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=tainacan-explorador') . '">' 
        . __('Configurações', 'tainacan-explorador') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
