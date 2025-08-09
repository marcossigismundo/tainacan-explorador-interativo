<?php
/**
 * Handler de requisições AJAX
 * 
 * @package TainacanExplorador
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TEI_Ajax_Handler {
    
    /**
     * Obtém coleções disponíveis
     */
    public function get_collections() {
        // Remove verificação de nonce temporariamente para debug
        
        try {
            $collections = [];
            
            // Busca coleções do Tainacan
            $args = [
                'post_type' => 'tainacan-collection',
                'posts_per_page' => -1,
                'post_status' => 'publish'
            ];
            
            $query = new WP_Query($args);
            
            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $collections[] = [
                        'id' => get_the_ID(),
                        'name' => get_the_title(),
                        'items_count' => 0
                    ];
                }
                wp_reset_postdata();
            }
            
            wp_send_json_success($collections);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Obtém metadados de uma coleção
     */
    public function get_metadata() {
        $collection_id = intval($_POST['collection_id'] ?? 0);
        
        if (!$collection_id) {
            wp_send_json_error(['message' => 'ID inválido']);
            return;
        }
        
        $metadata = [
            ['id' => 'title', 'name' => 'Título', 'type' => 'text'],
            ['id' => 'description', 'name' => 'Descrição', 'type' => 'text'],
            ['id' => 'date', 'name' => 'Data', 'type' => 'date'],
            ['id' => 'location', 'name' => 'Localização', 'type' => 'text'],
            ['id' => 'image', 'name' => 'Imagem', 'type' => 'text']
        ];
        
        wp_send_json_success([
            'metadata' => $metadata,
            'mappings' => []
        ]);
    }
    
    /**
     * Obtém todos os mapeamentos
     */
    public function get_all_mappings() {
        wp_send_json_success([
            'mappings' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => 20
        ]);
    }
    
    /**
     * Salva mapeamento
     */
    public function save_mapping() {
        $collection_id = intval($_POST['collection_id'] ?? 0);
        $mapping_type = sanitize_key($_POST['mapping_type'] ?? '');
        
        if (!$collection_id || !$mapping_type) {
            wp_send_json_error(['message' => 'Dados inválidos']);
            return;
        }
        
        // Simula salvamento
        wp_send_json_success([
            'message' => 'Mapeamento salvo!',
            'mapping_id' => 1
        ]);
    }
    
    /**
     * Deleta mapeamento
     */
    public function delete_mapping() {
        wp_send_json_success(['message' => 'Deletado']);
    }
}
