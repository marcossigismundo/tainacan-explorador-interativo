<?php
/**
 * Shortcode para visualização de Mapa
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_Mapa_Shortcode {
    
    /**
     * Renderiza o shortcode do mapa
     * 
     * @param array $atts Atributos do shortcode
     * @return string HTML do mapa
     */
    public function render($atts) {
        // Parse dos atributos
        $atts = shortcode_atts([
            'collection' => '',
            'height' => '500px',
            'width' => '100%',
            'zoom' => 10,
            'center' => '',
            'style' => 'streets',
            'cluster' => 'true',
            'fullscreen' => 'true',
            'filter' => '',
            'limit' => 100,
            'cache' => 'true',
            'class' => '',
            'id' => 'tei-map-' . uniqid()
        ], $atts, 'tainacan_explorador_mapa');
        
        // Validação da coleção
        if (empty($atts['collection'])) {
            return $this->render_error(__('ID da coleção não especificado.', 'tainacan-explorador'));
        }
        
        // Obtém mapeamento
        $mapping = TEI_Metadata_Mapper::get_mapping($atts['collection'], 'map');
        
        if (!$mapping) {
            return $this->render_error(__('Mapeamento não configurado para esta coleção.', 'tainacan-explorador'));
        }
        
        // Obtém dados da coleção
        $collection_data = $this->get_collection_data($atts['collection'], $mapping, $atts);
        
        if (is_wp_error($collection_data)) {
            return $this->render_error($collection_data->get_error_message());
        }
        
        // Prepara dados para o mapa
        $map_data = $this->prepare_map_data($collection_data, $mapping);
        
        // Gera configurações do mapa
        $map_config = $this->get_map_config($atts, $mapping);
        
        // Renderiza o template
        return $this->render_map($atts['id'], $map_data, $map_config, $atts);
    }
    
    /**
     * Obtém dados da coleção via API do Tainacan
     * 
     * @param int $collection_id ID da coleção
     * @param array $mapping Mapeamento de metadados
     * @param array $atts Atributos do shortcode
     * @return array|WP_Error
     */
    private function get_collection_data($collection_id, $mapping, $atts) {
        // Verifica cache
        if ($atts['cache'] === 'true') {
            $cache_key = 'tei_map_data_' . $collection_id . '_' . md5(serialize($atts));
            $cached_data = TEI_Cache_Manager::get($cache_key);
            
            if ($cached_data !== false) {
                return $cached_data;
            }
        }
        
        // Prepara parâmetros da API
        $api_params = [
            'perpage' => $atts['limit'],
            'paged' => 1,
            'fetch_only' => 'title,description,thumbnail',
            'fetch_only_meta' => implode(',', array_values($mapping['mapping_data']))
        ];
        
        // Adiciona filtros se especificado
        if (!empty($atts['filter'])) {
            $api_params['metaquery'] = $this->parse_filter($atts['filter']);
        }
        
        // Faz requisição à API do Tainacan
        $api_handler = new TEI_API_Handler();
        $response = $api_handler->get_collection_items($collection_id, $api_params);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Salva no cache
        if ($atts['cache'] === 'true' && !empty($response)) {
            TEI_Cache_Manager::set($cache_key, $response, HOUR_IN_SECONDS);
        }
        
        return $response;
    }
    
    /**
     * Prepara dados para o mapa
     * 
     * @param array $collection_data Dados da coleção
     * @param array $mapping Mapeamento
     * @return array
     */
    private function prepare_map_data($collection_data, $mapping) {
        $map_data = [
            'type' => 'FeatureCollection',
            'features' => []
        ];
        
        $location_field = $mapping['mapping_data']['location'] ?? '';
        $title_field = $mapping['mapping_data']['title'] ?? '';
        $description_field = $mapping['mapping_data']['description'] ?? '';
        $image_field = $mapping['mapping_data']['image'] ?? '';
        $link_field = $mapping['mapping_data']['link'] ?? '';
        $category_field = $mapping['mapping_data']['category'] ?? '';
        
        foreach ($collection_data['items'] as $item) {
            // Extrai coordenadas
            $coordinates = $this->extract_coordinates($item, $location_field);
            
            if (!$coordinates) {
                continue;
            }
            
            // Prepara propriedades do marcador
            $properties = [
                'id' => $item['id'],
                'title' => $this->get_field_value($item, $title_field, $item['title']),
                'description' => $this->get_field_value($item, $description_field, ''),
                'image' => $this->get_image_url($item, $image_field),
                'link' => $this->get_field_value($item, $link_field, $item['url']),
                'category' => $this->get_field_value($item, $category_field, ''),
                'popup_html' => ''
            ];
            
            // Gera HTML do popup
            $properties['popup_html'] = $this->generate_popup_html($properties);
            
            // Adiciona feature ao mapa
            $map_data['features'][] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => $coordinates
                ],
                'properties' => $properties
            ];
        }
        
        return $map_data;
    }
    
    /**
     * Extrai coordenadas de um item
     * 
     * @param array $item Item da coleção
     * @param string $location_field Campo de localização
     * @return array|null [longitude, latitude]
     */
    private function extract_coordinates($item, $location_field) {
        if (empty($location_field)) {
            return null;
        }
        
        $location_value = $this->get_field_value($item, $location_field);
        
        if (empty($location_value)) {
            return null;
        }
        
        // Tenta diferentes formatos de coordenadas
        
        // Formato: lat,lon
        if (preg_match('/^(-?\d+\.?\d*),\s*(-?\d+\.?\d*)$/', $location_value, $matches)) {
            return [(float)$matches[2], (float)$matches[1]]; // [lon, lat] para GeoJSON
        }
        
        // Formato: objeto com lat/lon
        if (is_array($location_value)) {
            if (isset($location_value['lat']) && isset($location_value['lon'])) {
                return [(float)$location_value['lon'], (float)$location_value['lat']];
            }
            if (isset($location_value['latitude']) && isset($location_value['longitude'])) {
                return [(float)$location_value['longitude'], (float)$location_value['latitude']];
            }
        }
        
        // Formato: endereço (necessita geocoding)
        if (is_string($location_value) && !empty($location_value)) {
            return $this->geocode_address($location_value);
        }
        
        return null;
    }
    
    /**
     * Geocodifica um endereço
     * 
     * @param string $address Endereço
     * @return array|null [longitude, latitude]
     */
    private function geocode_address($address) {
        // Verifica cache
        $cache_key = 'tei_geocode_' . md5($address);
        $cached = wp_cache_get($cache_key, 'tei_geocoding');
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Usa Nominatim (OpenStreetMap) para geocoding
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
        
        if (empty($data) || !isset($data[0]['lat']) || !isset($data[0]['lon'])) {
            return null;
        }
        
        $coordinates = [(float)$data[0]['lon'], (float)$data[0]['lat']];
        
        // Cache por 30 dias
        wp_cache_set($cache_key, $coordinates, 'tei_geocoding', 30 * DAY_IN_SECONDS);
        
        return $coordinates;
    }
    
    /**
     * Obtém valor de um campo
     * 
     * @param array $item Item da coleção
     * @param string $field_id ID do campo
     * @param mixed $default Valor padrão
     * @return mixed
     */
    private function get_field_value($item, $field_id, $default = '') {
        if (empty($field_id)) {
            return $default;
        }
        
        // Verifica metadados
        if (isset($item['metadata'][$field_id])) {
            $value = $item['metadata'][$field_id]['value'] ?? $default;
            
            // Se for array, pega o primeiro valor
            if (is_array($value) && !empty($value)) {
                return $value[0];
            }
            
            return $value;
        }
        
        // Verifica campos padrão
        if (isset($item[$field_id])) {
            return $item[$field_id];
        }
        
        return $default;
    }
    
    /**
     * Obtém URL da imagem
     * 
     * @param array $item Item da coleção
     * @param string $image_field Campo de imagem
     * @return string
     */
    private function get_image_url($item, $image_field) {
        // Primeiro tenta o campo especificado
        if (!empty($image_field)) {
            $image_value = $this->get_field_value($item, $image_field);
            if (!empty($image_value)) {
                if (is_numeric($image_value)) {
                    $image_url = wp_get_attachment_image_url($image_value, 'medium');
                    if ($image_url) {
                        return $image_url;
                    }
                } elseif (filter_var($image_value, FILTER_VALIDATE_URL)) {
                    return $image_value;
                }
            }
        }
        
        // Fallback para thumbnail do item
        if (isset($item['thumbnail']['medium'])) {
            return $item['thumbnail']['medium'];
        }
        
        // Placeholder
        return TEI_PLUGIN_URL . 'assets/images/placeholder.jpg';
    }
    
    /**
     * Gera HTML do popup
     * 
     * @param array $properties Propriedades do marcador
     * @return string
     */
    private function generate_popup_html($properties) {
        $html = '<div class="tei-map-popup">';
        
        if (!empty($properties['image'])) {
            $html .= '<div class="tei-popup-image">';
            $html .= '<img src="' . esc_url($properties['image']) . '" alt="' . esc_attr($properties['title']) . '">';
            $html .= '</div>';
        }
        
        $html .= '<div class="tei-popup-content">';
        $html .= '<h3 class="tei-popup-title">' . esc_html($properties['title']) . '</h3>';
        
        if (!empty($properties['category'])) {
            $html .= '<span class="tei-popup-category">' . esc_html($properties['category']) . '</span>';
        }
        
        if (!empty($properties['description'])) {
            $html .= '<div class="tei-popup-description">' . wp_kses_post($properties['description']) . '</div>';
        }
        
        if (!empty($properties['link'])) {
            $html .= '<a href="' . esc_url($properties['link']) . '" class="tei-popup-link" target="_blank">';
            $html .= __('Ver mais detalhes', 'tainacan-explorador');
            $html .= '</a>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Obtém configurações do mapa
     * 
     * @param array $atts Atributos do shortcode
     * @param array $mapping Mapeamento
     * @return array
     */
    private function get_map_config($atts, $mapping) {
        $settings = $mapping['visualization_settings'] ?? [];
        
        $config = [
            'zoom' => $atts['zoom'],
            'style' => $atts['style'],
            'cluster' => $atts['cluster'] === 'true',
            'fullscreen' => $atts['fullscreen'] === 'true',
            'center' => $this->parse_center($atts['center']),
            'tile_layer' => $this->get_tile_layer($atts['style']),
            'cluster_options' => [
                'maxClusterRadius' => $settings['cluster_radius'] ?? 80,
                'spiderfyOnMaxZoom' => true,
                'showCoverageOnHover' => true,
                'zoomToBoundsOnClick' => true
            ],
            'marker_options' => [
                'icon_url' => $settings['custom_icon'] ?? '',
                'icon_size' => $settings['icon_size'] ?? [32, 32],
                'icon_anchor' => $settings['icon_anchor'] ?? [16, 32],
                'popup_anchor' => [0, -32]
            ],
            'controls' => [
                'zoom' => true,
                'fullscreen' => $atts['fullscreen'] === 'true',
                'layers' => false,
                'scale' => true,
                'attribution' => true
            ]
        ];
        
        return apply_filters('tei_map_config', $config, $atts, $mapping);
    }
    
    /**
     * Parse do centro do mapa
     * 
     * @param string $center String de centro
     * @return array|null
     */
    private function parse_center($center) {
        if (empty($center)) {
            return null;
        }
        
        if (preg_match('/^(-?\d+\.?\d*),\s*(-?\d+\.?\d*)$/', $center, $matches)) {
            return [(float)$matches[1], (float)$matches[2]];
        }
        
        return null;
    }
    
    /**
     * Obtém camada de tiles baseada no estilo
     * 
     * @param string $style Estilo do mapa
     * @return array
     */
    private function get_tile_layer($style) {
        $layers = [
            'streets' => [
                'url' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                'attribution' => '© OpenStreetMap contributors'
            ],
            'satellite' => [
                'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                'attribution' => '© Esri'
            ],
            'terrain' => [
                'url' => 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
                'attribution' => '© OpenTopoMap'
            ],
            'dark' => [
                'url' => 'https://tiles.stadiamaps.com/tiles/alidade_smooth_dark/{z}/{x}/{y}{r}.png',
                'attribution' => '© Stadia Maps'
            ]
        ];
        
        return $layers[$style] ?? $layers['streets'];
    }
    
    /**
     * Renderiza o mapa
     * 
     * @param string $map_id ID do mapa
     * @param array $map_data Dados do mapa
     * @param array $config Configurações
     * @param array $atts Atributos
     * @return string
     */
    private function render_map($map_id, $map_data, $config, $atts) {
        ob_start();
        ?>
        <div class="tei-map-container <?php echo esc_attr($atts['class']); ?>" 
             style="height: <?php echo esc_attr($atts['height']); ?>; width: <?php echo esc_attr($atts['width']); ?>;">
            
            <div id="<?php echo esc_attr($map_id); ?>" class="tei-map" style="height: 100%; width: 100%;"></div>
            
            <div class="tei-map-loading">
                <div class="tei-spinner"></div>
                <p><?php esc_html_e('Carregando mapa...', 'tainacan-explorador'); ?></p>
            </div>
            
            <?php if ($config['fullscreen']): ?>
            <button class="tei-map-fullscreen-btn" aria-label="<?php esc_attr_e('Tela cheia', 'tainacan-explorador'); ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>
                </svg>
            </button>
            <?php endif; ?>
            
            <div class="tei-map-controls">
                <div class="tei-map-search">
                    <input type="text" 
                           placeholder="<?php esc_attr_e('Buscar no mapa...', 'tainacan-explorador'); ?>" 
                           class="tei-map-search-input">
                    <button class="tei-map-search-btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.35-4.35"/>
                        </svg>
                    </button>
                </div>
                
                <?php if (!empty($map_data['features'])): ?>
                <div class="tei-map-stats">
                    <span class="tei-map-count">
                        <?php printf(
                            esc_html__('%d locais', 'tainacan-explorador'),
                            count($map_data['features'])
                        ); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <script>
        (function() {
            const mapData = <?php echo wp_json_encode($map_data); ?>;
            const mapConfig = <?php echo wp_json_encode($config); ?>;
            const mapId = <?php echo wp_json_encode($map_id); ?>;
            
            // Inicializa o mapa quando o DOM estiver pronto
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    if (window.TEI_Map) {
                        new window.TEI_Map(mapId, mapData, mapConfig);
                    }
                });
            } else {
                if (window.TEI_Map) {
                    new window.TEI_Map(mapId, mapData, mapConfig);
                }
            }
        })();
        </script>
        
        <style>
        .tei-map-container {
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .tei-map-loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            z-index: 1000;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .tei-map.loaded + .tei-map-loading {
            display: none;
        }
        
        .tei-spinner {
            width: 40px;
            height: 40px;
            margin: 0 auto 10px;
            border: 3px solid #f3f4f6;
            border-top-color: #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .tei-map-popup {
            min-width: 250px;
            max-width: 350px;
        }
        
        .tei-popup-image {
            margin: -10px -10px 10px;
            overflow: hidden;
            border-radius: 4px 4px 0 0;
        }
        
        .tei-popup-image img {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }
        
        .tei-popup-title {
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 8px;
            color: #1f2937;
        }
        
        .tei-popup-category {
            display: inline-block;
            padding: 2px 8px;
            background: #eff6ff;
            color: #3b82f6;
            font-size: 12px;
            border-radius: 4px;
            margin-bottom: 8px;
        }
        
        .tei-popup-description {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.5;
            margin: 8px 0;
        }
        
        .tei-popup-link {
            display: inline-block;
            margin-top: 8px;
            color: #3b82f6;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        
        .tei-popup-link:hover {
            text-decoration: underline;
        }
        
        .tei-map-fullscreen-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 1000;
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .tei-map-fullscreen-btn:hover {
            background: #f3f4f6;
            border-color: #d1d5db;
        }
        
        .tei-map-controls {
            position: absolute;
            top: 10px;
            left: 50px;
            z-index: 1000;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .tei-map-search {
            display: flex;
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .tei-map-search-input {
            border: none;
            padding: 8px 12px;
            font-size: 14px;
            width: 200px;
            outline: none;
        }
        
        .tei-map-search-btn {
            background: #3b82f6;
            border: none;
            padding: 8px 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        
        .tei-map-search-btn:hover {
            background: #2563eb;
        }
        
        .tei-map-stats {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
        }
        
        /* Customização dos clusters */
        .marker-cluster {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            color: white;
            font-weight: bold;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
        }
        
        .marker-cluster-small {
            width: 40px;
            height: 40px;
            font-size: 12px;
        }
        
        .marker-cluster-medium {
            width: 50px;
            height: 50px;
            font-size: 14px;
        }
        
        .marker-cluster-large {
            width: 60px;
            height: 60px;
            font-size: 16px;
        }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Renderiza mensagem de erro
     * 
     * @param string $message Mensagem de erro
     * @return string
     */
    private function render_error($message) {
        return sprintf(
            '<div class="tei-error"><p>%s</p></div>',
            esc_html($message)
        );
    }
    
    /**
     * Parse de filtros
     * 
     * @param string $filter String de filtro
     * @return array
     */
    private function parse_filter($filter) {
        // Implementar parser de filtros
        // Formato: campo:valor,campo2:valor2
        $filters = [];
        $parts = explode(',', $filter);
        
        foreach ($parts as $part) {
            $filter_parts = explode(':', $part, 2);
            if (count($filter_parts) === 2) {
                $filters[] = [
                    'key' => trim($filter_parts[0]),
                    'value' => trim($filter_parts[1]),
                    'compare' => '='
                ];
            }
        }
        
        return $filters;
    }
}
