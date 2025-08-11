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
            'theme' => 'modern', // novo: tema visual
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
        $category_field = $mapping['mapping_data']['category'] ?? '';
        
        // Array de cores para categorias
        $category_colors = [];
        $color_palette = [
            '#3b82f6', // blue
            '#8b5cf6', // purple
            '#ec4899', // pink
            '#f97316', // orange
            '#10b981', // emerald
            '#06b6d4', // cyan
            '#f59e0b', // amber
            '#6366f1', // indigo
            '#14b8a6', // teal
            '#84cc16', // lime
        ];
        $color_index = 0;
        
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
            
            // Obtém categoria (para cor de fundo)
            $category = '';
            $background_color = '';
            if ($category_field && isset($metadata[$category_field])) {
                $category = $metadata[$category_field];
                
                // Atribui cor para categoria
                if (!isset($category_colors[$category])) {
                    $category_colors[$category] = $color_palette[$color_index % count($color_palette)];
                    $color_index++;
                }
                $background_color = $category_colors[$category];
            }
            
            // IMPORTANTE: Obtém o link do item no Tainacan
            $item_url = get_permalink($item->ID);
            
            // Adiciona link ao título e botão "Ver mais"
            $title_with_link = sprintf(
                '<a href="%s" target="_blank" style="color: inherit; text-decoration: none;">%s</a>',
                esc_url($item_url),
                esc_html($title)
            );
            
            // Adiciona botão "Ver no Tainacan" à descrição
            $description_with_link = wp_kses_post($description);
            $description_with_link .= sprintf(
                '<div style="margin-top: 15px;">
                    <a href="%s" target="_blank" style="
                        display: inline-block;
                        padding: 8px 16px;
                        background: linear-gradient(135deg, #3b82f6 0%%, #8b5cf6 100%%);
                        color: white;
                        text-decoration: none;
                        border-radius: 6px;
                        font-size: 14px;
                        font-weight: 500;
                        transition: transform 0.2s;
                    " onmouseover="this.style.transform=\'translateY(-2px)\'" onmouseout="this.style.transform=\'translateY(0)\'">
                        %s →
                    </a>
                </div>',
                esc_url($item_url),
                __('Ver no Tainacan', 'tainacan-explorador')
            );
            
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
            
            // Cria evento com estilo customizado
            $event = [
                'start_date' => $date_parts,
                'text' => [
                    'headline' => $title_with_link,
                    'text' => $description_with_link
                ],
                // Adiciona classe CSS customizada baseada na categoria
                'group' => $category ?: 'default',
                'background' => [
                    'color' => $background_color ?: '#f0f9ff',
                    'url' => '' // pode adicionar imagem de fundo se desejar
                ]
            ];
            
            if ($image_url) {
                $event['media'] = [
                    'url' => $image_url,
                    'thumbnail' => get_the_post_thumbnail_url($item->ID, 'thumbnail'),
                    'caption' => esc_html($title),
                    'link' => $item_url
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
        <div class="tei-timeline-wrapper tei-timeline-<?php echo esc_attr($atts['theme']); ?>" style="width: <?php echo esc_attr($atts['width']); ?>;">
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
        .tei-timeline-wrapper { 
            margin: 20px 0; 
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        #<?php echo esc_attr($timeline_id); ?> { 
            min-height: 500px; 
        }
        
        /* Estilo moderno para a timeline */
        .tei-timeline-modern .tl-timeline {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
        }
        
        /* Cards com gradiente suave */
        .tei-timeline-modern .tl-text {
            background: linear-gradient(135deg, #f6f9fc 0%, #ffffff 100%) !important;
            border-radius: 12px !important;
            padding: 20px !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08) !important;
        }
        
        /* Hover effect nos cards */
        .tei-timeline-modern .tl-text:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12) !important;
            transition: all 0.3s ease;
        }
        
        /* Estilo para diferentes categorias/grupos */
        .tl-timeline .tl-timemarker[class*="tl-timemarker-group"] .tl-timemarker-content-container {
            border-radius: 8px !important;
            overflow: hidden;
        }
        
        /* Cores para grupos/categorias */
        .tl-timeline .tl-timemarker-group-1 .tl-timemarker-content-container {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%) !important;
        }
        
        .tl-timeline .tl-timemarker-group-2 .tl-timemarker-content-container {
            background: linear-gradient(135deg, #e9d5ff 0%, #d8b4fe 100%) !important;
        }
        
        .tl-timeline .tl-timemarker-group-3 .tl-timemarker-content-container {
            background: linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%) !important;
        }
        
        .tl-timeline .tl-timemarker-group-4 .tl-timemarker-content-container {
            background: linear-gradient(135deg, #fed7aa 0%, #fdba74 100%) !important;
        }
        
        .tl-timeline .tl-timemarker-group-5 .tl-timemarker-content-container {
            background: linear-gradient(135deg, #bbf7d0 0%, #86efac 100%) !important;
        }
        
        /* Estilo para os títulos */
        .tl-headline {
            font-weight: 600 !important;
            color: #1f2937 !important;
            margin-bottom: 12px !important;
        }
        
        /* Links nos títulos */
        .tl-headline a {
            color: inherit !important;
            text-decoration: none !important;
            border-bottom: 2px solid transparent;
            transition: border-color 0.2s;
        }
        
        .tl-headline a:hover {
            border-bottom-color: #3b82f6;
        }
        
        /* Navegação temporal mais moderna */
        .tl-timenav {
            background: linear-gradient(180deg, #f9fafb 0%, #f3f4f6 100%) !important;
            border-top: 1px solid #e5e7eb !important;
        }
        
        /* Marcadores da timeline */
        .tl-timemarker .tl-timemarker-line-left,
        .tl-timemarker .tl-timemarker-line-right {
            border-color: #d1d5db !important;
        }
        
        /* Ponto ativo */
        .tl-timemarker.tl-timemarker-active .tl-timemarker-content-container {
            box-shadow: 0 8px 16px rgba(59, 130, 246, 0.2) !important;
            border: 2px solid #3b82f6 !important;
        }
        
        /* Imagens com borda arredondada */
        .tl-media-image img {
            border-radius: 8px !important;
        }
        
        /* Animação de entrada */
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .tl-slide-content {
            animation: slideInUp 0.5s ease;
        }
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
