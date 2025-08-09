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

// Carrega classes do includes
require_once TEI_PLUGIN_DIR . 'includes/class-metadata-mapper.php';
require_once TEI_PLUGIN_DIR . 'includes/class-cache-manager.php';
require_once TEI_PLUGIN_DIR . 'includes/class-api-handler.php';
require_once TEI_PLUGIN_DIR . 'includes/class-sanitizer.php';

// Carrega classes admin - CORREÇÃO: nome correto do arquivo
require_once TEI_PLUGIN_DIR . 'admin/class-admin-page.php';
require_once TEI_PLUGIN_DIR . 'admin/class-ajax-handler.php';

// Carrega shortcodes - usar apenas as versões class-*
require_once TEI_PLUGIN_DIR . 'shortcodes/class-mapa-shortcode.php';
require_once TEI_PLUGIN_DIR . 'shortcodes/class-timeline-shortcode.php';
require_once TEI_PLUGIN_DIR . 'shortcodes/class-story-shortcode.php';

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
        $this->check_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Verifica dependências
     */
    private function check_dependencies() {
        add_action('admin_notices', [$this, 'check_tainacan_active']);
    }
    
    /**
     * Verifica se Tainacan está ativo
     */
    public function check_tainacan_active() {
        if (!is_plugin_active('tainacan/tainacan.php')) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . __('Explorador Interativo para Tainacan', 'tainacan-explorador') . '</strong>: ';
            echo __('Este plugin requer o Tainacan ativo para funcionar.', 'tainacan-explorador');
            echo '</p></div>';
        }
    }
    
    private function init_hooks() {
        // Internacionalização
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('init', [$this, 'register_preview_endpoint']);
add_action('template_redirect', [$this, 'handle_preview']);

// Adicione estes novos métodos na classe:

/**
 * Registra endpoint de preview
 */
public function register_preview_endpoint() {
    add_rewrite_rule(
        '^preview/?$',
        'index.php?tei_preview=1',
        'top'
    );
    
    add_filter('query_vars', function($vars) {
        $vars[] = 'tei_preview';
        return $vars;
    });
}

/**
 * Handle preview requests
 */
public function handle_preview() {
    if (!get_query_var('tei_preview')) {
        return;
    }
    
    // Verifica permissões
    if (!current_user_can('manage_tainacan_explorer')) {
        wp_die(__('Acesso negado', 'tainacan-explorador'));
    }
    
    $type = sanitize_key($_GET['type'] ?? '');
    $collection = intval($_GET['collection'] ?? 0);
    
    if (!$type || !$collection) {
        wp_die(__('Parâmetros inválidos', 'tainacan-explorador'));
    }
    
    // Renderiza preview
    $this->render_preview($type, $collection);
    exit;
}

/**
 * Renderiza preview
 */
private function render_preview($type, $collection_id) {
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo sprintf(__('Preview - %s', 'tainacan-explorador'), ucfirst($type)); ?></title>
        <?php wp_head(); ?>
        <style>
            body { margin: 0; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            .preview-header { background: #f0f0f1; padding: 15px; margin: -20px -20px 20px; border-bottom: 1px solid #c3c4c7; }
            .preview-title { margin: 0; font-size: 18px; color: #1d2327; }
            .preview-close { float: right; text-decoration: none; color: #787c82; }
            .preview-close:hover { color: #2271b1; }
            .preview-container { max-width: 1200px; margin: 0 auto; }
        </style>
    </head>
    <body>
        <div class="preview-header">
            <a href="#" class="preview-close" onclick="window.close()">✕ <?php _e('Fechar', 'tainacan-explorador'); ?></a>
            <h1 class="preview-title">
                <?php echo sprintf(__('Preview: %s - Coleção #%d', 'tainacan-explorador'), ucfirst($type), $collection_id); ?>
            </h1>
        </div>
        
        <div class="preview-container">
            <?php
            // Renderiza shortcode correspondente
            switch($type) {
                case 'map':
                    echo do_shortcode('[tainacan_explorador_mapa collection="' . $collection_id . '" height="600px"]');
                    break;
                case 'timeline':
                    echo do_shortcode('[tainacan_explorador_timeline collection="' . $collection_id . '"]');
                    break;
                case 'story':
                    echo do_shortcode('[tainacan_explorador_story collection="' . $collection_id . '"]');
                    break;
                default:
                    echo '<p>' . __('Tipo de visualização inválido', 'tainacan-explorador') . '</p>';
            }
            ?>
        </div>
        
        <?php wp_footer(); ?>
    </body>
    </html>
        
        // Admin
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        }
        
        // AJAX handlers
        $this->register_ajax_handlers();
        
        // Frontend
        add_action('wp_enqueue_scripts', [$this, 'conditionally_enqueue_assets']);
        
        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Shortcodes
        add_action('init', [$this, 'register_shortcodes']);
        
        // Cache cleanup
        add_action('tei_cache_cleanup', ['TEI_Cache_Manager', 'cleanup_expired']);
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
        
        // Handlers para usuários logados
        add_action('wp_ajax_tei_get_collections', [$ajax, 'get_collections']);
        add_action('wp_ajax_tei_get_metadata', [$ajax, 'get_metadata']);
        add_action('wp_ajax_tei_save_mapping', [$ajax, 'save_mapping']);
        add_action('wp_ajax_tei_delete_mapping', [$ajax, 'delete_mapping']);
        add_action('wp_ajax_tei_get_all_mappings', [$ajax, 'get_all_mappings']);
        
        // Handler para limpeza de cache
        add_action('admin_post_tei_clear_cache', [$this, 'handle_clear_cache']);
    }
    
    public function handle_clear_cache() {
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'tei_clear_cache')) {
            wp_die(__('Ação não autorizada', 'tainacan-explorador'));
        }
        
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_die(__('Permissão negada', 'tainacan-explorador'));
        }
        
        TEI_Cache_Manager::clear_all();
        
        wp_redirect(add_query_arg(
            ['page' => 'tainacan-explorador-settings', 'cache_cleared' => '1'],
            admin_url('admin.php')
        ));
        exit;
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
            // CSS comum
            wp_enqueue_style('tei-common', TEI_PLUGIN_URL . 'assets/css/common.css', [], TEI_VERSION);
            
            // JS comum
            wp_enqueue_script('tei-common', TEI_PLUGIN_URL . 'assets/js/common.js', ['wp-api-fetch'], TEI_VERSION, true);
            
            // Localização
            wp_localize_script('tei-common', 'teiConfig', [
                'apiUrl' => rest_url('tainacan-explorador/v1/'),
                'nonce' => wp_create_nonce('wp_rest'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'pluginUrl' => TEI_PLUGIN_URL
            ]);
        }
        
        // Assets específicos para cada visualização
        if ($has_map) {
            wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
            wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
            wp_enqueue_script('leaflet-markercluster', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js', ['leaflet'], '1.5.3', true);
            wp_enqueue_style('leaflet-markercluster', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css', ['leaflet'], '1.5.3');
            wp_enqueue_script('tei-map', TEI_PLUGIN_URL . 'assets/js/maps.js', ['leaflet', 'tei-common'], TEI_VERSION, true);
        }
        
        if ($has_timeline) {
            wp_enqueue_style('timeline-js', 'https://cdn.knightlab.com/libs/timeline3/latest/css/timeline.css', [], '3.8.0');
            wp_enqueue_script('timeline-js', 'https://cdn.knightlab.com/libs/timeline3/latest/js/timeline.js', [], '3.8.0', true);
            wp_enqueue_script('tei-timeline', TEI_PLUGIN_URL . 'assets/js/timeline.js', ['timeline-js', 'tei-common'], TEI_VERSION, true);
        }
        
        if ($has_story) {
            wp_enqueue_script('scrollama', 'https://unpkg.com/scrollama@3.2.0/build/scrollama.min.js', [], '3.2.0', true);
            wp_enqueue_script('tei-story', TEI_PLUGIN_URL . 'assets/js/story.js', ['scrollama', 'tei-common'], TEI_VERSION, true);
            wp_enqueue_style('tei-story', TEI_PLUGIN_URL . 'assets/css/story.css', [], TEI_VERSION);
        }
    }
    
    /**
     * Ativação do plugin
     */
    public static function activate() {
        // Cria tabelas
        if (class_exists('TEI_Metadata_Mapper')) {
            TEI_Metadata_Mapper::create_tables();
        }
        
        // Adiciona capacidades
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('manage_tainacan_explorer');
        }
        
        // Limpa permalinks
        flush_rewrite_rules();
        
        // Salva versão
        update_option('tei_version', TEI_VERSION);
        
        // Cria diretório de cache
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/tainacan-explorer-cache';
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }
        
        // Agenda limpeza de cache
        if (!wp_next_scheduled('tei_cache_cleanup')) {
            wp_schedule_event(time(), 'daily', 'tei_cache_cleanup');
        }
    }
    
    /**
     * Desativação do plugin
     */
    public static function deactivate() {
        // Limpa cache
        if (class_exists('TEI_Cache_Manager')) {
            TEI_Cache_Manager::clear_all();
        }
        
        // Remove agendamento
        wp_clear_scheduled_hook('tei_cache_cleanup');
        
        // Limpa permalinks
        flush_rewrite_rules();
    }
    
    /**
     * Desinstalação do plugin
     */
    public static function uninstall() {
        // Remove tabelas
        if (class_exists('TEI_Metadata_Mapper')) {
            TEI_Metadata_Mapper::drop_tables();
        }
        
        // Remove opções
        delete_option('tei_version');
        delete_option('tei_settings');
        
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

// Link de configurações na lista de plugins
add_filter('plugin_action_links_' . TEI_PLUGIN_BASENAME, function($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=tainacan-explorador') . '">' 
        . __('Configurações', 'tainacan-explorador') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

