<?php
/**
 * Classe de Sanitização
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_Sanitizer {
    
    /**
     * Sanitiza atributos de shortcode
     * 
     * @param array|string $atts Atributos do shortcode
     * @param array $defaults Valores padrão
     * @return array
     */
    public static function sanitize_shortcode_atts($atts, $defaults) {
        // CORREÇÃO: Garantir que $atts seja um array antes de processar
        if (!is_array($atts)) {
            $atts = [];
        }
        
        // CORREÇÃO: Usar shortcode_atts primeiro para garantir estrutura correta
        $atts = shortcode_atts($defaults, $atts);
        
        // CORREÇÃO: Sanitizar cada valor individualmente, verificando o tipo
        foreach ($atts as $key => $value) {
            // CORREÇÃO: Verificar se o valor não é um array antes de sanitizar
            if (!is_array($value)) {
                $atts[$key] = self::sanitize_by_key($key, $value);
            } else {
                // Se for array, converter para string ou usar valor padrão
                $atts[$key] = isset($defaults[$key]) ? $defaults[$key] : '';
            }
        }
        
        return $atts;
    }
    
    /**
     * Sanitiza baseado na chave
     * 
     * @param string $key Chave do atributo
     * @param mixed $value Valor
     * @return mixed
     */
    private static function sanitize_by_key($key, $value) {
        // CORREÇÃO: Garantir que o valor seja string antes de processar
        if (is_array($value)) {
            $value = '';
        }
        
        // CORREÇÃO: Converter valor para string se necessário
        $value = (string) $value;
        
        switch ($key) {
            case 'collection':
            case 'collection_id':
            case 'zoom':
            case 'limit':
            case 'autoplay_speed':
            case 'transition_speed':
            case 'initial_zoom':
                return intval($value);
                
            case 'height':
            case 'width':
                return self::sanitize_dimension($value);
                
            case 'center':
            case 'filter':
            case 'class':
            case 'style':
            case 'animation':
            case 'navigation':
            case 'theme':
            case 'timenav_position':
            case 'language':
                return sanitize_text_field($value);
                
            case 'cluster':
            case 'fullscreen':
            case 'cache':
            case 'autoplay':
            case 'parallax':
            case 'hash_bookmark':
            case 'debug':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
                
            case 'id':
                return sanitize_html_class($value);
                
            case 'start_date':
            case 'end_date':
                return sanitize_text_field($value);
                
            default:
                return sanitize_text_field($value);
        }
    }
    
    /**
     * Sanitiza dimensão (height/width)
     * 
     * @param string $value
     * @return string
     */
    private static function sanitize_dimension($value) {
        // CORREÇÃO: Garantir que seja string
        $value = (string) $value;
        
        if (empty($value)) {
            return '100%';
        }
        
        if (is_numeric($value)) {
            return $value . 'px';
        }
        
        // Aceita valores com unidades (px, %, em, rem, vh, vw)
        if (preg_match('/^(\d+)(px|%|em|rem|vh|vw)?$/i', $value)) {
            return $value;
        }
        
        // Valor especial "auto"
        if ($value === 'auto') {
            return 'auto';
        }
        
        return '100%'; // Padrão
    }
    
    /**
     * Sanitiza e valida valor baseado no tipo
     * 
     * @param mixed $value Valor a sanitizar
     * @param string $type Tipo de dado
     * @param array $options Opções adicionais
     * @return mixed
     */
    public static function sanitize($value, $type = 'text', $options = []) {
        // CORREÇÃO: Verificar tipo do valor antes de processar
        if ($type !== 'array' && is_array($value)) {
            $value = implode(',', $value);
        }
        
        switch ($type) {
            case 'text':
                return sanitize_text_field((string) $value);
                
            case 'textarea':
                return sanitize_textarea_field((string) $value);
                
            case 'html':
                return wp_kses_post((string) $value);
                
            case 'email':
                return sanitize_email((string) $value);
                
            case 'url':
                return esc_url_raw((string) $value);
                
            case 'int':
            case 'integer':
                return intval($value);
                
            case 'float':
            case 'number':
                return floatval($value);
                
            case 'bool':
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
                
            case 'array':
                return self::sanitize_array($value, $options);
                
            case 'json':
                return self::sanitize_json($value);
                
            case 'coordinates':
                return self::sanitize_coordinates($value);
                
            case 'color':
                return self::sanitize_hex_color((string) $value);
                
            case 'slug':
                return sanitize_key((string) $value);
                
            case 'file':
                return sanitize_file_name((string) $value);
                
            default:
                return sanitize_text_field((string) $value);
        }
    }
    
    /**
     * Sanitiza array recursivamente
     * 
     * @param array $array
     * @param array $options
     * @return array
     */
    private static function sanitize_array($array, $options = []) {
        if (!is_array($array)) {
            return [];
        }
        
        $sanitized = [];
        $type = $options['type'] ?? 'text';
        
        foreach ($array as $key => $value) {
            $sanitized_key = sanitize_key($key);
            
            if (is_array($value)) {
                $sanitized[$sanitized_key] = self::sanitize_array($value, $options);
            } else {
                $sanitized[$sanitized_key] = self::sanitize($value, $type);
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitiza JSON
     * 
     * @param string $json
     * @return string
     */
    private static function sanitize_json($json) {
        if (is_string($json)) {
            $decoded = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return wp_json_encode(self::sanitize_array($decoded));
            }
        }
        return '{}';
    }
    
    /**
     * Sanitiza coordenadas
     * 
     * @param mixed $value
     * @return array|null
     */
    public static function sanitize_coordinates($value) {
        if (empty($value)) {
            return null;
        }
        
        // Se já for array com lat/lon
        if (is_array($value)) {
            if (isset($value['lat']) && isset($value['lon'])) {
                return [
                    'lat' => floatval($value['lat']),
                    'lon' => floatval($value['lon'])
                ];
            }
            if (isset($value[0]) && isset($value[1])) {
                return [
                    'lat' => floatval($value[0]),
                    'lon' => floatval($value[1])
                ];
            }
        }
        
        // Se for string
        if (is_string($value)) {
            // Formato: "lat,lon" ou "lat, lon"
            if (preg_match('/^(-?\d+\.?\d*)\s*,\s*(-?\d+\.?\d*)$/', $value, $matches)) {
                return [
                    'lat' => floatval($matches[1]),
                    'lon' => floatval($matches[2])
                ];
            }
            
            // Formato: "[lat, lon]"
            if (preg_match('/^\[?\s*(-?\d+\.?\d*)\s*,\s*(-?\d+\.?\d*)\s*\]?$/', $value, $matches)) {
                return [
                    'lat' => floatval($matches[1]),
                    'lon' => floatval($matches[2])
                ];
            }
            
            // Formato JSON
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return self::sanitize_coordinates($decoded);
            }
        }
        
        return null;
    }
    
    /**
     * Sanitiza cor hexadecimal
     * 
     * @param string $color
     * @return string
     */
    private static function sanitize_hex_color($color) {
        $color = (string) $color;
        if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color)) {
            return $color;
        }
        return '';
    }
    
    /**
     * Valida dados com regras
     * 
     * @param array $data Dados a validar
     * @param array $rules Regras de validação
     * @return array|WP_Error
     */
    public static function validate($data, $rules) {
        $errors = [];
        
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            
            // Campo obrigatório
            if (isset($rule['required']) && $rule['required'] && empty($value)) {
                $errors[$field] = __('Este campo é obrigatório', 'tainacan-explorador');
                continue;
            }
            
            // Tipo de validação
            if (!empty($value) && isset($rule['type'])) {
                switch ($rule['type']) {
                    case 'email':
                        if (!is_email($value)) {
                            $errors[$field] = __('Email inválido', 'tainacan-explorador');
                        }
                        break;
                        
                    case 'url':
                        if (!filter_var($value, FILTER_VALIDATE_URL)) {
                            $errors[$field] = __('URL inválida', 'tainacan-explorador');
                        }
                        break;
                        
                    case 'number':
                        if (!is_numeric($value)) {
                            $errors[$field] = __('Valor deve ser numérico', 'tainacan-explorador');
                        }
                        break;
                }
            }
            
            // Validação customizada
            if (isset($rule['validate_callback']) && is_callable($rule['validate_callback'])) {
                $result = call_user_func($rule['validate_callback'], $value);
                if ($result !== true) {
                    $errors[$field] = $result;
                }
            }
        }
        
        if (!empty($errors)) {
            return new WP_Error('validation_failed', __('Erro de validação', 'tainacan-explorador'), $errors);
        }
        
        return $data;
    }
}
