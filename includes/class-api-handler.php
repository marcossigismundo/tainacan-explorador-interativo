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
        $this->api_base = rest_url('tainacan/v2/');
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
        
        // Prepara endpoint - usa API v2 do Tainacan
        $endpoint = $this->api_base . "collection/{$collection_id}/items";
        
        // Parâmetros padrão
        $defaults = [
            'perpage' => 100,
            'paged' => 1,
            'order' => 'DESC',
            'orderby' => 'date',
            'fetch_only' => 'title,description,thumbnail,document,_attachments'
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
        
        // Processa resposta do Tainacan
        $items = [];
        $total = 0;
        
        // O Tainacan v2 retorna os itens diretamente no array principal
        if (isset($data['items'])) {
            $items = $data['items'];
            $total = $data['found_items'] ?? count($items);
        } else if (is_array($data)) {
            // Se for array direto de itens
            $items = $data;
            
            // Pega o total do header
            $headers = wp_remote_retrieve_headers($response);
            if (isset($headers['x-wp-total'])) {
                $total = intval($headers['x-wp-total']);
            } else {
                $total = count($items);
            }
        }
        
        // Processa cada item para normalizar estrutura
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
        
        // Usa API v2 do Tainacan
        $endpoint = $this->api_base . "collection/{$collection_id}/metadata";
        
        $response = wp_remote_get($endpoint, [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json'
            ]
        ]);
        
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (is_array($data)) {
                foreach ($data as $meta) {
                    // Pula metadados core
                    $metadata_type = $meta['metadata_type'] ?? '';
                    if (strpos($metadata_type, 'Core_') !== false) {
                        continue;
                    }
                    
                    $metadata[] = [
                        'id' => $meta['id'],
                        'name' => $meta['name'] ?? '',
                        'slug' => $meta['slug'] ?? '',
                        'type' => $meta['metadata_type_object']['name'] ?? 'Text',
                        'required' => $meta['required'] ?? false,
                        'multiple' => $meta['multiple'] ?? false
                    ];
                }
            }
        }
        
        // Fallback para método direto se disponível
        if (empty($metadata) && class_exists('\\Tainacan\\Repositories\\Metadata')) {
            $repository = \Tainacan\Repositories\Metadata::get_instance();
            $collection = new \Tainacan\Entities\Collection($collection_id);
            
            $tainacan_metadata = $repository->fetch_by_collection($collection, [], 'OBJECT');
            
            foreach ($tainacan_metadata as $meta) {
                if (strpos($meta->get_metadata_type(), 'Core_') !== false) {
                    continue;
                }
                
                $metadata[] = [
                    'id' => $meta->get_id(),
                    'name' => $meta->get_name(),
                    'slug' => $meta->get_slug(),
                    'type' => $meta->get_metadata_type(),
                    'required' => $meta->get_required(),
                    'multiple' => $meta->get_multiple()
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
 * Normaliza estrutura do item do Tainacan
 * CORREÇÃO: Processa corretamente diferentes estruturas de metadados
 * 
 * @param array $item Item bruto da API
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
        'document' => '',
        '_attachments' => [],
        'metadata' => []
    ];
    
    // Debug
    error_log('TEI Debug - Normalizing item: ' . $item['id']);
    error_log('TEI Debug - Item structure keys: ' . json_encode(array_keys($item)));
    
    // Título - pode estar em diferentes lugares
    if (isset($item['title']['rendered'])) {
        $normalized['title'] = $item['title']['rendered'];
    } elseif (isset($item['title'])) {
        $normalized['title'] = is_array($item['title']) ? ($item['title']['value'] ?? '') : $item['title'];
    }
    
    // Descrição
    if (isset($item['description']['rendered'])) {
        $normalized['description'] = $item['description']['rendered'];
    } elseif (isset($item['description'])) {
        $normalized['description'] = is_array($item['description']) ? ($item['description']['value'] ?? '') : $item['description'];
    }
    
    // URL do item
    if (isset($item['url'])) {
        $normalized['url'] = $item['url'];
    } elseif (isset($item['link'])) {
        $normalized['url'] = $item['link'];
    } else {
        // Constrói URL padrão do Tainacan
        $normalized['url'] = home_url("/colecoes/{$collection_id}/items/{$item['id']}");
    }
    
    // Thumbnail - estrutura do Tainacan
    if (isset($item['thumbnail'])) {
        $normalized['thumbnail'] = $item['thumbnail'];
    } elseif (isset($item['_thumbnail_id'])) {
        $normalized['thumbnail'] = $this->get_thumbnail_sizes($item['_thumbnail_id']);
    }
    
    // Document - documento principal do item
    if (isset($item['document'])) {
        $normalized['document'] = $item['document'];
    }
    
    // Attachments - anexos do item
    if (isset($item['_attachments'])) {
        $normalized['_attachments'] = $item['_attachments'];
    }
    
    // CORREÇÃO: Processa metadados mantendo IDs numéricos como chaves
    if (isset($item['metadata']) && is_array($item['metadata'])) {
        error_log('TEI Debug - Processing metadata, type: ' . gettype($item['metadata']));
        error_log('TEI Debug - Metadata keys: ' . json_encode(array_keys($item['metadata'])));
        
        // Verifica se metadata é array associativo (chaves são IDs) ou array indexado
        $is_associative = array_keys($item['metadata']) !== range(0, count($item['metadata']) - 1);
        
        if ($is_associative) {
            // Metadata já está indexado por ID
            foreach ($item['metadata'] as $meta_id => $meta) {
                if (is_array($meta)) {
                    $normalized['metadata'][$meta_id] = [
                        'id' => $meta_id,
                        'name' => $meta['name'] ?? $meta['metadatum']['name'] ?? '',
                        'value' => $meta['value'] ?? '',
                        'value_as_html' => $meta['value_as_html'] ?? '',
                        'value_as_string' => $meta['value_as_string'] ?? ''
                    ];
                } else {
                    // Valor simples
                    $normalized['metadata'][$meta_id] = [
                        'id' => $meta_id,
                        'value' => $meta
                    ];
                }
            }
        } else {
            // Metadata é array de objetos
            foreach ($item['metadata'] as $meta) {
                if (is_array($meta)) {
                    // Extrai o ID do metadado
                    $meta_id = $meta['metadatum_id'] ?? 
                              $meta['metadatum']['id'] ?? 
                              $meta['id'] ?? 
                              null;
                    
                    if ($meta_id) {
                        // Usa o ID como chave
                        $normalized['metadata'][$meta_id] = [
                            'id' => $meta_id,
                            'name' => $meta['metadatum']['name'] ?? $meta['name'] ?? '',
                            'value' => $meta['value'] ?? '',
                            'value_as_html' => $meta['value_as_html'] ?? '',
                            'value_as_string' => $meta['value_as_string'] ?? ''
                        ];
                        
                        // Debug
                        error_log('TEI Debug - Added metadata ID ' . $meta_id . ' with value: ' . 
                                 json_encode($meta['value'] ?? ''));
                    }
                }
            }
        }
    }
    
    // Debug final
    error_log('TEI Debug - Normalized metadata IDs: ' . json_encode(array_keys($normalized['metadata'])));
    
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
        
        // Tamanhos padrão do WordPress + Tainacan
        $available = [
            'thumbnail',
            'medium', 
            'medium_large',
            'large',
            'full',
            'tainacan-small',
            'tainacan-medium',
            'tainacan-medium-full',
            'tainacan-large'
        ];
        
        foreach ($available as $size) {
            $url = wp_get_attachment_image_url($attachment_id, $size);
            if ($url) {
                $sizes[$size] = $url;
            }
        }
        
        return $sizes;
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
