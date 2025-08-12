<?php
/**
 * Gerenciador de Cache Inteligente
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
     * Tamanhos de cache para estratégias
     */
    const SIZE_MEMORY = 1024;        // 1KB
    const SIZE_TRANSIENT = 102400;   // 100KB
    const SIZE_FILE = 1048576;       // 1MB
    
    /**
     * Obtém item do cache com estratégia inteligente
     * 
     * @param string $key Chave do cache
     * @param mixed $default Valor padrão
     * @return mixed
     */
    public static function get($key, $default = false) {
        $cache_key = self::CACHE_PREFIX . $key;
        
        // Tenta cache de memória primeiro (mais rápido)
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            self::log_cache_hit('memory', $key);
            return $cached;
        }
        
        // Tenta transient (persistente)
        $transient = get_transient($cache_key);
        if ($transient !== false) {
            // Adiciona ao cache de memória para próximas requisições
            wp_cache_set($cache_key, $transient, self::CACHE_GROUP, self::DEFAULT_EXPIRATION);
            self::log_cache_hit('transient', $key);
            return $transient;
        }
        
        // Tenta cache de arquivo para dados grandes
        $file_cache = self::get_file_cache($key);
        if ($file_cache !== false) {
            // Adiciona ao cache de memória
            wp_cache_set($cache_key, $file_cache, self::CACHE_GROUP, 300); // 5 min no memory
            self::log_cache_hit('file', $key);
            return $file_cache;
        }
        
        self::log_cache_miss($key);
        return $default;
    }
    
    /**
     * Define item no cache com estratégia baseada em tamanho
     * 
     * @param string $key Chave do cache
     * @param mixed $value Valor a ser cacheado
     * @param int $expiration Tempo de expiração
     * @return bool
     */
    public static function set($key, $value, $expiration = null) {
        $cache_key = self::CACHE_PREFIX . $key;
        $expiration = $expiration ?: self::DEFAULT_EXPIRATION;
        
        // Determina estratégia baseada no tamanho
        $size = strlen(serialize($value));
        $strategy = self::get_cache_strategy($size);
        
        // Sempre salva no cache de memória para acesso rápido
        wp_cache_set($cache_key, $value, self::CACHE_GROUP, $expiration);
        
        switch ($strategy) {
            case 'memory':
                // Apenas memória, já feito acima
                break;
                
            case 'transient':
                // Salva como transient para persistência
                set_transient($cache_key, $value, $expiration);
                break;
                
            case 'file':
                // Salva em arquivo para dados grandes
                self::set_file_cache($key, $value, $expiration);
                // Também salva referência em transient
                set_transient($cache_key . '_ref', 'file', $expiration);
                break;
        }
        
        // Log de cache para debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::log_cache_operation('set', $key, [
                'size' => $size,
                'strategy' => $strategy,
                'expiration' => $expiration
            ]);
        }
        
        return true;
    }
    
    /**
     * Determina estratégia de cache baseada no tamanho
     * 
     * @param int $size Tamanho em bytes
     * @return string
     */
    private static function get_cache_strategy($size) {
        if ($size < self::SIZE_MEMORY) {
            return 'memory';
        } elseif ($size < self::SIZE_TRANSIENT) {
            return 'transient';
        } else {
            return 'file';
        }
    }
    
    /**
     * Obtém cache de arquivo
     * 
     * @param string $key Chave do cache
     * @return mixed
     */
    private static function get_file_cache($key) {
        $file_path = self::get_cache_file_path($key);
        
        if (!file_exists($file_path)) {
            return false;
        }
        
        $data = @file_get_contents($file_path);
        if ($data === false) {
            return false;
        }
        
        $cache_data = @unserialize($data);
        if ($cache_data === false) {
            return false;
        }
        
        // Verifica expiração
        if (isset($cache_data['expires']) && $cache_data['expires'] < time()) {
            @unlink($file_path);
            return false;
        }
        
        return $cache_data['value'] ?? false;
    }
    
    /**
     * Define cache de arquivo
     * 
     * @param string $key Chave do cache
     * @param mixed $value Valor
     * @param int $expiration Expiração
     * @return bool
     */
    private static function set_file_cache($key, $value, $expiration) {
        $file_path = self::get_cache_file_path($key);
        $cache_dir = dirname($file_path);
        
        // Cria diretório se não existir
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
            
            // Adiciona .htaccess para proteção
            $htaccess = $cache_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, 'Deny from all');
            }
        }
        
        $cache_data = [
            'value' => $value,
            'expires' => time() + $expiration,
            'created' => time()
        ];
        
        return @file_put_contents($file_path, serialize($cache_data)) !== false;
    }
    
    /**
     * Obtém caminho do arquivo de cache
     * 
     * @param string $key Chave do cache
     * @return string
     */
    private static function get_cache_file_path($key) {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/tainacan-explorer-cache';
        $hash = md5($key);
        $sub_dir = substr($hash, 0, 2);
        
        return $cache_dir . '/' . $sub_dir . '/' . $hash . '.cache';
    }
    
    /**
     * Remove item do cache
     * 
     * @param string $key Chave do cache
     * @return bool
     */
    public static function delete($key) {
        $cache_key = self::CACHE_PREFIX . $key;
        
        // Remove de todas as camadas
        wp_cache_delete($cache_key, self::CACHE_GROUP);
        delete_transient($cache_key);
        delete_transient($cache_key . '_ref');
        
        // Remove arquivo se existir
        $file_path = self::get_cache_file_path($key);
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
        
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
        $pattern2 = self::CACHE_PREFIX . '%_' . $collection_id . '_%';
        
        // Remove transients do banco
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
             WHERE (option_name LIKE %s OR option_name LIKE %s)
             AND (option_name LIKE %s OR option_name LIKE %s)",
            '_transient_' . $pattern,
            '_transient_timeout_' . $pattern,
            '_transient_' . $pattern2,
            '_transient_timeout_' . $pattern2
        ));
        
        // Limpa cache de memória do grupo
        wp_cache_flush_group(self::CACHE_GROUP);
        
        // Limpa arquivos de cache da coleção
        self::clear_collection_file_cache($collection_id);
        
        // Dispara hook
        do_action('tei_cache_cleared', 'collection', $collection_id);
        
        return true;
    }
    
    /**
     * Limpa cache de arquivos da coleção
     * 
     * @param int $collection_id ID da coleção
     */
    private static function clear_collection_file_cache($collection_id) {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/tainacan-explorer-cache';
        
        if (!file_exists($cache_dir)) {
            return;
        }
        
        // Varre diretórios de cache
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache_dir),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'cache') {
                $content = @file_get_contents($file->getPathname());
                if ($content !== false) {
                    // Verifica se pertence à coleção
                    if (strpos($content, 'collection_' . $collection_id) !== false ||
                        strpos($content, '_' . $collection_id . '_') !== false) {
                        @unlink($file->getPathname());
                    }
                }
            }
        }
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
     * Cache com lock para evitar stampede
     * 
     * @param string $key Chave do cache
     * @param callable $callback Função para gerar dados
     * @param int $expiration Tempo de expiração
     * @return mixed
     */
    public static function remember_with_lock($key, $callback, $expiration = null) {
        $cached = self::get($key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Tenta obter lock
        $lock_key = $key . '_lock';
        $lock_acquired = add_transient($lock_key, 1, 30); // Lock por 30 segundos
        
        if (!$lock_acquired) {
            // Outro processo está gerando o cache
            // Aguarda e tenta novamente
            $attempts = 0;
            while ($attempts < 10) {
                sleep(1);
                $cached = self::get($key);
                if ($cached !== false) {
                    return $cached;
                }
                $attempts++;
            }
            
            // Fallback: gera mesmo assim
        }
        
        try {
            $value = call_user_func($callback);
            
            if ($value !== false && $value !== null) {
                self::set($key, $value, $expiration);
            }
            
            return $value;
        } finally {
            // Remove lock
            delete_transient($lock_key);
        }
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
        
        // Conta arquivos de cache
        $file_stats = self::get_file_cache_stats();
        
        return [
            'items' => intval($count),
            'size' => intval($size),
            'size_formatted' => size_format($size),
            'file_items' => $file_stats['count'],
            'file_size' => $file_stats['size'],
            'file_size_formatted' => size_format($file_stats['size']),
            'total_size' => intval($size) + $file_stats['size'],
            'total_size_formatted' => size_format(intval($size) + $file_stats['size']),
            'group' => self::CACHE_GROUP,
            'prefix' => self::CACHE_PREFIX
        ];
    }
    
    /**
     * Obtém estatísticas do cache de arquivos
     * 
     * @return array
     */
    private static function get_file_cache_stats() {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/tainacan-explorer-cache';
        
        if (!file_exists($cache_dir)) {
            return ['count' => 0, 'size' => 0];
        }
        
        $count = 0;
        $size = 0;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache_dir)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'cache') {
                $count++;
                $size += $file->getSize();
            }
        }
        
        return ['count' => $count, 'size' => $size];
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
        
        // Limpa arquivos de cache expirados
        self::cleanup_expired_files();
        
        // Log de limpeza
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('TEI Cache Cleanup: Expired items removed');
        }
    }
    
    /**
     * Limpa arquivos de cache expirados
     */
    private static function cleanup_expired_files() {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/tainacan-explorer-cache';
        
        if (!file_exists($cache_dir)) {
            return;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache_dir)
        );
        
        $deleted = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'cache') {
                $content = @file_get_contents($file->getPathname());
                if ($content !== false) {
                    $data = @unserialize($content);
                    if ($data !== false && isset($data['expires']) && $data['expires'] < time()) {
                        @unlink($file->getPathname());
                        $deleted++;
                    }
                }
            }
        }
        
        if ($deleted > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('TEI Cache Cleanup: ' . $deleted . ' expired files removed');
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
     * Log de cache hit
     */
    private static function log_cache_hit($type, $key) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf('[TEI Cache] HIT (%s): %s', $type, $key));
        }
    }
    
    /**
     * Log de cache miss
     */
    private static function log_cache_miss($key) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf('[TEI Cache] MISS: %s', $key));
        }
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
        $metadata_key = 'metadata_' . $collection_id;
        self::remember_with_lock($metadata_key, function() use ($api_handler, $collection_id) {
            return $api_handler->get_collection_metadata($collection_id);
        }, DAY_IN_SECONDS);
        
        // Cache de primeiros itens
        $items_key = 'items_preview_' . $collection_id;
        self::remember_with_lock($items_key, function() use ($api_handler, $collection_id) {
            return $api_handler->get_collection_items($collection_id, ['perpage' => 20]);
        }, HOUR_IN_SECONDS);
        
        // Cache de mapeamentos
        $mappings_key = 'mappings_' . $collection_id;
        self::remember($mappings_key, function() use ($collection_id) {
            $mappings = [];
            foreach (['map', 'timeline', 'story'] as $type) {
                $mapping = TEI_Metadata_Mapper::get_mapping($collection_id, $type);
                if ($mapping) {
                    $mappings[$type] = $mapping;
                }
            }
            return $mappings;
        }, DAY_IN_SECONDS);
        
        return true;
    }
}
