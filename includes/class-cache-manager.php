<?php
/**
 * Gerenciador de Cache
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_Cache_Manager {
    
    /**
     * Prefixo do cache
     */
    const CACHE_PREFIX = 'tei_cache_';
    
    /**
     * Grupo do cache
     */
    const CACHE_GROUP = 'tainacan_explorer';
    
    /**
     * Tempo padrão de cache (1 hora)
     */
    const DEFAULT_EXPIRATION = 3600;
    
    /**
     * Obtém item do cache
     * 
     * @param string $key Chave do cache
     * @param mixed $default Valor padrão
     * @return mixed
     */
    public static function get($key, $default = false) {
        $cache_key = self::CACHE_PREFIX . $key;
        
        // Tenta cache do WordPress primeiro
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }
        
        // Tenta transient como fallback
        $transient = get_transient($cache_key);
        if ($transient !== false) {
            // Adiciona ao cache de memória
            wp_cache_set($cache_key, $transient, self::CACHE_GROUP, self::DEFAULT_EXPIRATION);
            return $transient;
        }
        
        return $default;
    }
    
    /**
     * Define item no cache
     * 
     * @param string $key Chave do cache
     * @param mixed $value Valor a ser cacheado
     * @param int $expiration Tempo de expiração
     * @return bool
     */
    public static function set($key, $value, $expiration = null) {
        $cache_key = self::CACHE_PREFIX . $key;
        $expiration = $expiration ?: self::DEFAULT_EXPIRATION;
        
        // Salva no cache de memória
        wp_cache_set($cache_key, $value, self::CACHE_GROUP, $expiration);
        
        // Salva como transient para persistência
        set_transient($cache_key, $value, $expiration);
        
        // Log de cache para debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::log_cache_operation('set', $key, $expiration);
        }
        
        return true;
    }
    
    /**
     * Remove item do cache
     * 
     * @param string $key Chave do cache
     * @return bool
     */
    public static function delete($key) {
        $cache_key = self::CACHE_PREFIX . $key;
        
        wp_cache_delete($cache_key, self::CACHE_GROUP);
        delete_transient($cache_key);
        
        return true;
    }
    
    /**
     * Limpa cache de uma coleção específica
     * 
     * @param int $collection_id ID da coleção
     * @return bool
     */
    public static function clear_collection_cache($collection_id) {
        global $wpdb;
        
        // Pattern para transients da coleção
        $pattern = self::CACHE_PREFIX . '%collection_' . $collection_id . '%';
        
        // Remove transients do banco
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
             OR option_name LIKE %s",
            '_transient_' . $pattern,
            '_transient_timeout_' . $pattern
        ));
        
        // Limpa cache de memória do grupo
        wp_cache_flush_group(self::CACHE_GROUP);
        
        // Dispara hook
        do_action('tei_cache_cleared', 'collection', $collection_id);
        
        return true;
    }
    
    /**
     * Limpa todo o cache do plugin
     * 
     * @return bool
     */
    public static function clear_all() {
        global $wpdb;
        
        // Remove todos os transients do plugin
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
             OR option_name LIKE %s",
            '_transient_' . self::CACHE_PREFIX . '%',
            '_transient_timeout_' . self::CACHE_PREFIX . '%'
        ));
        
        // Limpa cache de memória
        wp_cache_flush_group(self::CACHE_GROUP);
        
        // Limpa cache de arquivos se existir
        self::clear_file_cache();
        
        // Dispara hook
        do_action('tei_cache_cleared', 'all', null);
        
        return true;
    }
    
    /**
     * Limpa cache de arquivos
     * 
     * @return bool
     */
    private static function clear_file_cache() {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/tainacan-explorer-cache';
        
        if (!file_exists($cache_dir)) {
            return true;
        }
        
        return self::delete_directory($cache_dir);
    }
    
    /**
     * Deleta diretório recursivamente
     * 
     * @param string $dir Caminho do diretório
     * @return bool
     */
    public static function delete_directory($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::delete_directory($path) : unlink($path);
        }
        
        return rmdir($dir);
    }
    
    /**
     * Cache com callback
     * 
     * @param string $key Chave do cache
     * @param callable $callback Função para gerar dados
     * @param int $expiration Tempo de expiração
     * @return mixed
     */
    public static function remember($key, $callback, $expiration = null) {
        $cached = self::get($key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $value = call_user_func($callback);
        
        if ($value !== false && $value !== null) {
            self::set($key, $value, $expiration);
        }
        
        return $value;
    }
    
    /**
     * Obtém estatísticas do cache
     * 
     * @return array
     */
    public static function get_stats() {
        global $wpdb;
        
        // Conta transients do plugin
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} 
             WHERE option_name LIKE %s",
            '_transient_' . self::CACHE_PREFIX . '%'
        ));
        
        // Calcula tamanho aproximado
        $size = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(LENGTH(option_value)) 
             FROM {$wpdb->options} 
             WHERE option_name LIKE %s",
            '_transient_' . self::CACHE_PREFIX . '%'
        ));
        
        return [
            'items' => intval($count),
            'size' => intval($size),
            'size_formatted' => size_format($size),
            'group' => self::CACHE_GROUP,
            'prefix' => self::CACHE_PREFIX
        ];
    }
    
    /**
     * Agenda limpeza automática de cache
     */
    public static function schedule_cleanup() {
        if (!wp_next_scheduled('tei_cache_cleanup')) {
            wp_schedule_event(time(), 'daily', 'tei_cache_cleanup');
        }
        
        add_action('tei_cache_cleanup', [__CLASS__, 'cleanup_expired']);
    }
    
    /**
     * Limpa cache expirado
     */
    public static function cleanup_expired() {
        global $wpdb;
        
        // Remove transients expirados
        $wpdb->query($wpdb->prepare(
            "DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b
             WHERE a.option_name LIKE %s
             AND a.option_name NOT LIKE %s
             AND b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12))
             AND b.option_value < %d",
            '_transient_' . self::CACHE_PREFIX . '%',
            '_transient_timeout_%',
            time()
        ));
        
        // Log de limpeza
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('TEI Cache Cleanup: Expired items removed');
        }
    }
    
    /**
     * Invalida cache por tag
     * 
     * @param string $tag Tag do cache
     * @return bool
     */
    public static function invalidate_by_tag($tag) {
        global $wpdb;
        
        $pattern = self::CACHE_PREFIX . '%_tag_' . $tag . '%';
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
             OR option_name LIKE %s",
            '_transient_' . $pattern,
            '_transient_timeout_' . $pattern
        ));
        
        return true;
    }
    
    /**
     * Log de operações de cache
     * 
     * @param string $operation Tipo de operação
     * @param string $key Chave do cache
     * @param mixed $extra Dados extras
     */
    private static function log_cache_operation($operation, $key, $extra = null) {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return;
        }
        
        $log_entry = sprintf(
            '[TEI Cache] %s: %s | Key: %s',
            current_time('mysql'),
            strtoupper($operation),
            $key
        );
        
        if ($extra !== null) {
            $log_entry .= ' | Extra: ' . print_r($extra, true);
        }
        
        error_log($log_entry);
    }
    
    /**
     * Aquece o cache
     * 
     * @param int $collection_id ID da coleção
     * @return bool
     */
    public static function warm_cache($collection_id) {
        // Pré-carrega dados frequentemente acessados
        $api_handler = new TEI_API_Handler();
        
        // Cache de metadados da coleção
        $metadata_key = 'collection_metadata_' . $collection_id;
        self::remember($metadata_key, function() use ($api_handler, $collection_id) {
            return $api_handler->get_collection_metadata($collection_id);
        }, DAY_IN_SECONDS);
        
        // Cache de primeiros itens
        $items_key = 'collection_items_preview_' . $collection_id;
        self::remember($items_key, function() use ($api_handler, $collection_id) {
            return $api_handler->get_collection_items($collection_id, ['perpage' => 20]);
        }, HOUR_IN_SECONDS);
        
        return true;
    }
}
