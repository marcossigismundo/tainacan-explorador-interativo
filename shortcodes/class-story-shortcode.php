<?php
/**
 * Shortcode para visualização de Storytelling
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_Story_Shortcode {
    
    /**
     * Renderiza o shortcode do storytelling
     */
    public function render($atts) {
        // Parse dos atributos
        $atts = TEI_Sanitizer::sanitize_shortcode_atts($atts, [
            'collection' => '',
            'height' => 'auto',
            'animation' => 'fade',
            'navigation' => 'dots',
            'autoplay' => false,
            'autoplay_speed' => 17000,
            'transition_speed' => 800,
            'parallax' => false,
            'fullscreen' => true,
            'cache' => true,
            'class' => '',
            'id' => 'tei-story-' . uniqid()
        ]);
        
        // Validação da coleção
        if (empty($atts['collection']) || !is_numeric($atts['collection'])) {
            return $this->render_error(__('ID da coleção não especificado ou inválido.', 'tainacan-explorador'));
        }
        
        $collection_id = intval($atts['collection']);
        
        // Obtém mapeamento
        $mapping = TEI_Metadata_Mapper::get_mapping($collection_id, 'story');
        
        if (!$mapping) {
            return $this->render_error(__('Mapeamento de storytelling não configurado para esta coleção.', 'tainacan-explorador'));
        }
        
        // Obtém dados da coleção
        $story_data = $this->get_story_data($collection_id, $mapping, $atts);
        
        if (is_wp_error($story_data)) {
            return $this->render_error($story_data->get_error_message());
        }
        
        // Gera configurações do storytelling
        $story_config = $this->get_story_config($atts, $mapping);
        
        // Renderiza o storytelling
        return $this->render_story($atts['id'], $story_data, $story_config, $atts);
    }
    
    /**
     * Obtém dados do storytelling
     */
    private function get_story_data($collection_id, $mapping, $atts) {
        // Verifica cache
        if ($atts['cache']) {
            $cache_key = 'tei_story_data_' . $collection_id . '_' . md5(serialize($atts));
            $cached_data = TEI_Cache_Manager::get($cache_key);
            
            if ($cached_data !== false) {
                return $cached_data;
            }
        }
        
        // Prepara parâmetros da API
        $api_params = [
            'perpage' => 50,
            'paged' => 1,
            'fetch_only' => 'title,description,thumbnail',
            'fetch_only_meta' => implode(',', array_values($mapping['mapping_data']))
        ];
        
        // Faz requisição à API do Tainacan
        $api_handler = new TEI_API_Handler();
        $response = $api_handler->get_collection_items($collection_id, $api_params);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Processa dados para o formato do storytelling
        $story_data = $this->process_story_data($response, $mapping);
        
        // Salva no cache
        if ($atts['cache'] && !empty($story_data)) {
            TEI_Cache_Manager::set($cache_key, $story_data, HOUR_IN_SECONDS);
        }
        
        return $story_data;
    }
    
    /**
     * Processa dados para o formato do storytelling
     */
    private function process_story_data($response, $mapping) {
        $story_data = [
            'title' => $mapping['collection_name'] ?? __('História', 'tainacan-explorador'),
            'description' => $mapping['visualization_settings']['intro'] ?? '',
            'chapters' => []
        ];
        
        $title_field = $mapping['mapping_data']['title'] ?? '';
        $description_field = $mapping['mapping_data']['description'] ?? '';
        $image_field = $mapping['mapping_data']['image'] ?? '';
        $background_field = $mapping['mapping_data']['background'] ?? '';
        $subtitle_field = $mapping['mapping_data']['subtitle'] ?? '';
        $link_field = $mapping['mapping_data']['link'] ?? '';
        $order_field = $mapping['mapping_data']['order'] ?? '';
        
        $items = $response['items'] ?? $response;
        
        foreach ($items as $index => $item) {
            // CORREÇÃO: Garantir que os valores sejam strings antes de usar esc_url
            $image_url = $this->get_image_url($item, $image_field);
            $background_url = $this->get_field_value($item, $background_field, '');
            $link_url = $this->get_field_value($item, $link_field, $item['url'] ?? '');
            
            // Converter arrays em strings se necessário
            if (is_array($image_url)) {
                $image_url = !empty($image_url) ? reset($image_url) : '';
            }
            if (is_array($background_url)) {
                $background_url = !empty($background_url) ? reset($background_url) : '';
            }
            if (is_array($link_url)) {
                $link_url = !empty($link_url) ? reset($link_url) : '';
            }
            
            $chapter = [
                'id' => 'chapter-' . ($item['id'] ?? $index),
                'title' => esc_html($this->get_field_value($item, $title_field, $item['title'] ?? '')),
                'subtitle' => esc_html($this->get_field_value($item, $subtitle_field, '')),
                'description' => wp_kses_post($this->get_field_value($item, $description_field, $item['description'] ?? '')),
                'image' => esc_url((string) $image_url),
                'background' => esc_url((string) $background_url),
                'link' => esc_url((string) $link_url),
                'order' => intval($this->get_field_value($item, $order_field, $index))
            ];
            
            $story_data['chapters'][] = $chapter;
        }
        
        // Ordena capítulos
        usort($story_data['chapters'], function($a, $b) {
            return $a['order'] - $b['order'];
        });
        
        return $story_data;
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
            $metadata = $item['metadata'][$field_id];
            
            if (isset($metadata['value'])) {
                $value = $metadata['value'];
            } elseif (isset($metadata['value_as_string'])) {
                $value = $metadata['value_as_string'];
            } else {
                $value = $metadata;
            }
            
            // CORREÇÃO: Sempre retornar string quando for array
            if (is_array($value)) {
                if (!empty($value)) {
                    // Se for array associativo com chaves específicas
                    if (isset($value['url'])) {
                        return $value['url'];
                    }
                    // Se for array simples, pega o primeiro valor
                    return (string) reset($value);
                }
                return $default;
            }
            
            return $value;
        }
        
        // Verifica campos padrão
        if (isset($item[$field_id])) {
            $value = $item[$field_id];
            if (is_array($value)) {
                if (!empty($value)) {
                    if (isset($value['url'])) {
                        return $value['url'];
                    }
                    return (string) reset($value);
                }
                return $default;
            }
            return $value;
        }
        
        // Campos especiais
        switch ($field_id) {
            case 'title':
                return $item['title'] ?? $default;
            case 'description':
                return $item['description'] ?? $item['excerpt'] ?? $default;
            default:
                return $default;
        }
    }
    
    /**
     * Obtém URL da imagem
     */
    private function get_image_url($item, $image_field) {
        if (!empty($image_field)) {
            $image_value = $this->get_field_value($item, $image_field);
            
            // CORREÇÃO: Garantir que seja string
            if (is_array($image_value)) {
                if (!empty($image_value)) {
                    // Se for array com estrutura de imagem do WordPress
                    if (isset($image_value['url'])) {
                        $image_value = $image_value['url'];
                    } elseif (isset($image_value['large'])) {
                        $image_value = $image_value['large'];
                    } elseif (isset($image_value['full'])) {
                        $image_value = $image_value['full'];
                    } else {
                        $image_value = reset($image_value);
                    }
                } else {
                    $image_value = '';
                }
            }
            
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
        
        // Fallback para thumbnail
        if (isset($item['thumbnail'])) {
            if (is_array($item['thumbnail'])) {
                if (isset($item['thumbnail']['large'])) {
                    return $item['thumbnail']['large'];
                } elseif (isset($item['thumbnail']['full'])) {
                    return $item['thumbnail']['full'];
                } elseif (isset($item['thumbnail']['url'])) {
                    return $item['thumbnail']['url'];
                } elseif (!empty($item['thumbnail'])) {
                    return (string) reset($item['thumbnail']);
                }
            } else {
                return $item['thumbnail'];
            }
        }
        
        return '';
    }
    
    /**
     * Obtém configurações do storytelling
     */
    private function get_story_config($atts, $mapping) {
        $settings = $mapping['visualization_settings'] ?? [];
        
        $config = [
            'animation' => $atts['animation'],
            'navigation' => $atts['navigation'],
            'autoplay' => $atts['autoplay'],
            'autoplay_speed' => intval($atts['autoplay_speed']),
            'transition_speed' => intval($atts['transition_speed']),
            'parallax' => $atts['parallax'],
            'fullscreen' => $atts['fullscreen']
        ];
        
        return apply_filters('tei_story_config', $config, $atts, $mapping);
    }
    
    /**
     * Renderiza o storytelling
     */
    private function render_story($story_id, $story_data, $config, $atts) {
        ob_start();
        ?>
        <div class="tei-story-wrapper <?php echo esc_attr($atts['class']); ?>" 
             id="<?php echo esc_attr($story_id); ?>"
             data-config='<?php echo esc_attr(wp_json_encode($config)); ?>'>
            
            <?php if (!empty($story_data['title']) || !empty($story_data['description'])): ?>
            <div class="tei-story-header">
                <?php if (!empty($story_data['title'])): ?>
                <h2 class="tei-story-title"><?php echo esc_html($story_data['title']); ?></h2>
                <?php endif; ?>
                
                <?php if (!empty($story_data['description'])): ?>
                <div class="tei-story-intro"><?php echo wp_kses_post($story_data['description']); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="tei-story-container swiper-container">
                <div class="swiper-wrapper">
                    <?php foreach ($story_data['chapters'] as $index => $chapter): ?>
                    <section class="tei-story-chapter swiper-slide" 
                             id="<?php echo esc_attr($chapter['id']); ?>"
                             data-index="<?php echo esc_attr($index); ?>"
                             <?php if (!empty($chapter['image'])): ?>
                             style="background-image: url('<?php echo esc_url($chapter['image']); ?>');"
                             <?php elseif (!empty($chapter['background'])): ?>
                             style="background-image: url('<?php echo esc_url($chapter['background']); ?>');"
                             <?php endif; ?>>
                        
                        <div class="tei-chapter-overlay"></div>
                        
                        <div class="tei-chapter-content">
                            <div class="tei-chapter-inner">
                                <div class="tei-chapter-text">
                                    <?php if (!empty($chapter['title'])): ?>
                                    <h3 class="tei-chapter-title"><?php echo esc_html($chapter['title']); ?></h3>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($chapter['subtitle'])): ?>
                                    <h4 class="tei-chapter-subtitle"><?php echo esc_html($chapter['subtitle']); ?></h4>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($chapter['description'])): ?>
                                    <div class="tei-chapter-description">
                                        <?php echo wp_kses_post($chapter['description']); ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($chapter['link'])): ?>
                                    <div class="tei-chapter-link">
                                        <a href="<?php echo esc_url($chapter['link']); ?>" 
                                           target="_blank" 
                                           rel="noopener noreferrer"
                                           class="tei-chapter-button">
                                            <?php _e('Ver mais detalhes', 'tainacan-explorador'); ?>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </section>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($config['navigation'] === 'arrows' || $config['navigation'] === 'both'): ?>
                <div class="swiper-button-prev"></div>
                <div class="swiper-button-next"></div>
                <?php endif; ?>
                
                <?php if ($config['navigation'] === 'dots' || $config['navigation'] === 'both'): ?>
                <div class="swiper-pagination"></div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        /* Story Wrapper */
        .tei-story-wrapper {
            position: relative;
            width: 100%;
        }
        
        /* Story Header */
        .tei-story-header {
            padding: 60px 20px;
            text-align: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            position: relative;
            overflow: hidden;
            margin-bottom: 0;
        }
        
        .tei-story-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0 0 16px;
            color: white;
        }
        
        .tei-story-intro {
            font-size: 1.125rem;
            line-height: 1.6;
            max-width: 800px;
            margin: 0 auto;
            opacity: 0.95;
        }
        
        /* Story Container */
        .tei-story-container {
            position: relative;
            width: 100%;
            background: #f9fafb;
        }
        
        /* Story Chapter */
        .tei-story-chapter {
            position: relative;
            min-height: 100vh;
            display: none; /* Esconde por padrão */
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }
        
        .tei-story-chapter.active {
            display: flex; /* Mostra apenas o ativo */
        }
        
        /* Chapter Overlay */
        .tei-chapter-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1;
        }
        
        /* Chapter Content */
        .tei-chapter-content {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .tei-chapter-inner {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 48px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            max-width: 600px;
            margin: 0 auto;
        }
        
        /* Chapter Media */
        .tei-chapter-media {
            overflow: hidden;
            border-radius: 12px;
        }
        
        .tei-chapter-media img {
            width: 100%;
            height: auto;
            display: block;
            object-fit: cover;
        }
        
        /* Chapter Text */
        .tei-chapter-text {
            padding: 20px;
        }
        
        .tei-chapter-title {
            font-size: 2rem;
            font-weight: 700;
            color: #111827;
            margin: 0 0 12px;
            line-height: 1.2;
        }
        
        .tei-chapter-subtitle {
            font-size: 1.125rem;
            color: #6b7280;
            margin: 0 0 24px;
            font-style: italic;
        }
        
        .tei-chapter-description {
            font-size: 1rem;
            line-height: 1.75;
            color: #374151;
            margin-bottom: 24px;
        }
        
        .tei-chapter-description p {
            margin: 0 0 16px;
        }
        
        .tei-chapter-description p:last-child {
            margin-bottom: 0;
        }
        
        /* Chapter Button */
        .tei-chapter-button {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .tei-chapter-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
            color: white;
        }
        
        /* Navigation */
        .swiper-button-prev,
        .swiper-button-next {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 48px;
            height: 48px;
            background: white;
            border-radius: 50%;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 10;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #374151;
        }
        
        .swiper-button-prev {
            left: 20px;
        }
        
        .swiper-button-next {
            right: 20px;
        }
        
        .swiper-button-prev::after,
        .swiper-button-next::after {
            font-size: 20px;
            font-weight: bold;
        }
        
        .swiper-pagination {
            position: absolute;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 10;
            display: flex;
            gap: 10px;
        }
        
        .swiper-pagination-bullet {
            width: 12px;
            height: 12px;
            background: rgba(255, 255, 255, 0.5);
            border: 2px solid white;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .swiper-pagination-bullet-active {
            background: white;
            transform: scale(1.2);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .tei-story-title {
                font-size: 1.875rem;
            }
            
            .tei-story-intro {
                font-size: 1rem;
            }
            
            .tei-chapter-inner {
                grid-template-columns: 1fr;
                padding: 24px;
            }
            
            .tei-chapter-title {
                font-size: 1.5rem;
            }
            
            .tei-chapter-text {
                padding: 10px;
            }
            
            .swiper-button-prev,
            .swiper-button-next {
                width: 40px;
                height: 40px;
            }
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var storyWrapper = $('#<?php echo esc_js($story_id); ?>');
            var chapters = storyWrapper.find('.tei-story-chapter');
            var currentIndex = 0;
            var totalChapters = chapters.length;
            
            // Esconde todos exceto o primeiro
            chapters.hide();
            chapters.eq(0).show().addClass('active');
            
            // Navegação por setas
            storyWrapper.on('click', '.swiper-button-next', function() {
                if (currentIndex < totalChapters - 1) {
                    chapters.eq(currentIndex).fadeOut(300).removeClass('active');
                    currentIndex++;
                    chapters.eq(currentIndex).fadeIn(300).addClass('active');
                    updatePagination();
                }
            });
            
            storyWrapper.on('click', '.swiper-button-prev', function() {
                if (currentIndex > 0) {
                    chapters.eq(currentIndex).fadeOut(300).removeClass('active');
                    currentIndex--;
                    chapters.eq(currentIndex).fadeIn(300).addClass('active');
                    updatePagination();
                }
            });
            
            // Navegação por pontos
            function createPagination() {
                var pagination = storyWrapper.find('.swiper-pagination');
                pagination.empty();
                for (var i = 0; i < totalChapters; i++) {
                    var bullet = $('<span class="swiper-pagination-bullet" data-index="' + i + '"></span>');
                    if (i === 0) bullet.addClass('swiper-pagination-bullet-active');
                    pagination.append(bullet);
                }
            }
            
            function updatePagination() {
                storyWrapper.find('.swiper-pagination-bullet').removeClass('swiper-pagination-bullet-active');
                storyWrapper.find('.swiper-pagination-bullet').eq(currentIndex).addClass('swiper-pagination-bullet-active');
            }
            
            storyWrapper.on('click', '.swiper-pagination-bullet', function() {
                var index = $(this).data('index');
                chapters.eq(currentIndex).fadeOut(300).removeClass('active');
                currentIndex = index;
                chapters.eq(currentIndex).fadeIn(300).addClass('active');
                updatePagination();
            });
            
            createPagination();
            
            // Autoplay se configurado
            <?php if ($config['autoplay']): ?>
            setInterval(function() {
                if (currentIndex < totalChapters - 1) {
                    storyWrapper.find('.swiper-button-next').click();
                } else {
                    chapters.eq(currentIndex).fadeOut(300).removeClass('active');
                    currentIndex = 0;
                    chapters.eq(currentIndex).fadeIn(300).addClass('active');
                    updatePagination();
                }
            }, <?php echo intval($config['autoplay_speed']); ?>);
            <?php endif; ?>
        });
        </script>
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
