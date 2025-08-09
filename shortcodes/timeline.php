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
     * 
     * @param array $atts Atributos do shortcode
     * @return string HTML da timeline
     */
    public function render($atts) {
        // Parse dos atributos
        $atts = shortcode_atts([
            'collection' => '',
            'height' => '650px',
            'width' => '100%',
            'start_date' => '',
            'end_date' => '',
            'initial_zoom' => 2,
            'language' => get_locale(),
            'timenav_position' => 'bottom',
            'hash_bookmark' => 'false',
            'debug' => 'false',
            'cache' => 'true',
            'class' => '',
            'id' => 'tei-timeline-' . uniqid()
        ], $atts, 'tainacan_explorador_timeline');
        
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
     * 
     * @param int $collection_id ID da coleção
     * @param array $mapping Mapeamento de metadados
     * @param array $atts Atributos do shortcode
     * @return array|WP_Error
     */
    private function get_timeline_data($collection_id, $mapping, $atts) {
        // Verifica cache
        if ($atts['cache'] === 'true') {
            $cache_key = 'tei_timeline_data_' . $collection_id . '_' . md5(serialize($atts));
            $cached_data = TEI_Cache_Manager::get($cache_key);
            
            if ($cached_data !== false) {
                return $cached_data;
            }
        }
        
        // Prepara parâmetros da API
        $api_params = [
            'perpage' => 200,
            'paged' => 1,
            'fetch_only' => 'title,description,thumbnail',
            'fetch_only_meta' => implode(',', array_values($mapping['mapping_data']))
        ];
        
        // Adiciona filtro de data se especificado
        if (!empty($atts['start_date']) || !empty($atts['end_date'])) {
            $date_field = $mapping['mapping_data']['date'] ?? '';
            if ($date_field) {
                $api_params['metaquery'] = [];
                
                if (!empty($atts['start_date'])) {
                    $api_params['metaquery'][] = [
                        'key' => $date_field,
                        'value' => $atts['start_date'],
                        'compare' => '>=',
                        'type' => 'DATE'
                    ];
                }
                
                if (!empty($atts['end_date'])) {
                    $api_params['metaquery'][] = [
                        'key' => $date_field,
                        'value' => $atts['end_date'],
                        'compare' => '<=',
                        'type' => 'DATE'
                    ];
                }
            }
        }
        
        // Faz requisição à API do Tainacan
        $api_handler = new TEI_API_Handler();
        $response = $api_handler->get_collection_items($collection_id, $api_params);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Processa dados para o formato da timeline
        $timeline_data = $this->process_timeline_data($response, $mapping);
        
        // Salva no cache
        if ($atts['cache'] === 'true' && !empty($timeline_data)) {
            TEI_Cache_Manager::set($cache_key, $timeline_data, HOUR_IN_SECONDS);
        }
        
        return $timeline_data;
    }
    
    /**
     * Processa dados para o formato TimelineJS
     * 
     * @param array $response Resposta da API
     * @param array $mapping Mapeamento
     * @return array
     */
    private function process_timeline_data($response, $mapping) {
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
        
        foreach ($response['items'] as $item) {
            // Obtém data
            $date_value = $this->get_field_value($item, $date_field);
            
            if (empty($date_value)) {
                continue;
            }
            
            // Parse da data
            $date_parts = $this->parse_date($date_value);
            
            if (!$date_parts) {
                continue;
            }
            
            // Cria evento
            $event = [
                'start_date' => $date_parts,
                'text' => [
                    'headline' => $this->get_field_value($item, $title_field, $item['title']),
                    'text' => $this->format_description($item, $description_field, $category_field, $link_field)
                ]
            ];
            
            // Adiciona mídia se disponível
            $image_url = $this->get_image_url($item, $image_field);
            if ($image_url) {
                $event['media'] = [
                    'url' => $image_url,
                    'thumbnail' => $item['thumbnail']['thumbnail'] ?? '',
                    'caption' => $this->get_field_value($item, $title_field, $item['title'])
                ];
            }
            
            // Adiciona grupo/categoria se disponível
            if ($category_field) {
                $category = $this->get_field_value($item, $category_field);
                if ($category) {
                    $event['group'] = $category;
                }
            }
            
            // Adiciona tipo de evento
            $event['unique_id'] = 'event-' . $item['id'];
            
            $timeline_data['events'][] = $event;
        }
        
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
     * 
     * @param string $date_value Valor da data
     * @return array|null
     */
    private function parse_date($date_value) {
        // Tenta diferentes formatos
        $timestamp = strtotime($date_value);
        
        if ($timestamp === false) {
            // Tenta formato brasileiro dd/mm/yyyy
            $parts = explode('/', $date_value);
            if (count($parts) === 3) {
                $timestamp = strtotime($parts[2] . '-' . $parts[1] . '-' . $parts[0]);
            }
        }
        
        if ($timestamp === false) {
            // Tenta apenas ano
            if (preg_match('/^\d{4}$/', $date_value)) {
                return [
                    'year' => intval($date_value)
                ];
            }
            return null;
        }
        
        $date_parts = [
            'year' => intval(date('Y', $timestamp))
        ];
        
        // Adiciona mês e dia se não for apenas ano
        if (!preg_match('/^\d{4}$/', $date_value)) {
            $date_parts['month'] = intval(date('n', $timestamp));
            $date_parts['day'] = intval(date('j', $timestamp));
        }
        
        return $date_parts;
    }
    
    /**
     * Formata descrição do evento
     * 
     * @param array $item Item da coleção
     * @param string $description_field Campo de descrição
     * @param string $category_field Campo de categoria
     * @param string $link_field Campo de link
     * @return string
     */
    private function format_description($item, $description_field, $category_field, $link_field) {
        $description = $this->get_field_value($item, $description_field, $item['description']);
        
        // Adiciona categoria se disponível
        if ($category_field) {
            $category = $this->get_field_value($item, $category_field);
            if ($category) {
                $description = '<span class="tei-timeline-category">' . esc_html($category) . '</span><br>' . $description;
            }
        }
        
        // Adiciona link se disponível
        $link = $this->get_field_value($item, $link_field, $item['url']);
        if ($link) {
            $description .= '<br><a href="' . esc_url($link) . '" target="_blank" class="tei-timeline-link">' 
                . __('Ver mais detalhes', 'tainacan-explorador') . '</a>';
        }
        
        return wp_kses_post($description);
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
        
        if (isset($item['metadata'][$field_id])) {
            $value = $item['metadata'][$field_id]['value'] ?? $default;
            
            if (is_array($value) && !empty($value)) {
                return $value[0];
            }
            
            return $value;
        }
        
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
        if (!empty($image_field)) {
            $image_value = $this->get_field_value($item, $image_field);
            if (!empty($image_value)) {
                if (is_numeric($image_value)) {
                    $image_url = wp_get_attachment_image_url($image_value, 'large');
                    if ($image_url) {
                        return $image_url;
                    }
                } elseif (filter_var($image_value, FILTER_VALIDATE_URL)) {
                    return $image_value;
                }
            }
        }
        
        if (isset($item['thumbnail']['tainacan-medium'][0])) {
            return $item['thumbnail']['tainacan-medium'][0];
        }
        
        if (isset($item['thumbnail']['medium'])) {
            return $item['thumbnail']['medium'];
        }
        
        return '';
    }
    
    /**
     * Obtém configurações da timeline
     * 
     * @param array $atts Atributos do shortcode
     * @param array $mapping Mapeamento
     * @return array
     */
    private function get_timeline_config($atts, $mapping) {
        $settings = $mapping['visualization_settings'] ?? [];
        
        $config = [
            'initial_zoom' => intval($atts['initial_zoom']),
            'language' => substr($atts['language'], 0, 2),
            'timenav_position' => $atts['timenav_position'],
            'hash_bookmark' => $atts['hash_bookmark'] === 'true',
            'debug' => $atts['debug'] === 'true',
            'duration' => intval($settings['duration'] ?? 1000),
            'ease' => $settings['ease'] ?? 'easeInOutQuint',
            'dragging' => $settings['dragging'] ?? true,
            'trackResize' => true,
            'slide_padding_lr' => intval($settings['slide_padding'] ?? 100),
            'slide_default_fade' => $settings['slide_fade'] ?? '0%',
            'marker' => [
                'line_color' => $settings['marker_color'] ?? '#da2121',
                'line_color_inactive' => $settings['marker_inactive'] ?? '#CCC'
            ]
        ];
        
        // Adiciona fontes customizadas se configuradas
        if (!empty($settings['font_headline'])) {
            $config['font'] = $settings['font_headline'];
        }
        
        return apply_filters('tei_timeline_config', $config, $atts, $mapping);
    }
    
    /**
     * Renderiza a timeline
     * 
     * @param string $timeline_id ID da timeline
     * @param array $timeline_data Dados da timeline
     * @param array $config Configurações
     * @param array $atts Atributos
     * @return string
     */
    private function render_timeline($timeline_id, $timeline_data, $config, $atts) {
        ob_start();
        ?>
        <div class="tei-timeline-container <?php echo esc_attr($atts['class']); ?>">
            <div id="<?php echo esc_attr($timeline_id); ?>" 
                 class="tei-timeline" 
                 style="height: <?php echo esc_attr($atts['height']); ?>; width: <?php echo esc_attr($atts['width']); ?>;">
            </div>
            
            <div class="tei-timeline-loading">
                <div class="tei-spinner"></div>
                <p><?php esc_html_e('Carregando linha do tempo...', 'tainacan-explorador'); ?></p>
            </div>
            
            <?php if (empty($timeline_data['events'])): ?>
            <div class="tei-timeline-empty">
                <p><?php esc_html_e('Nenhum evento encontrado para exibir na linha do tempo.', 'tainacan-explorador'); ?></p>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($timeline_data['events'])): ?>
        <script>
        (function() {
            const timelineData = <?php echo wp_json_encode($timeline_data); ?>;
            const timelineConfig = <?php echo wp_json_encode($config); ?>;
            const timelineId = <?php echo wp_json_encode($timeline_id); ?>;
            
            // Inicializa a timeline quando o DOM estiver pronto
            function initTimeline() {
                if (typeof TL !== 'undefined' && TL.Timeline) {
                    // Remove loading
                    const container = document.getElementById(timelineId).parentElement;
                    const loading = container.querySelector('.tei-timeline-loading');
                    if (loading) {
                        loading.style.display = 'none';
                    }
                    
                    // Cria timeline
                    const timeline = new TL.Timeline(timelineId, timelineData, timelineConfig);
                    
                    // Adiciona à janela para acesso global
                    window.TEI_Timelines = window.TEI_Timelines || {};
                    window.TEI_Timelines[timelineId] = timeline;
                    
                    // Dispara evento customizado
                    const event = new CustomEvent('tei:timeline:loaded', {
                        detail: { timeline, id: timelineId },
                        bubbles: true
                    });
                    document.getElementById(timelineId).dispatchEvent(event);
                } else {
                    setTimeout(initTimeline, 100);
                }
            }
            
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initTimeline);
            } else {
                initTimeline();
            }
        })();
        </script>
        <?php endif; ?>
        
        <style>
        .tei-timeline-container {
            position: relative;
            margin: 20px 0;
        }
        
        .tei-timeline-loading {
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
        
        .tei-timeline.tl-timeline {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
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
        
        .tei-timeline-empty {
            padding: 40px;
            text-align: center;
            background: #f9fafb;
            border-radius: 8px;
            color: #6b7280;
        }
        
        .tei-timeline-category {
            display: inline-block;
            padding: 2px 8px;
            background: #eff6ff;
            color: #3b82f6;
            font-size: 12px;
            border-radius: 4px;
            margin-bottom: 8px;
        }
        
        .tei-timeline-link {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
            margin-top: 10px;
            display: inline-block;
        }
        
        .tei-timeline-link:hover {
            text-decoration: underline;
        }
        
        /* Customização do TimelineJS */
        .tl-timeline .tl-timegroup-message {
            color: #6b7280;
        }
        
        .tl-timeline .tl-headline {
            font-weight: 600;
        }
        
        .tl-timeline .tl-timemarker-content-container {
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
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
            '<div class="tei-error tei-timeline-error"><p>%s</p></div>',
            esc_html($message)
        );
    }
}
