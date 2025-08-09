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

// Carrega arquivos essenciais imediatamente para hooks de ativação
require_once TEI_PLUGIN_DIR . 'includes/class-metadata-mapper.php';
require_once TEI_PLUGIN_DIR . 'includes/class-cache-manager.php';

/**
 * Classe principal do plugin
 */
class TainacanExploradorInterativo {
    
    /**
     * Instância única do plugin
     */
    private static $instance = null;
    
    /**
     * Obtém a instância única do plugin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Construtor
     */
    private function __construct() {
        $this->check_dependencies();
        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->register_shortcodes();
    }
    
    /**
     * Verifica dependências do plugin
     */
    private function check_dependencies() {
        add_action('admin_notices', [$this, 'check_tainacan_active']);
    }
    
    /**
     * Verifica se o Tainacan está ativo
     */
    public function check_tainacan_active() {
        if (!class_exists('Tainacan\Plugin')) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Explorador Interativo requer o plugin Tainacan ativo para funcionar.', 'tainacan-explorador');
            echo '</p></div>';
        }
    }
    
    /**
     * Carrega arquivos de dependências
     */
    private function load_dependencies() {
        // Classes principais (algumas já carregadas para ativação)
        if (!class_exists('TEI_API_Handler')) {
            require_once TEI_PLUGIN_DIR . 'includes/class-api-handler.php';
        }
        if (!class_exists('TEI_Sanitizer')) {
            require_once TEI_PLUGIN_DIR . 'includes/class-sanitizer.php';
        }
        
        // Admin
        if (is_admin()) {
            require_once TEI_PLUGIN_DIR . 'admin/class-admin-page.php';
            require_once TEI_PLUGIN_DIR . 'admin/class-ajax-handler.php';
        }
        
        // Shortcodes
        require_once TEI_PLUGIN_DIR . 'shortcodes/class-mapa-shortcode.php';
        require_once TEI_PLUGIN_DIR . 'shortcodes/class-timeline-shortcode.php';
        require_once TEI_PLUGIN_DIR . 'shortcodes/class-story-shortcode.php';
        
        // API customizada
        require_once TEI_PLUGIN_DIR . 'api/class-api-endpoints.php';
    }
    
    /**
     * Define o locale para internacionalização
     */
    private function set_locale() {
        add_action('plugins_loaded', function() {
            load_plugin_textdomain(
                'tainacan-explorador',
                false,
                dirname(TEI_PLUGIN_BASENAME) . '/languages/'
            );
        });
    }
    
    /**
     * Define hooks administrativos
     */
    private function define_admin_hooks() {
        if (is_admin()) {
            $admin = new TEI_Admin_Page();
            
            // Menu e páginas admin
            add_action('admin_menu', [$admin, 'add_menu_page']);
            add_action('admin_enqueue_scripts', [$admin, 'enqueue_admin_assets']);
            
            // AJAX handlers
            $ajax = new TEI_Ajax_Handler();
            add_action('wp_ajax_tei_get_collections', [$ajax, 'get_collections']);
            add_action('wp_ajax_tei_get_metadata', [$ajax, 'get_metadata']);
            add_action('wp_ajax_tei_save_mapping', [$ajax, 'save_mapping']);
            add_action('wp_ajax_tei_delete_mapping', [$ajax, 'delete_mapping']);
            add_action('wp_ajax_tei_test_visualization', [$ajax, 'test_visualization']);
            add_action('wp_ajax_tei_get_all_mappings', [$ajax, 'get_all_mappings']);
            add_action('wp_ajax_tei_clone_mapping', [$ajax, 'clone_mapping']);
            add_action('wp_ajax_tei_export_mappings', [$ajax, 'export_mappings']);
            add_action('wp_ajax_tei_import_mappings', [$ajax, 'import_mappings']);
            add_action('wp_ajax_tei_clear_cache', [$ajax, 'clear_cache']);
            add_action('wp_ajax_tei_get_stats', [$ajax, 'get_stats']);
            add_action('wp_ajax_tei_validate_tainacan_api', [$ajax, 'validate_tainacan_api']);
        }
    }
    
    /**
     * Define hooks públicos
     */
    private function define_public_hooks() {
        // Carrega assets apenas quando shortcodes são usados
        add_action('wp_enqueue_scripts', [$this, 'conditionally_enqueue_assets']);
        
        // REST API customizada
        add_action('rest_api_init', function() {
            $api = new TEI_API_Endpoints();
            $api->register_routes();
        });
    }
    
    /**
     * Registra shortcodes
     */
    private function register_shortcodes() {
        add_shortcode('tainacan_explorador_mapa', [new TEI_Mapa_Shortcode(), 'render']);
        add_shortcode('tainacan_explorador_timeline', [new TEI_Timeline_Shortcode(), 'render']);
        add_shortcode('tainacan_explorador_story', [new TEI_Story_Shortcode(), 'render']);
    }
    
    /**
     * Carrega assets condicionalmente
     */
    public function conditionally_enqueue_assets() {
        global $post;
        
        if (!is_a($post, 'WP_Post')) {
            return;
        }
        
        // Verifica se algum shortcode está presente
        $has_map = has_shortcode($post->post_content, 'tainacan_explorador_mapa');
        $has_timeline = has_shortcode($post->post_content, 'tainacan_explorador_timeline');
        $has_story = has_shortcode($post->post_content, 'tainacan_explorador_story');
        
        // Assets comuns
        if ($has_map || $has_timeline || $has_story) {
            wp_enqueue_style(
                'tei-common',
                TEI_PLUGIN_URL . 'assets/css/common.css',
                [],
                TEI_VERSION
            );
            
            wp_enqueue_script(
                'tei-common',
                TEI_PLUGIN_URL . 'assets/js/common.js',
                ['wp-api-fetch'],
                TEI_VERSION,
                true
            );
            
            wp_localize_script('tei-common', 'teiConfig', [
                'apiUrl' => home_url('/wp-json/tainacan-explorador/v1/'),
                'nonce' => wp_create_nonce('wp_rest'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'translations' => [
                    'loading' => __('Carregando...', 'tainacan-explorador'),
                    'error' => __('Erro ao carregar dados', 'tainacan-explorador'),
                    'noData' => __('Nenhum dado disponível', 'tainacan-explorador'),
                ]
            ]);
        }
        
        // Assets específicos do mapa
        if ($has_map) {
            wp_enqueue_style(
                'leaflet',
                'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
                [],
                '1.9.4'
            );
            
            wp_enqueue_script(
                'leaflet',
                'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
                [],
                '1.9.4',
                true
            );
            
            wp_enqueue_script(
                'leaflet-markercluster',
                'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js',
                ['leaflet'],
                '1.5.3',
                true
            );
            
            wp_enqueue_style(
                'leaflet-markercluster',
                'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css',
                ['leaflet'],
                '1.5.3'
            );
            
            wp_enqueue_script(
                'tei-map',
                TEI_PLUGIN_URL . 'assets/js/visualizations/map.js',
                ['leaflet', 'leaflet-markercluster', 'tei-common'],
                TEI_VERSION,
                true
            );
        }
        
        // Assets específicos da timeline
        if ($has_timeline) {
            wp_enqueue_style(
                'timeline-js',
                'https://cdn.knightlab.com/libs/timeline3/latest/css/timeline.css',
                [],
                '3.8.0'
            );
            
            wp_enqueue_script(
                'timeline-js',
                'https://cdn.knightlab.com/libs/timeline3/latest/js/timeline.js',
                [],
                '3.8.0',
                true
            );
            
            wp_enqueue_script(
                'tei-timeline',
                TEI_PLUGIN_URL . 'assets/js/visualizations/timeline.js',
                ['timeline-js', 'tei-common'],
                TEI_VERSION,
                true
            );
        }
        
        // Assets específicos do storytelling
        if ($has_story) {
            wp_enqueue_script(
                'scrollama',
                'https://unpkg.com/scrollama@3.2.0/build/scrollama.min.js',
                [],
                '3.2.0',
                true
            );
            
            wp_enqueue_script(
                'intersection-observer',
                'https://unpkg.com/intersection-observer@0.12.2/intersection-observer.js',
                [],
                '0.12.2',
                true
            );
            
            wp_enqueue_script(
                'tei-story',
                TEI_PLUGIN_URL . 'assets/js/visualizations/story.js',
                ['scrollama', 'tei-common'],
                TEI_VERSION,
                true
            );
            
            wp_enqueue_style(
                'tei-story',
                TEI_PLUGIN_URL . 'assets/css/story.css',
                [],
                TEI_VERSION
            );
        }
    }
    
    /**
     * Ações de ativação do plugin
     */
    public static function activate() {
        // Carrega classes necessárias se ainda não carregadas
        if (!class_exists('TEI_Metadata_Mapper')) {
            require_once TEI_PLUGIN_DIR . 'includes/class-metadata-mapper.php';
        }
        if (!class_exists('TEI_Cache_Manager')) {
            require_once TEI_PLUGIN_DIR . 'includes/class-cache-manager.php';
        }
        
        // Cria tabela de mapeamentos se necessário
        TEI_Metadata_Mapper::create_tables();
        
        // Define capacidades customizadas
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('manage_tainacan_explorer');
        }
        
        // Limpa permalinks
        flush_rewrite_rules();
        
        // Define opção de versão
        update_option('tei_version', TEI_VERSION);
        
        // Cria diretório de cache
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/tainacan-explorer-cache';
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }
    }
    
    /**
     * Ações de desativação do plugin
     */
    public static function deactivate() {
        // Limpa cache
        if (class_exists('TEI_Cache_Manager')) {
            TEI_Cache_Manager::clear_all();
        }
        
        // Limpa permalinks
        flush_rewrite_rules();
        
        // Remove tarefas agendadas
        wp_clear_scheduled_hook('tei_clear_cache');
    }
    
    /**
     * Ações de desinstalação do plugin
     */
    public static function uninstall() {
        // Carrega classes necessárias
        if (!class_exists('TEI_Metadata_Mapper')) {
            require_once TEI_PLUGIN_DIR . 'includes/class-metadata-mapper.php';
        }
        if (!class_exists('TEI_Cache_Manager')) {
            require_once TEI_PLUGIN_DIR . 'includes/class-cache-manager.php';
        }
        
        // Remove tabelas customizadas
        TEI_Metadata_Mapper::drop_tables();
        
        // Remove opções
        delete_option('tei_version');
        delete_option('tei_settings');
        delete_option('tei_mappings');
        
        // Remove capacidades
        $role = get_role('administrator');
        if ($role) {
            $role->remove_cap('manage_tainacan_explorer');
        }
        
        // Remove diretório de cache
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/tainacan-explorer-cache';
        if (file_exists($cache_dir)) {
            TEI_Cache_Manager::delete_directory($cache_dir);
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

// Adiciona link de configurações na página de plugins
add_filter('plugin_action_links_' . TEI_PLUGIN_BASENAME, function($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=tainacan-explorador') . '">' 
        . __('Configurações', 'tainacan-explorador') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
