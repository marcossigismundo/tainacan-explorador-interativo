/**
 * Explorador Interativo - Admin Interface
 * Correção para contagem de itens e botões
 */

(function(wp, window, jQuery) {
    'use strict';
    
    const { createElement: el, Fragment, useState, useEffect, useCallback } = wp.element;
    const { Button, Card, CardBody, CardHeader, SelectControl, TextControl, CheckboxControl, Spinner, Notice, TabPanel, Modal, Panel, PanelBody, PanelRow } = wp.components;
    const { __ } = wp.i18n;
    
    const AdminPanel = () => {
        const [collections, setCollections] = useState([]);
        const [selectedCollection, setSelectedCollection] = useState(null);
        const [metadata, setMetadata] = useState([]);
        const [savedMappings, setSavedMappings] = useState([]);
        const [mappings, setMappings] = useState({
            map: {},
            timeline: {},
            story: {}
        });
        const [visualizationSettings, setVisualizationSettings] = useState({
            map: {},
            timeline: {},
            story: {}
        });
        const [filterRules, setFilterRules] = useState({
            map: [],
            timeline: [],
            story: []
        });
        const [loading, setLoading] = useState(false);
        const [saving, setSaving] = useState(false);
        const [notification, setNotification] = useState(null);
        const [activeTab, setActiveTab] = useState('list');
        const [editingMapping, setEditingMapping] = useState(null);
        
        useEffect(() => {
            loadCollections();
            loadAllMappings();
        }, []);
        
        const loadCollections = async () => {
            setLoading(true);
            try {
                const response = await jQuery.ajax({
                    url: teiAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'tei_get_collections',
                        nonce: teiAdmin.ajaxNonce
                    }
                });
                
                if (response.success && response.data) {
                    setCollections(response.data);
                }
            } catch (error) {
                console.error('Erro:', error);
                setNotification({ type: 'error', message: 'Erro ao carregar coleções' });
            } finally {
                setLoading(false);
            }
        };
        
        const loadAllMappings = async () => {
            try {
                const response = await jQuery.ajax({
                    url: teiAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'tei_get_all_mappings',
                        nonce: teiAdmin.ajaxNonce
                    }
                });
                
                if (response.success && response.data) {
                    setSavedMappings(response.data.mappings || []);
                }
            } catch (error) {
                console.error('Erro ao carregar mapeamentos:', error);
            }
        };
        
        const loadMetadata = async (collectionId) => {
            setLoading(true);
            try {
                const response = await jQuery.ajax({
                    url: teiAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'tei_get_metadata',
                        collection_id: collectionId,
                        nonce: teiAdmin.ajaxNonce
                    }
                });
                
                if (response.success && response.data) {
                    setMetadata(response.data.metadata || []);
                    
                    // Carrega mapeamentos existentes
                    if (response.data.mappings) {
                        const existingMappings = {};
                        const existingSettings = {};
                        const existingFilters = {};
                        
                        ['map', 'timeline', 'story'].forEach(type => {
                            if (response.data.mappings[type]) {
                                existingMappings[type] = response.data.mappings[type].mapping_data || {};
                                existingSettings[type] = response.data.mappings[type].visualization_settings || {};
                                existingFilters[type] = response.data.mappings[type].filter_rules || [];
                            }
                        });
                        
                        setMappings(existingMappings);
                        setVisualizationSettings(existingSettings);
                        setFilterRules(existingFilters);
                    }
                }
            } catch (error) {
                console.error('Erro ao carregar metadados:', error);
                setNotification({ type: 'error', message: 'Erro ao carregar metadados' });
            } finally {
                setLoading(false);
            }
        };
        
        const handleEditMapping = (mapping) => {
            setEditingMapping(mapping);
            setSelectedCollection(mapping.collection_id);
            setActiveTab('config');
            loadMetadata(mapping.collection_id);
        };
        
        const handleDeleteMapping = async (mappingId) => {
            if (!confirm('Tem certeza que deseja excluir este mapeamento?')) {
                return;
            }
            
            try {
                const response = await jQuery.ajax({
                    url: teiAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'tei_delete_mapping',
                        mapping_id: mappingId,
                        nonce: teiAdmin.ajaxNonce
                    }
                });
                
                if (response.success) {
                    setNotification({ type: 'success', message: 'Mapeamento excluído com sucesso!' });
                    loadAllMappings();
                }
            } catch (error) {
                console.error('Erro ao deletar:', error);
                setNotification({ type: 'error', message: 'Erro ao excluir mapeamento' });
            }
        };
        
        const handleSaveMapping = async (type) => {
            if (!selectedCollection) {
                setNotification({ type: 'error', message: 'Selecione uma coleção' });
                return;
            }
            
            setSaving(true);
            
            const collectionName = collections.find(c => c.id == selectedCollection)?.name || '';
            
            try {
                const response = await jQuery.ajax({
                    url: teiAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'tei_save_mapping',
                        collection_id: selectedCollection,
                        collection_name: collectionName,
                        mapping_type: type,
                        mapping_data: mappings[type] || {},
                        visualization_settings: visualizationSettings[type] || {},
                        filter_rules: filterRules[type] || [],
                        nonce: teiAdmin.ajaxNonce
                    }
                });
                
                if (response.success) {
                    setNotification({ type: 'success', message: 'Mapeamento salvo com sucesso!' });
                    loadAllMappings();
                }
            } catch (error) {
                console.error('Erro ao salvar:', error);
                setNotification({ type: 'error', message: 'Erro ao salvar mapeamento' });
            } finally {
                setSaving(false);
            }
        };
        
        const handlePreview = (type) => {
            if (!selectedCollection) {
                alert('Selecione uma coleção primeiro');
                return;
            }
            
            const previewUrl = `${window.location.origin}/?tei-preview=1&type=${type}&collection=${selectedCollection}`;
            window.open(previewUrl, '_blank', 'width=1200,height=800');
        };
        
        const renderMappingsList = () => {
            return el(Fragment, null,
                el('h2', null, 'Mapeamentos Configurados'),
                loading ? el(Spinner) : 
                el('div', { className: 'mappings-grid' },
                    savedMappings.length === 0 ? 
                    el(Notice, { status: 'info', isDismissible: false }, 
                        'Nenhum mapeamento configurado ainda.'
                    ) :
                    savedMappings.map(mapping => 
                        el(Card, { key: mapping.id, className: 'mapping-card' },
                            el(CardHeader, null,
                                el('strong', null, mapping.collection_name),
                                el('span', { className: 'mapping-type' }, 
                                    mapping.mapping_type.toUpperCase()
                                )
                            ),
                            el(CardBody, null,
                                el('p', null, `ID da Coleção: ${mapping.collection_id}`),
                                el('p', null, `Atualizado: ${new Date(mapping.updated_at).toLocaleDateString('pt-BR')}`),
                                el('div', { className: 'mapping-actions' },
                                    el(Button, {
                                        isSecondary: true,
                                        isSmall: true,
                                        onClick: () => handleEditMapping(mapping)
                                    }, 'Editar'),
                                    el(Button, {
                                        isSecondary: true,
                                        isSmall: true,
                                        onClick: () => handlePreview(mapping.mapping_type)
                                    }, 'Visualizar'),
                                    el(Button, {
                                        isDestructive: true,
                                        isSmall: true,
                                        onClick: () => handleDeleteMapping(mapping.id)
                                    }, 'Excluir')
                                )
                            )
                        )
                    )
                )
            );
        };
        
        const renderConfiguration = () => {
            const collection = collections.find(c => c.id == selectedCollection);
            
            return el(Fragment, null,
                el('h2', null, 'Configurar Visualizações'),
                
                el(Card, null,
                    el(CardBody, null,
                        el(SelectControl, {
                            label: 'Selecione uma Coleção',
                            value: selectedCollection || '',
                            options: [
                                { label: 'Selecione...', value: '' },
                                ...collections.map(c => ({
                                    label: `${c.name} (${c.items_count || 0} itens)`,
                                    value: c.id
                                }))
                            ],
                            onChange: (value) => {
                                setSelectedCollection(value);
                                if (value) loadMetadata(value);
                            }
                        })
                    )
                ),
                
                selectedCollection && metadata.length > 0 && 
                el(TabPanel, {
                    className: 'tei-tabs',
                    activeClass: 'is-active',
                    tabs: [
                        { name: 'map', title: 'Mapa' },
                        { name: 'timeline', title: 'Timeline' },
                        { name: 'story', title: 'Storytelling' }
                    ],
                    children: (tab) => renderVisualizationConfig(tab.name)
                })
            );
        };
        
        const renderVisualizationConfig = (type) => {
            const fields = getFieldsForType(type);
            
            return el(Panel, null,
                el(PanelBody, { title: 'Mapeamento de Campos', initialOpen: true },
                    fields.map(field => 
                        el(SelectControl, {
                            key: field.key,
                            label: field.label,
                            value: mappings[type]?.[field.key] || '',
                            options: [
                                { label: 'Não mapeado', value: '' },
                                ...metadata.map(m => ({
                                    label: m.name,
                                    value: m.id
                                }))
                            ],
                            onChange: (value) => {
                                setMappings(prev => ({
                                    ...prev,
                                    [type]: {
                                        ...prev[type],
                                        [field.key]: value
                                    }
                                }));
                            }
                        })
                    )
                ),
                
                el(PanelBody, { title: 'Ações', initialOpen: false },
                    el('div', { className: 'button-group' },
                        el(Button, {
                            isPrimary: true,
                            onClick: () => handleSaveMapping(type),
                            disabled: saving
                        }, saving ? 'Salvando...' : 'Salvar Configuração'),
                        
                        el(Button, {
                            isSecondary: true,
                            onClick: () => handlePreview(type),
                            style: { marginLeft: '10px' }
                        }, 'Visualizar')
                    )
                )
            );
        };
        
        const getFieldsForType = (type) => {
            switch(type) {
                case 'map':
                    return [
                        { key: 'location', label: 'Campo de Localização' },
                        { key: 'title', label: 'Título' },
                        { key: 'description', label: 'Descrição' },
                        { key: 'image', label: 'Imagem' },
                        { key: 'link', label: 'Link' }
                    ];
                case 'timeline':
                    return [
                        { key: 'date', label: 'Campo de Data' },
                        { key: 'title', label: 'Título' },
                        { key: 'description', label: 'Descrição' },
                        { key: 'image', label: 'Imagem' },
                        { key: 'category', label: 'Categoria' }
                    ];
                case 'story':
                    return [
                        { key: 'title', label: 'Título' },
                        { key: 'description', label: 'Descrição' },
                        { key: 'image', label: 'Imagem Principal' },
                        { key: 'background', label: 'Imagem de Fundo' },
                        { key: 'order', label: 'Ordem' }
                    ];
                default:
                    return [];
            }
        };
        
        return el('div', { className: 'tei-admin-panel' },
            notification && el(Notice, {
                status: notification.type === 'error' ? 'error' : 'success',
                isDismissible: true,
                onRemove: () => setNotification(null)
            }, notification.message),
            
            el(TabPanel, {
                className: 'tei-main-tabs',
                activeClass: 'is-active',
                initialTabName: activeTab,
                tabs: [
                    { name: 'list', title: 'Mapeamentos' },
                    { name: 'config', title: 'Configurar' }
                ],
                children: (tab) => {
                    if (tab.name === 'list') {
                        return renderMappingsList();
                    } else {
                        return renderConfiguration();
                    }
                }
            })
        );
    };
    
    // Renderiza quando DOM estiver pronto
    document.addEventListener('DOMContentLoaded', () => {
        const container = document.getElementById('tei-admin-app');
        if (container) {
            wp.element.render(el(AdminPanel), container);
        }
    });
    
})(wp, window, jQuery);
