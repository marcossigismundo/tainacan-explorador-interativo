<?php
/**
 * Mapeador de Metadados
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_Metadata_Mapper {
    
    /**
     * Nome da tabela
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
            status varchar(20) DEFAULT 'active',
            created_by bigint(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY collection_id (collection_id),
            KEY mapping_type (mapping_type),
            KEY status (status),
            KEY collection_type (collection_id, mapping_type)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Adiciona índices adicionais se não existirem
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_collection_status ON $table_name (collection_id, status)");
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_updated_at ON $table_name (updated_at)");
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
     * Salva mapeamento
     * 
     * @param array $data Dados do mapeamento
     * @return int|WP_Error ID do mapeamento ou erro
     */
    public static function save_mapping($data) {
        global $wpdb;
        
        // Validação básica
        if (empty($data['collection_id']) || empty($data['mapping_type'])) {
            return new WP_Error('invalid_data', __('Dados de mapeamento inválidos', 'tainacan-explorador'));
        }
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        // Prepara dados
        $collection_id = intval($data['collection_id']);
        $collection_name = sanitize_text_field($data['collection_name'] ?? '');
        $mapping_type = sanitize_key($data['mapping_type']);
        $mapping_data = wp_json_encode($data['mapping_data'] ?? [], JSON_UNESCAPED_UNICODE);
        $visualization_settings = wp_json_encode($data['visualization_settings'] ?? [], JSON_UNESCAPED_UNICODE);
        $filter_rules = wp_json_encode($data['filter_rules'] ?? [], JSON_UNESCAPED_UNICODE);
        $created_by = get_current_user_id();
        
        // Verifica se já existe mapeamento
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name 
             WHERE collection_id = %d 
             AND mapping_type = %s 
             AND status = 'active'",
            $collection_id,
            $mapping_type
        ));
        
        if ($existing) {
            // Atualiza existente
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
                    'id' => $existing
                ],
                [
                    '%s', // collection_name
                    '%s', // mapping_data
                    '%s', // visualization_settings
                    '%s', // filter_rules
                    '%s'  // updated_at
                ],
                [
                    '%d'  // id
                ]
            );
            
            if ($result !== false) {
                // Limpa cache
                wp_cache_delete('tei_mapping_' . $collection_id . '_' . $mapping_type, 'tei_mappings');
                return intval($existing);
            }
        } else {
            // Insere novo
            $result = $wpdb->insert(
                $table_name,
                [
                    'collection_id' => $collection_id,
                    'collection_name' => $collection_name,
                    'mapping_type' => $mapping_type,
                    'mapping_data' => $mapping_data,
                    'visualization_settings' => $visualization_settings,
                    'filter_rules' => $filter_rules,
                    'status' => 'active',
                    'created_by' => $created_by,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                [
                    '%d', // collection_id
                    '%s', // collection_name
                    '%s', // mapping_type
                    '%s', // mapping_data
                    '%s', // visualization_settings
                    '%s', // filter_rules
                    '%s', // status
                    '%d', // created_by
                    '%s', // created_at
                    '%s'  // updated_at
                ]
            );
            
            if ($result !== false) {
                return intval($wpdb->insert_id);
            }
        }
        
        return new WP_Error('save_failed', __('Erro ao salvar mapeamento', 'tainacan-explorador'));
    }
    
    /**
     * Obtém mapeamento
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
        
        // Query segura usando prepared statement
        if ($mapping_type) {
            $result = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table_name 
                     WHERE collection_id = %d 
                     AND mapping_type = %s 
                     AND status = 'active'
                     ORDER BY updated_at DESC
                     LIMIT 1",
                    $collection_id,
                    $mapping_type
                ),
                ARRAY_A
            );
        } else {
            $result = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table_name 
                     WHERE collection_id = %d 
                     AND status = 'active'
                     ORDER BY updated_at DESC
                     LIMIT 1",
                    $collection_id
                ),
                ARRAY_A
            );
        }
        
        if ($result) {
            // Decodifica JSON com verificação
            $result['mapping_data'] = json_decode($result['mapping_data'], true) ?: [];
            $result['visualization_settings'] = json_decode($result['visualization_settings'], true) ?: [];
            $result['filter_rules'] = json_decode($result['filter_rules'], true) ?: [];
            
            // Adiciona metadados adicionais
            if (!empty($result['created_by'])) {
                $user = get_userdata($result['created_by']);
                if ($user) {
                    $result['author'] = [
                        'id' => $user->ID,
                        'name' => $user->display_name,
                        'email' => $user->user_email
                    ];
                }
            }
            
            // Cache por 1 hora
            wp_cache_set($cache_key, $result, 'tei_mappings', HOUR_IN_SECONDS);
        }
        
        return $result;
    }
    
    /**
     * Obtém mapeamento por ID
     * 
     * @param int $mapping_id ID do mapeamento
     * @return array|null
     */
    public static function get_mapping_by_id($mapping_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d",
                $mapping_id
            ),
            ARRAY_A
        );
        
        if ($result) {
            $result['mapping_data'] = json_decode($result['mapping_data'], true) ?: [];
            $result['visualization_settings'] = json_decode($result['visualization_settings'], true) ?: [];
            $result['filter_rules'] = json_decode($result['filter_rules'], true) ?: [];
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
        
        // Constrói query com prepared statements
        $query = "SELECT * FROM $table_name WHERE 1=1";
        $query_args = [];
        
        // Status
        if (!empty($args['status'])) {
            $query .= " AND status = %s";
            $query_args[] = $args['status'];
        }
        
        // Collection ID
        if (!empty($args['collection_id'])) {
            $query .= " AND collection_id = %d";
            $query_args[] = intval($args['collection_id']);
        }
        
        // Mapping type
        if (!empty($args['mapping_type'])) {
            $query .= " AND mapping_type = %s";
            $query_args[] = $args['mapping_type'];
        }
        
        // Ordenação (validada)
        $allowed_orderby = ['id', 'collection_id', 'mapping_type', 'created_at', 'updated_at'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'updated_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $query .= " ORDER BY $orderby $order";
        
        // Limite e offset
        $query .= " LIMIT %d OFFSET %d";
        $query_args[] = intval($args['limit']);
        $query_args[] = intval($args['offset']);
        
        // Executa query
        if (!empty($query_args)) {
            $results = $wpdb->get_results(
                $wpdb->prepare($query, ...$query_args),
                ARRAY_A
            );
        } else {
            $results = $wpdb->get_results($query, ARRAY_A);
        }
        
        // Decodifica JSON para cada resultado
        foreach ($results as &$result) {
            $result['mapping_data'] = json_decode($result['mapping_data'], true) ?: [];
            $result['visualization_settings'] = json_decode($result['visualization_settings'], true) ?: [];
            $result['filter_rules'] = json_decode($result['filter_rules'], true) ?: [];
        }
        
        return $results;
    }
    
    /**
     * Deleta mapeamento
     * 
     * @param int $mapping_id ID do mapeamento
     * @return bool
     */
    public static function delete_mapping($mapping_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        
        // Soft delete - apenas marca como inativo
        $result = $wpdb->update(
            $table_name,
            [
                'status' => 'deleted',
                'updated_at' => current_time('mysql')
            ],
            ['id' => $mapping_id],
            ['%s', '%s'],
            ['%d']
        );
        
        if ($result !== false) {
            // Limpa cache relacionado
            $mapping = self::get_mapping_by_id($mapping_id);
            if ($mapping) {
                $cache_key = 'tei_mapping_' . $mapping['collection_id'] . '_' . $mapping['mapping_type'];
                wp_cache_delete($cache_key, 'tei_mappings');
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * Aplica filtros aos parâmetros da API
     * 
     * @param array $api_params Parâmetros da API
     * @param array $filter_rules Regras de filtro
     * @return array
     */
    public static function apply_filters($api_params, $filter_rules) {
        if (empty($filter_rules) || !is_array($filter_rules)) {
            return $api_params;
        }
        
        $metaquery = [];
        
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
                    'queries' => $metaquery
                ];
            } else {
                $api_params['metaquery'] = $metaquery[0];
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
        // Campos obrigatórios por tipo
        $required_fields = self::get_required_fields($type);
        
        if (!empty($required_fields)) {
            foreach ($required_fields as $field) {
                if (empty($mapping_data[$field])) {
                    return new WP_Error(
                        'missing_field',
                        sprintf(__('Campo obrigatório ausente: %s', 'tainacan-explorador'), $field)
                    );
                }
            }
        }
        
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
            'map' => ['location'], // Apenas localização é obrigatória para mapa
            'timeline' => ['date'], // Apenas data é obrigatória para timeline
            'story' => [] // Nenhum campo obrigatório para story
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
        
        // Remove informações sensíveis
        foreach ($mappings as &$mapping) {
            unset($mapping['id']);
            unset($mapping['created_by']);
            unset($mapping['author']);
        }
        
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
     * @param bool $overwrite Sobrescrever existentes
     * @return array Resultado da importação
     */
    public static function import_mappings($data, $overwrite = false) {
        if (!isset($data['mappings']) || !is_array($data['mappings'])) {
            return [
                'success' => false,
                'message' => __('Dados de importação inválidos', 'tainacan-explorador')
            ];
        }
        
        $imported = 0;
        $skipped = 0;
        $errors = [];
        
        foreach ($data['mappings'] as $mapping) {
            // Verifica se já existe
            $existing = self::get_mapping(
                $mapping['collection_id'],
                $mapping['mapping_type']
            );
            
            if ($existing && !$overwrite) {
                $skipped++;
                continue;
            }
            
            // Salva mapeamento
            $result = self::save_mapping($mapping);
            
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } else {
                $imported++;
            }
        }
        
        return [
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }
}
