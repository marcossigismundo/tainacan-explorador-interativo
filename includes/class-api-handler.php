/**
 * Obtém itens de uma coleção
 * 
 * @param int $collection_id ID da coleção
 * @param array $params Parâmetros da consulta
 * @return array|WP_Error
 */
public function get_collection_items($collection_id, $params = []) {
    // Validação
    if (!$collection_id || !is_numeric($collection_id)) {
        return new WP_Error('invalid_collection', __('ID da coleção inválido', 'tainacan-explorador'));
    }
    
    // Cache check
    $cache_key = 'tei_items_' . $collection_id . '_' . md5(serialize($params));
    $cached = TEI_Cache_Manager::get($cache_key);
    
    if ($cached !== false) {
        return $cached;
    }
    
    // Tenta método direto do Tainacan PRIMEIRO (mais confiável)
    if (class_exists('\\Tainacan\\Repositories\\Items')) {
        try {
            $items_repo = \Tainacan\Repositories\Items::get_instance();
            $collection = new \Tainacan\Entities\Collection($collection_id);
            
            // Prepara argumentos
            $args = [
                'posts_per_page' => $params['perpage'] ?? 100,
                'paged' => $params['paged'] ?? 1,
                'order' => $params['order'] ?? 'DESC',
                'orderby' => $params['orderby'] ?? 'date',
                'post_status' => 'publish'
            ];
            
            // Aplica filtros de metadados se existirem
            if (isset($params['metaquery']) && is_array($params['metaquery'])) {
                $meta_query = [];
                
                foreach ($params['metaquery'] as $query) {
                    if (is_array($query)) {
                        // Se for estrutura completa do meta_query
                        if (isset($query['relation'])) {
                            $meta_query = $query;
                            break;
                        } else {
                            // Query individual
                            $meta_query[] = [
                                'key' => $query['key'],
                                'value' => $query['value'],
                                'compare' => $query['compare'] ?? '='
                            ];
                        }
                    }
                }
                
                if (!empty($meta_query)) {
                    $args['meta_query'] = $meta_query;
                }
            }
            
            // Busca itens
            $items = $items_repo->fetch($args, $collection, 'OBJECT');
            $total = $items_repo->found_posts;
            
            // Processa itens
            $processed_items = [];
            foreach ($items as $item) {
                $item_array = $item->_toArray();
                
                // Busca metadados do item
                $metadata_repo = \Tainacan\Repositories\Metadata::get_instance();
                $metadata = $metadata_repo->fetch($item, 'OBJECT');
                
                $metadata_array = [];
                foreach ($metadata as $metadatum) {
                    $meta_id = $metadatum->get_metadatum()->get_id();
                    $metadata_array[$meta_id] = [
                        'metadatum_id' => $meta_id,
                        'metadatum' => [
                            'id' => $meta_id,
                            'name' => $metadatum->get_metadatum()->get_name()
                        ],
                        'value' => $metadatum->get_value(),
                        'value_as_html' => $metadatum->get_value_as_html(),
                        'value_as_string' => $metadatum->get_value_as_string()
                    ];
                }
                
                $item_array['metadata'] = $metadata_array;
                $processed_items[] = $this->normalize_item($item_array, $collection_id);
            }
            
            $result = [
                'items' => $processed_items,
                'total' => $total
            ];
            
            // Cache result
            if (!empty($result['items'])) {
                TEI_Cache_Manager::set($cache_key, $result, HOUR_IN_SECONDS);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log('TEI: Erro ao buscar via repositório: ' . $e->getMessage());
            // Continua para tentar via API REST
        }
    }
    
    // Fallback para API REST
    $endpoint = $this->api_base . "collection/{$collection_id}/items";
    
    // Parâmetros padrão
    $defaults = [
        'perpage' => 100,
        'paged' => 1,
        'order' => 'DESC',
        'orderby' => 'date'
    ];
    
    $params = wp_parse_args($params, $defaults);
    
    // Adiciona parâmetros à URL
    $url = add_query_arg($params, $endpoint);
    
    // Faz requisição
    $response = wp_remote_get($url, [
        'timeout' => $this->timeout,
        'headers' => [
            'Content-Type' => 'application/json'
        ]
    ]);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('json_error', __('Erro ao decodificar resposta', 'tainacan-explorador'));
    }
    
    // Processa resposta
    $items = [];
    $total = 0;
    
    if (isset($data['items'])) {
        $items = $data['items'];
        $total = $data['found_items'] ?? count($items);
    } else if (is_array($data)) {
        $items = $data;
        $headers = wp_remote_retrieve_headers($response);
        if (isset($headers['x-wp-total'])) {
            $total = intval($headers['x-wp-total']);
        } else {
            $total = count($items);
        }
    }
    
    // Processa cada item
    $processed_items = [];
    foreach ($items as $item) {
        $processed_items[] = $this->normalize_item($item, $collection_id);
    }
    
    $result = [
        'items' => $processed_items,
        'total' => $total
    ];
    
    // Cache result
    if (!empty($result['items'])) {
        TEI_Cache_Manager::set($cache_key, $result, HOUR_IN_SECONDS);
    }
    
    return $result;
}
