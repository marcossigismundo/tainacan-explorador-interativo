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
        $atts = TEI_Sanitizer::sanitize_shortcode_atts($atts, [
            'collection' => '',
            'height' => '500px',
            'width' => '100%',
            'zoom' => 10,
            'center' => '',
            'style' => 'streets',
            'cluster' => true,
            'fullscreen' => true,
            'filter' => '',
            'limit' => 100,
            'cache' => true,
            'class' => '',
            'id' => 'tei-map-' . uniqid()
        ]);
        
        // Validação da coleção
        if (empty($atts['collection']) || !is_numeric($atts['collection'])) {
            return $this->render_error(__('ID da coleção não especificado ou inválido.', 'tainacan-explorador'));
        }
        
        $collection_id = intval($atts['collection']);
        
        // Obtém mapeamento
        $mapping = TEI_Metadata_Mapper::get_mapping($collection_id, 'map');
        
        if (!$mapping) {
            return $this->render_error(__('Mapeamento não configurado para esta coleção.', 'tainacan-explorador'));
        }
        
        // Obtém dados da coleção
        $collection_data = $this->get_collection_data($collection_id, $mapping, $atts);
        
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
     */
    private function get_collection_data($collection_id, $mapping, $atts) {
        // Gera chave de cache única baseada nos parâmetros
        $cache_key_parts = [
            'map_data',
            $collection_id,
            md5(serialize([
                'limit' => $atts['limit'],
                'filter' => $atts['filter'],
                'mapping' => $mapping['mapping_data']
            ]))
        ];
        
        $cache_key = 'tei_' . implode('_', $cache_key_parts);
        
        // Verifica cache
        if ($atts['cache']) {
            $cached_data = TEI_Cache_Manager::get($cache_key);
            
            if ($cached_data !== false) {
                return $cached_data;
            }
        }
        
        // Prepara parâmetros da API
        $api_params = [
            'perpage' => intval($atts['limit']),
            'paged' => 1,
            'fetch_only' => 'title,description,thumbnail,document,url'
        ];
        
        // Adiciona metadados necessários
        $required_metadata = array_filter(array_values($mapping['mapping_data']));
        if (!empty($required_metadata)) {
            $api_params['fetch_only_meta'] = implode(',', $required_metadata);
        }
        
        // Adiciona filtros se especificado
        if (!empty($atts['filter'])) {
            $api_params['metaquery'] = $this->parse_filter($atts['filter']);
        }
        
        // Aplica filtros do mapeamento
        if (!empty($mapping['filter_rules'])) {
            $api_params = TEI_Metadata_Mapper::apply_filters($api_params, $mapping['filter_rules']);
        }
        
        // Faz requisição à API do Tainacan
        $api_handler = new TEI_API_Handler();
        $response = $api_handler->get_collection_items($collection_id, $api_params);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Salva no cache
        if ($atts['cache'] && !empty($response)) {
            TEI_Cache_Manager::set($cache_key, $response, HOUR_IN_SECONDS);
        }
        
        return $response;
    }
    
    /**
     * Prepara dados para o mapa
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
        
        // Processa cada item
        $items = $collection_data['items'] ?? $collection_data;
        
        foreach ($items as $item) {
            // Extrai coordenadas
            $coordinates = $this->extract_coordinates($item, $location_field);
            
            if (!$coordinates) {
                continue;
            }
            
            // Prepara propriedades do marcador
            $properties = [
                'id' => $item['id'] ?? '',
                'title' => $this->get_field_value($item, $title_field, $item['title'] ?? ''),
                'description' => $this->get_field_value($item, $description_field, ''),
                'image' => $this->get_image_url($item, $image_field),
                'link' => $this->get_field_value($item, $link_field, $item['url'] ?? ''),
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
     */
    private function extract_coordinates($item, $location_field) {
        if (empty($location_field)) {
            return null;
        }
        
        $location_value = $this->get_field_value($item, $location_field);
        
        if (empty($location_value)) {
            return null;
        }
        
        // Tenta extrair coordenadas do valor
        $coords = TEI_Sanitizer::sanitize($location_value, 'coordinates');
        
        if ($coords) {
            return [$coords['lon'], $coords['lat']]; // [lon, lat] para GeoJSON
        }
        
        // Tenta geocoding se for endereço
        if (is_string($location_value) && !empty($location_value)) {
            return $this->geocode_address($location_value);
        }
        
        return null;
    }
    
    /**
     * Geocodifica um endereço
     */
    private function geocode_address($address) {
        // Verifica cache
        $cache_key = 'geocode_' . md5($address);
        $cached = TEI_Cache_Manager::get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Usa API handler para geocoding
        $api = new TEI_API_Handler();
        $result = $api->geocode_address($address);
        
        if ($result && isset($result['lat']) && isset($result['lon'])) {
            $coordinates = [$result['lon'], $result['lat']];
            // Cache por 30 dias
            TEI_Cache_Manager::set($cache_key, $coordinates, 30 * DAY_IN_SECONDS);
            return $coordinates;
        }
        
        return null;
    }
    
    /**
     * Obtém valor de um campo
     */
    private function get_field_value($item, $field_id, $default = '') {
        if (empty($field_id)) {
            return $default;
        }
        
        // Verifica metadados
        if (isset($item['metadata']) && isset($item['metadata'][$field_id])) {
            $metadata = $item['metadata'][$field_id];
            
            // Diferentes formatos possíveis
            if (isset($metadata['value'])) {
                $value = $metadata['value'];
            } elseif (isset($metadata['value_as_string'])) {
                $value = $metadata['value_as_string'];
            } else {
                $value = $metadata;
            }
            
            // Se for array, pega o primeiro valor
            if (is_array($value) && !empty($value)) {
                return reset($value);
            }
            
            return $value;
        }
        
        // Verifica campos padrão do item
        if (isset($item[$field_id])) {
            return $item[$field_id];
        }
        
        // Campos especiais
        switch ($field_id) {
            case 'title':
                return $item['title'] ?? $default;
            case 'description':
                return $item['description'] ?? $item['excerpt'] ?? $default;
            case 'thumbnail':
                return isset($item['thumbnail']) ? $item['thumbnail']['medium'] ?? '' : $default;
            default:
                return $default;
        }
    }
    
    /**
     * Obtém URL da imagem
     */
    private function get_image_url($item, $image_field) {
        // Primeiro tenta o campo especificado
        if (!empty($image_field)) {
            $image_value = $this->get_field_value($item, $image_field);
            
            if (!empty($image_value)) {
                // Se for ID de attachment
                if (is_numeric($image_value)) {
                    $image_url = wp_get_attachment_image_url($image_value, 'medium');
                    if ($image_url) {
                        return $image_url;
                    }
                }
                // Se já for URL
                elseif (filter_var($image_value, FILTER_VALIDATE_URL)) {
                    return $image_value;
                }
            }
        }
        
        // Fallback para thumbnail do item
        if (isset($item['thumbnail'])) {
            if (is_array($item['thumbnail'])) {
                return $item['thumbnail']['medium'] ?? $item['thumbnail']['full'] ?? '';
            }
            return $item['thumbnail'];
        }
        
        return '';
    }
    
    /**
     * Gera HTML do popup
     */
    private function generate_popup_html($properties) {
        $html = '<div class="tei-map-popup">';
        
        if (!empty($properties['image'])) {
            $html .= '<div class="tei-popup-image">';
            $html .= '<img src="' . esc_url($properties['image']) . '" alt="' . esc_attr($properties['title']) . '">';
            $html .= '</div>';
        }
        
        $html .= '<div class="tei-popup-content">';
        
        if (!empty($properties['title'])) {
            $html .= '<h3 class="tei-popup-title">' . esc_html($properties['title']) . '</h3>';
        }
        
        if (!empty($properties['description'])) {
            $html .= '<div class="tei-popup-description">' . wp_kses_post($properties['description']) . '</div>';
        }
        
        if (!empty($properties['link'])) {
            $html .= '<div class="tei-popup-link">';
            $html .= '<a href="' . esc_url($properties['link']) . '" target="_blank" class="tei-popup-button">';
            $html .= __('Ver mais detalhes', 'tainacan-explorador');
            $html .= '</a>';
            $html .= '</div>';
        }
        
        $html .= '</div></div>';
        
        return $html;
    }
    
    /**
     * Obtém configurações do mapa
     */
    private function get_map_config($atts, $mapping) {
        $settings = $mapping['visualization_settings'] ?? [];
        
        $config = [
            'zoom' => intval($atts['zoom']),
            'center' => $this->parse_center($atts['center']),
            'style' => $atts['style'],
            'cluster' => filter_var($atts['cluster'], FILTER_VALIDATE_BOOLEAN),
            'fullscreen' => filter_var($atts['fullscreen'], FILTER_VALIDATE_BOOLEAN),
            'scrollWheelZoom' => $settings['scroll_zoom'] ?? true,
            'dragging' => $settings['dragging'] ?? true,
            'doubleClickZoom' => $settings['double_click_zoom'] ?? true,
            'attribution' => $settings['attribution'] ?? '© OpenStreetMap contributors'
        ];
        
        // Tile layer baseado no estilo
        $config['tileLayer'] = $this->get_tile_layer($config['style']);
        
        return apply_filters('tei_map_config', $config, $atts, $mapping);
    }
    
    /**
     * Parse do centro do mapa
     */
    private function parse_center($center) {
        if (empty($center)) {
            return [-15.7801, -47.9292]; // Brasília como padrão
        }
        
        $coords = TEI_Sanitizer::sanitize($center, 'coordinates');
        
        if ($coords) {
            return [$coords['lat'], $coords['lon']];
        }
        
        return [-15.7801, -47.9292];
    }
    
    /**
     * Obtém tile layer baseado no estilo
     */
    private function get_tile_layer($style) {
        $layers = [
            'streets' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            'satellite' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            'terrain' => 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
            'dark' => 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
            'light' => 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png'
        ];
        
        return $layers[$style] ?? $layers['streets'];
    }
    
    /**
     * Parse de filtros
     */
    private function parse_filter($filter) {
        // Implementar parser de filtros
        // Formato: field:operator:value,field2:operator2:value2
        $filters = [];
        
        if (!empty($filter)) {
            $parts = explode(',', $filter);
            foreach ($parts as $part) {
                $filter_parts = explode(':', $part);
                if (count($filter_parts) === 3) {
                    $filters[] = [
                        'key' => trim($filter_parts[0]),
                        'compare' => trim($filter_parts[1]),
                        'value' => trim($filter_parts[2])
                    ];
                }
            }
        }
        
        return $filters;
    }
    
    /**
     * Renderiza o mapa
     */
    private function render_map($map_id, $map_data, $config, $atts) {
        ob_start();
        ?>
        <div class="tei-map-wrapper <?php echo esc_attr($atts['class']); ?>" 
             style="width: <?php echo esc_attr($atts['width']); ?>; height: <?php echo esc_attr($atts['height']); ?>;">
            
            <div id="<?php echo esc_attr($map_id); ?>" 
                 class="tei-map-container" 
                 style="width: 100%; height: 100%;"
                 data-map-config='<?php echo esc_attr(wp_json_encode($config)); ?>'
                 data-map-data='<?php echo esc_attr(wp_json_encode($map_data)); ?>'>
                
                <div class="tei-map-loading">
                    <span class="spinner is-active"></span>
                    <p><?php _e('Carregando mapa...', 'tainacan-explorador'); ?></p>
                </div>
            </div>
            
            <?php if ($config['fullscreen']): ?>
            <button class="tei-map-fullscreen" 
                    aria-label="<?php esc_attr_e('Tela cheia', 'tainacan-explorador'); ?>"
                    title="<?php esc_attr_e('Tela cheia', 'tainacan-explorador'); ?>">
                <span class="dashicons dashicons-fullscreen-alt"></span>
            </button>
            <?php endif; ?>
            
            <?php if (!empty($map_data['features'])): ?>
            <div class="tei-map-search">
                <input type="text" 
                       class="tei-map-search-input" 
                       placeholder="<?php esc_attr_e('Buscar no mapa...', 'tainacan-explorador'); ?>">
                <button class="tei-map-search-clear" style="display:none;">
                    <span class="dashicons dashicons-no"></span>
                </button>
            </div>
            <?php endif; ?>
        </div>
        
        <script type="text/javascript">
        (function() {
            // Aguarda o carregamento do Leaflet
            function initMap() {
                if (typeof L === 'undefined' || !L.map) {
                    setTimeout(initMap, 100);
                    return;
                }
                
                var mapId = '<?php echo esc_js($map_id); ?>';
                var container = document.getElementById(mapId);
                
                if (!container) return;
                
                var config = JSON.parse(container.getAttribute('data-map-config'));
                var data = JSON.parse(container.getAttribute('data-map-data'));
                
                // Inicializa o mapa
                var map = L.map(mapId, {
                    center: config.center,
                    zoom: config.zoom,
                    scrollWheelZoom: config.scrollWheelZoom,
                    dragging: config.dragging,
                    doubleClickZoom: config.doubleClickZoom
                });
                
                // Adiciona tile layer
                L.tileLayer(config.tileLayer, {
                    attribution: config.attribution
                }).addTo(map);
                
                // Adiciona marcadores
                if (data.features && data.features.length > 0) {
                    var markers = config.cluster ? L.markerClusterGroup() : L.featureGroup();
                    
                    data.features.forEach(function(feature) {
                        if (feature.geometry && feature.geometry.coordinates) {
                            var coords = [
                                feature.geometry.coordinates[1], // lat
                                feature.geometry.coordinates[0]  // lon
                            ];
                            
                            var marker = L.marker(coords);
                            
                            if (feature.properties && feature.properties.popup_html) {
                                marker.bindPopup(feature.properties.popup_html);
                            }
                            
                            markers.addLayer(marker);
                        }
                    });
                    
                    map.addLayer(markers);
                    
                    // Ajusta o zoom para mostrar todos os marcadores
                    if (data.features.length > 1) {
                        map.fitBounds(markers.getBounds(), { padding: [50, 50] });
                    }
                }
                
                // Remove loading
                container.querySelector('.tei-map-loading').style.display = 'none';
            }
            
            // Inicia quando DOM estiver pronto
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initMap);
            } else {
                initMap();
            }
        })();
        </script>
        
        <style>
        .tei-map-wrapper {
            position: relative;
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .tei-map-loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            z-index: 1000;
        }
        
        .tei-map-fullscreen {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 1000;
            background: white;
            border: 2px solid rgba(0,0,0,0.2);
            border-radius: 4px;
            padding: 5px;
            cursor: pointer;
        }
        
        .tei-map-search {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 1000;
            background: white;
            border-radius: 4px;
            padding: 5px;
            display: flex;
            align-items: center;
            box-shadow: 0 1px 5px rgba(0,0,0,0.4);
        }
        
        .tei-map-search-input {
            border: none;
            padding: 5px 10px;
            width: 200px;
            outline: none;
        }
        
        .tei-popup-image img {
            max-width: 200px;
            height: auto;
            margin-bottom: 10px;
        }
        
        .tei-popup-title {
            margin: 0 0 10px 0;
            font-size: 16px;
            font-weight: bold;
        }
        
        .tei-popup-description {
            margin-bottom: 10px;
            color: #666;
        }
        
        .tei-popup-button {
            display: inline-block;
            padding: 5px 15px;
            background: #0073aa;
            color: white;
            text-decoration: none;
            border-radius: 3px;
        }
        
        .tei-popup-button:hover {
            background: #005177;
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Renderiza erro
     */
    private function render_error($message) {
        return '<div class="tei-error notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }
}
