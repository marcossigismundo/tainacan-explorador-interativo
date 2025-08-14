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
            'autoplay_speed' => 7000,
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
            
            <div class="tei-story-container">
                <?php foreach ($story_data['chapters'] as $index => $chapter): ?>
                <section class="tei-story-chapter" 
                         id="<?php echo esc_attr($chapter['id']); ?>"
                         data-index="<?php echo esc_attr($index); ?>">
                    
                    <?php if (!empty($chapter['background'])): ?>
                    <div class="tei-chapter-background" 
                         style="background-image: url('<?php echo esc_url($chapter['background']); ?>');">
                    </div>
                    <?php endif; ?>
                    
                    <div class="tei-chapter-content">
                        <?php if (!empty($chapter['image'])): ?>
                        <div class="tei-chapter-media">
                            <img src="<?php echo esc_url($chapter['image']); ?>" 
                                 alt="<?php echo esc_attr($chapter['title']); ?>">
                        </div>
                        <?php endif; ?>
                        
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
                                   class="tei-chapter-button">
                                    <?php _e('Ver mais detalhes', 'tainacan-explorador'); ?>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>
            
            <?php if ($config['navigation'] !== 'none'): ?>
            <div class="tei-story-navigation">
                <?php if ($config['navigation'] === 'dots'): ?>
                <div class="tei-story-dots">
                    <?php foreach ($story_data['chapters'] as $index => $chapter): ?>
                    <button class="tei-story-dot <?php echo $index === 0 ? 'active' : ''; ?>" 
                            data-index="<?php echo esc_attr($index); ?>"
                            aria-label="<?php echo esc_attr(sprintf(__('Ir para capítulo %d', 'tainacan-explorador'), $index + 1)); ?>">
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($config['navigation'] === 'arrows' || $config['navigation'] === 'both'): ?>
                <button class="tei-story-prev" aria-label="<?php esc_attr_e('Capítulo anterior', 'tainacan-explorador'); ?>">
                    <span class="dashicons dashicons-arrow-left-alt2"></span>
                </button>
                <button class="tei-story-next" aria-label="<?php esc_attr_e('Próximo capítulo', 'tainacan-explorador'); ?>">
                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <style>
        .tei-story-wrapper {
            position: relative;
            width: 100%;
        }
        
        .tei-story-chapter {
            min-height: 100vh;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
        }
        
        .tei-chapter-background {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-size: cover;
            background-position: center;
            opacity: 0.3;
            z-index: -1;
        }
        
        .tei-chapter-content {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            align-items: center;
        }
        
        .tei-chapter-media img {
            width: 100%;
            height: auto;
            border-radius: 8px;
        }
        
        .tei-chapter-title {
            font-size: 2.5em;
            margin-bottom: 20px;
        }
        
        .tei-chapter-subtitle {
            font-size: 1.5em;
            color: #666;
            margin-bottom: 20px;
        }
        
        .tei-chapter-description {
            font-size: 1.1em;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        
        .tei-chapter-button {
            display: inline-block;
            padding: 12px 30px;
            background: #0073aa;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background 0.3s;
        }
        
        .tei-chapter-button:hover {
            background: #005177;
        }
        
        @media (max-width: 768px) {
            .tei-chapter-content {
                grid-template-columns: 1fr;
            }
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
