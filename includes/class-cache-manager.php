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
    const CACHE_PREFIX = 'tei_';
    
    /**
     * Grupo do cache
     */
    const CACHE_GROUP = 'tainacan_explorer';
    
    /**
     * Tempo padrão de expiração (1 hora)
     */
    const DEFAULT_EXPIRATION = 3600;
    
    /**
     * Tamanhos limites para estratégias
     */
    const SIZE_MEMORY = 10240;      // 10KB - usa memória
    const SIZE_TRANSIENT = 1048576;  // 1MB - usa transient
    // Acima de 1MB usa arquivo
    
    /**
     * Obtém item do cache
     * 
     * @param string $key Chave do cache
     * @return mixed Valor ou false
     */
    public static function get($key) {
        $cache_key = self::CACHE_PREFIX . $key;
        
        // Tenta memória primeiro
        $value = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($value !== false) {
            self::log_cache_hit('memory', $key);
            return $value;
        }
        
        // Tenta transient
        $value = get_transient($cache_key);
        if ($value !== false) {
            // Salva na memória para próximas requisições
            wp_cache_set($cache_key, $value, self::CACHE_GROUP);
            self::log_cache_hit('transient', $key);
            return $value;
        }
        
        // Verifica se existe referência para arquivo
        $file_ref = get_transient($cache_key . '_ref');
        if ($file_ref === 'file') {
            $value = self::get_file_cache($key);
            if ($value !== false) {
                // Salva na memória para próximas requisições
                wp_cache_set($cache_key, $value, self::CACHE_GROUP);
                self::log_cache_hit('file', $key);
                return $value;
            }
        }
        
        self::log_cache_miss($key);
        return false;
    }
    
    /**
     * Define item no cache
     * 
     * @param string $key Chave do cache
     * @param mixed $value Valor
     * @param int $expiration Tempo de expiração em segundos
     * @return bool
     */
    public static function set($key, $value, $expiration = null) {
        $cache_key = self::CACHE_PREFIX . $key;
        $expiration = $expiration ?: self::DEFAULT_EXPIRATION;
        
        // Determina estratégia baseada no tamanho
        $size = strlen(serialize($value));
        $strategy = self::get_cache_strategy($size);
        
        // Sempre salva na memória para acesso rápido
        wp_cache_set($cache_key, $value, self::CACHE_GROUP, $expiration);
        
        switch ($strategy) {
            case 'memory':
                // Apenas memória
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
        
        // Log para debug
        self::log_cache_operation('set', $key, [
            'size' => $size,
            'strategy' => $strategy,
            'expiration' => $expiration
        ]);
        
        return true;
    }
    
    /**
     * Cache com callback (memoization)
     * 
     * @param string $key Chave do cache
     * @param callable $callback Função para gerar o valor
     * @param int $expiration Tempo de expiração
     * @return mixed
     */
    public static function remember($key, $callback, $expiration = null) {
        $value = self::get($key);
        
        if ($value === false) {
            $value = call_user_func($callback);
            
            if ($value !== false && $value !== null) {
                self::set($key, $value, $expiration);
            }
        }
        
        return $value;
    }
    
    /**
     * Cache com lock para evitar race conditions
     * 
     * @param string $key Chave do cache
     * @param callable $callback Função para gerar o valor
     * @param int $expiration Tempo de expiração
     * @return mixed
     */
    public static function remember_with_lock($key, $callback, $expiration = null) {
        $value = self::get($key);
        
        if ($value !== false) {
            return $value;
        }
        
        $lock_key = self::CACHE_PREFIX . $key . '_lock';
        
        // Tenta adquirir lock usando wp_cache_add (atômico)
        $lock_acquired = wp_cache_add($lock_key, 1, self::CACHE_GROUP, 30);
        
        if (!$lock_acquired) {
            // Outro processo está gerando o cache
            // Aguarda e tenta novamente
            $attempts = 0;
            while ($attempts < 10) {
                usleep(100000); // 100ms
                $cached = self::get($key);
                if ($cached !== false) {
                    return $cached;
                }
                $attempts++;
            }
            
            // Fallback: gera mesmo assim se não conseguiu após esperar
            $value = call_user_func($callback);
        } else {
            try {
                $value = call_user_func($callback);
                
                if ($value !== false && $value !== null) {
                    self::set($key, $value, $expiration);
                }
            } finally {
                // Remove lock
                wp_cache_delete($lock_key, self::CACHE_GROUP);
            }
        }
        
        return $value;
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
        
        return @file_put_contents($file_path, serialize($cache_data), LOCK_EX) !== false;
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
        
        // Patterns para transients da coleção
        $patterns = [
            self::CACHE_PREFIX . '%_' . $collection_id . '_%',
            self::CACHE_PREFIX . 'map_data_' . $collection_id . '_%',
            self::CACHE_PREFIX . 'timeline_data_' . $collection_id . '_%',
            self::CACHE_PREFIX . 'story_data_' . $collection_id . '_%',
            self::CACHE_PREFIX . 'metadata_' . $collection_id,
            self::CACHE_PREFIX . 'items_' . $collection_id . '_%',
            self::CACHE_PREFIX . 'mappings_' . $collection_id
        ];
        
        foreach ($patterns as $pattern) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                 WHERE option_name LIKE %s 
                 OR option_name LIKE %s",
                '_transient_' . $pattern,
                '_transient_timeout_' . $pattern
            ));
        }
        
        // Limpa cache de memória
        wp_cache_flush_group(self::CACHE_GROUP);
        
        // Limpa arquivos de cache da coleção
        self::clear_file_cache_by_pattern('*_' . $collection_id . '_*');
        
        return true;
    }
    
    /**
     * Limpa todo o cache
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
        
        // Limpa todos os arquivos de cache
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/tainacan-explorer-cache';
        
        if (file_exists($cache_dir)) {
            self::delete_directory($cache_dir);
            wp_mkdir_p($cache_dir);
        }
        
        return true;
    }
    
    /**
     * Limpa arquivos de cache por padrão
     * 
     * @param string $pattern Padrão glob
     * @return int Número de arquivos deletados
     */
    private static function clear_file_cache_by_pattern($pattern) {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/tainacan-explorer-cache';
        
        $deleted = 0;
        $dirs = glob($cache_dir . '/*', GLOB_ONLYDIR);
        
        foreach ($dirs as $dir) {
            $files = glob($dir . '/' . $pattern . '.cache');
            foreach ($files as $file) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        return $deleted;
    }
    
    /**
     * Deleta diretório recursivamente
     * 
     * @param string $dir Caminho do diretório
     * @return bool
     */
    public static function delete_directory($dir) {
        if (!file_exists($dir)) {
            return true;
        }
        
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            if (!self::delete_directory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        
        return rmdir($dir);
    }
    
    /**
     * Limpa cache expirado
     * 
     * @return void
     */
    public static function cleanup_expired() {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/tainacan-explorer-cache';
        
        if (!file_exists($cache_dir)) {
            return;
        }
        
        $deleted = 0;
        $dirs = glob($cache_dir . '/*', GLOB_ONLYDIR);
        
        foreach ($dirs as $dir) {
            $files = glob($dir . '/*.cache');
            foreach ($files as $file) {
                $data = @file_get_contents($file);
                if ($data === false) {
                    continue;
                }
                
                $cache_data = @unserialize($data);
                if ($cache_data === false) {
                    @unlink($file);
                    $deleted++;
                    continue;
                }
                
                if (isset($cache_data['expires']) && $cache_data['expires'] < time()) {
                    @unlink($file);
                    $deleted++;
                }
            }
        }
        
        if ($deleted > 0) {
            error_log('[TEI Cache] Cleanup: ' . $deleted . ' expired files removed');
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
        
        $count = 0;
        $size = 0;
        
        if (file_exists($cache_dir)) {
            $dirs = glob($cache_dir . '/*', GLOB_ONLYDIR);
            
            foreach ($dirs as $dir) {
                $files = glob($dir . '/*.cache');
                $count += count($files);
                
                foreach ($files as $file) {
                    $size += filesize($file);
                }
            }
        }
        
        return [
            'count' => $count,
            'size' => $size
        ];
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
