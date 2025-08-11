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
     * Obtém dados da timeline - MÉTODO DIRETO
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
        
        error_log('TEI Timeline - Fetching data for collection: ' . $collection_id);
        error_log('TEI Timeline - Mapping: ' . json_encode($mapping['mapping_data']));
        
        // BUSCA DIRETA NO BANCO DO WORDPRESS
        global $wpdb;
        
        // Determina o post type da coleção
        $post_type = 'tnc_col_' . $collection_id . '_item';
        
        // Busca itens diretamente
        $items_query = $wpdb->prepare(
            "SELECT ID, post_title, post_content, post_excerpt 
             FROM {$wpdb->posts} 
             WHERE post_type = %s 
             AND post_status = 'publish' 
             ORDER BY post_date DESC 
             LIMIT 200",
            $post_type
        );
        
        $items = $wpdb->get_results($items_query);
        
        error_log('TEI Timeline - Found ' . count($items) . ' items in database');
        
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
        
        foreach ($items as $item) {
            // Busca metadados do item
            $metadata = [];
            
            // Busca todos os metadados numéricos
            $meta_query = $wpdb->prepare(
                "SELECT meta_key, meta_value 
                 FROM {$wpdb->postmeta} 
                 WHERE post_id = %d 
                 AND meta_key REGEXP '^[0-9]+$'",
                $item->ID
            );
            
            $metas = $wpdb->get_results($meta_query, OBJECT_K);
            
            foreach ($metas as $meta_key => $meta_obj) {
                $metadata[$meta_key] = $meta_obj->meta_value;
            }
            
            error_log('TEI Timeline - Item ' . $item->ID . ' has ' . count($metadata) . ' metadata fields');
            
            // Obtém data
            $date_value = '';
            if ($date_field && isset($metadata[$date_field])) {
                $date_value = $metadata[$date_field];
            }
            
            error_log('TEI Timeline - Date field ' . $date_field . ' = ' . $date_value);
            
            if (empty($date_value)) {
                // Tenta usar a data de publicação como fallback
                $date_value = get_post_field('post_date', $item->ID);
            }
            
            if (empty($date_value)) {
                continue;
            }
            
            // Parse da data
            $date_parts = $this->parse_date($date_value);
            
            if (!$date_parts) {
                continue;
            }
            
            // Obtém título
            $title = '';
            if ($title_field === 'title') {
                $title = $item->post_title;
            } elseif ($title_field && isset($metadata[$title_field])) {
                $title = $metadata[$title_field];
            } else {
                $title = $item->post_title;
            }
            
            // Obtém descrição
            $description = '';
            if ($description_field === 'description') {
                $description = $item->post_content ?: $item->post_excerpt;
            } elseif ($description_field && isset($metadata[$description_field])) {
                $description = $metadata[$description_field];
            } else {
                $description = $item->post_excerpt ?: wp_trim_words($item->post_content, 30);
            }
            
            // Obtém imagem
            $image_url = '';
            if ($image_field === 'thumbnail') {
                $image_url = get_the_post_thumbnail_url($item->ID, 'large');
            } elseif ($image_field === 'document') {
                $document = get_post_meta($item->ID, 'document', true);
                if ($document) {
                    $image_url = wp_get_attachment_url($document);
                }
            } elseif ($image_field && isset($metadata[$image_field])) {
                $image_value = $metadata[$image_field];
                if (is_numeric($image_value)) {
                    $image_url = wp_get_attachment_url($image_value);
                } else {
                    $image_url = $image_value;
                }
            }
            
            // Cria evento
            $event = [
                'start_date' => $date_parts,
                'text' => [
                    'headline' => esc_html($title),
                    'text' => wp_kses_post($description)
                ]
            ];
            
            if ($image_url) {
                $event['media'] = [
                    'url' => $image_url,
                    'thumbnail' => get_the_post_thumbnail_url($item->ID, 'thumbnail'),
                    'caption' => esc_html($title)
                ];
            }
            
            $event['unique_id'] = 'event-' . $item->ID;
            
            $timeline_data['events'][] = $event;
            
            error_log('TEI Timeline - Added event for item ' . $item->ID);
        }
        
        error_log('TEI Timeline - Total events created: ' . count($timeline_data['events']));
        
        // Se não há eventos, adiciona um exemplo
        if (empty($timeline_data['events'])) {
            $timeline_data['events'][] = [
                'start_date' => ['year' => date('Y'), 'month' => date('n'), 'day' => date('j')],
                'text' => [
                    'headline' => __('Nenhum item com data encontrado', 'tainacan-explorador'),
                    'text' => __('Verifique se o campo de data está mapeado corretamente e se os itens possuem datas válidas.', 'tainacan-explorador')
                ]
            ];
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
        
        // Salva no cache
        if ($atts['cache'] && !empty($timeline_data['events'])) {
            TEI_Cache_Manager::set($cache_key, $timeline_data, HOUR_IN_SECONDS);
        }
        
        return $timeline_data;
    }
    
    /**
     * Parse de data
     */
    private function parse_date($date_value) {
        // Remove espaços e caracteres invisíveis
        $date_value = trim($date_value);
        
        error_log('TEI Timeline - Parsing date: ' . $date_value);
        
        // Tenta diferentes formatos de data
        $formats = [
            'Y-m-d H:i:s', // WordPress default
            'Y-m-d',
            'd/m/Y',
            'm/d/Y',
            'Y',
            'd-m-Y',
            'Y/m/d',
            'd.m.Y',
            'Y.m.d'
        ];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $date_value);
            if ($date !== false) {
                $result = [
                    'year' => intval($date->format('Y')),
                    'month' => intval($date->format('n')),
                    'day' => intval($date->format('j'))
                ];
                error_log('TEI Timeline - Parsed date successfully: ' . json_encode($result));
                return $result;
            }
        }
        
        // Tenta strtotime como fallback
        $timestamp = strtotime($date_value);
        if ($timestamp !== false && $timestamp > 0) {
            $result = [
                'year' => intval(date('Y', $timestamp)),
                'month' => intval(date('n', $timestamp)),
                'day' => intval(date('j', $timestamp))
            ];
            error_log('TEI Timeline - Parsed date with strtotime: ' . json_encode($result));
            return $result;
        }
        
        // Tenta apenas ano
        if (preg_match('/^(\d{4})$/', $date_value, $matches)) {
            $result = ['year' => intval($matches[1])];
            error_log('TEI Timeline - Parsed year only: ' . json_encode($result));
            return $result;
        }
        
        error_log('TEI Timeline - Failed to parse date: ' . $date_value);
        return null;
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
            
            console.log('Timeline Data:', timelineData);
            console.log('Timeline Events:', timelineData.events.length);
            
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
