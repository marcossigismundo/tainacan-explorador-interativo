<?php
/**
 * Classe de sanitização e validação
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_Sanitizer {
    
    /**
     * Sanitiza dados de entrada
     * 
     * @param mixed $data Dados a serem sanitizados
     * @param string $type Tipo de sanitização
     * @return mixed
     */
    public static function sanitize($data, $type = 'text') {
        if (is_array($data)) {
            return self::sanitize_array($data, $type);
        }
        
        switch ($type) {
            case 'text':
                return sanitize_text_field($data);
                
            case 'textarea':
                return sanitize_textarea_field($data);
                
            case 'html':
                return wp_kses_post($data);
                
            case 'email':
                return sanitize_email($data);
                
            case 'url':
                return esc_url_raw($data);
                
            case 'int':
                return intval($data);
                
            case 'float':
                return floatval($data);
                
            case 'bool':
                return filter_var($data, FILTER_VALIDATE_BOOLEAN);
                
            case 'key':
                return sanitize_key($data);
                
            case 'title':
                return sanitize_title($data);
                
            case 'filename':
                return sanitize_file_name($data);
                
            case 'json':
                return self::sanitize_json($data);
                
            case 'coordinates':
                return self::sanitize_coordinates($data);
                
            case 'date':
                return self::sanitize_date($data);
                
            default:
                return sanitize_text_field($data);
        }
    }
    
    /**
     * Sanitiza array recursivamente
     * 
     * @param array $array Array a ser sanitizado
     * @param string $type Tipo de sanitização
     * @return array
     */
    private static function sanitize_array($array, $type = 'text') {
        $sanitized = [];
        
        foreach ($array as $key => $value) {
            $clean_key = sanitize_key($key);
            
            if (is_array($value)) {
                $sanitized[$clean_key] = self::sanitize_array($value, $type);
            } else {
                $sanitized[$clean_key] = self::sanitize($value, $type);
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitiza JSON
     * 
     * @param string $json String JSON
     * @return string
     */
    private static function sanitize_json($json) {
        if (is_string($json)) {
            $decoded = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $sanitized = self::sanitize_array($decoded);
                return wp_json_encode($sanitized);
            }
        }
        return '';
    }
    
    /**
     * Sanitiza coordenadas
     * 
     * @param mixed $coords Coordenadas
     * @return array|null
     */
    private static function sanitize_coordinates($coords) {
        if (is_string($coords)) {
            // Formato: lat,lon
            if (preg_match('/^(-?\d+\.?\d*),\s*(-?\d+\.?\d*)$/', $coords, $matches)) {
                return [
                    'lat' => floatval($matches[1]),
                    'lon' => floatval($matches[2])
                ];
            }
        } elseif (is_array($coords)) {
            if (isset($coords['lat']) && isset($coords['lon'])) {
                return [
                    'lat' => floatval($coords['lat']),
                    'lon' => floatval($coords['lon'])
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Sanitiza data
     * 
     * @param string $date Data
     * @return string
     */
    private static function sanitize_date($date) {
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d H:i:s', $timestamp);
        }
        return '';
    }
    
    /**
     * Valida dados
     * 
     * @param mixed $data Dados a serem validados
     * @param string $type Tipo de validação
     * @param array $options Opções adicionais
     * @return bool|WP_Error
     */
    public static function validate($data, $type, $options = []) {
        switch ($type) {
            case 'required':
                if (empty($data)) {
                    return new WP_Error('required', __('Este campo é obrigatório', 'tainacan-explorador'));
                }
                break;
                
            case 'email':
                if (!is_email($data)) {
                    return new WP_Error('invalid_email', __('Email inválido', 'tainacan-explorador'));
                }
                break;
                
            case 'url':
                if (!filter_var($data, FILTER_VALIDATE_URL)) {
                    return new WP_Error('invalid_url', __('URL inválida', 'tainacan-explorador'));
                }
                break;
                
            case 'numeric':
                if (!is_numeric($data)) {
                    return new WP_Error('not_numeric', __('Valor deve ser numérico', 'tainacan-explorador'));
                }
                break;
                
            case 'min':
                if (isset($options['value']) && $data < $options['value']) {
                    return new WP_Error('min_value', sprintf(__('Valor mínimo: %s', 'tainacan-explorador'), $options['value']));
                }
                break;
                
            case 'max':
                if (isset($options['value']) && $data > $options['value']) {
                    return new WP_Error('max_value', sprintf(__('Valor máximo: %s', 'tainacan-explorador'), $options['value']));
                }
                break;
                
            case 'length':
                $length = is_array($data) ? count($data) : strlen($data);
                if (isset($options['min']) && $length < $options['min']) {
                    return new WP_Error('min_length', sprintf(__('Comprimento mínimo: %d', 'tainacan-explorador'), $options['min']));
                }
                if (isset($options['max']) && $length > $options['max']) {
                    return new WP_Error('max_length', sprintf(__('Comprimento máximo: %d', 'tainacan-explorador'), $options['max']));
                }
                break;
                
            case 'in_array':
                if (isset($options['values']) && !in_array($data, $options['values'])) {
                    return new WP_Error('invalid_option', __('Opção inválida', 'tainacan-explorador'));
                }
                break;
                
            case 'coordinates':
                $coords = self::sanitize_coordinates($data);
                if (!$coords) {
                    return new WP_Error('invalid_coords', __('Coordenadas inválidas', 'tainacan-explorador'));
                }
                if ($coords['lat'] < -90 || $coords['lat'] > 90) {
                    return new WP_Error('invalid_lat', __('Latitude deve estar entre -90 e 90', 'tainacan-explorador'));
                }
                if ($coords['lon'] < -180 || $coords['lon'] > 180) {
                    return new WP_Error('invalid_lon', __('Longitude deve estar entre -180 e 180', 'tainacan-explorador'));
                }
                break;
                
            case 'date':
                if (strtotime($data) === false) {
                    return new WP_Error('invalid_date', __('Data inválida', 'tainacan-explorador'));
                }
                break;
                
            case 'json':
                if (is_string($data)) {
                    json_decode($data);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        return new WP_Error('invalid_json', __('JSON inválido', 'tainacan-explorador'));
                    }
                }
                break;
        }
        
        return true;
    }
    
    /**
     * Escapa dados para output
     * 
     * @param mixed $data Dados a serem escapados
     * @param string $context Contexto de escape
     * @return mixed
     */
    public static function escape($data, $context = 'html') {
        if (is_array($data)) {
            return array_map(function($item) use ($context) {
                return self::escape($item, $context);
            }, $data);
        }
        
        switch ($context) {
            case 'html':
                return esc_html($data);
                
            case 'attr':
                return esc_attr($data);
                
            case 'url':
                return esc_url($data);
                
            case 'js':
                return esc_js($data);
                
            case 'textarea':
                return esc_textarea($data);
                
            case 'sql':
                global $wpdb;
                return esc_sql($data);
                
            default:
                return esc_html($data);
        }
    }
    
    /**
     * Limpa e valida parâmetros de shortcode
     * 
     * @param array $atts Atributos do shortcode
     * @param array $defaults Valores padrão
     * @return array
     */
    public static function sanitize_shortcode_atts($atts, $defaults) {
        $sanitized = [];
        
        foreach ($defaults as $key => $default) {
            if (isset($atts[$key])) {
                $value = $atts[$key];
                
                // Detecta tipo baseado no valor padrão
                if (is_bool($default)) {
                    $sanitized[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                } elseif (is_int($default)) {
                    $sanitized[$key] = intval($value);
                } elseif (is_float($default)) {
                    $sanitized[$key] = floatval($value);
                } elseif (is_array($default)) {
                    $sanitized[$key] = is_array($value) ? self::sanitize_array($value) : $default;
                } else {
                    $sanitized[$key] = sanitize_text_field($value);
                }
            } else {
                $sanitized[$key] = $default;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitiza consulta SQL
     * 
     * @param string $query Query SQL
     * @param array $args Argumentos para prepare
     * @return string
     */
    public static function prepare_sql($query, $args = []) {
        global $wpdb;
        
        if (empty($args)) {
            return $query;
        }
        
        // Sanitiza cada argumento baseado no tipo
        $sanitized_args = [];
        foreach ($args as $arg) {
            if (is_int($arg)) {
                $sanitized_args[] = '%d';
            } elseif (is_float($arg)) {
                $sanitized_args[] = '%f';
            } else {
                $sanitized_args[] = '%s';
            }
        }
        
        return $wpdb->prepare($query, $args);
    }
    
    /**
     * Remove scripts maliciosos
     * 
     * @param string $input Input a ser limpo
     * @return string
     */
    public static function remove_scripts($input) {
        // Remove tags script
        $input = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $input);
        
        // Remove atributos on* (onclick, onload, etc)
        $input = preg_replace('#(<[^>]+[\s\r\n\"\'])(on|xmlns)[^>]*>#iU', '$1>', $input);
        
        // Remove javascript: e vbscript: protocols
        $input = preg_replace('#([a-z]*)[\x00-\x20]*=[\x00-\x20]*([`\'"]*)[\x00-\x20]*j[\x00-\x20]*a[\x00-\x20]*v[\x00-\x20]*a[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iU', '$1=$2nojavascript...', $input);
        $input = preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*v[\x00-\x20]*b[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iU', '$1=$2novbscript...', $input);
        
        // Remove namespaced elements
        $input = preg_replace('#</*\w+:\w[^>]*>#i', '', $input);
        
        return $input;
    }
    
    /**
     * Valida nonce
     * 
     * @param string $nonce Nonce a ser validado
     * @param string $action Ação do nonce
     * @return bool
     */
    public static function verify_nonce($nonce, $action) {
        return wp_verify_nonce($nonce, $action);
    }
    
    /**
     * Valida capacidade do usuário
     * 
     * @param string $capability Capacidade requerida
     * @param int $user_id ID do usuário (opcional)
     * @return bool
     */
    public static function check_capability($capability, $user_id = null) {
        if ($user_id) {
            return user_can($user_id, $capability);
        }
        return current_user_can($capability);
    }
}
