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
        $this->api_base = rest_url('tainacan/v2');
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
        
        // Faz requisição
        $response = $this->make_request('/collections', $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Processa resposta
        $collections = $this->parse_collections_response($response);
        
        // Cache por 1 hora
        TEI_Cache_Manager::set($cache_key, $collections, HOUR_IN_SECONDS);
        
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
        
        $endpoint = sprintf('/collection/%d/metadata', $collection_id);
        $response = $this->make_request($endpoint, [
            'perpage' => 100,
            'include_disabled' => false
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $metadata = $this->parse_metadata_response($response);
        
        // Cache por 1 dia
        TEI_Cache_Manager::set($cache_key, $metadata, DAY_IN_SECONDS);
        
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
        
        $endpoint = sprintf('/collection/%d/items', $collection_id);
        $response = $this->make_request($endpoint, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $items = $this->parse_items_response($response);
        
        // Cache por 30 minutos
        if (!isset($args['no_cache'])) {
            TEI_Cache_Manager::set($cache_key, $items, 30 * MINUTE_IN_SECONDS);
        }
        
        return $items;
    }
    
    /**
     * Obtém um item específico
     * 
     * @param int $collection_id ID da coleção
     * @param int $item_id ID do item
     * @return array|WP_Error
     */
    public function get_item($collection_id, $item_id) {
        $cache_key = 'item_' . $collection_id . '_' . $item_id;
        $cached = TEI_Cache_Manager::get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $endpoint = sprintf('/collection/%d/items/%d', $collection_id, $item_id);
        $response = $this->make_request($endpoint);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $item = $this->parse_item_response($response);
        
        // Cache por 1 hora
        TEI_Cache_Manager::set($cache_key, $item, HOUR_IN_SECONDS);
        
        return $item;
    }
    
    /**
     * Busca itens em múltiplas coleções
     * 
     * @param string $search_query Query de busca
     * @param array $args Argumentos adicionais
     * @return array|WP_Error
     */
    public function search_items($search_query, $args = []) {
        $defaults = [
            'perpage' => 50,
            'paged' => 1,
            'search' => $search_query,
            'sentence' => true
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $response = $this->make_request('/items', $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return $this->parse_items_response($response);
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
     * Obtém taxonomias de uma coleção
     * 
     * @param int $collection_id ID da coleção
     * @return array|WP_Error
     */
    public function get_collection_taxonomies($collection_id) {
        $cache_key = 'collection_taxonomies_' . $collection_id;
        $cached = TEI_Cache_Manager::get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $endpoint = sprintf('/collection/%d/metadata', $collection_id);
        $response = $this->make_request($endpoint, [
            'metaquery' => [
                [
                    'key' => 'metadata_type',
                    'value' => 'Tainacan\\Metadata_Types\\Taxonomy'
                ]
            ]
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $taxonomies = [];
        foreach ($response as $tax) {
            if (isset($tax['metadata_type_options']['taxonomy_id'])) {
                $taxonomies[] = [
                    'id' => $tax['id'],
                    'name' => $tax['name'],
                    'taxonomy_id' => $tax['metadata_type_options']['taxonomy_id']
                ];
            }
        }
        
        TEI_Cache_Manager::set($cache_key, $taxonomies, DAY_IN_SECONDS);
        
        return $taxonomies;
    }
    
    /**
     * Valida conexão com API do Tainacan
     * 
     * @return bool|WP_Error
     */
    public function validate_connection() {
        $response = $this->make_request('/collections', ['perpage' => 1]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return true;
    }
}
