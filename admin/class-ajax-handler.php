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

// Proteção contra inclusão múltipla
if (!defined('TEI_AJAX_HANDLER_LOADED')) {
    define('TEI_AJAX_HANDLER_LOADED', true);

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
                if (!current_user_can('edit_posts')) {
                    wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
                    return;
                }
            }
            
            try {
                $collections = [];
                
                // Método 1: WP_Query direto para post type Tainacan
                $args = [
                    'post_type' => 'tainacan-collection',
                    'posts_per_page' => -1,
                    'post_status' => 'publish'
                ];
                
                $query = new WP_Query($args);
                
                if ($query->have_posts()) {
                    while ($query->have_posts()) {
                        $query->the_post();
                        $post_id = get_the_ID();
                        
                        // Conta itens da coleção
                        $items_count = 0;
                        $item_post_type = 'tnc_col_' . $post_id . '_item';
                        $count_posts = wp_count_posts($item_post_type);
                        if ($count_posts) {
                            $items_count = $count_posts->publish ?? 0;
                        }
                        
                        $collections[] = [
                            'id' => $post_id,
                            'name' => get_the_title(),
                            'items_count' => $items_count
                        ];
                    }
                    wp_reset_postdata();
                }
                
                wp_send_json_success($collections);
                
            } catch (Exception $e) {
                wp_send_json_error([
                    'message' => __('Erro ao carregar coleções: ', 'tainacan-explorador') . $e->getMessage()
                ]);
            }
            
            wp_die();
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
                if (!current_user_can('edit_posts')) {
                    wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
                    return;
                }
            }
            
            $collection_id = intval($_POST['collection_id'] ?? 0);
            
            if (!$collection_id) {
                wp_send_json_error(['message' => __('ID da coleção inválido', 'tainacan-explorador')]);
                return;
            }
            
            try {
                $metadata = [];
                
                // Busca metadados básicos
                global $wpdb;
                $post_type = 'tnc_col_' . $collection_id . '_item';
                
                // Campos padrão
                $metadata[] = [
                    'id' => 'post_title',
                    'name' => __('Título', 'tainacan-explorador'),
                    'type' => 'text'
                ];
                
                $metadata[] = [
                    'id' => 'post_content',
                    'name' => __('Descrição', 'tainacan-explorador'),
                    'type' => 'textarea'
                ];
                
                $metadata[] = [
                    'id' => 'post_date',
                    'name' => __('Data', 'tainacan-explorador'),
                    'type' => 'date'
                ];
                
                // Busca campos customizados
                $custom_fields = $wpdb->get_results($wpdb->prepare(
                    "SELECT DISTINCT meta_key 
                     FROM {$wpdb->postmeta} pm
                     JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                     WHERE p.post_type = %s
                     AND meta_key NOT LIKE '\_%'
                     LIMIT 50",
                    $post_type
                ));
                
                foreach ($custom_fields as $field) {
                    $metadata[] = [
                        'id' => $field->meta_key,
                        'name' => ucfirst(str_replace(['_', '-'], ' ', $field->meta_key)),
                        'type' => 'text'
                    ];
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
            
            wp_die();
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
            
            // Verifica permissões
            if (!current_user_can('manage_tainacan_explorer')) {
                if (!current_user_can('edit_posts')) {
                    wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
                    return;
                }
            }
            
            try {
                $page = intval($_POST['page'] ?? 1);
                $per_page = intval($_POST['per_page'] ?? 20);
                $search = sanitize_text_field($_POST['search'] ?? '');
                $filter_type = sanitize_key($_POST['filter_type'] ?? '');
                
                $args = [
                    'limit' => $per_page,
                    'offset' => ($page - 1) * $per_page
                ];
                
                if ($filter_type && $filter_type !== 'all') {
                    $args['mapping_type'] = $filter_type;
                }
                
                $mappings = TEI_Metadata_Mapper::get_all_mappings($args);
                
                // Filtra por busca se necessário
                if ($search) {
                    $mappings = array_filter($mappings, function($mapping) use ($search) {
                        return stripos($mapping['collection_name'], $search) !== false;
                    });
                }
                
                wp_send_json_success([
                    'mappings' => $mappings,
                    'total' => count($mappings),
                    'page' => $page,
                    'per_page' => $per_page
                ]);
                
            } catch (Exception $e) {
                wp_send_json_error([
                    'message' => __('Erro ao carregar mapeamentos: ', 'tainacan-explorador') . $e->getMessage()
                ]);
            }
            
            wp_die();
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
                if (!current_user_can('edit_posts')) {
                    wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
                    return;
                }
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
                    $sanitized_mapping[sanitize_key($key)] = sanitize_text_field($value);
                }
            }
            
            // Sanitiza configurações de visualização
            $sanitized_settings = [];
            if (is_array($visualization_settings)) {
                foreach ($visualization_settings as $key => $value) {
                    if (is_bool($value)) {
                        $sanitized_settings[sanitize_key($key)] = $value;
                    } elseif (is_numeric($value)) {
                        $sanitized_settings[sanitize_key($key)] = floatval($value);
                    } else {
                        $sanitized_settings[sanitize_key($key)] = sanitize_text_field($value);
                    }
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
            
            wp_die();
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
                if (!current_user_can('edit_posts')) {
                    wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
                    return;
                }
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
            
            wp_die();
        }
    }
}
