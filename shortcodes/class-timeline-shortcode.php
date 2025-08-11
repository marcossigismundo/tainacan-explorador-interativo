<?php
/**
 * Shortcode para visualização de Timeline
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_Timeline_Shortcode {
    
    /**
     * Renderiza o shortcode da timeline
     */
    public function render($atts) {
        // Parse dos atributos
        $atts = TEI_Sanitizer::sanitize_shortcode_atts($atts, [
            'collection' => '',
            'height' => '650px',
            'width' => '100%',
            'start_date' => '',
            'end_date' => '',
            'initial_zoom' => 2,
            'language' => get_locale(),
            'timenav_position' => 'bottom',
            'hash_bookmark' => false,
            'debug' => false,
            'cache' => true,
            'class' => '',
            'id' => 'tei-timeline-' . uniqid()
        ]);
        
        // Validação da coleção
        if (empty($atts['collection'])) {
            return $this->render_error(__('ID da coleção não especificado.', 'tainacan-explorador'));
        }
        
        // Obtém mapeamento
        $mapping = TEI_Metadata_Mapper::get_mapping($atts['collection'], 'timeline');
        
        if (!$mapping) {
            return $this->render_error(__('Mapeamento de timeline não configurado para esta coleção.', 'tainacan-explorador'));
        }
        
        // Obtém dados da coleção
        $timeline_data = $this->get_timeline_data($atts['collection'], $mapping, $atts);
        
        if (is_wp_error($timeline_data)) {
            return $this->render_error($timeline_data->get_error_message());
        }
        
        // Gera configurações da timeline
        $timeline_config = $this->get_timeline_config($atts, $mapping);
        
        // Renderiza a timeline
        return $this->render_timeline($atts['id'], $timeline_data, $timeline_config, $atts);
    }
    
  /**
 * Obtém dados da timeline
 */
private function get_timeline_data($collection_id, $mapping, $atts) {
    // Verifica cache
    if ($atts['cache']) {
        $cache_key = 'tei_timeline_data_' . $collection_id . '_' . md5(serialize($atts) . serialize($mapping['filter_rules'] ?? []));
        $cached_data = TEI_Cache_Manager::get($cache_key);
        
        if ($cached_data !== false) {
            return $cached_data;
        }
    }
    
    // Prepara parâmetros da API - SEM FILTROS por enquanto para testar
    $api_params = [
        'perpage' => 200,
        'paged' => 1,
        'order' => 'DESC',
        'orderby' => 'date'
    ];
    
    // COMENTADO TEMPORARIAMENTE para testar sem filtros
    /*
    // Aplica filtros configurados
    if (!empty($mapping['filter_rules'])) {
        $api_params = TEI_Metadata_Mapper::apply_filter_rules($api_params, $mapping['filter_rules']);
    }
    */
    
    // Log para debug
    error_log('TEI Debug - Fetching timeline data for collection: ' . $collection_id);
    error_log('TEI Debug - API params: ' . json_encode($api_params));
    
    // Usa API Handler para buscar itens
    $api_handler = new TEI_API_Handler();
    $response = $api_handler->get_collection_items($collection_id, $api_params);
    
    if (is_wp_error($response)) {
        error_log('TEI Error getting timeline data: ' . $response->get_error_message());
        return $response;
    }
    
    // Debug detalhado
    error_log('TEI Debug - Timeline items fetched: ' . count($response['items'] ?? []));
    if (!empty($response['items'])) {
        $first_item = $response['items'][0];
        error_log('TEI Debug - First item structure: ' . json_encode(array_keys($first_item)));
        error_log('TEI Debug - First item metadata keys: ' . json_encode(array_keys($first_item['metadata'] ?? [])));
    }
    
    // Processa dados para o formato da timeline
    $timeline_data = $this->process_timeline_data($response, $mapping);
    
    // Salva no cache
    if ($atts['cache'] && !empty($timeline_data['events'])) {
        TEI_Cache_Manager::set($cache_key, $timeline_data, HOUR_IN_SECONDS);
    }
    
    return $timeline_data;
}
    
/**
 * Processa dados para o formato TimelineJS
 */
private function process_timeline_data($response, $mapping) {
    error_log('TEI Timeline - Starting to process data');
    error_log('TEI Timeline - Mapping data: ' . json_encode($mapping['mapping_data']));
    
    $timeline_data = [
        'title' => [
            'text' => [
                'headline' => $mapping['collection_name'] ?? __('Timeline', 'tainacan-explorador'),
                'text' => $mapping['visualization_settings']['description'] ?? ''
            ]
        ],
        'events' => []
    ];
    
    $date_field = $mapping['mapping_data']['date'] ?? '';
    $title_field = $mapping['mapping_data']['title'] ?? '';
    $description_field = $mapping['mapping_data']['description'] ?? '';
    $image_field = $mapping['mapping_data']['image'] ?? '';
    $category_field = $mapping['mapping_data']['category'] ?? '';
    $link_field = $mapping['mapping_data']['link'] ?? '';
    
    error_log('TEI Timeline - Field mappings: date=' . $date_field . ', title=' . $title_field);
    
    foreach ($response['items'] as $index => $item) {
        error_log('TEI Timeline - Processing item ' . $index . ' (ID: ' . $item['id'] . ')');
        
        // Obtém data
        $date_value = $this->get_field_value($item, $date_field);
        error_log('TEI Timeline - Date value for item ' . $item['id'] . ': ' . $date_value);
        
        if (empty($date_value)) {
            error_log('TEI Timeline - Skipping item ' . $item['id'] . ' - no date value');
            continue;
        }
        
        // Parse da data
        $date_parts = $this->parse_date($date_value);
        
        if (!$date_parts) {
            error_log('TEI Timeline - Skipping item ' . $item['id'] . ' - could not parse date: ' . $date_value);
            continue;
        }
        
        error_log('TEI Timeline - Date parsed successfully: ' . json_encode($date_parts));
        
        // Obtém título
        $title = $this->get_field_value($item, $title_field, $item['title']);
        error_log('TEI Timeline - Title: ' . $title);
        
        // Cria evento
        $event = [
            'start_date' => $date_parts,
            'text' => [
                'headline' => TEI_Sanitizer::escape($title, 'html'),
                'text' => $this->format_description($item, $description_field, $category_field, $link_field)
            ]
        ];
        
        // Obtém imagem
        $image_url = $this->get_image_url($item, $image_field);
        if ($image_url) {
            $event['media'] = [
                'url' => $image_url,
                'thumbnail' => $this->get_thumbnail_url($item),
                'caption' => TEI_Sanitizer::escape($title, 'html')
            ];
        }
        
        // Adiciona grupo/categoria se disponível
        if ($category_field) {
            $category = $this->get_field_value($item, $category_field);
            if ($category) {
                $event['group'] = TEI_Sanitizer::sanitize($category, 'text');
            }
        }
        
        // Adiciona tipo de evento
        $event['unique_id'] = 'event-' . $item['id'];
        
        $timeline_data['events'][] = $event;
        error_log('TEI Timeline - Event added for item ' . $item['id']);
    }
    
    error_log('TEI Timeline - Total events created: ' . count($timeline_data['events']));
    
    // Ordena eventos por data
    usort($timeline_data['events'], function($a, $b) {
        $date_a = sprintf('%04d%02d%02d', 
            $a['start_date']['year'], 
            $a['start_date']['month'] ?? 1, 
            $a['start_date']['day'] ?? 1
        );
        $date_b = sprintf('%04d%02d%02d', 
            $b['start_date']['year'], 
            $b['start_date']['month'] ?? 1, 
            $b['start_date']['day'] ?? 1
        );
        return strcmp($date_a, $date_b);
    });
    
    return $timeline_data;
}
    
    /**
     * Parse de data
     */
    private function parse_date($date_value) {
        // Remove espaços e caracteres invisíveis
        $date_value = trim($date_value);
        
        // Tenta diferentes formatos de data
        $formats = [
            'Y-m-d',
            'd/m/Y',
            'm/d/Y',
            'Y',
            'd-m-Y',
            'Y/m/d'
        ];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $date_value);
            if ($date !== false) {
                return [
                    'year' => intval($date->format('Y')),
                    'month' => intval($date->format('n')),
                    'day' => intval($date->format('j'))
                ];
            }
        }
        
        // Tenta strtotime como fallback
        $timestamp = strtotime($date_value);
        if ($timestamp !== false && $timestamp > 0) {
            return [
                'year' => intval(date('Y', $timestamp)),
                'month' => intval(date('n', $timestamp)),
                'day' => intval(date('j', $timestamp))
            ];
        }
        
        // Tenta apenas ano
        if (preg_match('/^(\d{4})$/', $date_value, $matches)) {
            return ['year' => intval($matches[1])];
        }
        
        return null;
    }
    
    /**
     * Formata descrição do evento
     */
    private function format_description($item, $description_field, $category_field, $link_field) {
        $description = $this->get_field_value($item, $description_field, $item['description']);
        $description = wp_kses_post($description);
        
        // Adiciona categoria se disponível
        if ($category_field) {
            $category = $this->get_field_value($item, $category_field);
            if ($category) {
                $category = TEI_Sanitizer::escape($category, 'html');
                $description = '<span class="tei-timeline-category">' . $category . '</span><br>' . $description;
            }
        }
        
        // Adiciona link se disponível
        $link = $this->get_field_value($item, $link_field, $item['url']);
        if ($link) {
            $link = TEI_Sanitizer::escape($link, 'url');
            $description .= '<br><a href="' . $link . '" target="_blank" class="tei-timeline-link">' 
                . __('Ver mais detalhes', 'tainacan-explorador') . '</a>';
        }
        
        return $description;
    }
    
    /**
     * Obtém valor de um campo
     */
    private function get_field_value($item, $field_id, $default = '') {
        if (empty($field_id)) {
            return $default;
        }
        
        // Campos especiais
        if (!is_numeric($field_id)) {
            if ($field_id === 'title' && isset($item['title'])) {
                return is_array($item['title']) ? ($item['title']['rendered'] ?? $item['title']) : $item['title'];
            }
            
            if ($field_id === 'description' && isset($item['description'])) {
                return is_array($item['description']) ? ($item['description']['rendered'] ?? $item['description']) : $item['description'];
            }
            
            if (isset($item[$field_id])) {
                return $item[$field_id];
            }
        }
        
        // Metadados por ID numérico
        if (is_numeric($field_id) && isset($item['metadata'][$field_id])) {
            $meta = $item['metadata'][$field_id];
            if (is_array($meta)) {
                $value = $meta['value'] ?? $meta['value_as_string'] ?? '';
                return is_array($value) && !empty($value) ? reset($value) : $value;
            }
            return $meta;
        }
        
        return $default;
    }
    
    /**
     * Obtém URL da imagem
     */
    private function get_image_url($item, $image_field) {
        if (!empty($image_field)) {
            $image_value = $this->get_field_value($item, $image_field);
            if (!empty($image_value)) {
                if (is_numeric($image_value)) {
                    $image_url = wp_get_attachment_image_url($image_value, 'large');
                    if ($image_url) return $image_url;
                } elseif (filter_var($image_value, FILTER_VALIDATE_URL)) {
                    return $image_value;
                }
            }
        }
        
        // Fallback para document
        if (!empty($item['document']) && filter_var($item['document'], FILTER_VALIDATE_URL)) {
            return $item['document'];
        }
        
        // Fallback para thumbnail
        if (isset($item['thumbnail']['large'])) {
            return $item['thumbnail']['large'];
        }
        
        return '';
    }
    
    /**
     * Obtém URL do thumbnail
     */
    private function get_thumbnail_url($item) {
        if (isset($item['thumbnail']['thumbnail'])) {
            return $item['thumbnail']['thumbnail'];
        }
        return '';
    }
    
    /**
     * Obtém configurações da timeline
     */
    private function get_timeline_config($atts, $mapping) {
        $settings = $mapping['visualization_settings'] ?? [];
        
        $config = [
            'initial_zoom' => intval($atts['initial_zoom']),
            'language' => substr($atts['language'], 0, 2),
            'timenav_position' => $atts['timenav_position'],
            'hash_bookmark' => $atts['hash_bookmark'],
            'debug' => $atts['debug']
        ];
        
        return apply_filters('tei_timeline_config', $config, $atts, $mapping);
    }
    
    /**
     * Renderiza a timeline
     */
    private function render_timeline($timeline_id, $timeline_data, $config, $atts) {
        // Adiciona dados de teste se vazio
        if (empty($timeline_data['events'])) {
            $timeline_data = [
                'title' => [
                    'text' => [
                        'headline' => 'Timeline - Sem dados',
                        'text' => 'Nenhum item com data válida foi encontrado. Verifique o mapeamento.'
                    ]
                ],
                'events' => [
                    [
                        'start_date' => ['year' => 2024, 'month' => 1, 'day' => 1],
                        'text' => [
                            'headline' => 'Exemplo de Evento',
                            'text' => 'Configure o campo de data corretamente no admin.'
                        ]
                    ]
                ]
            ];
        }
        
        ob_start();
        ?>
        <div class="tei-timeline-wrapper" style="width: <?php echo esc_attr($atts['width']); ?>;">
            <div id="<?php echo esc_attr($timeline_id); ?>" 
                 style="width: 100%; height: <?php echo esc_attr($atts['height']); ?>;">
            </div>
        </div>
        
        <script type="text/javascript">
        (function() {
            var timelineData = <?php echo wp_json_encode($timeline_data); ?>;
            var timelineConfig = <?php echo wp_json_encode($config); ?>;
            var timelineId = '<?php echo esc_js($timeline_id); ?>';
            
            function initTimeline() {
                if (typeof TL === 'undefined' || !TL.Timeline) {
                    setTimeout(initTimeline, 100);
                    return;
                }
                
                try {
                    new TL.Timeline(timelineId, timelineData, timelineConfig);
                } catch(e) {
                    console.error('Timeline error:', e);
                    document.getElementById(timelineId).innerHTML = 
                        '<div style="padding: 20px; text-align: center; color: #666;">' +
                        'Erro ao carregar timeline: ' + e.message + '</div>';
                }
            }
            
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initTimeline);
            } else {
                initTimeline();
            }
        })();
        </script>
        
        <style>
        .tei-timeline-wrapper { margin: 20px 0; }
        #<?php echo esc_attr($timeline_id); ?> { min-height: 500px; }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Renderiza mensagem de erro
     */
    private function render_error($message) {
        return sprintf(
            '<div class="tei-error" style="padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px;">
                <p style="margin: 0;">%s</p>
            </div>',
            esc_html($message)
        );
    }
}
