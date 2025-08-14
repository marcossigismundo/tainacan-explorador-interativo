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
            'width' => '100%', // Padrão mudado para 100% - largura total
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
        // Define estilos específicos por tema
        $theme_styles = $this->get_theme_styles($atts['theme']);
        
        ob_start();
        ?>
        <div class="tei-timeline-wrapper tei-timeline-<?php echo esc_attr($atts['theme']); ?>" 
             data-theme="<?php echo esc_attr($atts['theme']); ?>"
             style="width: <?php echo esc_attr($atts['width']); ?>;">
            <div id="<?php echo esc_attr($timeline_id); ?>" 
                 style="width: 100%; height: <?php echo esc_attr($atts['height']); ?>;">
            </div>
        </div>
        
        <script type="text/javascript">
        (function() {
            var timelineData = <?php echo wp_json_encode($timeline_data); ?>;
            var timelineConfig = <?php echo wp_json_encode($config); ?>;
            var timelineId = '<?php echo esc_js($timeline_id); ?>';
            var theme = '<?php echo esc_js($atts['theme']); ?>';
            
            function initTimeline() {
                if (typeof TL === 'undefined' || !TL.Timeline) {
                    setTimeout(initTimeline, 100);
                    return;
                }
                
                try {
                    var timeline = new TL.Timeline(timelineId, timelineData, timelineConfig);
                    
                    // Aplica estilos após carregar
                    setTimeout(function() {
                        applyThemeStyles(theme);
                    }, 500);
                } catch(e) {
                    console.error('Timeline error:', e);
                    document.getElementById(timelineId).innerHTML = 
                        '<div style="padding: 20px; text-align: center; color: #666;">' +
                        'Erro ao carregar timeline: ' + e.message + '</div>';
                }
            }
            
            function applyThemeStyles(theme) {
                var container = document.getElementById(timelineId);
                if (!container) return;
                
                // Força aplicação de estilos baseado no tema
                var slides = container.querySelectorAll('.tl-slide-content');
                slides.forEach(function(slide) {
                    slide.classList.add('theme-' + theme);
                });
            }
            
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initTimeline);
            } else {
                initTimeline();
            }
        })();
        </script>
        
        <style>
        /* Base styles */
        .tei-timeline-wrapper { 
            margin: 20px 0; 
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
        }
        
        #<?php echo esc_attr($timeline_id); ?> { 
            min-height: 500px; 
        }
        
        /* Remove fundo azul padrão do TimelineJS */
        #<?php echo esc_attr($timeline_id); ?> .tl-slide-content {
            background: <?php echo $theme_styles['slide_bg']; ?> !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-slide {
            background: <?php echo $theme_styles['container_bg']; ?> !important;
        }
        
        /* Tema: Modern (Padrão) */
        <?php if ($atts['theme'] === 'modern'): ?>
        #<?php echo esc_attr($timeline_id); ?> .tl-slide-content {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%) !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 16px !important;
            padding: 32px !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-headline-date {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important;
            color: white !important;
            padding: 4px 12px !important;
            border-radius: 20px !important;
            font-size: 12px !important;
            font-weight: 600 !important;
            display: inline-block !important;
            margin-bottom: 12px !important;
        }
        <?php endif; ?>
        
        /* Tema: Dark */
        <?php if ($atts['theme'] === 'dark'): ?>
        #<?php echo esc_attr($timeline_id); ?> .tl-slide-content {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%) !important;
            color: #e2e8f0 !important;
            border: 1px solid #334155 !important;
            border-radius: 16px !important;
            padding: 32px !important;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3) !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-headline,
        #<?php echo esc_attr($timeline_id); ?> .tl-headline a {
            color: #f1f5f9 !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-text {
            color: #cbd5e1 !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-timenav {
            background: #0f172a !important;
            border-top: 1px solid #334155 !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-timemarker-content-container {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }
        <?php endif; ?>
        
        /* Tema: Minimal */
        <?php if ($atts['theme'] === 'minimal'): ?>
        #<?php echo esc_attr($timeline_id); ?> .tl-slide-content {
            background: #ffffff !important;
            border: none !important;
            border-left: 4px solid #e5e7eb !important;
            border-radius: 0 !important;
            padding: 24px !important;
            box-shadow: none !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-headline {
            font-weight: 400 !important;
            color: #111827 !important;
            font-size: 28px !important;
            letter-spacing: -0.02em !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-text {
            color: #6b7280 !important;
            line-height: 1.8 !important;
        }
        <?php endif; ?>
        
        /* Tema: Colorful */
        <?php if ($atts['theme'] === 'colorful'): ?>
        #<?php echo esc_attr($timeline_id); ?> .tl-slide-content:nth-child(5n+1) {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%) !important;
            border: 2px solid #f59e0b !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-slide-content:nth-child(5n+2) {
            background: linear-gradient(135deg, #ddd6fe 0%, #c4b5fd 100%) !important;
            border: 2px solid #8b5cf6 !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-slide-content:nth-child(5n+3) {
            background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%) !important;
            border: 2px solid #ef4444 !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-slide-content:nth-child(5n+4) {
            background: linear-gradient(135deg, #a7f3d0 0%, #6ee7b7 100%) !important;
            border: 2px solid #10b981 !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-slide-content:nth-child(5n+5) {
            background: linear-gradient(135deg, #bfdbfe 0%, #93c5fd 100%) !important;
            border: 2px solid #3b82f6 !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-slide-content {
            border-radius: 20px !important;
            padding: 28px !important;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1) !important;
        }
        <?php endif; ?>
        
        /* Tema: Professional */
        <?php if ($atts['theme'] === 'professional'): ?>
        #<?php echo esc_attr($timeline_id); ?> .tl-slide-content {
            background: #fafafa !important;
            border: 1px solid #d4d4d8 !important;
            border-radius: 8px !important;
            padding: 40px !important;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1) !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-headline {
            font-family: 'Georgia', serif !important;
            font-weight: 700 !important;
            color: #18181b !important;
            font-size: 32px !important;
            margin-bottom: 20px !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-text {
            font-family: 'Georgia', serif !important;
            color: #3f3f46 !important;
            line-height: 1.75 !important;
            font-size: 16px !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-timenav {
            background: #fafafa !important;
            border-top: 2px solid #18181b !important;
        }
        <?php endif; ?>
        
        /* Estilos comuns aprimorados */
        #<?php echo esc_attr($timeline_id); ?> .tl-headline {
            font-weight: 700 !important;
            margin-bottom: 16px !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-headline a {
            text-decoration: none !important;
            transition: all 0.3s ease !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-headline a:hover {
            opacity: 0.8 !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-media-image img {
            border-radius: 12px !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important;
        }
        
        /* Animações */
        #<?php echo esc_attr($timeline_id); ?> .tl-slide-content {
            transition: all 0.3s ease !important;
        }
        
        #<?php echo esc_attr($timeline_id); ?> .tl-slide-content:hover {
            transform: translateY(-4px) !important;
        }
        
        /* Remove o fundo azul claro padrão */
        #<?php echo esc_attr($timeline_id); ?> .tl-slide,
        #<?php echo esc_attr($timeline_id); ?> .tl-slide-content,
        #<?php echo esc_attr($timeline_id); ?> .tl-text-content {
            background-color: transparent !important;
        }
        
        /* Garante que o tema seja aplicado */
        #<?php echo esc_attr($timeline_id); ?> .tl-text-content-container {
            background: none !important;
        }

            /* Customização das cores das setas de navegação */
#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-next,
#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-previous {
    color: #3b82f6 !important; /* Azul */
    background: white !important;
    border: 2px solid #3b82f6 !important;
    opacity: 1 !important;
}

#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-next:hover,
#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-previous:hover {
    color: white !important;
    background: #3b82f6 !important;
}

/* Texto dos botões de navegação */
#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-title,
#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-description {
    color: #fff !important; /* Cinza escuro */
}

/* Ícones das setas */
#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-icon::before {
    color: #3b82f6 !important;
}

            /* Força cor do texto dos botões de navegação */
#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-next .tl-slidenav-content-container,
#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-previous .tl-slidenav-content-container {
    color: #1f2937 !important;
    opacity: 1 !important;
}

#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-next .tl-slidenav-title,
#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-previous .tl-slidenav-title {
    color: #1f2937 !important;
    font-weight: 600 !important;
}

#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-next .tl-slidenav-description,
#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-previous .tl-slidenav-description {
    color: #4b5563 !important;
    opacity: 1 !important;
}

/* Estado hover */
#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-next:hover .tl-slidenav-content-container,
#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-previous:hover .tl-slidenav-content-container {
    color: #111827 !important;
    background: rgba(255, 255, 255, 0.95) !important;
}

/* Remove transparência dos botões */
#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-next,
#<?php echo esc_attr($timeline_id); ?> .tl-slidenav-previous {
    opacity: 1 !important;
    background: rgba(255, 255, 255, 0.9) !important;
}
            
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Obtém estilos por tema
     */
    private function get_theme_styles($theme) {
        $themes = [
            'modern' => [
                'slide_bg' => 'linear-gradient(135deg, #ffffff 0%, #f8fafc 100%)',
                'container_bg' => '#ffffff'
            ],
            'dark' => [
                'slide_bg' => 'linear-gradient(135deg, #1e293b 0%, #0f172a 100%)',
                'container_bg' => '#0f172a'
            ],
            'minimal' => [
                'slide_bg' => '#ffffff',
                'container_bg' => '#ffffff'
            ],
            'colorful' => [
                'slide_bg' => '#ffffff',
                'container_bg' => '#ffffff'
            ],
            'professional' => [
                'slide_bg' => '#fafafa',
                'container_bg' => '#ffffff'
            ]
        ];
        
        return $themes[$theme] ?? $themes['modern'];
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
