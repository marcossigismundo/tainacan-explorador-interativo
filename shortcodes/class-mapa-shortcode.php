<?php
/**
 * Shortcode para visualização de Mapa
 * Arquivo: shortcodes/class-mapa-shortcode.php
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
     */
    private function get_collection_data($collection_id, $mapping, $atts) {
        // Verifica cache
        if ($atts['cache']) {
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
     */
    private function extract_coordinates($item, $location_field) {
        if (empty($location_field)) {
            return null;
        }
        
        $location_value = $this->get_field_value($item, $location_field);
        
        if (empty($location_value)) {
            return null;
        }
        
        // Usa TEI_Sanitizer para coordenadas
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
        $cache_key = 'tei_geocode_' . md5($address);
        $cached = TEI_Cache_Manager::get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Obtém configurações
        $settings = get_option('tei_settings', []);
        $service = $settings['geocoding_service'] ?? 'nominatim';
        $api_key = $settings['geocoding_api_key'] ?? '';
        
        $coordinates = null;
        
        // Usa API handler para geocoding
        $api = new TEI_API_Handler();
        $result = $api->geocode_address($address);
        
        if ($result && isset($result['lat']) && isset($result['lon'])) {
            $coordinates = [$result['lon'], $result['lat']];
            // Cache por 30 dias
            TEI_Cache_Manager::set($cache_key, $coordinates, 30 * DAY_IN_SECONDS);
        }
        
        return $coordinates;
    }
    
    /**
     * Obtém valor de um campo
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
     */
    private function get_map_config($atts, $mapping) {
        $settings = $mapping['visualization_settings'] ?? [];
        
        $config = [
            'zoom' => $atts['zoom'],
            'style' => $atts['style'],
            'cluster' => $atts['cluster'],
            'fullscreen' => $atts['fullscreen'],
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
                'fullscreen' => $atts['fullscreen'],
                'layers' => false,
                'scale' => true,
                'attribution' => true
            ]
        ];
        
        return apply_filters('tei_map_config', $config, $atts, $mapping);
    }
    
    /**
     * Parse do centro do mapa
     */
    private function parse_center($center) {
        if (empty($center)) {
            return null;
        }
        
        $coords = TEI_Sanitizer::sanitize($center, 'coordinates');
        if ($coords) {
            return [$coords['lat'], $coords['lon']];
        }
        
        return null;
    }
    
    /**
     * Obtém camada de tiles baseada no estilo
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
     */
    private function render_map($map_id, $map_data, $config, $atts) {
        ob_start();
        ?>
        <div class="tei-map-container <?php echo esc_attr($atts['class']); ?>" 
     style="width: 100vw; height: 100vh; position: relative; margin: 0;">
            
            <div id="<?php echo esc_attr($map_id); ?>" 
                 class="tei-map" 
                 data-tei-map="true"
                 data-tei-map-config='<?php echo esc_attr(wp_json_encode($config)); ?>'
                 data-tei-map-data='<?php echo esc_attr(wp_json_encode($map_data)); ?>'
                 style="height: 100%; width: 100%;">
            </div>
            
            <div class="tei-loading-overlay">
                <div class="tei-loading-content">
                    <div class="tei-spinner"></div>
                    <p><?php esc_html_e('Carregando mapa...', 'tainacan-explorador'); ?></p>
                </div>
            </div>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Renderiza mensagem de erro
     */
    private function render_error($message) {
        return sprintf(
            '<div class="tei-error"><p>%s</p></div>',
            esc_html($message)
        );
    }
    
    /**
     * Parse de filtros
     */
    private function parse_filter($filter) {
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
