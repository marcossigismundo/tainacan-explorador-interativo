/**
 * Salva mapeamento (método modificado - linha ~117)
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
    $filter_rules = $_POST['filter_rules'] ?? []; // NOVO
    
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
    
    // NOVO: Sanitiza regras de filtro
    $sanitized_filters = [];
    if (is_array($filter_rules)) {
        foreach ($filter_rules as $rule) {
            if (is_array($rule)) {
                $sanitized_filters[] = [
                    'metadatum' => sanitize_text_field($rule['metadatum'] ?? ''),
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
        'filter_rules' => $sanitized_filters // NOVO
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
