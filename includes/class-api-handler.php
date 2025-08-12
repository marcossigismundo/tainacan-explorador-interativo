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
        
        // Usa API REST do Tainacan v2 corretamente
        $endpoint = $this->api_base . "collection/{$collection_id}/items";
        
        // Parâmetros padrão otimizados
        $defaults = [
            'perpage' => 100,
            'paged' => 1,
            'order' => 'DESC',
            'orderby' => 'date',
            'fetch_only' => 'title,description,thumbnail,document,_attachments'
        ];
        
        $params = wp_parse_args($params, $defaults);
        
        // Se há metadados específicos para buscar, adiciona ao fetch_only
        if (!empty($params['fetch_only_meta'])) {
            // Busca primeiro os metadados da coleção para validar IDs
            $metadata_list = $this->get_collection_metadata($collection_id);
            if (!is_wp_error($metadata_list)) {
                $valid_meta_ids = array_column($metadata_list, 'id');
                $requested_meta = explode(',', $params['fetch_only_meta']);
                $valid_requested = array_intersect($requested_meta, $valid_meta_ids);
                
                if (!empty($valid_requested)) {
                    // Adiciona metadados válidos ao fetch_only
                    $params['fetch_only'] .= ',' . implode(',', array_map(function($id) {
                        return 'meta:' . $id;
                    }, $valid_requested));
                }
            }
            unset($params['fetch_only_meta']);
        }
        
        // Adiciona parâmetros à URL
        $url = add_query_arg($params, $endpoint);
        
        error_log('TEI Debug - Fetching items from: ' . $url);
        
        // Faz requisição com headers apropriados
        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]);
        
        if (is_wp_error($response)) {
            error_log('TEI Error - API request failed: ' . $response->get_error_message());
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Erro ao decodificar resposta', 'tainacan-explorador'));
        }
        
        // Processa resposta do Tainacan v2
        $items = [];
        $total = 0;
        
        // Tainacan v2 retorna diretamente array de itens
        if (is_array($data)) {
            $items = $data;
            // Obtém total do header
            $headers = wp_remote_retrieve_headers($response);
            if (isset($headers['x-wp-total'])) {
                $total = intval($headers['x-wp-total']);
            } else {
                $total = count($items);
            }
        }
        
        error_log('TEI Debug - Items found: ' . count($items));
        
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
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]);
        
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (is_array($data)) {
                foreach ($data as $meta) {
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
        
        // Cache result
        if (!empty($metadata)) {
            TEI_Cache_Manager::set($cache_key, $metadata, DAY_IN_SECONDS);
        }
        
        return $metadata;
    }
    
    /**
     * Normaliza estrutura do item do Tainacan
     * 
     * @param array $item Item bruto da API
     * @param int $collection_id ID da coleção
     * @return array
     */
    private function normalize_item($item, $collection_id) {
        // Obtém ID do item de forma segura
        $item_id = 0;
        if (isset($item['id'])) {
            $item_id = $item['id'];
        } elseif (isset($item['ID'])) {
            $item_id = $item['ID'];
        } elseif (isset($item['item_id'])) {
            $item_id = $item['item_id'];
        }
        
        $normalized = [
            'id' => $item_id,
            'title' => '',
            'description' => '',
            'url' => '',
            'thumbnail' => [],
            'document' => '',
            '_attachments' => [],
            'metadata' => []
        ];
        
        // Título
        if (isset($item['title'])) {
            if (is_array($item['title'])) {
                $normalized['title'] = $item['title']['rendered'] ?? $item['title']['value'] ?? '';
            } else {
                $normalized['title'] = $item['title'];
            }
        }
        
        // Descrição  
        if (isset($item['description'])) {
            if (is_array($item['description'])) {
                $normalized['description'] = $item['description']['rendered'] ?? $item['description']['value'] ?? '';
            } else {
                $normalized['description'] = $item['description'];
            }
        }
        
        // URL
        if (isset($item['url'])) {
            $normalized['url'] = $item['url'];
        } elseif (isset($item['link'])) {
            $normalized['url'] = $item['link'];
        } elseif ($item_id) {
            // Constrói URL padrão do Tainacan
            $normalized['url'] = home_url("/colecao/{$collection_id}/item/{$item_id}");
        }
        
        // Thumbnail
        if (isset($item['thumbnail'])) {
            $normalized['thumbnail'] = $item['thumbnail'];
        } elseif (isset($item['_thumbnail_id'])) {
            // Busca thumbnail por ID
            $normalized['thumbnail'] = $this->get_thumbnail_sizes($item['_thumbnail_id']);
        }
        
        // Document
        if (isset($item['document'])) {
            $normalized['document'] = $item['document'];
        }
        
        // Attachments
        if (isset($item['_attachments'])) {
            $normalized['_attachments'] = $item['_attachments'];
        }
        
        // Processa metadados - Estrutura correta do Tainacan v2
        // Os metadados vêm diretamente no item com chave meta:<id>
        foreach ($item as $key => $value) {
            // Verifica se é um metadado (começa com 'meta:' ou é numérico)
            if (strpos($key, 'meta:') === 0 || is_numeric($key)) {
                $meta_id = str_replace('meta:', '', $key);
                
                // Se o valor é array com estrutura de metadado
                if (is_array($value) && isset($value['value'])) {
                    $normalized['metadata'][$meta_id] = [
                        'id' => $meta_id,
                        'name' => $value['name'] ?? '',
                        'value' => $value['value'] ?? '',
                        'value_as_html' => $value['value_as_html'] ?? '',
                        'value_as_string' => $value['value_as_string'] ?? ''
                    ];
                } else {
                    // Valor direto
                    $normalized['metadata'][$meta_id] = [
                        'id' => $meta_id,
                        'name' => '',
                        'value' => $value,
                        'value_as_html' => $value,
                        'value_as_string' => is_array($value) ? json_encode($value) : (string)$value
                    ];
                }
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('TEI Debug - Added metadata: ' . $meta_id . ' = ' . json_encode($value));
                }
            }
        }
        
        // Fallback: Se não encontrou metadados no formato esperado, tenta formato antigo
        if (empty($normalized['metadata']) && isset($item['metadata']) && is_array($item['metadata'])) {
            foreach ($item['metadata'] as $meta_key => $meta_value) {
                if (is_numeric($meta_key)) {
                    $normalized['metadata'][$meta_key] = [
                        'id' => $meta_key,
                        'name' => '',
                        'value' => $meta_value,
                        'value_as_html' => $meta_value,
                        'value_as_string' => is_array($meta_value) ? json_encode($meta_value) : (string)$meta_value
                    ];
                }
            }
        }
        
        error_log('TEI Debug - Total metadata processed: ' . count($normalized['metadata']));
        
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
}
