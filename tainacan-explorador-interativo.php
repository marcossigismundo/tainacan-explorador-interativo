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
define('TEI_PLUGIN_FILE', __FILE__);

// Carrega classes do includes
require_once TEI_PLUGIN_DIR . 'includes/class-metadata-mapper.php';
require_once TEI_PLUGIN_DIR . 'includes/class-cache-manager.php';
require_once TEI_PLUGIN_DIR . 'includes/class-api-handler.php';
require_once TEI_PLUGIN_DIR . 'includes/class-sanitizer.php';

// Carrega classes admin
require_once TEI_PLUGIN_DIR . 'admin/class-admin-page.php';
require_once TEI_PLUGIN_DIR . 'admin/class-ajax-handler.php';

// Carrega shortcodes
require_once TEI_PLUGIN_DIR . 'shortcodes/class-mapa-shortcode.php';
require_once TEI_PLUGIN_DIR . 'shortcodes/class-timeline-shortcode.php';
require_once TEI_PLUGIN_DIR . 'shortcodes/class-story-shortcode.php';

// Carrega API
require_once TEI_PLUGIN_DIR . 'api/class-api-endpoints.php';

/**
 * Classe principal do plugin
 */
final class TainacanExploradorInterativo {
    
    private static $instance = null;
    private $ajax_handler = null;
    private $dependencies_checked = false;
    
    /**
     * Singleton
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
        $this->init_hooks();
    }
    
    /**
     * Previne clonagem
     */
    private function __clone() {}
    
    /**
     * Previne unserialize
     */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
    
    /**
     * Inicializa hooks
     */
    private function init_hooks() {
        // Verifica dependências antes de tudo
        add_action('plugins_loaded', [$this, 'check_dependencies'], 5);
        
        // Internacionalização
        add_action('plugins_loaded', [$this, 'load_textdomain'], 10);
        
        // Se dependências OK, inicializa o plugin
        add_action('plugins_loaded', [$this, 'init_plugin'], 15);
        
        // Admin
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // Frontend
        add_action('wp_enqueue_scripts', [$this, 'conditionally_enqueue_assets']);
        
        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Shortcodes
        add_action('init', [$this, 'register_shortcodes']);
        
        // AJAX - Registra apenas uma vez
        add_action('admin_init', [$this, 'register_ajax_handlers']);
        
        // Preview
        add_action('init', [$this, 'register_preview_endpoint']);
        add_action('template_redirect', [$this, 'handle_preview']);
        
        // Cache cleanup
        if (!wp_next_scheduled('tei_cache_cleanup')) {
            wp_schedule_event(time(), 'daily', 'tei_cache_cleanup');
        }
        add_action('tei_cache_cleanup', ['TEI_Cache_Manager', 'cleanup_expired']);
    }
    
    /**
     * Verifica dependências
     */
    public function check_dependencies() {
        if ($this->dependencies_checked) {
            return;
        }
        
        $this->dependencies_checked = true;
        
        if (!$this->is_tainacan_active()) {
            add_action('admin_notices', [$this, 'show_dependency_notice']);
            // Desativa funcionalidades mas não o plugin inteiro
            remove_action('init', [$this, 'register_shortcodes']);
            remove_action('rest_api_init', [$this, 'register_rest_routes']);
            return false;
        }
        
        return true;
    }
    
    /**
     * Verifica se Tainacan está ativo
     */
    private function is_tainacan_active() {
        return class_exists('Tainacan\Plugin') || 
               is_plugin_active('tainacan/tainacan.php') || 
               function_exists('tainacan_get_api_postdata');
    }
    
    /**
     * Mostra aviso de dependência
     */
    public function show_dependency_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php _e('Explorador Interativo para Tainacan', 'tainacan-explorador'); ?></strong>: 
                <?php _e('Este plugin requer o Tainacan ativo para funcionar.', 'tainacan-explorador'); ?>
                <a href="<?php echo admin_url('plugins.php'); ?>"><?php _e('Ativar Tainacan', 'tainacan-explorador'); ?></a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Inicializa o plugin se dependências OK
     */
    public function init_plugin() {
        if (!$this->dependencies_checked || !$this->is_tainacan_active()) {
            return;
        }
        
        // Inicialização adicional aqui se necessário
    }
    
    /**
     * Carrega textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'tainacan-explorador',
            false,
            dirname(TEI_PLUGIN_BASENAME) . '/languages/'
        );
    }
    
    /**
     * Adiciona menu administrativo
     */
    public function add_admin_menu() {
        if (!$this->is_tainacan_active()) {
            return;
        }
        
        if (class_exists('TEI_Admin_Page')) {
            $admin = new TEI_Admin_Page();
            $admin->add_menu_page();
        }
    }
    
    /**
     * Carrega assets administrativos
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'tainacan-explorador') === false) {
            return;
        }
        
        // WordPress admin styles
        wp_enqueue_style('wp-components');
        
        // React e dependências
        $deps = [
            'wp-element',
            'wp-components', 
            'wp-api-fetch',
            'wp-i18n',
            'wp-notices',
            'wp-data'
        ];
        
        // Admin script
        wp_enqueue_script(
            'tei-admin-react',
            TEI_PLUGIN_URL . 'assets/js/admin.js',
            $deps,
            TEI_VERSION,
            true
        );
        
        // CSS administrativo
        wp_enqueue_style(
            'tei-admin-styles',
            TEI_PLUGIN_URL . 'assets/css/admin.css',
            ['wp-components'],
            TEI_VERSION
        );
        
        // Localização
        wp_localize_script('tei-admin-react', 'teiAdmin', [
            'apiUrl' => rest_url('tainacan-explorador/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'ajaxNonce' => wp_create_nonce('tei_admin'),
            'pluginUrl' => TEI_PLUGIN_URL,
            'translations' => [
                'loading' => __('Carregando...', 'tainacan-explorador'),
                'error' => __('Erro ao carregar dados', 'tainacan-explorador'),
                'saved' => __('Configurações salvas com sucesso!', 'tainacan-explorador'),
                'confirm_delete' => __('Tem certeza que deseja excluir este mapeamento?', 'tainacan-explorador')
            ]
        ]);
    }
    
    /**
     * Registra AJAX handlers - CORRIGIDO para evitar duplicação
     */
    public function register_ajax_handlers() {
        if (!class_exists('TEI_Ajax_Handler') || $this->ajax_handler !== null) {
            return;
        }
        
        $this->ajax_handler = new TEI_Ajax_Handler();
        
        // Handlers para usuários logados
        $ajax_actions = [
            'tei_get_collections' => 'get_collections',
            'tei_get_metadata' => 'get_metadata',
            'tei_save_mapping' => 'save_mapping',
            'tei_delete_mapping' => 'delete_mapping',
            'tei_get_all_mappings' => 'get_all_mappings',
            'tei_clear_collection_cache' => 'clear_collection_cache'
        ];
        
        foreach ($ajax_actions as $action => $method) {
            add_action('wp_ajax_' . $action, [$this->ajax_handler, $method]);
        }
        
        // Permite acesso público apenas para visualização
        add_action('wp_ajax_nopriv_tei_get_collections', [$this->ajax_handler, 'get_collections']);
    }
    
    /**
     * Handler para limpeza de cache
     */
    public function handle_clear_cache() {
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'tei_clear_cache')) {
            wp_die(__('Nonce inválido', 'tainacan-explorador'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Permissão negada', 'tainacan-explorador'));
        }
        
        TEI_Cache_Manager::clear_all();
        
        wp_redirect(add_query_arg('cache_cleared', '1', wp_get_referer()));
        exit;
    }
    
    /**
     * Registra endpoint de preview
     */
    public function register_preview_endpoint() {
        add_rewrite_endpoint('tei-preview', EP_ROOT);
    }
    
    /**
     * Handle preview
     */
    public function handle_preview() {
        if (!isset($_GET['tei-preview'])) {
            return;
        }
        
        $type = sanitize_key($_GET['type'] ?? '');
        $collection = intval($_GET['collection'] ?? 0);
        
        if (!$type || !$collection) {
            wp_die(__('Parâmetros inválidos', 'tainacan-explorador'));
        }
        
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
            <?php 
            $this->conditionally_enqueue_assets(true, $type);
            wp_head(); 
            ?>
            <style>
                body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
                .preview-header { 
                    background: #f0f0f1; 
                    padding: 15px; 
                    border-bottom: 1px solid #c3c4c7; 
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    z-index: 10000;
                }
                .preview-title { margin: 0; font-size: 18px; color: #1d2327; }
                .preview-close { float: right; text-decoration: none; color: #787c82; }
                .preview-close:hover { color: #2271b1; }
                .preview-container { 
                    margin-top: 60px;
                    width: 100%;
                    height: calc(100vh - 60px);
                }
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
                switch($type) {
                    case 'map':
                        echo do_shortcode('[tainacan_explorador_mapa collection="' . $collection_id . '" height="100%" width="100%"]');
                        break;
                    case 'timeline':
                        echo do_shortcode('[tainacan_explorador_timeline collection="' . $collection_id . '" height="100%"]');
                        break;
                    case 'story':
                        echo do_shortcode('[tainacan_explorador_story collection="' . $collection_id . '" height="100%"]');
                        break;
                    default:
                        echo '<p style="padding: 20px;">' . __('Tipo de visualização inválido', 'tainacan-explorador') . '</p>';
                }
                ?>
            </div>
            
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }
    
    /**
     * Registra rotas REST
     */
    public function register_rest_routes() {
        if (class_exists('TEI_API_Endpoints')) {
            $api = new TEI_API_Endpoints();
            $api->register_routes();
        }
    }
    
    /**
     * Registra shortcodes
     */
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
    
    /**
     * Carrega assets condicionalmente
     */
    public function conditionally_enqueue_assets($force = false, $type = null) {
        global $post;
        
        if ($force) {
            $has_map = ($type === 'map');
            $has_timeline = ($type === 'timeline');
            $has_story = ($type === 'story');
        } else {
            if (!is_a($post, 'WP_Post')) {
                return;
            }
            
            $has_map = has_shortcode($post->post_content, 'tainacan_explorador_mapa');
            $has_timeline = has_shortcode($post->post_content, 'tainacan_explorador_timeline');
            $has_story = has_shortcode($post->post_content, 'tainacan_explorador_story');
        }
        
        if ($has_map || $has_timeline || $has_story) {
            wp_enqueue_style('tei-common', TEI_PLUGIN_URL . 'assets/css/common.css', [], TEI_VERSION);
            wp_enqueue_script('tei-common', TEI_PLUGIN_URL . 'assets/js/common.js', ['wp-api-fetch'], TEI_VERSION, true);
            
            wp_localize_script('tei-common', 'teiConfig', [
                'apiUrl' => rest_url('tainacan-explorador/v1/'),
                'nonce' => wp_create_nonce('wp_rest'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'pluginUrl' => TEI_PLUGIN_URL
            ]);
        }
        
        if ($has_map) {
            wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
            wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
            wp_enqueue_script('leaflet-markercluster', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js', ['leaflet'], '1.5.3', true);
            wp_enqueue_style('leaflet-markercluster', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css', ['leaflet'], '1.5.3');
            wp_enqueue_script('tei-map', TEI_PLUGIN_URL . 'assets/js/visualizations/maps.js', ['leaflet', 'tei-common'], TEI_VERSION, true);
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
    
    /**
     * Ativação do plugin
     */
    public static function activate() {
        // Cria tabelas
        if (class_exists('TEI_Metadata_Mapper')) {
            TEI_Metadata_Mapper::create_tables();
        }
        
        // Adiciona capability
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('manage_tainacan_explorer');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Versão
        update_option('tei_version', TEI_VERSION);
        
        // Cria diretório de cache
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/tainacan-explorer-cache';
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
            
            // Protege diretório
            $htaccess = $cache_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, 'Deny from all');
            }
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
        
        // Remove agendamentos
        wp_clear_scheduled_hook('tei_cache_cleanup');
        
        // Flush rewrite rules
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
        
        // Remove capabilities
        $role = get_role('administrator');
        if ($role) {
            $role->remove_cap('manage_tainacan_explorer');
        }
        
        // Remove diretório de cache
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/tainacan-explorer-cache';
        if (file_exists($cache_dir) && class_exists('TEI_Cache_Manager')) {
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
}, 1);

// Link de configurações na lista de plugins
add_filter('plugin_action_links_' . TEI_PLUGIN_BASENAME, function($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=tainacan-explorador') . '">' 
        . __('Configurações', 'tainacan-explorador') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
