<?php
/**
 * Handler de requisições AJAX
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_Ajax_Handler {
    
    /**
     * Obtém coleções disponíveis
     */
    public function get_collections() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
            return;
        }
        
        try {
            $collections = [];
            
            // Primeiro tenta via API REST do Tainacan v2
            $endpoint = rest_url('tainacan/v2/collections');
            
            // Faz a requisição com contexto de edição se o usuário tiver permissão
            $args = [
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/json',
                ]
            ];
            
            // Se o usuário estiver logado, adiciona cookies para autenticação
            if (is_user_logged_in()) {
                $args['cookies'] = $_COOKIE;
                $endpoint = add_query_arg('context', 'edit', $endpoint);
            }
            
            $response = wp_remote_get($endpoint, $args);
            
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $tainacan_collections = json_decode($body, true);
                
                if (is_array($tainacan_collections)) {
                    foreach ($tainacan_collections as $col) {
                        // Para cada coleção, busca a contagem de itens
                        $items_endpoint = rest_url("tainacan/v2/collection/{$col['id']}/items");
                        $items_args = [
                            'timeout' => 10,
                            'headers' => [
                                'Content-Type' => 'application/json'
                            ]
                        ];
                        
                        // Adiciona parâmetro para pegar apenas 1 item (para contagem)
                        $items_endpoint = add_query_arg(['perpage' => 1], $items_endpoint);
                        
                        $items_response = wp_remote_get($items_endpoint, $items_args);
                        
                        $total_items = 0;
                        if (!is_wp_error($items_response)) {
                            $headers = wp_remote_retrieve_headers($items_response);
                            // O Tainacan retorna o total no header X-WP-Total
                            if (isset($headers['x-wp-total'])) {
                                $total_items = intval($headers['x-wp-total']);
                            }
                        }
                        
                        $collections[] = [
                            'id' => $col['id'],
                            'name' => $col['name'] ?? '',
                            'items_count' => $total_items
                        ];
                    }
                }
            }
            
            // Se a API não retornar nada, tenta query direta
            if (empty($collections)) {
                // Busca diretamente no banco via WP_Query
                $args = [
                    'post_type' => 'tainacan-collection',
                    'posts_per_page' => -1,
                    'post_status' => 'publish'
                ];
                
                $query = new WP_Query($args);
                
                if ($query->have_posts()) {
                    while ($query->have_posts()) {
                        $query->the_post();
                        $collection_id = get_the_ID();
                        
                        // Busca post type dos itens desta coleção
                        $collection_post_type = 'tnc_col_' . $collection_id . '_item';
                        
                        // Conta os itens publicados
                        $count_posts = wp_count_posts($collection_post_type);
                        $total_items = isset($count_posts->publish) ? intval($count_posts->publish) : 0;
                        
                        $collections[] = [
                            'id' => $collection_id,
                            'name' => get_the_title(),
                            'items_count' => $total_items
                        ];
                    }
                    wp_reset_postdata();
                }
            }
            
            wp_send_json_success($collections);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => __('Erro ao carregar coleções: ', 'tainacan-explorador') . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Obtém metadados de uma coleção
     */
    public function get_metadata() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
            return;
        }
        
        $collection_id = intval($_POST['collection_id'] ?? 0);
        
        if (!$collection_id) {
            wp_send_json_error(['message' => __('ID da coleção inválido', 'tainacan-explorador')]);
            return;
        }
        
        try {
            // Usa TEI_API_Handler para obter metadados
            $api_handler = new TEI_API_Handler();
            $metadata = $api_handler->get_collection_metadata($collection_id);
            
            if (is_wp_error($metadata)) {
                wp_send_json_error(['message' => $metadata->get_error_message()]);
                return;
            }
            
            // Adiciona campos especiais que sempre existem
            $special_fields = [
                ['id' => 'thumbnail', 'name' => __('Miniatura', 'tainacan-explorador'), 'type' => 'image', 'slug' => 'thumbnail'],
                ['id' => 'document', 'name' => __('Documento', 'tainacan-explorador'), 'type' => 'attachment', 'slug' => 'document'],
                ['id' => '_attachments', 'name' => __('Anexos', 'tainacan-explorador'), 'type' => 'attachments', 'slug' => '_attachments'],
                ['id' => 'title', 'name' => __('Título', 'tainacan-explorador'), 'type' => 'text', 'slug' => 'title'],
                ['id' => 'description', 'name' => __('Descrição', 'tainacan-explorador'), 'type' => 'textarea', 'slug' => 'description']
            ];
            
            $metadata = array_merge($metadata, $special_fields);
            
            // Obtém mapeamentos existentes
            $existing_mappings = [];
            $mapping_types = ['map', 'timeline', 'story'];
            
            foreach ($mapping_types as $type) {
                $mapping = TEI_Metadata_Mapper::get_mapping($collection_id, $type);
                if ($mapping) {
                    $existing_mappings[$type] = $mapping;
                }
            }
            
            wp_send_json_success([
                'metadata' => $metadata,
                'mappings' => $existing_mappings,
                'debug' => [
                    'collection_id' => $collection_id,
                    'metadata_count' => count($metadata),
                    'has_mappings' => !empty($existing_mappings)
                ]
            ]);
            
        } catch (Exception $e) {
            error_log('TEI Error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => __('Erro ao carregar metadados: ', 'tainacan-explorador') . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Salva mapeamento
     */
    public function save_mapping() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
            return;
        }
        
        // Valida dados
        $collection_id = intval($_POST['collection_id'] ?? 0);
        $collection_name = sanitize_text_field($_POST['collection_name'] ?? '');
        $mapping_type = sanitize_key($_POST['mapping_type'] ?? '');
        $mapping_data = $_POST['mapping_data'] ?? [];
        $visualization_settings = $_POST['visualization_settings'] ?? [];
        $filter_rules = $_POST['filter_rules'] ?? [];
        
        if (!$collection_id || !$mapping_type) {
            wp_send_json_error(['message' => __('Dados inválidos', 'tainacan-explorador')]);
            return;
        }
        
        // Sanitiza dados do mapeamento preservando IDs
        $sanitized_mapping = [];
        if (is_array($mapping_data)) {
            foreach ($mapping_data as $key => $value) {
                $sanitized_mapping[sanitize_key($key)] = $value; // Preserva IDs numéricos
            }
        }
        
        // Sanitiza configurações de visualização
        $sanitized_settings = [];
        if (is_array($visualization_settings)) {
            foreach ($visualization_settings as $key => $value) {
                $sanitized_settings[sanitize_key($key)] = sanitize_text_field($value);
            }
        }
        
        // Sanitiza regras de filtro
        $sanitized_filters = [];
        if (is_array($filter_rules)) {
            foreach ($filter_rules as $rule) {
                if (is_array($rule)) {
                    $sanitized_filters[] = [
                        'metadatum' => $rule['metadatum'] ?? '',
                        'operator' => sanitize_text_field($rule['operator'] ?? '='),
                        'value' => sanitize_text_field($rule['value'] ?? '')
                    ];
                }
            }
        }
        
        // Salva mapeamento
        $result = TEI_Metadata_Mapper::save_mapping([
            'collection_id' => $collection_id,
            'collection_name' => $collection_name,
            'mapping_type' => $mapping_type,
            'mapping_data' => $sanitized_mapping,
            'visualization_settings' => $sanitized_settings,
            'filter_rules' => $sanitized_filters
        ]);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }
        
        if (!$result) {
            wp_send_json_error(['message' => __('Erro ao salvar mapeamento', 'tainacan-explorador')]);
            return;
        }
        
        // Limpa cache
        if (class_exists('TEI_Cache_Manager')) {
            TEI_Cache_Manager::clear_collection_cache($collection_id);
        }
        
        wp_send_json_success([
            'message' => __('Mapeamento salvo com sucesso!', 'tainacan-explorador'),
            'mapping_id' => $result
        ]);
    }
    
    /**
     * Obtém todos os mapeamentos
     */
    public function get_all_mappings() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
            return;
        }
        
        $mappings = TEI_Metadata_Mapper::get_all_mappings();
        
        wp_send_json_success([
            'mappings' => $mappings,
            'total' => count($mappings),
            'page' => 1,
            'per_page' => 20
        ]);
    }
    
    /**
     * Deleta mapeamento
     */
    public function delete_mapping() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
            return;
        }
        
        $mapping_id = intval($_POST['mapping_id'] ?? 0);
        
        if (!$mapping_id) {
            wp_send_json_error(['message' => __('ID inválido', 'tainacan-explorador')]);
            return;
        }
        
        $result = TEI_Metadata_Mapper::delete_mapping($mapping_id);
        
        if (!$result) {
            wp_send_json_error(['message' => __('Erro ao deletar', 'tainacan-explorador')]);
            return;
        }
        
        wp_send_json_success(['message' => __('Deletado com sucesso!', 'tainacan-explorador')]);
    }
}
