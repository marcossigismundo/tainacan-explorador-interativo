<?php
/**
 * Endpoints da REST API customizada
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_API_Endpoints {
    
    /**
     * Namespace da API
     */
    const NAMESPACE = 'tainacan-explorador/v1';
    
    /**
     * Registra rotas da API
     */
    public function register_routes() {
        // Rota para obter visualizações
        register_rest_route(self::NAMESPACE, '/visualizations/(?P<collection_id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_visualizations'],
            'permission_callback' => '__return_true',
            'args' => [
                'collection_id' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
                'type' => [
                    'required' => false,
                    'validate_callback' => function($param) {
                        return in_array($param, ['map', 'timeline', 'story']);
                    }
                ]
            ]
        ]);
        
        // Rota para dados do mapa
        register_rest_route(self::NAMESPACE, '/map-data/(?P<collection_id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_map_data'],
            'permission_callback' => '__return_true',
            'args' => [
                'collection_id' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
                'page' => [
                    'required' => false,
                    'default' => 1,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0;
                    }
                ],
                'per_page' => [
                    'required' => false,
                    'default' => 100,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 500;
                    }
                ],
                'bounds' => [
                    'required' => false,
                    'validate_callback' => function($param) {
                        // Formato: north,south,east,west
                        $parts = explode(',', $param);
                        return count($parts) === 4 && array_filter($parts, 'is_numeric') === $parts;
                    }
                ]
            ]
        ]);
        
        // Rota para dados da timeline
        register_rest_route(self::NAMESPACE, '/timeline-data/(?P<collection_id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_timeline_data'],
            'permission_callback' => '__return_true',
            'args' => [
                'collection_id' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
                'start_date' => [
                    'required' => false,
                    'validate_callback' => function($param) {
                        return strtotime($param) !== false;
                    }
                ],
                'end_date' => [
                    'required' => false,
                    'validate_callback' => function($param) {
                        return strtotime($param) !== false;
                    }
                ]
            ]
        ]);
        
        // Rota para dados do storytelling
        register_rest_route(self::NAMESPACE, '/story-data/(?P<collection_id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_story_data'],
            'permission_callback' => '__return_true',
            'args' => [
                'collection_id' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
                'chapter' => [
                    'required' => false,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ]
            ]
        ]);
        
        // Rota para geocoding
        register_rest_route(self::NAMESPACE, '/geocode', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'geocode_address'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
            'args' => [
                'address' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return !empty($param);
                    }
                ]
            ]
        ]);
        
        // Rota para estatísticas
        register_rest_route(self::NAMESPACE, '/stats/(?P<collection_id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_stats'],
            'permission_callback' => '__return_true',
            'args' => [
                'collection_id' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
                'type' => [
                    'required' => false,
                    'validate_callback' => function($param) {
                        return in_array($param, ['map', 'timeline', 'story']);
                    }
                ]
            ]
        ]);
        
        // Rota para configurações (requer autenticação)
        register_rest_route(self::NAMESPACE, '/mappings', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_mappings'],
                'permission_callback' => function() {
                    return current_user_can('manage_tainacan_explorer');
                }
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_mapping'],
                'permission_callback' => function() {
                    return current_user_can('manage_tainacan_explorer');
                },
                'args' => $this->get_mapping_args()
            ]
        ]);
        
        // Rota para mapeamento específico
        register_rest_route(self::NAMESPACE, '/mappings/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_mapping'],
                'permission_callback' => function() {
                    return current_user_can('manage_tainacan_explorer');
                }
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_mapping'],
                'permission_callback' => function() {
                    return current_user_can('manage_tainacan_explorer');
                },
                'args' => $this->get_mapping_args()
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_mapping'],
                'permission_callback' => function() {
                    return current_user_can('manage_tainacan_explorer');
                }
            ]
        ]);
        
        // Rota para busca
        register_rest_route(self::NAMESPACE, '/search', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'search_items'],
            'permission_callback' => '__return_true',
            'args' => [
                'q' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return strlen($param) >= 2;
                    }
                ],
                'collections' => [
                    'required' => false,
                    'validate_callback' => function($param) {
                        return is_array($param) || is_numeric($param);
                    }
                ],
                'type' => [
                    'required' => false,
                    'validate_callback' => function($param) {
                        return in_array($param, ['map', 'timeline', 'story']);
                    }
                ]
            ]
        ]);
    }
    
    /**
     * Obtém dados de visualizações
     */
    public function get_visualizations($request) {
        $collection_id = $request->get_param('collection_id');
        $type = $request->get_param('type');
        
        if ($type) {
            $mapping = TEI_Metadata_Mapper::get_mapping($collection_id, $type);
            
            if (!$mapping) {
                return new WP_Error(
                    'no_mapping',
                    __('Mapeamento não encontrado', 'tainacan-explorador'),
                    ['status' => 404]
                );
            }
            
            return rest_ensure_response($mapping);
        }
        
        // Retorna todos os mapeamentos da coleção
        $mappings = TEI_Metadata_Mapper::get_all_mappings([
            'collection_id' => $collection_id
        ]);
        
        return rest_ensure_response($mappings);
    }
    
    /**
     * Obtém dados para o mapa
     */
    public function get_map_data($request) {
        $collection_id = $request->get_param('collection_id');
        $page = $request->get_param('page');
        $per_page = $request->get_param('per_page');
        $bounds = $request->get_param('bounds');
        
        // Obtém mapeamento
        $mapping = TEI_Metadata_Mapper::get_mapping($collection_id, 'map');
        
        if (!$mapping) {
            return new WP_Error(
                'no_mapping',
                __('Mapeamento de mapa não configurado', 'tainacan-explorador'),
                ['status' => 404]
            );
        }
        
        // Prepara parâmetros da API
        $api_params = [
            'perpage' => $per_page,
            'paged' => $page,
            'fetch_only_meta' => implode(',', array_values($mapping['mapping_data']))
        ];
        
        // Adiciona filtro de bounds se fornecido
        if ($bounds) {
            $bounds_array = explode(',', $bounds);
            // Implementar filtro geográfico baseado em bounds
            // Isso dependeria de como o Tainacan armazena dados geográficos
        }
        
        // Obtém itens da coleção
        $api = new TEI_API_Handler();
        $items = $api->get_collection_items($collection_id, $api_params);
        
        if (is_wp_error($items)) {
            return $items;
        }
        
        // Processa dados para o formato do mapa
        $map_data = $this->process_map_data($items['items'], $mapping);
        
        return rest_ensure_response([
            'type' => 'FeatureCollection',
            'features' => $map_data,
            'total' => $items['total'],
            'page' => $page,
            'per_page' => $per_page
        ]);
    }
    
    /**
     * Obtém dados para a timeline
     */
    public function get_timeline_data($request) {
        $collection_id = $request->get_param('collection_id');
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');
        
        // Obtém mapeamento
        $mapping = TEI_Metadata_Mapper::get_mapping($collection_id, 'timeline');
        
        if (!$mapping) {
            return new WP_Error(
                'no_mapping',
                __('Mapeamento de timeline não configurado', 'tainacan-explorador'),
                ['status' => 404]
            );
        }
        
        // Prepara parâmetros da API
        $api_params = [
            'perpage' => 200,
            'fetch_only_meta' => implode(',', array_values($mapping['mapping_data']))
        ];
        
        // Adiciona filtro de data se fornecido
        if ($start_date || $end_date) {
            // Implementar filtro de data
            // Isso dependeria do campo de data mapeado
        }
        
        // Obtém itens da coleção
        $api = new TEI_API_Handler();
        $items = $api->get_collection_items($collection_id, $api_params);
        
        if (is_wp_error($items)) {
            return $items;
        }
        
        // Processa dados para o formato da timeline
        $timeline_data = $this->process_timeline_data($items['items'], $mapping);
        
        return rest_ensure_response([
            'title' => [
                'text' => [
                    'headline' => $mapping['collection_name'] ?? __('Timeline', 'tainacan-explorador')
                ]
            ],
            'events' => $timeline_data
        ]);
    }
    
    /**
     * Obtém dados para o storytelling
     */
    public function get_story_data($request) {
        $collection_id = $request->get_param('collection_id');
        $chapter = $request->get_param('chapter');
        
        // Obtém mapeamento
        $mapping = TEI_Metadata_Mapper::get_mapping($collection_id, 'story');
        
        if (!$mapping) {
            return new WP_Error(
                'no_mapping',
                __('Mapeamento de storytelling não configurado', 'tainacan-explorador'),
                ['status' => 404]
            );
        }
        
        // Obtém itens da coleção
        $api = new TEI_API_Handler();
        $items = $api->get_collection_items($collection_id, [
            'perpage' => 50,
            'fetch_only_meta' => implode(',', array_values($mapping['mapping_data']))
        ]);
        
        if (is_wp_error($items)) {
            return $items;
        }
        
        // Processa dados para o formato do storytelling
        $story_data = $this->process_story_data($items['items'], $mapping);
        
        // Se capítulo específico solicitado
        if ($chapter !== null && isset($story_data[$chapter])) {
            return rest_ensure_response($story_data[$chapter]);
        }
        
        return rest_ensure_response([
            'chapters' => $story_data,
            'total' => count($story_data)
        ]);
    }
    
    /**
     * Geocodifica endereço
     */
    public function geocode_address($request) {
        $address = $request->get_param('address');
        
        // Verifica cache
        $cache_key = 'geocode_' . md5($address);
        $cached = TEI_Cache_Manager::get($cache_key);
        
        if ($cached !== false) {
            return rest_ensure_response($cached);
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
                $coordinates = $this->geocode_google($address, $settings['geocoding_api_key'] ?? '');
                break;
                
            case 'mapbox':
                $coordinates = $this->geocode_mapbox($address, $settings['geocoding_api_key'] ?? '');
                break;
        }
        
        if (!$coordinates) {
            return new WP_Error(
                'geocoding_failed',
                __('Não foi possível geocodificar o endereço', 'tainacan-explorador'),
                ['status' => 404]
            );
        }
        
        // Cache por 30 dias
        TEI_Cache_Manager::set($cache_key, $coordinates, 30 * DAY_IN_SECONDS);
        
        return rest_ensure_response($coordinates);
    }
    
    /**
     * Obtém estatísticas
     */
    public function get_stats($request) {
        $collection_id = $request->get_param('collection_id');
        $type = $request->get_param('type');
        
        $stats = [
            'collection_id' => $collection_id,
            'mappings' => []
        ];
        
        if ($type) {
            $mapping = TEI_Metadata_Mapper::get_mapping($collection_id, $type);
            if ($mapping) {
                $stats['mappings'][$type] = [
                    'configured' => true,
                    'last_updated' => $mapping['updated_at']
                ];
            }
        } else {
            // Estatísticas de todos os tipos
            foreach (['map', 'timeline', 'story'] as $viz_type) {
                $mapping = TEI_Metadata_Mapper::get_mapping($collection_id, $viz_type);
                $stats['mappings'][$viz_type] = [
                    'configured' => !empty($mapping),
                    'last_updated' => $mapping['updated_at'] ?? null
                ];
            }
        }
        
        // Adiciona estatísticas de cache
        $stats['cache'] = TEI_Cache_Manager::get_stats();
        
        return rest_ensure_response($stats);
    }
    
    /**
     * Processa dados para o mapa
     */
    private function process_map_data($items, $mapping) {
        $features = [];
        $location_field = $mapping['mapping_data']['location'] ?? '';
        
        foreach ($items as $item) {
            // Extrai coordenadas
            $coords = $this->extract_item_coordinates($item, $location_field);
            
            if (!$coords) {
                continue;
            }
            
            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$coords['lon'], $coords['lat']]
                ],
                'properties' => [
                    'id' => $item['id'],
                    'title' => $this->get_item_field($item, $mapping['mapping_data']['title'] ?? ''),
                    'description' => $this->get_item_field($item, $mapping['mapping_data']['description'] ?? ''),
                    'image' => $this->get_item_field($item, $mapping['mapping_data']['image'] ?? ''),
                    'link' => $item['url'] ?? ''
                ]
            ];
        }
        
        return $features;
    }
    
    /**
     * Processa dados para a timeline
     */
    private function process_timeline_data($items, $mapping) {
        $events = [];
        $date_field = $mapping['mapping_data']['date'] ?? '';
        
        foreach ($items as $item) {
            $date = $this->get_item_field($item, $date_field);
            
            if (!$date) {
                continue;
            }
            
            $events[] = [
                'start_date' => [
                    'year' => date('Y', strtotime($date)),
                    'month' => date('m', strtotime($date)),
                    'day' => date('d', strtotime($date))
                ],
                'text' => [
                    'headline' => $this->get_item_field($item, $mapping['mapping_data']['title'] ?? ''),
                    'text' => $this->get_item_field($item, $mapping['mapping_data']['description'] ?? '')
                ],
                'media' => [
                    'url' => $this->get_item_field($item, $mapping['mapping_data']['image'] ?? ''),
                    'thumbnail' => $item['thumbnail']['thumbnail'] ?? ''
                ]
            ];
        }
        
        return $events;
    }
    
    /**
     * Processa dados para o storytelling
     */
    private function process_story_data($items, $mapping) {
        $chapters = [];
        
        foreach ($items as $index => $item) {
            $chapters[] = [
                'id' => 'chapter-' . ($index + 1),
                'title' => $this->get_item_field($item, $mapping['mapping_data']['title'] ?? ''),
                'content' => $this->get_item_field($item, $mapping['mapping_data']['description'] ?? ''),
                'image' => $this->get_item_field($item, $mapping['mapping_data']['image'] ?? ''),
                'background' => $this->get_item_field($item, $mapping['mapping_data']['background'] ?? ''),
                'link' => $item['url'] ?? ''
            ];
        }
        
        return $chapters;
    }
    
    /**
     * Extrai coordenadas de um item
     */
    private function extract_item_coordinates($item, $location_field) {
        if (!$location_field) {
            return null;
        }
        
        $location = $this->get_item_field($item, $location_field);
        
        if (!$location) {
            return null;
        }
        
        return TEI_Sanitizer::sanitize_coordinates($location);
    }
    
    /**
     * Obtém campo de um item
     */
    private function get_item_field($item, $field_id) {
        if (!$field_id) {
            return '';
        }
        
        if (isset($item['metadata'][$field_id])) {
            return $item['metadata'][$field_id]['value'] ?? '';
        }
        
        return $item[$field_id] ?? '';
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
     * Obtém argumentos para mapeamento
     */
    private function get_mapping_args() {
        return [
            'collection_id' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ],
            'collection_name' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'mapping_type' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return in_array($param, ['map', 'timeline', 'story']);
                }
            ],
            'mapping_data' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return is_array($param);
                }
            ],
            'visualization_settings' => [
                'required' => false,
                'validate_callback' => function($param) {
                    return is_array($param);
                }
            ]
        ];
    }
}
