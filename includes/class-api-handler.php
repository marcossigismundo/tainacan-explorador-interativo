<?php
/**
 * Handler para comunicação com API do Tainacan
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
        $this->api_base = rest_url('wp/v2/');
    }
    
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
        
        // Prepara endpoint
        $endpoint = $this->get_tainacan_endpoint($collection_id);
        
        // Parâmetros padrão
        $defaults = [
            'perpage' => 100,
            'paged' => 1,
            'order' => 'DESC',
            'orderby' => 'date'
        ];
        
        $params = wp_parse_args($params, $defaults);
        
        // Faz requisição
        $response = $this->make_request($endpoint, $params);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Processa resposta
        $data = $this->process_items_response($response, $collection_id);
        
        // Cache result
        if (!empty($data['items'])) {
            TEI_Cache_Manager::set($cache_key, $data, HOUR_IN_SECONDS);
        }
        
        return $data;
    }
    
    /**
     * Obtém metadados de uma coleção
     * 
     * @param int $collection_id ID da coleção
     * @return array|WP_Error
     */
    public function get_collection_metadata($collection_id) {
        // Cache check
        $cache_key = 'tei_metadata_' . $collection_id;
        $cached = TEI_Cache_Manager::get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $metadata = [];
        
        // Tenta usar API do Tainacan se disponível
        if (function_exists('tainacan_get_api_postdata')) {
            $endpoint = rest_url("tainacan/v2/collection/{$collection_id}/metadata");
            
            $response = wp_remote_get($endpoint, [
                'timeout' => $this->timeout,
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $metadata = json_decode($body, true);
            }
        }
        
        // Fallback para método direto
        if (empty($metadata) && class_exists('\Tainacan\Repositories\Metadata')) {
            $repository = \Tainacan\Repositories\Metadata::get_instance();
            $collection = new \Tainacan\Entities\Collection($collection_id);
            
            $tainacan_metadata = $repository->fetch_by_collection($collection, [], 'OBJECT');
            
            foreach ($tainacan_metadata as $meta) {
                $metadata[] = [
                    'id' => $meta->get_id(),
                    'name' => $meta->get_name(),
                    'type' => $meta->get_metadata_type(),
                    'slug' => $meta->get_slug()
                ];
            }
        }
        
        // Cache result
        if (!empty($metadata)) {
            TEI_Cache_Manager::set($cache_key, $metadata, DAY_IN_SECONDS);
        }
        
        return $metadata;
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
        
        $settings = get_option('tei_settings', []);
        $service = $settings['geocoding_service'] ?? 'nominatim';
        
        $result = null;
        
        switch ($service) {
            case 'nominatim':
                $result = $this->geocode_nominatim($address);
                break;
            case 'google':
                $api_key = $settings['geocoding_api_key'] ?? '';
                if ($api_key) {
                    $result = $this->geocode_google($address, $api_key);
                }
                break;
            case 'mapbox':
                $api_key = $settings['geocoding_api_key'] ?? '';
                if ($api_key) {
                    $result = $this->geocode_mapbox($address, $api_key);
                }
                break;
        }
        
        // Cache result
        if ($result) {
            TEI_Cache_Manager::set($cache_key, $result, 30 * DAY_IN_SECONDS);
        }
        
        return $result;
    }
    
    /**
     * Faz requisição HTTP
     * 
     * @param string $url URL
     * @param array $params Parâmetros
     * @return array|WP_Error
     */
    private function make_request($url, $params = []) {
        $args = [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ];
        
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error(
                'api_error',
                sprintf(__('API retornou código %d', 'tainacan-explorador'), $code)
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Erro ao decodificar resposta', 'tainacan-explorador'));
        }
        
        return $data;
    }
    
    /**
     * Obtém endpoint do Tainacan
     * 
     * @param int $collection_id ID da coleção
     * @return string
     */
    private function get_tainacan_endpoint($collection_id) {
        // Verifica se Tainacan está ativo
        if (defined('TAINACAN_VERSION')) {
            return rest_url("tainacan/v2/collection/{$collection_id}/items");
        }
        
        // Fallback para post type customizado
        $post_type = 'tnc_col_' . $collection_id . '_item';
        return rest_url("wp/v2/{$post_type}");
    }
    
    /**
     * Processa resposta de itens
     * 
     * @param array $response Resposta da API
     * @param int $collection_id ID da coleção
     * @return array
     */
    private function process_items_response($response, $collection_id) {
        $items = [];
        $total = 0;
        
        if (is_array($response)) {
            // Se for resposta do Tainacan
            if (isset($response['items'])) {
                $items = $response['items'];
                $total = $response['found_items'] ?? count($items);
            }
            // Se for array direto
            elseif (isset($response[0]) && is_array($response[0])) {
                $items = $response;
                $total = count($items);
            }
        }
        
        // Processa cada item
        $processed = [];
        foreach ($items as $item) {
            $processed[] = $this->normalize_item($item, $collection_id);
        }
        
        return [
            'items' => $processed,
            'total' => $total
        ];
    }
    
    /**
     * Normaliza estrutura do item
     * 
     * @param array $item Item bruto
     * @param int $collection_id ID da coleção
     * @return array
     */
    private function normalize_item($item, $collection_id) {
        $normalized = [
            'id' => $item['id'] ?? 0,
            'title' => '',
            'description' => '',
            'url' => '',
            'thumbnail' => [],
            'metadata' => []
        ];
        
        // Título
        if (isset($item['title']['rendered'])) {
            $normalized['title'] = $item['title']['rendered'];
        } elseif (isset($item['title'])) {
            $normalized['title'] = $item['title'];
        }
        
        // Descrição
        if (isset($item['description']['rendered'])) {
            $normalized['description'] = $item['description']['rendered'];
        } elseif (isset($item['description'])) {
            $normalized['description'] = $item['description'];
        }
        
        // URL
        if (isset($item['url'])) {
            $normalized['url'] = $item['url'];
        } elseif (isset($item['link'])) {
            $normalized['url'] = $item['link'];
        }
        
        // Thumbnail
        if (isset($item['thumbnail'])) {
            $normalized['thumbnail'] = $item['thumbnail'];
        } elseif (isset($item['_thumbnail_id'])) {
            $normalized['thumbnail'] = $this->get_thumbnail_sizes($item['_thumbnail_id']);
        }
        
        // Metadados
        if (isset($item['metadata'])) {
            $normalized['metadata'] = $item['metadata'];
        } elseif (isset($item['meta'])) {
            $normalized['metadata'] = $item['meta'];
        }
        
        return $normalized;
    }
    
    /**
     * Obtém tamanhos de thumbnail
     * 
     * @param int $attachment_id ID do anexo
     * @return array
     */
    private function get_thumbnail_sizes($attachment_id) {
        $sizes = [];
        $available = ['thumbnail', 'medium', 'large', 'full'];
        
        foreach ($available as $size) {
            $url = wp_get_attachment_image_url($attachment_id, $size);
            if ($url) {
                $sizes[$size] = $url;
            }
        }
        
        return $sizes;
    }
    
    /**
     * Geocoding via Nominatim
     */
    private function geocode_nominatim($address) {
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $address,
            'format' => 'json',
            'limit' => 1,
            'addressdetails' => 1
        ]);
        
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'TainacanExplorador/1.0',
                'Accept-Language' => get_locale()
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
     * Geocoding via Google
     */
    private function geocode_google($address, $api_key) {
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
            'address' => $address,
            'key' => $api_key,
            'language' => substr(get_locale(), 0, 2)
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
        $url = sprintf(
            'https://api.mapbox.com/geocoding/v5/mapbox.places/%s.json?access_token=%s&language=%s',
            urlencode($address),
            $api_key,
            substr(get_locale(), 0, 2)
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
}
