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
        }
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
        }
        
        $api = new TEI_API_Handler();
        $collections = $api->get_collections();
        
        if (is_wp_error($collections)) {
            wp_send_json_error([
                'message' => $collections->get_error_message()
            ]);
        }
        
        wp_send_json_success($collections);
    }
    
    /**
     * Obtém metadados de uma coleção
     */
    public function get_metadata() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
        }
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
        }
        
        $collection_id = intval($_POST['collection_id'] ?? 0);
        
        if (!$collection_id) {
            wp_send_json_error(['message' => __('ID da coleção inválido', 'tainacan-explorador')]);
        }
        
        $api = new TEI_API_Handler();
        $metadata = $api->get_collection_metadata($collection_id);
        
        if (is_wp_error($metadata)) {
            wp_send_json_error([
                'message' => $metadata->get_error_message()
            ]);
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
    }
    
    /**
     * Salva mapeamento
     */
    public function save_mapping() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
        }
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
        }
        
        // Valida dados
        $collection_id = intval($_POST['collection_id'] ?? 0);
        $collection_name = sanitize_text_field($_POST['collection_name'] ?? '');
        $mapping_type = sanitize_key($_POST['mapping_type'] ?? '');
        $mapping_data = $_POST['mapping_data'] ?? [];
        $visualization_settings = $_POST['visualization_settings'] ?? [];
        
        if (!$collection_id || !$mapping_type) {
            wp_send_json_error(['message' => __('Dados inválidos', 'tainacan-explorador')]);
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
        }
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
        }
        
        $mapping_id = intval($_POST['mapping_id'] ?? 0);
        
        if (!$mapping_id) {
            wp_send_json_error(['message' => __('ID do mapeamento inválido', 'tainacan-explorador')]);
        }
        
        $result = TEI_Metadata_Mapper::delete_mapping($mapping_id);
        
        if (!$result) {
            wp_send_json_error(['message' => __('Erro ao deletar mapeamento', 'tainacan-explorador')]);
        }
        
        wp_send_json_success([
            'message' => __('Mapeamento deletado com sucesso!', 'tainacan-explorador')
        ]);
    }
    
    /**
     * Testa visualização
     */
    public function test_visualization() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
        }
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
        }
        
        $collection_id = intval($_POST['collection_id'] ?? 0);
        $visualization_type = sanitize_key($_POST['type'] ?? '');
        
        if (!$collection_id || !$visualization_type) {
            wp_send_json_error(['message' => __('Dados inválidos', 'tainacan-explorador')]);
        }
        
        // Verifica se existe mapeamento
        $mapping = TEI_Metadata_Mapper::get_mapping($collection_id, $visualization_type);
        
        if (!$mapping) {
            wp_send_json_error(['message' => __('Mapeamento não configurado', 'tainacan-explorador')]);
        }
        
        // Obtém alguns itens para teste
        $api = new TEI_API_Handler();
        $items = $api->get_collection_items($collection_id, [
            'perpage' => 10,
            'fetch_only_meta' => implode(',', array_values($mapping['mapping_data']))
        ]);
        
        if (is_wp_error($items)) {
            wp_send_json_error(['message' => $items->get_error_message()]);
        }
        
        // Gera URL de preview
        $preview_url = add_query_arg([
            'tei_preview' => 1,
            'collection' => $collection_id,
            'type' => $visualization_type,
            'nonce' => wp_create_nonce('tei_preview_' . $collection_id)
        ], home_url());
        
        wp_send_json_success([
            'preview_url' => $preview_url,
            'items_count' => $items['total'] ?? 0,
            'sample_data' => array_slice($items['items'] ?? [], 0, 3)
        ]);
    }
    
    /**
     * Obtém todos os mapeamentos
     */
    public function get_all_mappings() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
        }
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
        }
        
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
    }
    
    /**
     * Clona mapeamento
     */
    public function clone_mapping() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
        }
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
        }
        
        $mapping_id = intval($_POST['mapping_id'] ?? 0);
        $target_collection_id = intval($_POST['target_collection_id'] ?? 0);
        
        if (!$mapping_id) {
            wp_send_json_error(['message' => __('ID do mapeamento inválido', 'tainacan-explorador')]);
        }
        
        // Se não especificou coleção destino, clona para a mesma coleção
        if (!$target_collection_id) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'tei_metadata_mappings';
            $original = $wpdb->get_row($wpdb->prepare(
                "SELECT collection_id FROM $table_name WHERE id = %d",
                $mapping_id
            ));
            
            if ($original) {
                $target_collection_id = $original->collection_id;
            }
        }
        
        $new_mapping_id = TEI_Metadata_Mapper::clone_mapping($mapping_id, $target_collection_id);
        
        if (!$new_mapping_id) {
            wp_send_json_error(['message' => __('Erro ao clonar mapeamento', 'tainacan-explorador')]);
        }
        
        wp_send_json_success([
            'message' => __('Mapeamento clonado com sucesso!', 'tainacan-explorador'),
            'new_mapping_id' => $new_mapping_id
        ]);
    }
    
    /**
     * Exporta mapeamentos
     */
    public function export_mappings() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
        }
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
        }
        
        $collection_id = intval($_POST['collection_id'] ?? 0);
        
        $export_data = TEI_Metadata_Mapper::export_mappings($collection_id);
        
        wp_send_json_success($export_data);
    }
    
    /**
     * Importa mapeamentos
     */
    public function import_mappings() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
        }
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
        }
        
        $import_data = $_POST['import_data'] ?? '';
        
        if (!$import_data) {
            wp_send_json_error(['message' => __('Dados de importação inválidos', 'tainacan-explorador')]);
        }
        
        // Decodifica JSON
        $data = json_decode(stripslashes($import_data), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('Formato JSON inválido', 'tainacan-explorador')]);
        }
        
        $result = TEI_Metadata_Mapper::import_mappings($data);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => sprintf(__('%d mapeamentos importados com sucesso!', 'tainacan-explorador'), $result),
            'imported_count' => $result
        ]);
    }
    
    /**
     * Limpa cache
     */
    public function clear_cache() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
        }
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
        }
        
        $type = sanitize_key($_POST['type'] ?? 'all');
        $collection_id = intval($_POST['collection_id'] ?? 0);
        
        if ($type === 'collection' && $collection_id) {
            TEI_Cache_Manager::clear_collection_cache($collection_id);
            $message = __('Cache da coleção limpo com sucesso!', 'tainacan-explorador');
        } else {
            TEI_Cache_Manager::clear_all();
            $message = __('Todo o cache foi limpo com sucesso!', 'tainacan-explorador');
        }
        
        wp_send_json_success(['message' => $message]);
    }
    
    /**
     * Obtém estatísticas do sistema
     */
    public function get_stats() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
        }
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'tei_metadata_mappings';
        
        // Estatísticas de mapeamentos
        $total_mappings = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'active'");
        
        $mappings_by_type = $wpdb->get_results(
            "SELECT mapping_type, COUNT(*) as count 
             FROM $table_name 
             WHERE status = 'active' 
             GROUP BY mapping_type",
            ARRAY_A
        );
        
        // Estatísticas de cache
        $cache_stats = TEI_Cache_Manager::get_stats();
        
        // Estatísticas de uso
        $shortcode_usage = [
            'map' => $this->count_shortcode_usage('tainacan_explorador_mapa'),
            'timeline' => $this->count_shortcode_usage('tainacan_explorador_timeline'),
            'story' => $this->count_shortcode_usage('tainacan_explorador_story')
        ];
        
        wp_send_json_success([
            'mappings' => [
                'total' => intval($total_mappings),
                'by_type' => $mappings_by_type
            ],
            'cache' => $cache_stats,
            'usage' => $shortcode_usage,
            'system' => [
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => TEI_VERSION,
                'memory_usage' => size_format(memory_get_usage()),
                'memory_limit' => ini_get('memory_limit')
            ]
        ]);
    }
    
    /**
     * Conta uso de shortcode
     */
    private function count_shortcode_usage($shortcode) {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_status = 'publish' 
             AND (post_type = 'post' OR post_type = 'page')
             AND post_content LIKE %s",
            '%[' . $shortcode . '%'
        ));
        
        return intval($count);
    }
    
    /**
     * Valida API do Tainacan
     */
    public function validate_tainacan_api() {
        // Verifica nonce
        if (!check_ajax_referer('tei_admin', 'nonce', false)) {
            wp_send_json_error(['message' => __('Nonce inválido', 'tainacan-explorador')]);
        }
        
        // Verifica permissões
        if (!current_user_can('manage_tainacan_explorer')) {
            wp_send_json_error(['message' => __('Permissão negada', 'tainacan-explorador')]);
        }
        
        $api = new TEI_API_Handler();
        $result = $api->validate_connection();
        
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => __('Erro ao conectar com a API do Tainacan', 'tainacan-explorador'),
                'details' => $result->get_error_message()
            ]);
        }
        
        wp_send_json_success([
            'message' => __('Conexão com Tainacan estabelecida com sucesso!', 'tainacan-explorador'),
            'api_status' => 'connected'
        ]);
    }
}
