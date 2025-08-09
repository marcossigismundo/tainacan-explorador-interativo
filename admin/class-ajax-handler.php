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

// Previne redeclaração da classe
if (class_exists('TEI_Ajax_Handler')) {
    return;
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
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
            return;
        }
        
        try {
            // Método direto usando Tainacan PHP API
            $collections = [];
            
            // Verifica se o Tainacan está instalado
            if (!class_exists('\Tainacan\Repositories\Collections')) {
                // Tenta carregar o Tainacan
                $tainacan_plugin_path = WP_PLUGIN_DIR . '/tainacan/tainacan.php';
                if (file_exists($tainacan_plugin_path)) {
                    include_once $tainacan_plugin_path;
                }
            }
            
            if (class_exists('\Tainacan\Repositories\Collections')) {
                $repository = \Tainacan\Repositories\Collections::get_instance();
                
                $args = [
                    'posts_per_page' => -1,
                    'post_status' => 'publish'
                ];
                
                $tainacan_collections = $repository->fetch($args, 'OBJECT');
                
                if ($tainacan_collections) {
                    foreach ($tainacan_collections as $col) {
                        $collections[] = [
                            'id' => $col->get_id(),
                            'name' => $col->get_name(),
                            'items_count' => $repository->get_items_count($col->get_id())
                        ];
                    }
                }
            } else {
                // Fallback usando WP_Query direto
                $args = [
                    'post_type' => 'tainacan-collection',
                    'posts_per_page' => -1,
                    'post_status' => 'publish'
                ];
                
                $query = new WP_Query($args);
                
                if ($query->have_posts()) {
                    while ($query->have_posts()) {
                        $query->the_post();
                        $collections[] = [
                            'id' => get_the_ID(),
                            'name' => get_the_title(),
                            'items_count' => wp_count_posts('tnc_col_' . get_the_ID() . '_item')->publish ?? 0
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
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
            return;
        }
        
        $collection_id = intval($_POST['collection_id'] ?? 0);
        
        if (!$collection_id) {
            wp_send_json_error(['message' => __('ID da coleção inválido', 'tainacan-explorador')]);
            return;
        }
        
        try {
            $metadata = [];
            
            if (class_exists('\Tainacan\Repositories\Metadata')) {
                $repository = \Tainacan\Repositories\Metadata::get_instance();
                $collection = new \Tainacan\Entities\Collection($collection_id);
                
                $args = [
                    'include_disabled' => false
                ];
                
                $tainacan_metadata = $repository->fetch_by_collection($collection, $args, 'OBJECT');
                
                if ($tainacan_metadata) {
                    foreach ($tainacan_metadata as $meta) {
                        $metadata[] = [
                            'id' => $meta->get_id(),
                            'name' => $meta->get_name(),
                            'type' => $meta->get_metadata_type()
                        ];
                    }
                }
            } else {
                // Fallback - busca metadados do post type
                global $wpdb;
                
                $post_type = 'tnc_col_' . $collection_id . '_item';
                
                // Busca campos customizados usados neste post type
                $custom_fields = $wpdb->get_results($wpdb->prepare(
                    "SELECT DISTINCT meta_key 
                     FROM {$wpdb->postmeta} pm
                     JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                     WHERE p.post_type = %s
                     AND meta_key NOT LIKE '\_%'",
                    $post_type
                ));
                
                foreach ($custom_fields as $field) {
                    $metadata[] = [
                        'id' => $field->meta_key,
                        'name' => ucfirst(str_replace('_', ' ', $field->meta_key)),
                        'type' => 'text'
                    ];
                }
            }
            
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
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
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
        foreach ($mapping_data as $key => $value) {
            $sanitized_mapping[sanitize_key($key)] = sanitize_text_field($value);
        }
        
        // Sanitiza configurações de visualização
        $sanitized_settings = [];
        foreach ($visualization_settings as $key => $value) {
            if (is_bool($value)) {
                $sanitized_settings[sanitize_key($key)] = $value;
            } elseif (is_numeric($value)) {
                $sanitized_settings[sanitize_key($key)] = floatval($value);
            } else {
                $sanitized_settings[sanitize_key($key)] = sanitize_text_field($value);
            }
        }
        
        // Valida mapeamento
        $validation = TEI_Metadata_Mapper::validate_mapping($sanitized_mapping, $mapping_type);
        if (is_wp_error($validation)) {
            wp_send_json_error(['message' => $validation->get_error_message()]);
            return;
        }
        
        // Salva mapeamento
        $result = TEI_Metadata_Mapper::save_mapping([
            'collection_id' => $collection_id,
            'collection_name' => $collection_name,
            'mapping_type' => $mapping_type,
            'mapping_data' => $sanitized_mapping,
            'visualization_settings' => $sanitized_settings
        ]);
        
        if (!$result) {
            wp_send_json_error(['message' => __('Erro ao salvar mapeamento', 'tainacan-explorador')]);
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
     * Deleta mapeamento
     */
    public function delete_mapping() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
            return;
        }
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
            return;
        }
        
        $mapping_id = intval($_POST['mapping_id'] ?? 0);
        
        if (!$mapping_id) {
            wp_send_json_error(['message' => __('ID do mapeamento inválido', 'tainacan-explorador')]);
            return;
        }
        
        $result = TEI_Metadata_Mapper::delete_mapping($mapping_id);
        
        if (!$result) {
            wp_send_json_error(['message' => __('Erro ao deletar mapeamento', 'tainacan-explorador')]);
            return;
        }
        
        wp_send_json_success([
            'message' => __('Mapeamento deletado com sucesso!', 'tainacan-explorador')
        ]);
    }
    
    /**
     * Outros métodos continuam conforme original...
     */
}
