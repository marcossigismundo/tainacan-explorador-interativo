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
     * Obtém coleções com contagem real de itens
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
        
        // Formata coleções COM CONTAGEM REAL
        $collections = [];
        foreach ($data as $collection) {
            if (isset($collection['id']) && isset($collection['name'])) {
                // BUSCA CONTAGEM REAL DE ITENS
                $items_count = $this->get_collection_items_count($collection['id']);
                
                $collections[] = [
                    'id' => $collection['id'],
                    'name' => $collection['name'],
                    'description' => $collection['description'] ?? '',
                    'items_count' => $items_count, // Contagem real
                    'thumbnail' => $collection['thumbnail'] ?? ''
                ];
            }
        }
        
        return $collections;
    }
    
    /**
     * Obtém contagem de itens de uma coleção
     * 
     * @param int $collection_id
     * @return int
     */
    private function get_collection_items_count($collection_id) {
        $url = $this->api_base . 'collection/' . $collection_id . '/items?perpage=1';
        
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json'
            ]
        ]);
        
        if (is_wp_error($response)) {
            return 0;
        }
        
        // O total vem no header X-WP-Total
        $headers = wp_remote_retrieve_headers($response);
        
        if (isset($headers['x-wp-total'])) {
            return intval($headers['x-wp-total']);
        }
        
        return 0;
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
        $items = isset($data['items']) ? $data['items'] : $data;
        
        error_log('TEI Debug - Items found: ' . count($items));
        
        // Normaliza itens
        $normalized = [];
        foreach ($items as $item) {
            $normalized[] = $this->normalize_item($item);
        }
        
        // Obtém total do header
        $headers = wp_remote_retrieve_headers($response);
        $total = isset($headers['x-wp-total']) ? intval($headers['x-wp-total']) : count($normalized);
        
        // Estrutura de resposta
        $result = [
            'items' => $normalized,
            'total' => $total,
            'pages' => isset($data['pages']) ? $data['pages'] : ceil($total / $params['perpage']),
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
        
        // Processa metadados
        if (isset($item['metadata']) && is_array($item['metadata'])) {
            foreach ($item['metadata'] as $meta_id => $meta_data) {
                if (is_array($meta_data)) {
                    $normalized['metadata'][$meta_id] = [
                        'id' => $meta_data['id'] ?? $meta_id,
                        'name' => $meta_data['name'] ?? '',
                        'value' => $meta_data['value'] ?? '',
                        'value_as_html' => $meta_data['value_as_html'] ?? '',
                        'value_as_string' => $meta_data['value_as_string'] ?? ''
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
