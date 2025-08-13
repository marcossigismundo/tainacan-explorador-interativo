<?php
/**
 * Handler para API do Tainacan
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_API_Handler {
    
    /**
     * Base URL da API
     */
    private $api_base;
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->api_base = rest_url('tainacan/v2/');
    }
    
    /**
     * Obtém coleções
     * 
     * @return array|WP_Error
     */
    public function get_collections() {
        $url = $this->api_base . 'collections';
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json'
            ]
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!is_array($data)) {
            return new WP_Error('invalid_response', __('Resposta inválida da API', 'tainacan-explorador'));
        }
        
        // Formata coleções
        $collections = [];
        foreach ($data as $collection) {
            if (isset($collection['id']) && isset($collection['name'])) {
                $collections[] = [
                    'id' => $collection['id'],
                    'name' => $collection['name'],
                    'description' => $collection['description'] ?? '',
                    'items_count' => $collection['total_items'] ?? 0,
                    'thumbnail' => $collection['thumbnail'] ?? ''
                ];
            }
        }
        
        return $collections;
    }
    
    /**
     * Obtém metadados de uma coleção
     * 
     * @param int $collection_id ID da coleção
     * @return array|WP_Error
     */
    public function get_collection_metadata($collection_id) {
        $url = $this->api_base . 'collection/' . $collection_id . '/metadata';
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json'
            ]
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!is_array($data)) {
            return new WP_Error('invalid_response', __('Resposta inválida da API', 'tainacan-explorador'));
        }
        
        // Formata metadados
        $metadata = [];
        foreach ($data as $meta) {
            if (isset($meta['id'])) {
                $metadata[] = [
                    'id' => $meta['id'],
                    'name' => $meta['name'] ?? '',
                    'slug' => $meta['slug'] ?? '',
                    'type' => $meta['metadata_type'] ?? $meta['type'] ?? 'text',
                    'required' => $meta['required'] ?? false,
                    'multiple' => $meta['multiple'] ?? false,
                    'cardinality' => $meta['cardinality'] ?? 1
                ];
            }
        }
        
        return $metadata;
    }
    
    /**
     * Obtém itens de uma coleção
     * 
     * @param int $collection_id ID da coleção
     * @param array $params Parâmetros da query
     * @return array|WP_Error
     */
    public function get_collection_items($collection_id, $params = []) {
        // Cache key única
        $cache_key = 'items_' . $collection_id . '_' . md5(serialize($params));
        $cached = TEI_Cache_Manager::get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Parâmetros padrão
        $defaults = [
            'perpage' => 100,
            'paged' => 1,
            'order' => 'DESC',
            'orderby' => 'date',
            'exposer' => 'json',
            'fetch' => 'all'
        ];
        
        $params = wp_parse_args($params, $defaults);
        
        // Adiciona fetch_only_meta se especificado
        if (!empty($params['fetch_only_meta'])) {
            // Garante que são strings
            if (is_array($params['fetch_only_meta'])) {
                $params['fetch_only_meta'] = implode(',', $params['fetch_only_meta']);
            }
        }
        
        $url = $this->api_base . 'collection/' . $collection_id . '/items?' . http_build_query($params);
        
        error_log('TEI Debug - Fetching items from: ' . $url);
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json'
            ]
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!is_array($data)) {
            return new WP_Error('invalid_response', __('Resposta inválida da API', 'tainacan-explorador'));
        }
        
        // Se for resposta paginada
        if (isset($data['items'])) {
            $items = $data['items'];
        } else {
            $items = $data;
        }
        
        error_log('TEI Debug - Items found: ' . count($items));
        
        // Normaliza itens
        $normalized = [];
        foreach ($items as $item) {
            $normalized[] = $this->normalize_item($item);
        }
        
        // Estrutura de resposta
        $result = [
            'items' => $normalized,
            'total' => isset($data['total']) ? $data['total'] : count($normalized),
            'pages' => isset($data['pages']) ? $data['pages'] : 1,
            'page' => $params['paged']
        ];
        
        // Cache por 1 hora
        TEI_Cache_Manager::set($cache_key, $result, HOUR_IN_SECONDS);
        
        return $result;
    }
    
    /**
     * Normaliza item do Tainacan
     * 
     * @param array $item Item da API
     * @return array Item normalizado
     */
    private function normalize_item($item) {
        if (!is_array($item)) {
            return [];
        }
        
        // Debug - mostra estrutura
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('TEI Debug - First item structure: ' . json_encode(array_keys($item)));
        }
        
        $normalized = [
            'id' => $item['id'] ?? '',
            'title' => $item['title'] ?? '',
            'description' => $item['description'] ?? '',
            'url' => $item['url'] ?? '',
            'collection_id' => $item['collection_id'] ?? '',
            'thumbnail' => $this->extract_thumbnail($item),
            'document' => $item['document'] ?? '',
            'document_type' => $item['document_type'] ?? '',
            'metadata' => []
        ];
        
        // Processa metadados - FORMATO CORRETO DO TAINACAN
        if (isset($item['metadata']) && is_array($item['metadata'])) {
            foreach ($item['metadata'] as $meta_key => $meta_data) {
                // O Tainacan retorna metadados como array associativo
                // onde a chave é o slug do metadado
                if (is_array($meta_data)) {
                    // Estrutura típica: { "id": 123, "value": "...", "value_as_html": "...", "value_as_string": "..." }
                    $meta_id = $meta_data['id'] ?? $meta_key;
                    
                    $normalized['metadata'][$meta_id] = [
                        'id' => $meta_id,
                        'name' => $meta_data['name'] ?? '',
                        'value' => $meta_data['value'] ?? '',
                        'value_as_html' => $meta_data['value_as_html'] ?? '',
                        'value_as_string' => $meta_data['value_as_string'] ?? ''
                    ];
                    
                    // Também adiciona por slug para facilitar acesso
                    if (isset($meta_data['slug'])) {
                        $normalized['metadata'][$meta_data['slug']] = $normalized['metadata'][$meta_id];
                    }
                } else {
                    // Valor direto (formato antigo ou simplificado)
                    $normalized['metadata'][$meta_key] = [
                        'id' => $meta_key,
                        'name' => '',
                        'value' => $meta_data,
                        'value_as_html' => $meta_data,
                        'value_as_string' => is_string($meta_data) ? $meta_data : json_encode($meta_data)
                    ];
                }
            }
        }
        
        // Processa metadados em formato alternativo (se existir)
        foreach ($item as $key => $value) {
            // Detecta metadados por padrão de nomenclatura
            if (strpos($key, 'metadata_') === 0 || preg_match('/^\d+$/', $key)) {
                if (!isset($normalized['metadata'][$key])) {
                    $normalized['metadata'][$key] = [
                        'id' => $key,
                        'name' => '',
                        'value' => $value,
                        'value_as_html' => is_string($value) ? $value : '',
                        'value_as_string' => is_string($value) ? $value : json_encode($value)
                    ];
                }
            }
        }
        
        error_log('TEI Debug - Total metadata processed: ' . count($normalized['metadata']));
        
        return $normalized;
    }
    
    /**
     * Extrai thumbnail do item
     * 
     * @param array $item
     * @return array
     */
    private function extract_thumbnail($item) {
        $thumbnail = [];
        
        // Tenta diferentes formatos
        if (isset($item['thumbnail']) && is_array($item['thumbnail'])) {
            $thumbnail = $item['thumbnail'];
        } elseif (isset($item['_thumbnail_id'])) {
            $thumb_id = $item['_thumbnail_id'];
            if ($thumb_id) {
                $thumbnail = [
                    'thumbnail' => wp_get_attachment_image_url($thumb_id, 'thumbnail'),
                    'medium' => wp_get_attachment_image_url($thumb_id, 'medium'),
                    'large' => wp_get_attachment_image_url($thumb_id, 'large'),
                    'full' => wp_get_attachment_image_url($thumb_id, 'full')
                ];
            }
        }
        
        return $thumbnail;
    }
    
    /**
     * Geocodifica um endereço
     * 
     * @param string $address Endereço
     * @return array|null
     */
    public function geocode_address($address) {
        if (empty($address)) {
            return null;
        }
        
        // Cache check
        $cache_key = 'geocode_' . md5($address);
        $cached = TEI_Cache_Manager::get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Obtém configurações
        $settings = get_option('tei_settings', []);
        $service = $settings['geocoding_service'] ?? 'nominatim';
        
        $coordinates = null;
        
        switch ($service) {
            case 'nominatim':
                $coordinates = $this->geocode_nominatim($address);
                break;
            case 'google':
                $api_key = $settings['geocoding_api_key'] ?? '';
                if ($api_key) {
                    $coordinates = $this->geocode_google($address, $api_key);
                }
                break;
            case 'mapbox':
                $api_key = $settings['geocoding_api_key'] ?? '';
                if ($api_key) {
                    $coordinates = $this->geocode_mapbox($address, $api_key);
                }
                break;
        }
        
        if ($coordinates) {
            // Cache por 30 dias
            TEI_Cache_Manager::set($cache_key, $coordinates, 30 * DAY_IN_SECONDS);
        }
        
        return $coordinates;
    }
    
    /**
     * Geocoding com Nominatim
     */
    private function geocode_nominatim($address) {
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $address,
            'format' => 'json',
            'limit' => 1
        ]);
        
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'TainacanExplorador/1.0'
            ]
        ]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($data[0])) {
            return [
                'lat' => floatval($data[0]['lat']),
                'lon' => floatval($data[0]['lon'])
            ];
        }
        
        return null;
    }
    
    /**
     * Geocoding com Google Maps
     */
    private function geocode_google($address, $api_key) {
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
            'address' => $address,
            'key' => $api_key
        ]);
        
        $response = wp_remote_get($url, ['timeout' => 10]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($data['results'][0]['geometry']['location'])) {
            $location = $data['results'][0]['geometry']['location'];
            return [
                'lat' => floatval($location['lat']),
                'lon' => floatval($location['lng'])
            ];
        }
        
        return null;
    }
    
    /**
     * Geocoding com Mapbox
     */
    private function geocode_mapbox($address, $api_key) {
        $url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/' . 
               urlencode($address) . '.json?' . http_build_query([
                   'access_token' => $api_key,
                   'limit' => 1
               ]);
        
        $response = wp_remote_get($url, ['timeout' => 10]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($data['features'][0]['center'])) {
            $center = $data['features'][0]['center'];
            return [
                'lat' => floatval($center[1]),
                'lon' => floatval($center[0])
            ];
        }
        
        return null;
    }
}
