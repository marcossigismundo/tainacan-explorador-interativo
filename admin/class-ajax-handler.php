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
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
            return;
        }
        
        try {
            $collections = [];
            $endpoint = rest_url('tainacan/v2/collections');
            
            $args = [
                'timeout' => 30,
                'headers' => ['Content-Type' => 'application/json']
            ];
            
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
                        $items_endpoint = rest_url("tainacan/v2/collection/{$col['id']}/items");
                        $items_endpoint = add_query_arg(['perpage' => 1], $items_endpoint);
                        
                        $items_response = wp_remote_get($items_endpoint, ['timeout' => 10]);
                        $total_items = 0;
                        
                        if (!is_wp_error($items_response)) {
                            $headers = wp_remote_retrieve_headers($items_response);
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
            
            wp_send_json_success($collections);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Erro ao carregar coleções: ', 'tainacan-explorador') . $e->getMessage()]);
        }
    }
    
    /**
     * Limpa cache de coleção
     */
    public function clear_collection_cache() {
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
            return;
        }
        
        $collection_id = intval($_POST['collection_id'] ?? 0);
        if ($collection_id) {
            TEI_Cache_Manager::clear_collection_cache($collection_id);
        }
        
        wp_send_json_success();
    }
    
    /**
     * Obtém metadados de uma coleção (sem duplicação)
     */
    public function get_metadata() {
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
            // Limpa cache para buscar dados atualizados
            TEI_Cache_Manager::delete('tei_metadata_' . $collection_id);
            
            // Usa TEI_API_Handler existente
            $api_handler = new TEI_API_Handler();
            $metadata = $api_handler->get_collection_metadata($collection_id);
            
            if (is_wp_error($metadata)) {
                wp_send_json_error(['message' => $metadata->get_error_message()]);
                return;
            }
            
            // Adiciona campos especiais SEM duplicar
            $special_fields = [];
            $existing_slugs = array_column($metadata, 'slug');
            
            if (!in_array('thumbnail', $existing_slugs)) {
                $special_fields[] = ['id' => 'thumbnail', 'name' => __('Miniatura', 'tainacan-explorador'), 'type' => 'image', 'slug' => 'thumbnail'];
            }
            if (!in_array('document', $existing_slugs)) {
                $special_fields[] = ['id' => 'document', 'name' => __('Documento', 'tainacan-explorador'), 'type' => 'attachment', 'slug' => 'document'];
            }
            if (!in_array('_attachments', $existing_slugs)) {
                $special_fields[] = ['id' => '_attachments', 'name' => __('Anexos', 'tainacan-explorador'), 'type' => 'attachments', 'slug' => '_attachments'];
            }
            
            // Adiciona título e descrição apenas se não existirem
            $has_title = false;
            $has_description = false;
            
            foreach ($metadata as $meta) {
                if (strtolower($meta['name']) === 'título' || strtolower($meta['name']) === 'title') {
                    $has_title = true;
                }
                if (strtolower($meta['name']) === 'descrição' || strtolower($meta['name']) === 'description') {
                    $has_description = true;
                }
            }
            
            if (!$has_title) {
                $special_fields[] = ['id' => 'title', 'name' => __('Título', 'tainacan-explorador'), 'type' => 'text', 'slug' => 'title'];
            }
            if (!$has_description) {
                $special_fields[] = ['id' => 'description', 'name' => __('Descrição', 'tainacan-explorador'), 'type' => 'textarea', 'slug' => 'description'];
            }
            
            $metadata = array_merge($metadata, $special_fields);
            
            // Obtém mapeamentos existentes
            $existing_mappings = [];
            foreach (['map', 'timeline', 'story'] as $type) {
                $mapping = TEI_Metadata_Mapper::get_mapping($collection_id, $type);
                if ($mapping) {
                    $existing_mappings[$type] = $mapping;
                }
            }
            
            wp_send_json_success([
                'metadata' => $metadata,
                'mappings' => $existing_mappings
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Erro ao carregar metadados: ', 'tainacan-explorador') . $e->getMessage()]);
        }
    }
    
    /**
     * Salva mapeamento
     */
    public function save_mapping() {
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
            return;
        }
        
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
        
        // Sanitiza mapeamento preservando IDs
        $sanitized_mapping = [];
        if (is_array($mapping_data)) {
            foreach ($mapping_data as $key => $value) {
                $sanitized_mapping[sanitize_key($key)] = $value;
            }
        }
        
        // Sanitiza filtros
        $sanitized_filters = [];
        if (is_array($filter_rules)) {
            foreach ($filter_rules as $rule) {
                if (is_array($rule) && !empty($rule['metadatum'])) {
                    $sanitized_filters[] = [
                        'metadatum' => $rule['metadatum'],
                        'operator' => sanitize_text_field($rule['operator'] ?? '='),
                        'value' => sanitize_text_field($rule['value'] ?? '')
                    ];
                }
            }
        }
        
        $result = TEI_Metadata_Mapper::save_mapping([
            'collection_id' => $collection_id,
            'collection_name' => $collection_name,
            'mapping_type' => $mapping_type,
            'mapping_data' => $sanitized_mapping,
            'visualization_settings' => $visualization_settings,
            'filter_rules' => $sanitized_filters
        ]);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }
        
        TEI_Cache_Manager::clear_collection_cache($collection_id);
        
        wp_send_json_success(['message' => __('Mapeamento salvo com sucesso!', 'tainacan-explorador'), 'mapping_id' => $result]);
    }
    
    /**
     * Obtém todos os mapeamentos
     */
    public function get_all_mappings() {
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
            return;
        }
        
        $mappings = TEI_Metadata_Mapper::get_all_mappings();
        
        wp_send_json_success([
            'mappings' => $mappings,
            'total' => count($mappings)
        ]);
    }
    
    /**
     * Deleta mapeamento
     */
    public function delete_mapping() {
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
