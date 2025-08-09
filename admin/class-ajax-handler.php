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
            
            // Usa REST API do Tainacan
            $response = wp_remote_get(rest_url('tainacan/v2/collections'), [
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $tainacan_collections = json_decode($body, true);
                
                if (is_array($tainacan_collections)) {
                    foreach ($tainacan_collections as $col) {
                        // Busca contagem de itens
                        $items_response = wp_remote_get(rest_url("tainacan/v2/collection/{$col['id']}/items?perpage=1"), [
                            'timeout' => 10
                        ]);
                        
                        $total_items = 0;
                        if (!is_wp_error($items_response)) {
                            $headers = wp_remote_retrieve_headers($items_response);
                            $total_items = isset($headers['x-wp-total']) ? intval($headers['x-wp-total']) : 0;
                        }
                        
                        $collections[] = [
                            'id' => $col['id'],
                            'name' => $col['name'],
                            'items_count' => $total_items
                        ];
                    }
                }
            }
            
            // Fallback para query direta se API falhar
            if (empty($collections)) {
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
                        
                        // Conta itens
                        $items_count = wp_count_posts('tnc_col_' . $collection_id . '_item');
                        $total = isset($items_count->publish) ? $items_count->publish : 0;
                        
                        $collections[] = [
                            'id' => $collection_id,
                            'name' => get_the_title(),
                            'items_count' => $total
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
            $metadata = [];
            
            // Usa REST API do Tainacan para metadados
            $response = wp_remote_get(rest_url("tainacan/v2/collection/{$collection_id}/metadata"), [
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $tainacan_metadata = json_decode($body, true);
                
                if (is_array($tainacan_metadata)) {
                    foreach ($tainacan_metadata as $meta) {
                        // Pula metadados de sistema
                        if (isset($meta['metadata_type']) && $meta['metadata_type'] === 'Tainacan\\Metadata_Types\\Core_Title') {
                            continue;
                        }
                        if (isset($meta['metadata_type']) && $meta['metadata_type'] === 'Tainacan\\Metadata_Types\\Core_Description') {
                            continue;
                        }
                        
                        $metadata[] = [
                            'id' => $meta['id'],
                            'name' => $meta['name'],
                            'type' => isset($meta['metadata_type_object']) ? $meta['metadata_type_object']['name'] : 'text'
                        ];
                    }
                }
            }
            
            // Adiciona campos padrão sempre disponíveis
            array_unshift($metadata, 
                ['id' => 'title', 'name' => __('Título', 'tainacan-explorador'), 'type' => 'text'],
                ['id' => 'description', 'name' => __('Descrição', 'tainacan-explorador'), 'type' => 'text'],
                ['id' => 'thumbnail', 'name' => __('Miniatura', 'tainacan-explorador'), 'type' => 'image']
            );
            
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
                'mappings' => $existing_mappings
            ]);
            
        } catch (Exception $e) {
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
        
        if (!$collection_id || !$mapping_type) {
            wp_send_json_error(['message' => __('Dados inválidos', 'tainacan-explorador')]);
            return;
        }
        
        // Sanitiza dados do mapeamento
        $sanitized_mapping = [];
        if (is_array($mapping_data)) {
            foreach ($mapping_data as $key => $value) {
                $sanitized_mapping[sanitize_key($key)] = $value; // Não sanitiza o valor para preservar IDs numéricos
            }
        }
        
        // Sanitiza configurações de visualização
        $sanitized_settings = [];
        if (is_array($visualization_settings)) {
            foreach ($visualization_settings as $key => $value) {
                $sanitized_settings[sanitize_key($key)] = $value;
            }
        }
        
        // Salva mapeamento
        $result = TEI_Metadata_Mapper::save_mapping([
            'collection_id' => $collection_id,
            'collection_name' => $collection_name,
            'mapping_type' => $mapping_type,
            'mapping_data' => $sanitized_mapping,
            'visualization_settings' => $sanitized_settings
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
