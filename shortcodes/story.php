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
     * 
     * @param array $atts Atributos do shortcode
     * @return string HTML do storytelling
     */
    public function render($atts) {
        // Parse dos atributos
        $atts = shortcode_atts([
            'collection' => '',
            'height' => 'auto',
            'animation' => 'fade',
            'navigation' => 'dots',
            'autoplay' => 'false',
            'autoplay_speed' => 7000,
            'transition_speed' => 800,
            'parallax' => 'true',
            'fullscreen' => 'true',
            'cache' => 'true',
            'class' => '',
            'id' => 'tei-story-' . uniqid()
        ], $atts, 'tainacan_explorador_story');
        
        // Validação da coleção
        if (empty($atts['collection'])) {
            return $this->render_error(__('ID da coleção não especificado.', 'tainacan-explorador'));
        }
        
        // Obtém mapeamento
        $mapping = TEI_Metadata_Mapper::get_mapping($atts['collection'], 'story');
        
        if (!$mapping) {
            return $this->render_error(__('Mapeamento de storytelling não configurado para esta coleção.', 'tainacan-explorador'));
        }
        
        // Obtém dados da coleção
        $story_data = $this->get_story_data($atts['collection'], $mapping, $atts);
        
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
     * 
     * @param int $collection_id ID da coleção
     * @param array $mapping Mapeamento de metadados
     * @param array $atts Atributos do shortcode
     * @return array|WP_Error
     */
    private function get_story_data($collection_id, $mapping, $atts) {
        // Verifica cache
        if ($atts['cache'] === 'true') {
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
        if ($atts['cache'] === 'true' && !empty($story_data)) {
            TEI_Cache_Manager::set($cache_key, $story_data, HOUR_IN_SECONDS);
        }
        
        return $story_data;
    }
    
    /**
     * Processa dados para o formato do storytelling
     * 
     * @param array $response Resposta da API
     * @param array $mapping Mapeamento
     * @return array
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
        
        foreach ($response['items'] as $index => $item) {
            $chapter = [
                'id' => 'chapter-' . $item['id'],
                'index' => $index + 1,
                'title' => $this->get_field_value($item, $title_field, $item['title']),
                'subtitle' => $this->get_field_value($item, $subtitle_field, ''),
                'content' => $this->format_content($item, $description_field),
                'image' => $this->get_image_url($item, $image_field),
                'background' => $this->get_background($item, $background_field, $image_field),
                'link' => $this->get_field_value($item, $link_field, $item['url']),
                'metadata' => $this->get_chapter_metadata($item, $mapping)
            ];
            
            // Adiciona ordem se especificada
            if ($order_field) {
                $chapter['order'] = intval($this->get_field_value($item, $order_field, $index));
            } else {
                $chapter['order'] = $index;
            }
            
            $story_data['chapters'][] = $chapter;
        }
        
        // Ordena capítulos
        usort($story_data['chapters'], function($a, $b) {
            return $a['order'] - $b['order'];
        });
        
        return $story_data;
    }
    
    /**
     * Formata conteúdo do capítulo
     * 
     * @param array $item Item da coleção
     * @param string $description_field Campo de descrição
     * @return string
     */
    private function format_content($item, $description_field) {
        $content = $this->get_field_value($item, $description_field, $item['description']);
        
        // Processa shortcodes se houver
        $content = do_shortcode($content);
        
        // Aplica autop para parágrafos
        $content = wpautop($content);
        
        return wp_kses_post($content);
    }
    
    /**
     * Obtém background do capítulo
     * 
     * @param array $item Item da coleção
     * @param string $background_field Campo de background
     * @param string $image_field Campo de imagem (fallback)
     * @return array
     */
    private function get_background($item, $background_field, $image_field) {
        $background = [
            'type' => 'color',
            'value' => '#ffffff'
        ];
        
        // Primeiro tenta campo de background
        if ($background_field) {
            $bg_value = $this->get_field_value($item, $background_field);
            
            if ($bg_value) {
                if (filter_var($bg_value, FILTER_VALIDATE_URL)) {
                    $background = [
                        'type' => 'image',
                        'value' => $bg_value
                    ];
                } elseif (preg_match('/^#[0-9A-F]{6}$/i', $bg_value)) {
                    $background = [
                        'type' => 'color',
                        'value' => $bg_value
                    ];
                } elseif (strpos($bg_value, 'gradient') !== false) {
                    $background = [
                        'type' => 'gradient',
                        'value' => $bg_value
                    ];
                }
            }
        }
        
        // Fallback para imagem se não houver background
        if ($background['type'] === 'color' && $background['value'] === '#ffffff') {
            $image_url = $this->get_image_url($item, $image_field);
            if ($image_url) {
                $background = [
                    'type' => 'image',
                    'value' => $image_url
                ];
            }
        }
        
        return $background;
    }
    
    /**
     * Obtém metadados adicionais do capítulo
     * 
     * @param array $item Item da coleção
     * @param array $mapping Mapeamento
     * @return array
     */
    private function get_chapter_metadata($item, $mapping) {
        $metadata = [];
        
        // Adiciona metadados extras configurados
        $extra_fields = $mapping['visualization_settings']['extra_fields'] ?? [];
        
        foreach ($extra_fields as $field_key => $field_label) {
            $value = $this->get_field_value($item, $field_key);
            if ($value) {
                $metadata[$field_label] = $value;
            }
        }
        
        return $metadata;
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
                    $image_url = wp_get_attachment_image_url($image_value, 'full');
                    if ($image_url) {
                        return $image_url;
                    }
                } elseif (filter_var($image_value, FILTER_VALIDATE_URL)) {
                    return $image_value;
                }
            }
        }
        
        if (isset($item['thumbnail']['full'][0])) {
            return $item['thumbnail']['full'][0];
        }
        
        if (isset($item['thumbnail']['tainacan-medium'][0])) {
            return $item['thumbnail']['tainacan-medium'][0];
        }
        
        return '';
    }
    
    /**
     * Obtém configurações do storytelling
     * 
     * @param array $atts Atributos do shortcode
     * @param array $mapping Mapeamento
     * @return array
     */
    private function get_story_config($atts, $mapping) {
        $settings = $mapping['visualization_settings'] ?? [];
        
        $config = [
            'animation' => $atts['animation'],
            'navigation' => $atts['navigation'],
            'autoplay' => $atts['autoplay'] === 'true',
            'autoplay_speed' => intval($atts['autoplay_speed']),
            'transition_speed' => intval($atts['transition_speed']),
            'parallax' => $atts['parallax'] === 'true',
            'fullscreen' => $atts['fullscreen'] === 'true',
            'loop' => $settings['loop'] ?? false,
            'keyboard' => $settings['keyboard'] ?? true,
            'touch' => $settings['touch'] ?? true,
            'mousewheel' => $settings['mousewheel'] ?? false,
            'progress' => $settings['show_progress'] ?? true,
            'chapter_numbers' => $settings['show_numbers'] ?? true,
            'social_share' => $settings['social_share'] ?? false,
            'theme' => $settings['theme'] ?? 'light'
        ];
        
        return apply_filters('tei_story_config', $config, $atts, $mapping);
    }
    
    /**
     * Renderiza o storytelling
     * 
     * @param string $story_id ID do storytelling
     * @param array $story_data Dados do storytelling
     * @param array $config Configurações
     * @param array $atts Atributos
     * @return string
     */
    private function render_story($story_id, $story_data, $config, $atts) {
        ob_start();
        ?>
        <div class="tei-story-container <?php echo esc_attr($atts['class']); ?>" 
             data-story-id="<?php echo esc_attr($story_id); ?>">
            
            <?php if (!empty($story_data['title']) || !empty($story_data['description'])): ?>
            <div class="tei-story-header">
                <?php if (!empty($story_data['title'])): ?>
                <h2 class="tei-story-title"><?php echo esc_html($story_data['title']); ?></h2>
                <?php endif; ?>
                
                <?php if (!empty($story_data['description'])): ?>
                <div class="tei-story-intro">
                    <?php echo wp_kses_post($story_data['description']); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($config['progress']): ?>
            <div class="tei-story-progress">
                <div class="tei-story-progress-bar"></div>
            </div>
            <?php endif; ?>
            
            <div id="<?php echo esc_attr($story_id); ?>" class="tei-story-wrapper">
                <?php foreach ($story_data['chapters'] as $index => $chapter): ?>
                <section class="tei-story-chapter" 
                         data-chapter-id="<?php echo esc_attr($chapter['id']); ?>"
                         data-chapter-index="<?php echo esc_attr($index); ?>">
                    
                    <div class="tei-story-background" 
                         data-background-type="<?php echo esc_attr($chapter['background']['type']); ?>"
                         <?php if ($chapter['background']['type'] === 'image'): ?>
                         style="background-image: url('<?php echo esc_url($chapter['background']['value']); ?>');"
                         <?php elseif ($chapter['background']['type'] === 'gradient'): ?>
                         style="background: <?php echo esc_attr($chapter['background']['value']); ?>;"
                         <?php else: ?>
                         style="background-color: <?php echo esc_attr($chapter['background']['value']); ?>;"
                         <?php endif; ?>>
                    </div>
                    
                    <div class="tei-story-content">
                        <div class="tei-story-content-inner">
                            <?php if ($config['chapter_numbers']): ?>
                            <span class="tei-story-chapter-number">
                                <?php echo sprintf('%02d', $chapter['index']); ?>
                            </span>
                            <?php endif; ?>
                            
                            <h3 class="tei-story-chapter-title">
                                <?php echo esc_html($chapter['title']); ?>
                            </h3>
                            
                            <?php if (!empty($chapter['subtitle'])): ?>
                            <p class="tei-story-chapter-subtitle">
                                <?php echo esc_html($chapter['subtitle']); ?>
                            </p>
                            <?php endif; ?>
                            
                            <?php if (!empty($chapter['image']) && $chapter['background']['type'] !== 'image'): ?>
                            <div class="tei-story-image">
                                <img src="<?php echo esc_url($chapter['image']); ?>" 
                                     alt="<?php echo esc_attr($chapter['title']); ?>">
                            </div>
                            <?php endif; ?>
                            
                            <div class="tei-story-text">
                                <?php echo $chapter['content']; ?>
                            </div>
                            
                            <?php if (!empty($chapter['metadata'])): ?>
                            <div class="tei-story-metadata">
                                <?php foreach ($chapter['metadata'] as $label => $value): ?>
                                <div class="tei-story-meta-item">
                                    <span class="tei-story-meta-label"><?php echo esc_html($label); ?>:</span>
                                    <span class="tei-story-meta-value"><?php echo esc_html($value); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($chapter['link'])): ?>
                            <a href="<?php echo esc_url($chapter['link']); ?>" 
                               class="tei-story-link" 
                               target="_blank">
                                <?php esc_html_e('Ver mais detalhes', 'tainacan-explorador'); ?>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M7 17L17 7M17 7H7M17 7V17"/>
                                </svg>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>
            
            <?php if ($config['navigation'] !== 'none'): ?>
            <nav class="tei-story-nav tei-story-nav-<?php echo esc_attr($config['navigation']); ?>">
                <?php if ($config['navigation'] === 'dots'): ?>
                <div class="tei-story-dots">
                    <?php foreach ($story_data['chapters'] as $index => $chapter): ?>
                    <button class="tei-story-dot <?php echo $index === 0 ? 'active' : ''; ?>" 
                            data-chapter="<?php echo esc_attr($index); ?>"
                            aria-label="<?php echo esc_attr(sprintf(__('Ir para capítulo %d', 'tainacan-explorador'), $index + 1)); ?>">
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php elseif ($config['navigation'] === 'arrows'): ?>
                <button class="tei-story-prev" aria-label="<?php esc_attr_e('Capítulo anterior', 'tainacan-explorador'); ?>">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M15 18l-6-6 6-6"/>
                    </svg>
                </button>
                <button class="tei-story-next" aria-label="<?php esc_attr_e('Próximo capítulo', 'tainacan-explorador'); ?>">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M9 18l6-6-6-6"/>
                    </svg>
                </button>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
            
            <?php if ($config['fullscreen']): ?>
            <button class="tei-story-fullscreen" aria-label="<?php esc_attr_e('Tela cheia', 'tainacan-explorador'); ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>
                </svg>
            </button>
            <?php endif; ?>
        </div>
        
        <script>
        (function() {
            const storyData = <?php echo wp_json_encode($story_data); ?>;
            const storyConfig = <?php echo wp_json_encode($config); ?>;
            const storyId = <?php echo wp_json_encode($story_id); ?>;
            
            // Inicializa o storytelling quando o DOM estiver pronto
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    if (window.TEI_Story) {
                        new window.TEI_Story(storyId, storyData, storyConfig);
                    }
                });
            } else {
                if (window.TEI_Story) {
                    new window.TEI_Story(storyId, storyData, storyConfig);
                }
            }
        })();
        </script>
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
            '<div class="tei-error tei-story-error"><p>%s</p></div>',
            esc_html($message)
        );
    }
}
