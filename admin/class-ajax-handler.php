<?php
/**
 * Handler para requisições AJAX
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_Ajax_Handler {
    
    /**
     * Obtém coleções do Tainacan com contagem de itens
     */
    public function get_collections() {
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
            return;
        }
        
        try {
            $api_handler = new TEI_API_Handler();
            $collections = $api_handler->get_collections();
            
            if (is_wp_error($collections)) {
                wp_send_json_error(['message' => $collections->get_error_message()]);
                return;
            }
            
            wp_send_json_success($collections);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => __('Erro ao carregar coleções: ', 'tainacan-explorador') . $e->getMessage()]);
        }
    }
    
    /**
     * Obtém metadados de uma coleção
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
            TEI_Cache_Manager::delete('metadata_' . $collection_id);
            
            $api_handler = new TEI_API_Handler();
            $metadata = $api_handler->get_collection_metadata($collection_id);
            
            if (is_wp_error($metadata)) {
                wp_send_json_error(['message' => $metadata->get_error_message()]);
                return;
            }
            
            // Adiciona campos especiais
            $special_fields = [
                ['id' => 'thumbnail', 'name' => __('Miniatura', 'tainacan-explorador'), 'type' => 'image', 'slug' => 'thumbnail'],
                ['id' => 'document', 'name' => __('Documento', 'tainacan-explorador'), 'type' => 'attachment', 'slug' => 'document'],
                ['id' => 'title', 'name' => __('Título', 'tainacan-explorador'), 'type' => 'text', 'slug' => 'title'],
                ['id' => 'description', 'name' => __('Descrição', 'tainacan-explorador'), 'type' => 'textarea', 'slug' => 'description']
            ];
            
            // Remove duplicatas
            $existing_ids = array_column($metadata, 'id');
            foreach ($special_fields as $field) {
                if (!in_array($field['id'], $existing_ids)) {
                    $metadata[] = $field;
                }
            }
            
            // Obtém mapeamentos existentes para TODAS as visualizações
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
     * Salva mapeamento - PERMITE MÚLTIPLOS POR COLEÇÃO
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
                $sanitized_key = sanitize_key($key);
                // Preserva IDs numéricos e strings
                $sanitized_mapping[$sanitized_key] = is_numeric($value) ? $value : sanitize_text_field($value);
            }
        }
        
        // Sanitiza configurações
        $sanitized_settings = [];
        if (is_array($visualization_settings)) {
            foreach ($visualization_settings as $key => $value) {
                $sanitized_key = sanitize_key($key);
                if (is_array($value)) {
                    $sanitized_settings[$sanitized_key] = array_map('sanitize_text_field', $value);
                } else {
                    $sanitized_settings[$sanitized_key] = sanitize_text_field($value);
                }
            }
        }
        
        // Sanitiza filtros
        $sanitized_filters = [];
        if (is_array($filter_rules)) {
            foreach ($filter_rules as $rule) {
                if (is_array($rule) && !empty($rule['metadatum'])) {
                    $sanitized_filters[] = [
                        'metadatum' => is_numeric($rule['metadatum']) ? $rule['metadatum'] : sanitize_text_field($rule['metadatum']),
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
            'visualization_settings' => $sanitized_settings,
            'filter_rules' => $sanitized_filters
        ]);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }
        
        // Limpa cache da coleção
        TEI_Cache_Manager::clear_collection_cache($collection_id);
        
        wp_send_json_success([
            'message' => __('Mapeamento salvo com sucesso!', 'tainacan-explorador'),
            'mapping_id' => $result
        ]);
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
        
        // Obtém informações do mapeamento antes de deletar
        $mapping_info = TEI_Metadata_Mapper::get_mapping_by_id($mapping_id);
        
        $result = TEI_Metadata_Mapper::delete_mapping($mapping_id);
        
        if (!$result) {
            wp_send_json_error(['message' => __('Erro ao deletar', 'tainacan-explorador')]);
            return;
        }
        
        // Limpa cache da coleção
        if ($mapping_info && isset($mapping_info['collection_id'])) {
            TEI_Cache_Manager::clear_collection_cache($mapping_info['collection_id']);
        }
        
        wp_send_json_success(['message' => __('Deletado com sucesso!', 'tainacan-explorador')]);
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
            wp_send_json_success(['message' => __('Cache da coleção limpo!', 'tainacan-explorador')]);
        } else {
            TEI_Cache_Manager::clear_all();
            wp_send_json_success(['message' => __('Todo o cache foi limpo!', 'tainacan-explorador')]);
        }
    }
}
