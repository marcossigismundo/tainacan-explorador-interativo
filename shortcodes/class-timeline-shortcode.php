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
            $cache_key = 'tei_timeline_data_' . $collection_id . '_' . md5(serialize($atts));
            $cached_data = TEI_Cache_Manager::get($cache_key);
            
            if ($cached_data !== false) {
                return $cached_data;
            }
        }
        
        // Prepara parâmetros da API
        $api_params = [
            'perpage' => 200,
            'paged' => 1
        ];
        
        // Adiciona filtro de data se especificado
        if (!empty($atts['start_date']) || !empty($atts['end_date'])) {
            $date_field = $mapping['mapping_data']['date'] ?? '';
            if ($date_field) {
                $api_params['metaquery'] = [];
                
                if (!empty($atts['start_date'])) {
                    $api_params['metaquery'][] = [
                        'key' => $date_field,
                        'value' => TEI_Sanitizer::sanitize($atts['start_date'], 'date'),
                        'compare' => '>=',
                        'type' => 'DATE'
                    ];
                }
                
                if (!empty($atts['end_date'])) {
                    $api_params['metaquery'][] = [
                        'key' => $date_field,
                        'value' => TEI_Sanitizer::sanitize($atts['end_date'], 'date'),
                        'compare' => '<=',
                        'type' => 'DATE'
                    ];
                }
            }
        }
        
        // Faz requisição à API REST do Tainacan
        $endpoint = rest_url("tainacan/v2/collection/{$collection_id}/items");
        $url = add_query_arg($api_params, $endpoint);
        
        $api_response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json'
            ]
        ]);
        
        if (is_wp_error($api_response)) {
            return $api_response;
        }
        
        $body = wp_remote_retrieve_body($api_response);
        $data = json_decode($body, true);
        
        if (!is_array($data) || !isset($data['items'])) {
            return new WP_Error('no_data', __('Nenhum dado encontrado', 'tainacan-explorador'));
        }
        
        $response = ['items' => $data['items']];
        
        // Processa dados para o formato da timeline
        $timeline_data = $this->process_timeline_data($response, $mapping);
        
        // Salva no cache
        if ($atts['cache'] && !empty($timeline_data)) {
            TEI_Cache_Manager::set($cache_key, $timeline_data, HOUR_IN_SECONDS);
        }
        
        return $timeline_data;
    }
    
    /**
     * Processa dados para o formato TimelineJS
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
            // Obtém data - tenta diferentes formas
            $date_value = null;
            
            // Se for ID numérico, busca no metadata
            if (is_numeric($date_field) && isset($item['metadata'])) {
                foreach ($item['metadata'] as $meta) {
                    // Verifica diferentes estruturas possíveis
                    $meta_id = $meta['metadatum_id'] ?? $meta['metadatum']['id'] ?? $meta['id'] ?? null;
                    if ($meta_id == $date_field) {
                        $date_value = $meta['value'] ?? $meta['value_as_string'] ?? '';
                        break;
                    }
                }
            }
            // Se for string, tenta como nome do campo
            elseif (isset($item[$date_field])) {
                $date_value = $item[$date_field];
            }
            
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
                    'headline' => TEI_Sanitizer::escape($this->get_field_value($item, $title_field, $item['title']), 'html'),
                    'text' => $this->format_description($item, $description_field, $category_field, $link_field)
                ]
            ];
            
            // Adiciona mídia se disponível
            $image_url = $this->get_image_url($item, $image_field);
            if ($image_url) {
                $event['media'] = [
                    'url' => $image_url,
                    'thumbnail' => $item['thumbnail'] ?? '',
                    'caption' => TEI_Sanitizer::escape($this->get_field_value($item, $title_field, $item['title']), 'html')
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
     */
    private function parse_date($date_value) {
        $sanitized_date = TEI_Sanitizer::sanitize($date_value, 'date');
        
        if (empty($sanitized_date)) {
            // Tenta apenas ano
            if (preg_match('/^\d{4}$/', $date_value)) {
                return [
                    'year' => intval($date_value)
                ];
            }
            return null;
        }
        
        $timestamp = strtotime($sanitized_date);
        
        if ($timestamp === false) {
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
        
        // Para campos especiais
        if ($field_id === 'title' && isset($item['title'])) {
            return is_array($item['title']) ? $item['title']['rendered'] : $item['title'];
        }
        
        if ($field_id === 'description' && isset($item['description'])) {
            return is_array($item['description']) ? $item['description']['rendered'] : $item['description'];
        }
        
        if ($field_id === 'thumbnail' && isset($item['thumbnail'])) {
            return $item['thumbnail'];
        }
        
        // Para metadados do Tainacan (usando ID numérico)
        if (is_numeric($field_id) && isset($item['metadata'])) {
            foreach ($item['metadata'] as $meta) {
                // Verifica diferentes estruturas possíveis
                $meta_id = $meta['metadatum_id'] ?? $meta['metadatum']['id'] ?? $meta['id'] ?? null;
                if ($meta_id == $field_id) {
                    return $meta['value'] ?? $meta['value_as_string'] ?? '';
                }
            }
        }
        
        // Tenta direto no item
        if (isset($item[$field_id])) {
            return $item[$field_id];
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
                    if ($image_url) {
                        return $image_url;
                    }
                } elseif (filter_var($image_value, FILTER_VALIDATE_URL)) {
                    return TEI_Sanitizer::escape($image_value, 'url');
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
     */
    private function get_timeline_config($atts, $mapping) {
        $settings = $mapping['visualization_settings'] ?? [];
        
        $config = [
            'initial_zoom' => intval($atts['initial_zoom']),
            'language' => substr($atts['language'], 0, 2),
            'timenav_position' => $atts['timenav_position'],
            'hash_bookmark' => $atts['hash_bookmark'],
            'debug' => $atts['debug'],
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
     */
    private function render_timeline($timeline_id, $timeline_data, $config, $atts) {
        ob_start();
        ?>
        <div class="tei-timeline-container <?php echo esc_attr($atts['class']); ?>" style="width: 100vw; height: 100vh; position: relative; left: 50%; right: 50%; margin-left: -50vw; margin-right: -50vw;">
            <div id="<?php echo esc_attr($timeline_id); ?>" 
                 class="tei-timeline" 
                 style="height: 100%; width: 100%;">
            </div>
            
            <?php if (empty($timeline_data['events'])): ?>
            <div class="tei-no-data">
                <p><?php esc_html_e('Nenhum evento encontrado para exibir na linha do tempo.', 'tainacan-explorador'); ?></p>
            </div>
            <?php else: ?>
            <div class="tei-loading-overlay" id="tei-loading-<?php echo esc_attr($timeline_id); ?>" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.9); display: flex; align-items: center; justify-content: center; z-index: 1000;">
                <div class="tei-loading-content">
                    <div class="tei-spinner"></div>
                    <p><?php esc_html_e('Carregando linha do tempo...', 'tainacan-explorador'); ?></p>
                </div>
            </div>
            
            <script type="text/javascript">
            (function() {
                var timelineData = <?php echo wp_json_encode($timeline_data); ?>;
                var timelineConfig = <?php echo wp_json_encode($config); ?>;
                var timelineId = '<?php echo esc_js($timeline_id); ?>';
                
                function initTimeline() {
                    if (typeof TL !== 'undefined' && TL.Timeline) {
                        try {
                            new TL.Timeline(timelineId, timelineData, timelineConfig);
                            // Remove loading após criar timeline
                            var loading = document.getElementById('tei-loading-' + timelineId);
                            if (loading) {
                                loading.style.display = 'none';
                            }
                        } catch(e) {
                            console.error('Erro ao criar timeline:', e);
                            var loading = document.getElementById('tei-loading-' + timelineId);
                            if (loading) {
                                loading.innerHTML = '<p>Erro ao carregar timeline</p>';
                            }
                        }
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
        </div>
        
        <style>
        @media (max-width: 100%) {
            .tei-timeline-container {
                width: 100vw !important;
                margin: 0 !important;
                left: 0 !important;
                right: 0 !important;
            }
            .tl-timeline {
                font-size: 14px !important;
            }
        }
        </style>
        <?php
        
        $output = ob_get_clean();
        
        // Remove quebras de linha extras que podem causar problemas JSON
        $output = trim($output);
        
        return $output;
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
}
