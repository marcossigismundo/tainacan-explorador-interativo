<?php
/**
 * Página administrativa do plugin
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_Admin_Page {
    
    /**
     * Capability necessária
     */
    const CAPABILITY = 'manage_tainacan_explorer';
    
    /**
     * Slug da página
     */
    const PAGE_SLUG = 'tainacan-explorador';
    
    /**
     * Construtor
     */
    public function __construct() {
        // Hooks são adicionados pelo arquivo principal
    }
    
    /**
     * Adiciona página ao menu
     */
    public function add_menu_page() {
        // Verifica se o menu Tainacan existe
        global $menu;
        $tainacan_menu_exists = false;
        
        foreach ($menu as $item) {
            if (isset($item[2]) && $item[2] === 'tainacan_admin') {
                $tainacan_menu_exists = true;
                break;
            }
        }
        
        if ($tainacan_menu_exists) {
            // Adiciona como submenu do Tainacan
            add_submenu_page(
                'tainacan_admin',
                __('Explorador Interativo', 'tainacan-explorador'),
                __('Explorador Interativo', 'tainacan-explorador'),
                self::CAPABILITY,
                self::PAGE_SLUG,
                [$this, 'render_page']
            );
        } else {
            // Adiciona menu principal se Tainacan não estiver disponível
            add_menu_page(
                __('Explorador Interativo', 'tainacan-explorador'),
                __('Explorador', 'tainacan-explorador'),
                self::CAPABILITY,
                self::PAGE_SLUG,
                [$this, 'render_page'],
                'dashicons-location-alt',
                26
            );
        }
        
        // Adiciona submenus
        $this->add_submenus();
    }
    
    /**
     * Adiciona submenus
     */
    private function add_submenus() {
        $parent = $this->get_parent_slug();
        
        // Configurações
        add_submenu_page(
            $parent,
            __('Configurações do Explorador', 'tainacan-explorador'),
            __('Configurações', 'tainacan-explorador'),
            self::CAPABILITY,
            self::PAGE_SLUG . '-settings',
            [$this, 'render_settings_page']
        );
        
        // Importar/Exportar
        add_submenu_page(
            $parent,
            __('Importar/Exportar', 'tainacan-explorador'),
            __('Importar/Exportar', 'tainacan-explorador'),
            self::CAPABILITY,
            self::PAGE_SLUG . '-tools',
            [$this, 'render_tools_page']
        );
        
        // Ajuda
        add_submenu_page(
            $parent,
            __('Ajuda', 'tainacan-explorador'),
            __('Ajuda', 'tainacan-explorador'),
            self::CAPABILITY,
            self::PAGE_SLUG . '-help',
            [$this, 'render_help_page']
        );
    }
    
    /**
     * Obtém slug do menu pai
     */
    private function get_parent_slug() {
        global $menu;
        foreach ($menu as $item) {
            if (isset($item[2]) && $item[2] === 'tainacan_admin') {
                return 'tainacan_admin';
            }
        }
        return self::PAGE_SLUG;
    }
    
    /**
     * Renderiza página principal
     */
    public function render_page() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Você não tem permissão para acessar esta página.', 'tainacan-explorador'));
        }
        
        ?>
        <div class="wrap tei-admin-wrap">
            <div id="tei-admin-root"></div>
            
            <noscript>
                <div class="notice notice-error">
                    <p><?php esc_html_e('Este plugin requer JavaScript para funcionar corretamente.', 'tainacan-explorador'); ?></p>
                </div>
            </noscript>
        </div>
        <?php
    }
    
    /**
     * Renderiza página de configurações
     */
    public function render_settings_page() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Você não tem permissão para acessar esta página.', 'tainacan-explorador'));
        }
        
        // Processa formulário se enviado
        if (isset($_POST['tei_settings_nonce']) && wp_verify_nonce($_POST['tei_settings_nonce'], 'tei_settings')) {
            $this->save_settings();
        }
        
        $settings = get_option('tei_settings', $this->get_default_settings());
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Configurações do Explorador Interativo', 'tainacan-explorador'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('tei_settings', 'tei_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="cache_duration">
                                <?php esc_html_e('Duração do Cache', 'tainacan-explorador'); ?>
                            </label>
                        </th>
                        <td>
                            <select name="cache_duration" id="cache_duration">
                                <option value="1800" <?php selected($settings['cache_duration'], 1800); ?>>30 minutos</option>
                                <option value="3600" <?php selected($settings['cache_duration'], 3600); ?>>1 hora</option>
                                <option value="7200" <?php selected($settings['cache_duration'], 7200); ?>>2 horas</option>
                                <option value="86400" <?php selected($settings['cache_duration'], 86400); ?>>24 horas</option>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Tempo de armazenamento em cache dos dados das coleções.', 'tainacan-explorador'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="items_per_page">
                                <?php esc_html_e('Itens por Página', 'tainacan-explorador'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number" name="items_per_page" id="items_per_page" 
                                   value="<?php echo esc_attr($settings['items_per_page']); ?>" 
                                   min="10" max="500" />
                            <p class="description">
                                <?php esc_html_e('Número padrão de itens carregados por visualização.', 'tainacan-explorador'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="geocoding_service">
                                <?php esc_html_e('Serviço de Geocodificação', 'tainacan-explorador'); ?>
                            </label>
                        </th>
                        <td>
                            <select name="geocoding_service" id="geocoding_service">
                                <option value="nominatim" <?php selected($settings['geocoding_service'], 'nominatim'); ?>>
                                    Nominatim (OpenStreetMap)
                                </option>
                                <option value="google" <?php selected($settings['geocoding_service'], 'google'); ?>>
                                    Google Maps
                                </option>
                                <option value="mapbox" <?php selected($settings['geocoding_service'], 'mapbox'); ?>>
                                    Mapbox
                                </option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr class="geocoding-api-key" style="<?php echo $settings['geocoding_service'] === 'nominatim' ? 'display:none;' : ''; ?>">
                        <th scope="row">
                            <label for="geocoding_api_key">
                                <?php esc_html_e('API Key', 'tainacan-explorador'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text" name="geocoding_api_key" id="geocoding_api_key" 
                                   value="<?php echo esc_attr($settings['geocoding_api_key']); ?>" 
                                   class="regular-text" />
                            <p class="description">
                                <?php esc_html_e('Chave de API para o serviço de geocodificação selecionado.', 'tainacan-explorador'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Recursos Habilitados', 'tainacan-explorador'); ?>
                        </th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="enable_map" value="1" 
                                           <?php checked($settings['enable_map'], true); ?> />
                                    <?php esc_html_e('Visualização de Mapa', 'tainacan-explorador'); ?>
                                </label><br>
                                
                                <label>
                                    <input type="checkbox" name="enable_timeline" value="1" 
                                           <?php checked($settings['enable_timeline'], true); ?> />
                                    <?php esc_html_e('Linha do Tempo', 'tainacan-explorador'); ?>
                                </label><br>
                                
                                <label>
                                    <input type="checkbox" name="enable_story" value="1" 
                                           <?php checked($settings['enable_story'], true); ?> />
                                    <?php esc_html_e('Storytelling', 'tainacan-explorador'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Modo Debug', 'tainacan-explorador'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="debug_mode" value="1" 
                                       <?php checked($settings['debug_mode'], true); ?> />
                                <?php esc_html_e('Ativar logs detalhados para depuração', 'tainacan-explorador'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <h2><?php esc_html_e('Cache', 'tainacan-explorador'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Estatísticas do Cache', 'tainacan-explorador'); ?>
                        </th>
                        <td>
                            <?php
                            $cache_stats = TEI_Cache_Manager::get_stats();
                            ?>
                            <p>
                                <?php printf(
                                    __('Itens em cache: %d | Tamanho: %s', 'tainacan-explorador'),
                                    $cache_stats['items'],
                                    $cache_stats['size_formatted']
                                ); ?>
                            </p>
                            <p>
                                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=tei_clear_cache'), 'tei_clear_cache'); ?>" 
                                   class="button">
                                    <?php esc_html_e('Limpar Cache', 'tainacan-explorador'); ?>
                                </a>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#geocoding_service').on('change', function() {
                if ($(this).val() === 'nominatim') {
                    $('.geocoding-api-key').hide();
                } else {
                    $('.geocoding-api-key').show();
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Renderiza página de ferramentas
     */
    public function render_tools_page() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Você não tem permissão para acessar esta página.', 'tainacan-explorador'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Importar/Exportar Configurações', 'tainacan-explorador'); ?></h1>
            
            <div class="card">
                <h2><?php esc_html_e('Exportar Mapeamentos', 'tainacan-explorador'); ?></h2>
                <p><?php esc_html_e('Exporte todos os mapeamentos configurados para backup ou migração.', 'tainacan-explorador'); ?></p>
                <p>
                    <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=tei_export_mappings'), 'tei_export'); ?>" 
                       class="button button-primary">
                        <?php esc_html_e('Exportar Mapeamentos', 'tainacan-explorador'); ?>
                    </a>
                </p>
            </div>
            
            <div class="card">
                <h2><?php esc_html_e('Importar Mapeamentos', 'tainacan-explorador'); ?></h2>
                <form method="post" enctype="multipart/form-data" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('tei_import', 'tei_import_nonce'); ?>
                    <input type="hidden" name="action" value="tei_import_mappings">
                    
                    <p>
                        <label for="import_file">
                            <?php esc_html_e('Selecione o arquivo JSON:', 'tainacan-explorador'); ?>
                        </label><br>
                        <input type="file" name="import_file" id="import_file" accept=".json" required>
                    </p>
                    
                    <p>
                        <label>
                            <input type="checkbox" name="overwrite" value="1">
                            <?php esc_html_e('Sobrescrever mapeamentos existentes', 'tainacan-explorador'); ?>
                        </label>
                    </p>
                    
                    <p>
                        <input type="submit" class="button button-primary" 
                               value="<?php esc_attr_e('Importar', 'tainacan-explorador'); ?>">
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderiza página de ajuda
     */
    public function render_help_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Ajuda - Explorador Interativo', 'tainacan-explorador'); ?></h1>
            
            <div class="card">
                <h2><?php esc_html_e('Como usar', 'tainacan-explorador'); ?></h2>
                <ol>
                    <li><?php esc_html_e('Selecione uma coleção do Tainacan', 'tainacan-explorador'); ?></li>
                    <li><?php esc_html_e('Configure o mapeamento dos metadados para cada tipo de visualização', 'tainacan-explorador'); ?></li>
                    <li><?php esc_html_e('Salve as configurações', 'tainacan-explorador'); ?></li>
                    <li><?php esc_html_e('Use os shortcodes em suas páginas ou posts', 'tainacan-explorador'); ?></li>
                </ol>
            </div>
            
            <div class="card">
                <h2><?php esc_html_e('Shortcodes Disponíveis', 'tainacan-explorador'); ?></h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Shortcode', 'tainacan-explorador'); ?></th>
                            <th><?php esc_html_e('Descrição', 'tainacan-explorador'); ?></th>
                            <th><?php esc_html_e('Parâmetros', 'tainacan-explorador'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>[tainacan_explorador_mapa]</code></td>
                            <td><?php esc_html_e('Exibe mapa interativo', 'tainacan-explorador'); ?></td>
                            <td>collection, height, width, zoom, style</td>
                        </tr>
                        <tr>
                            <td><code>[tainacan_explorador_timeline]</code></td>
                            <td><?php esc_html_e('Exibe linha do tempo', 'tainacan-explorador'); ?></td>
                            <td>collection, height, start_date, end_date</td>
                        </tr>
                        <tr>
                            <td><code>[tainacan_explorador_story]</code></td>
                            <td><?php esc_html_e('Exibe narrativa visual', 'tainacan-explorador'); ?></td>
                            <td>collection, animation, navigation</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="card">
                <h2><?php esc_html_e('Suporte', 'tainacan-explorador'); ?></h2>
                <p>
                    <?php printf(
                        __('Para suporte e documentação completa, visite: %s', 'tainacan-explorador'),
                        '<a href="https://github.com/seu-usuario/tainacan-explorador" target="_blank">GitHub</a>'
                    ); ?>
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Carrega assets administrativos
     */
    public function enqueue_admin_assets($hook) {
        // Verifica se está na página do plugin
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }
        
        // React e dependências
        wp_enqueue_script(
            'tei-admin-react',
            TEI_PLUGIN_URL . 'assets/js/admin.js',
            ['wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n'],
            TEI_VERSION,
            true
        );
        
        // CSS administrativo
        wp_enqueue_style(
            'tei-admin-styles',
            TEI_PLUGIN_URL . 'assets/css/admin.css',
            [],
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
        
        // Scripts inline para inicialização
        wp_add_inline_script('tei-admin-react', '
            document.addEventListener("DOMContentLoaded", function() {
                if (window.TEI_Admin) {
                    window.TEI_Admin.init("tei-admin-root");
                }
            });
        ');
    }
    
    /**
     * Obtém configurações padrão
     */
    private function get_default_settings() {
        return [
            'cache_duration' => 3600,
            'items_per_page' => 100,
            'geocoding_service' => 'nominatim',
            'geocoding_api_key' => '',
            'enable_map' => true,
            'enable_timeline' => true,
            'enable_story' => true,
            'debug_mode' => false
        ];
    }
    
    /**
     * Salva configurações
     */
    private function save_settings() {
        $settings = [
            'cache_duration' => intval($_POST['cache_duration'] ?? 3600),
            'items_per_page' => intval($_POST['items_per_page'] ?? 100),
            'geocoding_service' => sanitize_text_field($_POST['geocoding_service'] ?? 'nominatim'),
            'geocoding_api_key' => sanitize_text_field($_POST['geocoding_api_key'] ?? ''),
            'enable_map' => !empty($_POST['enable_map']),
            'enable_timeline' => !empty($_POST['enable_timeline']),
            'enable_story' => !empty($_POST['enable_story']),
            'debug_mode' => !empty($_POST['debug_mode'])
        ];
        
        update_option('tei_settings', $settings);
        
        add_settings_error(
            'tei_settings',
            'settings_updated',
            __('Configurações salvas com sucesso!', 'tainacan-explorador'),
            'success'
        );
    }
}
