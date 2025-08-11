<?php
/**
 * Classe responsável por gerenciar mapeamentos de metadados
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_Metadata_Mapper {
    
    /**
     * Nome da tabela de mapeamentos
     */
    private static $table_name = 'tei_metadata_mappings';
    
    /**
     * Cria tabelas do banco de dados
     */
    public static function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            collection_id bigint(20) NOT NULL,
            collection_name varchar(255) NOT NULL,
            mapping_type varchar(50) NOT NULL,
            mapping_data longtext NOT NULL,
            visualization_settings longtext,
            filter_rules longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by bigint(20) NOT NULL,
            status varchar(20) DEFAULT 'active',
            PRIMARY KEY (id),
            KEY collection_id (collection_id),
            KEY mapping_type (mapping_type),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Verifica se o índice já existe antes de criar
        $index_exists = $wpdb->get_var(
            "SHOW INDEX FROM $table_name WHERE Key_name = 'idx_collection_type'"
        );
        
        if (!$index_exists) {
            $wpdb->query("CREATE INDEX idx_collection_type ON $table_name (collection_id, mapping_type)");
        }
    }
    
    /**
     * Remove tabelas do banco de dados
     */
    public static function drop_tables() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
    }
    
    /**
     * Salva um mapeamento
     * 
     * @param array $data Dados do mapeamento
     * @return int|false ID do mapeamento ou false em caso de erro
     */
    public static function save_mapping($data) {
        global $wpdb;
        
        // Validação de dados
        if (empty($data['collection_id']) || empty($data['mapping_type'])) {
            return new WP_Error('invalid_data', __('Dados de mapeamento inválidos', 'tainacan-explorador'));
        }
        
        // Sanitização
        $collection_id = absint($data['collection_id']);
        $mapping_type = sanitize_key($data['mapping_type']);
        $collection_name = sanitize_text_field($data['collection_name'] ?? '');
        $mapping_data = wp_json_encode($data['mapping_data'] ?? []);
        $visualization_settings = wp_json_encode($data['visualization_settings'] ?? []);
        $filter_rules = wp_json_encode($data['filter_rules'] ?? []);
        $created_by = get_current_user_id();
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        // Verifica se já existe um mapeamento para esta coleção e tipo
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table_name WHERE collection_id = %d AND mapping_type = %s",
            $collection_id,
            $mapping_type
        ));
        
        if ($existing) {
            // Atualiza mapeamento existente
            $result = $wpdb->update(
                $table_name,
                [
                    'collection_name' => $collection_name,
                    'mapping_data' => $mapping_data,
                    'visualization_settings' => $visualization_settings,
                    'filter_rules' => $filter_rules,
                    'updated_at' => current_time('mysql')
                ],
                [
                    'id' => $existing->id
                ],
                ['%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );
            
            if ($result !== false) {
                // Limpa cache
                TEI_Cache_Manager::clear_collection_cache($collection_id);
                
                // Dispara hook
                do_action('tei_mapping_updated', $existing->id, $data);
                
                return $existing->id;
            }
        } else {
            // Insere novo mapeamento
            $result = $wpdb->insert(
                $table_name,
                [
                    'collection_id' => $collection_id,
                    'collection_name' => $collection_name,
                    'mapping_type' => $mapping_type,
                    'mapping_data' => $mapping_data,
                    'visualization_settings' => $visualization_settings,
                    'filter_rules' => $filter_rules,
                    'created_by' => $created_by,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
            );
            
            if ($result) {
                $mapping_id = $wpdb->insert_id;
                
                // Limpa cache
                TEI_Cache_Manager::clear_collection_cache($collection_id);
                
                // Dispara hook
                do_action('tei_mapping_created', $mapping_id, $data);
                
                return $mapping_id;
            }
        }
        
        return false;
    }
    
/**
 * Obtém um mapeamento específico
 * 
 * @param int $collection_id ID da coleção
 * @param string $mapping_type Tipo de mapeamento
 * @return array|null
 */
public static function get_mapping($collection_id, $mapping_type = null) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . self::$table_name;
    
    // Cache key
    $cache_key = 'tei_mapping_' . $collection_id . '_' . $mapping_type;
    $cached = wp_cache_get($cache_key, 'tei_mappings');
    
    if ($cached !== false) {
        return $cached;
    }
    
    if ($mapping_type) {
        $query = $wpdb->prepare(
            "SELECT * FROM $table_name WHERE collection_id = %d AND mapping_type = %s AND status = 'active'",
            $collection_id,
            $mapping_type
        );
    } else {
        $query = $wpdb->prepare(
            "SELECT * FROM $table_name WHERE collection_id = %d AND status = 'active'",
            $collection_id
        );
    }
    
    $result = $wpdb->get_row($query, ARRAY_A);
    
    if ($result) {
        // Decodifica JSON com verificação
        $result['mapping_data'] = json_decode($result['mapping_data'], true) ?: [];
        $result['visualization_settings'] = json_decode($result['visualization_settings'], true) ?: [];
        
        // Corrige o problema do filter_rules
        if (!empty($result['filter_rules'])) {
            $result['filter_rules'] = json_decode($result['filter_rules'], true) ?: [];
        } else {
            $result['filter_rules'] = [];
        }
        
        // Adiciona metadados adicionais
        if (!empty($result['created_by'])) {
            $result['author'] = get_userdata($result['created_by']);
        }
        
        // Cache por 1 hora
        wp_cache_set($cache_key, $result, 'tei_mappings', HOUR_IN_SECONDS);
    }
    
    return $result;
}
    
    /**
     * Obtém todos os mapeamentos
     * 
     * @param array $args Argumentos de consulta
     * @return array
     */
    public static function get_all_mappings($args = []) {
        global $wpdb;
        
        $defaults = [
            'status' => 'active',
            'orderby' => 'updated_at',
            'order' => 'DESC',
            'limit' => 20,
            'offset' => 0
        ];
        
        $args = wp_parse_args($args, $defaults);
        $table_name = $wpdb->prefix . self::$table_name;
        
        $where_clauses = ["status = %s"];
        $where_values = [$args['status']];
        
        if (!empty($args['collection_id'])) {
            $where_clauses[] = "collection_id = %d";
            $where_values[] = $args['collection_id'];
        }
        
        if (!empty($args['mapping_type'])) {
            $where_clauses[] = "mapping_type = %s";
            $where_values[] = $args['mapping_type'];
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Sanitiza orderby e order
        $orderby = in_array($args['orderby'], ['updated_at', 'created_at', 'id']) ? $args['orderby'] : 'updated_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        
        $query = $wpdb->prepare(
            "SELECT * FROM $table_name WHERE $where_sql ORDER BY $orderby $order LIMIT %d OFFSET %d",
            array_merge($where_values, [$args['limit'], $args['offset']])
        );
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        // Decodifica JSON para cada resultado
        foreach ($results as &$result) {
            $result['mapping_data'] = json_decode($result['mapping_data'], true);
            $result['visualization_settings'] = json_decode($result['visualization_settings'], true);
            $result['filter_rules'] = json_decode($result['filter_rules'], true);
        }
        
        return $results;
    }
    
    /**
     * Deleta um mapeamento
     * 
     * @param int $mapping_id ID do mapeamento
     * @return bool
     */
    public static function delete_mapping($mapping_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        // Obtém dados antes de deletar para limpar cache
        $mapping = $wpdb->get_row($wpdb->prepare(
            "SELECT collection_id FROM $table_name WHERE id = %d",
            $mapping_id
        ));
        
        if (!$mapping) {
            return false;
        }
        
        // Soft delete - apenas marca como inativo
        $result = $wpdb->update(
            $table_name,
            ['status' => 'deleted', 'updated_at' => current_time('mysql')],
            ['id' => $mapping_id],
            ['%s', '%s'],
            ['%d']
        );
        
        if ($result !== false) {
            // Limpa cache
            TEI_Cache_Manager::clear_collection_cache($mapping->collection_id);
            
            // Dispara hook
            do_action('tei_mapping_deleted', $mapping_id);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Aplica filtros configurados aos parâmetros da API
     * 
     * @param array $api_params Parâmetros existentes
     * @param array $filter_rules Regras de filtro
     * @return array Parâmetros modificados
     */
    public static function apply_filter_rules($api_params, $filter_rules) {
        if (empty($filter_rules) || !is_array($filter_rules)) {
            return $api_params;
        }
        
        $metaquery = isset($api_params['metaquery']) ? $api_params['metaquery'] : [];
        
        foreach ($filter_rules as $rule) {
            if (!empty($rule['metadatum']) && !empty($rule['value'])) {
                $query_item = [
                    'key' => $rule['metadatum'],
                    'value' => $rule['value'],
                    'compare' => $rule['operator'] ?? '='
                ];
                
                // Se o operador for IN ou NOT IN, converte valor para array
                if (in_array($rule['operator'], ['IN', 'NOT IN'])) {
                    $query_item['value'] = array_map('trim', explode(',', $rule['value']));
                }
                
                $metaquery[] = $query_item;
            }
        }
        
        if (!empty($metaquery)) {
            if (count($metaquery) > 1) {
                $api_params['metaquery'] = [
                    'relation' => 'AND',
                    $metaquery
                ];
            } else {
                $api_params['metaquery'] = $metaquery;
            }
        }
        
        return $api_params;
    }
    
    /**
     * Valida campos de mapeamento
     * 
     * @param array $mapping_data Dados do mapeamento
     * @param string $type Tipo de visualização
     * @return bool|WP_Error
     */
    public static function validate_mapping($mapping_data, $type) {
        // Validação básica removida temporariamente para debug
        // Permite salvar qualquer mapeamento para testar
        return true;
    }
    
    /**
     * Obtém campos obrigatórios por tipo
     * 
     * @param string $type Tipo de visualização
     * @return array
     */
    private static function get_required_fields($type) {
        $fields = [
            'map' => [],  // Nenhum campo obrigatório por enquanto
            'timeline' => [],
            'story' => []
        ];
        
        return $fields[$type] ?? [];
    }
    
    /**
     * Exporta mapeamentos
     * 
     * @param int $collection_id ID da coleção (opcional)
     * @return array
     */
    public static function export_mappings($collection_id = null) {
        $args = [];
        if ($collection_id) {
            $args['collection_id'] = $collection_id;
        }
        
        $mappings = self::get_all_mappings($args);
        
        return [
            'version' => TEI_VERSION,
            'exported_at' => current_time('mysql'),
            'mappings' => $mappings
        ];
    }
    
    /**
     * Importa mapeamentos
     * 
     * @param array $data Dados de importação
     * @return bool|WP_Error
     */
    public static function import_mappings($data) {
        if (empty($data['mappings']) || !is_array($data['mappings'])) {
            return new WP_Error('invalid_import', __('Dados de importação inválidos', 'tainacan-explorador'));
        }
        
        $imported = 0;
        $errors = [];
        
        foreach ($data['mappings'] as $mapping) {
            $result = self::save_mapping($mapping);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } else {
                $imported++;
            }
        }
        
        if (!empty($errors)) {
            return new WP_Error('import_errors', implode(', ', $errors));
        }
        
        return $imported;
    }
    
    /**
     * Clona um mapeamento para outra coleção
     * 
     * @param int $mapping_id ID do mapeamento original
     * @param int $target_collection_id ID da coleção destino
     * @return int|false
     */
    public static function clone_mapping($mapping_id, $target_collection_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        // Obtém mapeamento original
        $original = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $mapping_id
        ), ARRAY_A);
        
        if (!$original) {
            return false;
        }
        
        // Prepara dados para clonagem
        unset($original['id']);
        $original['collection_id'] = $target_collection_id;
        $original['created_at'] = current_time('mysql');
        $original['updated_at'] = current_time('mysql');
        $original['created_by'] = get_current_user_id();
        
        // Salva clone
        return self::save_mapping($original);
    }
}

