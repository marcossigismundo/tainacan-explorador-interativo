<?php
/**
 * Handler de API para comunicação com Tainacan
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_API_Handler {
    
    /**
     * Base URL da API do Tainacan
     */
    private $api_base;
    
    /**
     * Timeout para requisições
     */
    private $timeout = 30;
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->api_base = rest_url('wp/v2');
    }
    
    /**
     * Obtém coleções disponíveis
     * 
     * @param array $args Argumentos da query
     * @return array|WP_Error
     */
    public function get_collections($args = []) {
        $defaults = [
            'perpage' => 100,
            'paged' => 1,
            'status' => 'publish',
            'orderby' => 'name',
            'order' => 'ASC'
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Cache key
        $cache_key = 'tainacan_collections_' . md5(serialize($args));
        $cached = TEI_Cache_Manager::get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Usa API direta do Tainacan
        $collections = [];
        
        if (class_exists('\Tainacan\Repositories\Collections')) {
            $repository = \Tainacan\Repositories\Collections::get_instance();
            
            $tainacan_args = [
                'posts_per_page' => $args['perpage'],
                'paged' => $args['paged'],
                'post_status' => $args['status'],
                'orderby' => $args['orderby'],
                'order' => $args['order']
            ];
            
            $tainacan_collections = $repository->fetch($tainacan_args);
            
            if ($tainacan_collections) {
                foreach ($tainacan_collections as $col) {
                    $collections[] = [
                        'id' => $col->get_id(),
                        'name' => $col->get_name(),
                        'description' => $col->get_description(),
                        'slug' => $col->get_slug(),
                        'items_count' => $repository->count_items($col->get_id()),
                        'thumbnail' => $col->get_thumbnail(),
                        'status' => $col->get_status(),
                        'url' => $col->get_url()
                    ];
                }
            }
        } else {
            // Fallback para REST API
            $response = $this->make_request('/tainacan/v2/collections', $args);
            
            if (!is_wp_error($response)) {
                $collections = $this->parse_collections_response($response);
            }
        }
        
        // Cache por 1 hora
        if (!empty($collections)) {
            TEI_Cache_Manager::set($cache_key, $collections, HOUR_IN_SECONDS);
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
        $cache_key = 'collection_metadata_' . $collection_id;
        $cached = TEI_Cache_Manager::get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $metadata = [];
        
        if (class_exists('\Tainacan\Repositories\Metadata')) {
            $repository = \Tainacan\Repositories\Metadata::get_instance();
            
            $args = [
                'collection_id' => $collection_id,
                'include_disabled' => false
            ];
            
            $tainacan_metadata = $repository->fetch_by_collection(
                new \Tainacan\Entities\Collection($collection_id),
                $args
            );
            
            if ($tainacan_metadata) {
                foreach ($tainacan_metadata as $meta) {
                    $metadata[] = [
                        'id' => $meta->get_id(),
                        'name' => $meta->get_name(),
                        'slug' => $meta->get_slug(),
                        'type' => $this->get_metadata_type($meta->get_metadata_type()),
                        'cardinality' => $meta->get_cardinality(),
                        'required' => $meta->get_required(),
                        'collection_key' => $meta->get_collection_key(),
                        'multiple' => $meta->get_multiple(),
                        'options' => $meta->get_metadata_type_options()
                    ];
                }
            }
        } else {
            // Fallback para REST API
            $endpoint = sprintf('/tainacan/v2/collection/%d/metadata', $collection_id);
            $response = $this->make_request($endpoint, [
                'perpage' => 100,
                'include_disabled' => false
            ]);
            
            if (!is_wp_error($response)) {
                $metadata = $this->parse_metadata_response($response);
            }
        }
        
        // Cache por 1 dia
        if (!empty($metadata)) {
            TEI_Cache_Manager::set($cache_key, $metadata, DAY_IN_SECONDS);
        }
        
        return $metadata;
    }
    
    /**
     * Obtém itens de uma coleção
     * 
     * @param int $collection_id ID da coleção
     * @param array $args Argumentos da query
     * @return array|WP_Error
     */
    public function get_collection_items($collection_id, $args = []) {
        $defaults = [
            'perpage' => 100,
            'paged' => 1,
            'status' => 'publish',
            'fetch_only' => 'title,description,thumbnail',
            'fetch_only_meta' => ''
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Cache key baseada nos argumentos
        $cache_key = 'collection_items_' . $collection_id . '_' . md5(serialize($args));
        $cached = TEI_Cache_Manager::get($cache_key);
        
        if ($cached !== false && !isset($args['no_cache'])) {
            return $cached;
        }
        
        $items = ['items' => [], 'total' => 0];
        
        if (class_exists('\Tainacan\Repositories\Items')) {
            $repository = \Tainacan\Repositories\Items::get_instance();
            
            $tainacan_args = [
                'posts_per_page' => $args['perpage'],
                'paged' => $args['paged'],
                'post_status' => $args['status']
            ];
            
            $collection = new \Tainacan\Entities\Collection($collection_id);
            $tainacan_items = $repository->fetch($tainacan_args, $collection);
            
            if ($tainacan_items) {
                foreach ($tainacan_items as $item) {
                    $parsed_item = [
                        'id' => $item->get_id(),
                        'title' => $item->get_title(),
                        'description' => $item->get_description(),
                        'url' => $item->get_url(),
                        'status' => $item->get_status(),
                        'thumbnail' => []
                    ];
                    
                    // Thumbnail
                    $thumbnail_id = $item->get_thumbnail_id();
                    if ($thumbnail_id) {
                        $parsed_item['thumbnail'] = [
                            'full' => wp_get_attachment_url($thumbnail_id),
                            'medium' => wp_get_attachment_image_url($thumbnail_id, 'medium'),
                            'thumbnail' => wp_get_attachment_image_url($thumbnail_id, 'thumbnail')
                        ];
                    }
                    
                    // Metadata
                    $parsed_item['metadata'] = [];
                    $item_metadata = $item->get_metadata();
                    if ($item_metadata) {
                        foreach ($item_metadata as $meta) {
                            $meta_key = $meta->get_metadatum()->get_id();
                            $parsed_item['metadata'][$meta_key] = [
                                'name' => $meta->get_metadatum()->get_name(),
                                'value' => $meta->get_value(),
                                'value_as_html' => $meta->get_value_as_html(),
                                'value_as_string' => $meta->get_value_as_string()
                            ];
                        }
                    }
                    
                    $items['items'][] = $parsed_item;
                }
                
                $items['total'] = $repository->count_items($collection_id);
            }
        } else {
            // Fallback para REST API
            $endpoint = sprintf('/tainacan/v2/collection/%d/items', $collection_id);
            $response = $this->make_request($endpoint, $args);
            
            if (!is_wp_error($response)) {
                $items = $this->parse_items_response($response);
            }
        }
        
        // Cache por 30 minutos
        if (!isset($args['no_cache']) && !empty($items['items'])) {
            TEI_Cache_Manager::set($cache_key, $items, 30 * MINUTE_IN_SECONDS);
        }
        
        return $items;
    }
    
    /**
     * Faz requisição à API
     * 
     * @param string $endpoint Endpoint da API
     * @param array $args Argumentos da requisição
     * @param string $method Método HTTP
     * @return array|WP_Error
     */
    private function make_request($endpoint, $args = [], $method = 'GET') {
        $url = $this->api_base . $endpoint;
        
        if ($method === 'GET' && !empty($args)) {
            $url = add_query_arg($args, $url);
        }
        
        $request_args = [
            'timeout' => $this->timeout,
            'headers' => [
                'Accept' => 'application/json',
                'X-WP-Nonce' => wp_create_nonce('wp_rest')
            ],
            'method' => $method
        ];
        
        if ($method !== 'GET' && !empty($args)) {
            $request_args['body'] = json_encode($args);
            $request_args['headers']['Content-Type'] = 'application/json';
        }
        
        // Log de debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('TEI API Request: ' . $url);
        }
        
        $response = wp_remote_request($url, $request_args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Erro ao decodificar resposta da API', 'tainacan-explorador'));
        }
        
        // Verifica erros da API
        if (isset($data['error'])) {
            return new WP_Error($data['error']['code'] ?? 'api_error', $data['error']['message'] ?? 'Unknown error');
        }
        
        return $data;
    }
    
    /**
     * Processa resposta de coleções
     * 
     * @param array $response Resposta da API
     * @return array
     */
    private function parse_collections_response($response) {
        if (!is_array($response)) {
            return [];
        }
        
        $collections = [];
        
        foreach ($response as $collection) {
            $collections[] = [
                'id' => $collection['id'] ?? 0,
                'name' => $collection['name'] ?? '',
                'description' => $collection['description'] ?? '',
                'slug' => $collection['slug'] ?? '',
                'items_count' => $collection['total_items'] ?? 0,
                'thumbnail' => $collection['thumbnail'] ?? [],
                'status' => $collection['status'] ?? 'publish',
                'url' => $collection['url'] ?? ''
            ];
        }
        
        return $collections;
    }
    
    /**
     * Processa resposta de metadados
     * 
     * @param array $response Resposta da API
     * @return array
     */
    private function parse_metadata_response($response) {
        if (!is_array($response)) {
            return [];
        }
        
        $metadata = [];
        
        foreach ($response as $field) {
            // Pula metadados core que não são úteis para mapeamento
            if (isset($field['metadata_type']) && in_array($field['metadata_type'], ['Tainacan\\Metadata_Types\\Core_Title', 'Tainacan\\Metadata_Types\\Core_Description'])) {
                continue;
            }
            
            $metadata[] = [
                'id' => $field['id'] ?? 0,
                'name' => $field['name'] ?? '',
                'slug' => $field['slug'] ?? '',
                'type' => $this->get_metadata_type($field['metadata_type'] ?? ''),
                'cardinality' => $field['cardinality'] ?? 1,
                'required' => $field['required'] ?? 'no',
                'collection_key' => $field['collection_key'] ?? 'no',
                'multiple' => $field['multiple'] ?? 'no',
                'options' => $field['metadata_type_options'] ?? []
            ];
        }
        
        return $metadata;
    }
    
    /**
     * Obtém tipo simplificado de metadado
     * 
     * @param string $type_class Classe do tipo
     * @return string
     */
    private function get_metadata_type($type_class) {
        $types = [
            'Tainacan\\Metadata_Types\\Text' => 'text',
            'Tainacan\\Metadata_Types\\Textarea' => 'textarea',
            'Tainacan\\Metadata_Types\\Date' => 'date',
            'Tainacan\\Metadata_Types\\Numeric' => 'numeric',
            'Tainacan\\Metadata_Types\\Selectbox' => 'select',
            'Tainacan\\Metadata_Types\\Relationship' => 'relationship',
            'Tainacan\\Metadata_Types\\Taxonomy' => 'taxonomy',
            'Tainacan\\Metadata_Types\\Compound' => 'compound',
            'Tainacan\\Metadata_Types\\User' => 'user',
            'Tainacan\\Metadata_Types\\URL' => 'url'
        ];
        
        return $types[$type_class] ?? 'text';
    }
    
    /**
     * Processa resposta de itens
     * 
     * @param array $response Resposta da API
     * @return array
     */
    private function parse_items_response($response) {
        if (!is_array($response)) {
            return ['items' => [], 'total' => 0];
        }
        
        // Verifica se é uma resposta paginada
        if (isset($response['items'])) {
            $items = $response['items'];
            $total = $response['found_items'] ?? count($items);
        } else {
            $items = $response;
            $total = count($items);
        }
        
        $parsed_items = [];
        
        foreach ($items as $item) {
            $parsed_items[] = $this->parse_single_item($item);
        }
        
        return [
            'items' => $parsed_items,
            'total' => $total
        ];
    }
    
    /**
     * Processa resposta de um item
     * 
     * @param array $response Resposta da API
     * @return array
     */
    private function parse_item_response($response) {
        return $this->parse_single_item($response);
    }
    
    /**
     * Processa um único item
     * 
     * @param array $item Dados do item
     * @return array
     */
    private function parse_single_item($item) {
        $parsed = [
            'id' => $item['id'] ?? 0,
            'title' => $item['title'] ?? '',
            'description' => $item['description'] ?? '',
            'url' => $item['url'] ?? '',
            'status' => $item['status'] ?? 'publish',
            'thumbnail' => []
        ];
        
        // Processa thumbnail
        if (isset($item['thumbnail'])) {
            $parsed['thumbnail'] = [
                'full' => $item['thumbnail']['full'][0] ?? '',
                'medium' => $item['thumbnail']['tainacan-medium'][0] ?? $item['thumbnail']['medium'][0] ?? '',
                'thumbnail' => $item['thumbnail']['thumbnail'][0] ?? ''
            ];
        }
        
        // Processa metadados
        $parsed['metadata'] = [];
        if (isset($item['metadata'])) {
            foreach ($item['metadata'] as $key => $meta) {
                $parsed['metadata'][$key] = [
                    'name' => $meta['name'] ?? '',
                    'value' => $meta['value'] ?? '',
                    'value_as_html' => $meta['value_as_html'] ?? '',
                    'value_as_string' => $meta['value_as_string'] ?? ''
                ];
            }
        }
        
        // Processa attachments
        if (isset($item['attachments'])) {
            $parsed['attachments'] = $item['attachments'];
        }
        
        return $parsed;
    }
    
    /**
     * Geocodifica endereço
     */
    public function geocode_address($address) {
        $settings = get_option('tei_settings', []);
        $service = $settings['geocoding_service'] ?? 'nominatim';
        
        switch ($service) {
            case 'nominatim':
                return $this->geocode_nominatim($address);
            case 'google':
                return $this->geocode_google($address, $settings['geocoding_api_key'] ?? '');
            case 'mapbox':
                return $this->geocode_mapbox($address, $settings['geocoding_api_key'] ?? '');
        }
        
        return null;
    }
    
    /**
     * Geocoding via Nominatim
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
        
        if (empty($data) || !isset($data[0]['lat'])) {
            return null;
        }
        
        return [
            'lat' => floatval($data[0]['lat']),
            'lon' => floatval($data[0]['lon']),
            'display_name' => $data[0]['display_name'] ?? $address
        ];
    }
    
    /**
     * Geocoding via Google Maps
     */
    private function geocode_google($address, $api_key) {
        if (empty($api_key)) {
            return null;
        }
        
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
            'address' => $address,
            'key' => $api_key
        ]);
        
        $response = wp_remote_get($url, ['timeout' => 10]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($data['results']) || !isset($data['results'][0]['geometry'])) {
            return null;
        }
        
        $location = $data['results'][0]['geometry']['location'];
        
        return [
            'lat' => floatval($location['lat']),
            'lon' => floatval($location['lng']),
            'display_name' => $data['results'][0]['formatted_address'] ?? $address
        ];
    }
    
    /**
     * Geocoding via Mapbox
     */
    private function geocode_mapbox($address, $api_key) {
        if (empty($api_key)) {
            return null;
        }
        
        $url = sprintf(
            'https://api.mapbox.com/geocoding/v5/mapbox.places/%s.json?access_token=%s',
            urlencode($address),
            $api_key
        );
        
        $response = wp_remote_get($url, ['timeout' => 10]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($data['features'])) {
            return null;
        }
        
        $coordinates = $data['features'][0]['geometry']['coordinates'];
        
        return [
            'lat' => floatval($coordinates[1]),
            'lon' => floatval($coordinates[0]),
            'display_name' => $data['features'][0]['place_name'] ?? $address
        ];
    }
    
    /**
     * Valida conexão com API do Tainacan
     * 
     * @return bool|WP_Error
     */
    public function validate_connection() {
        // Testa usando API direta
        if (class_exists('\Tainacan\Repositories\Collections')) {
            return true;
        }
        
        // Testa REST API
        $response = $this->make_request('/tainacan/v2/collections', ['perpage' => 1]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return true;
    }
}
