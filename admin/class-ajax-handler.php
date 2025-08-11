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
    
    private $api_base;
    private $timeout = 30;
    
    public function __construct() {
        $this->api_base = rest_url('tainacan/v2/');
    }
    
    /**
     * Obtém itens de uma coleção com metadados completos
     */
    public function get_collection_items($collection_id, $params = []) {
        if (!$collection_id || !is_numeric($collection_id)) {
            return new WP_Error('invalid_collection', __('ID da coleção inválido', 'tainacan-explorador'));
        }
        
        // Cache check
        $cache_key = 'tei_items_' . $collection_id . '_' . md5(serialize($params));
        $cached = TEI_Cache_Manager::get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Busca itens primeiro
        $endpoint = $this->api_base . "collection/{$collection_id}/items";
        
        $defaults = [
            'perpage' => 100,
            'paged' => 1,
            'order' => 'DESC',
            'orderby' => 'date'
        ];
        
        $params = wp_parse_args($params, $defaults);
        $url = add_query_arg($params, $endpoint);
        
        error_log('TEI Debug - Fetching items from: ' . $url);
        
        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => ['Content-Type' => 'application/json']
        ]);
        
        if (is_wp_error($response)) {
            error_log('TEI Error - API request failed: ' . $response->get_error_message());
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $items = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Erro ao decodificar resposta', 'tainacan-explorador'));
        }
        
        $total = count($items);
        $headers = wp_remote_retrieve_headers($response);
        if (isset($headers['x-wp-total'])) {
            $total = intval($headers['x-wp-total']);
        }
        
        // Para cada item, busca metadados completos individualmente
        $processed_items = [];
        foreach ($items as $item) {
            $item_with_meta = $this->get_item_with_full_metadata($collection_id, $item['id']);
            if (!is_wp_error($item_with_meta)) {
                $processed_items[] = $item_with_meta;
            } else {
                // Fallback para item sem metadados
                $processed_items[] = $this->normalize_item($item, $collection_id);
            }
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
     * Busca item com metadados completos
     */
    private function get_item_with_full_metadata($collection_id, $item_id) {
        // Tenta primeiro o endpoint direto do item com fetch_only=metadata
        $url = $this->api_base . "items/{$item_id}?fetch_only=metadata";
        
        error_log('TEI Debug - Fetching full item with metadata from: ' . $url);
        
        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => ['Content-Type' => 'application/json']
        ]);
        
        if (is_wp_error($response)) {
            error_log('TEI Error - Failed to fetch item: ' . $response->get_error_message());
            return $response;
        }
        
        $item_data = json_decode(wp_remote_retrieve_body($response), true);
        
        // Se não tem metadados, tenta endpoint alternativo
        if (!isset($item_data['metadata']) || empty($item_data['metadata'])) {
            $alt_url = $this->api_base . "item/{$item_id}/metadata";
            error_log('TEI Debug - Trying alternative endpoint: ' . $alt_url);
            
            $alt_response = wp_remote_get($alt_url, [
                'timeout' => $this->timeout,
                'headers' => ['Content-Type' => 'application/json']
            ]);
            
            if (!is_wp_error($alt_response)) {
                $metadata_raw = json_decode(wp_remote_retrieve_body($alt_response), true);
                if (is_array($metadata_raw)) {
                    $item_data['metadata'] = $metadata_raw;
                }
            }
        }
        
        // Normaliza dados
        $normalized = [
            'id' => $item_id,
            'title' => '',
            'description' => '',
            'url' => $item_data['url'] ?? '',
            'thumbnail' => $item_data['thumbnail'] ?? [],
            'document' => $item_data['document'] ?? '',
            '_attachments' => $item_data['_attachments'] ?? [],
            'metadata' => []
        ];
        
        // Processa título e descrição
        if (isset($item_data['title'])) {
            $normalized['title'] = is_array($item_data['title']) ? 
                ($item_data['title']['rendered'] ?? $item_data['title']['value'] ?? '') : 
                $item_data['title'];
        }
        
        if (isset($item_data['description'])) {
            $normalized['description'] = is_array($item_data['description']) ? 
                ($item_data['description']['rendered'] ?? $item_data['description']['value'] ?? '') : 
                $item_data['description'];
        }
        
        // Processa metadados - FORMATO TAINACAN
        if (isset($item_data['metadata']) && is_array($item_data['metadata'])) {
            foreach ($item_data['metadata'] as $meta) {
                // Formato com metadatum_id
                if (isset($meta['metadatum_id'])) {
                    $meta_id = $meta['metadatum_id'];
                    $normalized['metadata'][$meta_id] = [
                        'id' => $meta_id,
                        'name' => $meta['metadatum']['name'] ?? '',
                        'value' => $meta['value'] ?? '',
                        'value_as_html' => $meta['value_as_html'] ?? '',
                        'value_as_string' => $meta['value_as_string'] ?? ''
                    ];
                    error_log('TEI Debug - Added metadata (format 1): ' . $meta_id . ' = ' . json_encode($meta['value'] ?? ''));
                }
                // Formato alternativo
                elseif (isset($meta['id']) && isset($meta['value'])) {
                    $meta_id = $meta['id'];
                    $normalized['metadata'][$meta_id] = [
                        'id' => $meta_id,
                        'name' => $meta['name'] ?? '',
                        'value' => $meta['value'] ?? '',
                        'value_as_html' => $meta['value_as_html'] ?? '',
                        'value_as_string' => $meta['value_as_string'] ?? ''
                    ];
                    error_log('TEI Debug - Added metadata (format 2): ' . $meta_id . ' = ' . json_encode($meta['value'] ?? ''));
                }
            }
        }
        
        // Se ainda não tem metadados, tenta buscar por query direta
        if (empty($normalized['metadata'])) {
            global $wpdb;
            $post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'tainacan_item_id' AND meta_value = %d LIMIT 1",
                $item_id
            ));
            
            if (!$post_id) {
                // Tenta pelo post type
                $post_type = 'tnc_col_' . $collection_id . '_item';
                $post_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID = %d LIMIT 1",
                    $post_type,
                    $item_id
                ));
            }
            
            if ($post_id) {
                $metas = get_post_meta($post_id);
                foreach ($metas as $key => $values) {
                    if (is_numeric($key)) {
                        $normalized['metadata'][$key] = [
                            'id' => $key,
                            'value' => $values[0] ?? ''
                        ];
                        error_log('TEI Debug - Added metadata from DB: ' . $key . ' = ' . $values[0]);
                    }
                }
            }
        }
        
        error_log('TEI Debug - Item ' . $item_id . ' has ' . count($normalized['metadata']) . ' metadata fields');
        
        return $normalized;
    }
    
    /**
     * Obtém metadados de uma coleção
     */
    public function get_collection_metadata($collection_id) {
        $cache_key = 'tei_metadata_' . $collection_id;
        $cached = TEI_Cache_Manager::get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $metadata = [];
        $endpoint = $this->api_base . "collection/{$collection_id}/metadata";
        $url = add_query_arg(['perpage' => 999], $endpoint);
        
        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => ['Content-Type' => 'application/json']
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
                        'type' => $this->extract_metadata_type($meta),
                        'required' => $meta['required'] ?? false,
                        'multiple' => $meta['multiple'] ?? false,
                        'collection_key' => $meta['collection_key'] ?? false
                    ];
                }
            }
        }
        
        if (!empty($metadata)) {
            TEI_Cache_Manager::set($cache_key, $metadata, DAY_IN_SECONDS);
        }
        
        return $metadata;
    }
    
    /**
     * Extrai tipo de metadado
     */
    private function extract_metadata_type($meta) {
        if (isset($meta['metadata_type_object']['name'])) {
            return $meta['metadata_type_object']['name'];
        }
        
        if (isset($meta['metadata_type'])) {
            $parts = explode('\\', $meta['metadata_type']);
            return end($parts);
        }
        
        return 'Text';
    }
    
    /**
     * Normaliza estrutura do item (fallback)
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
        
        // Campos básicos
        if (isset($item['title'])) {
            $normalized['title'] = is_array($item['title']) ? 
                ($item['title']['rendered'] ?? $item['title']['value'] ?? '') : 
                $item['title'];
        }
        
        if (isset($item['description'])) {
            $normalized['description'] = is_array($item['description']) ? 
                ($item['description']['rendered'] ?? $item['description']['value'] ?? '') : 
                $item['description'];
        }
        
        $normalized['url'] = $item['url'] ?? $item['link'] ?? '';
        $normalized['thumbnail'] = $item['thumbnail'] ?? [];
        $normalized['document'] = $item['document'] ?? '';
        $normalized['_attachments'] = $item['_attachments'] ?? [];
        
        return $normalized;
    }
    
    /**
     * Geocodifica um endereço
     */
    public function geocode_address($address) {
        if (empty($address)) {
            return null;
        }
        
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
        
        if ($result) {
            TEI_Cache_Manager::set($cache_key, $result, 30 * DAY_IN_SECONDS);
        }
        
        return $result;
    }
    
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
    
    private function geocode_google($address, $api_key) {
        if (empty($api_key)) {
            return null;
        }
        
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
}
