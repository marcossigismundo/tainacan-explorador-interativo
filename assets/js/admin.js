/**
 * Explorador Interativo - Admin Interface (CORRIGIDO)
 * 
 * @package TainacanExplorador
 * @version 1.0.0
 */

(function(wp, window, jQuery) {
    'use strict';
    
    const { createElement: el, Fragment, useState, useEffect, useCallback } = wp.element;
    const { Button, Card, CardBody, CardHeader, SelectControl, TextControl, CheckboxControl, Spinner, Notice, TabPanel, Modal, SearchControl } = wp.components;
    const { __ } = wp.i18n;
    
    const AdminPanel = () => {
        const [collections, setCollections] = useState([]);
        const [selectedCollection, setSelectedCollection] = useState(null);
        const [metadata, setMetadata] = useState([]);
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
        const [loading, setLoading] = useState(false);
        const [saving, setSaving] = useState(false);
        const [notification, setNotification] = useState(null);
        
        useEffect(() => {
            loadCollections();
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
                showNotification(__('Erro ao carregar coleções', 'tainacan-explorador'), 'error');
            } finally {
                setLoading(false);
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
                        
                        ['map', 'timeline', 'story'].forEach(type => {
                            if (response.data.mappings[type]) {
                                existingMappings[type] = response.data.mappings[type].mapping_data || {};
                                existingSettings[type] = response.data.mappings[type].visualization_settings || {};
                            }
                        });
                        
                        setMappings(existingMappings);
                        setVisualizationSettings(existingSettings);
                    }
                }
            } catch (error) {
                console.error('Erro:', error);
                showNotification(__('Erro ao carregar metadados', 'tainacan-explorador'), 'error');
            } finally {
                setLoading(false);
            }
        };
        
        const handleCollectionSelect = (collectionId) => {
            const collection = collections.find(c => c.id == collectionId);
            setSelectedCollection(collection);
            if (collection) {
                loadMetadata(collection.id);
            }
        };
        
        const handleMappingChange = (visualizationType, field, value) => {
            setMappings(prev => ({
                ...prev,
                [visualizationType]: {
                    ...prev[visualizationType],
                    [field]: value
                }
            }));
        };
        
        const handleSettingChange = (visualizationType, setting, value) => {
            setVisualizationSettings(prev => ({
                ...prev,
                [visualizationType]: {
                    ...prev[visualizationType],
                    [setting]: value
                }
            }));
        };
        
        const saveMappings = async (mappingType) => {
            if (!selectedCollection) {
                showNotification(__('Selecione uma coleção primeiro', 'tainacan-explorador'), 'warning');
                return;
            }
            
            setSaving(true);
            try {
                const response = await jQuery.ajax({
                    url: teiAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'tei_save_mapping',
                        collection_id: selectedCollection.id,
                        collection_name: selectedCollection.name,
                        mapping_type: mappingType,
                        mapping_data: mappings[mappingType] || {},
                        visualization_settings: visualizationSettings[mappingType] || {},
                        nonce: teiAdmin.ajaxNonce
                    }
                });
                
                if (response.success) {
                    showNotification(__('Mapeamento salvo com sucesso!', 'tainacan-explorador'), 'success');
                } else {
                    showNotification(response.data?.message || __('Erro ao salvar', 'tainacan-explorador'), 'error');
                }
            } catch (error) {
                console.error('Erro ao salvar:', error);
                showNotification(__('Erro ao salvar mapeamento', 'tainacan-explorador'), 'error');
            } finally {
                setSaving(false);
            }
        };
        
        const testVisualization = (type) => {
            if (!selectedCollection) {
                showNotification(__('Selecione uma coleção primeiro', 'tainacan-explorador'), 'warning');
                return;
            }
            
            // Primeiro salva o mapeamento atual
            saveMappings(type).then(() => {
                // Abre preview em nova janela
                const url = `${window.location.origin}/preview?type=${type}&collection=${selectedCollection.id}`;
                window.open(url, '_blank', 'width=1200,height=800');
            });
        };
        
        const showNotification = (message, type = 'info') => {
            setNotification({ message, type });
            setTimeout(() => setNotification(null), 5000);
        };
        
        const getFieldsForType = (type) => {
            const fields = {
                map: [
                    { key: 'location', label: __('Campo de Localização', 'tainacan-explorador'), required: true },
                    { key: 'title', label: __('Campo de Título', 'tainacan-explorador'), required: true },
                    { key: 'description', label: __('Campo de Descrição', 'tainacan-explorador') },
                    { key: 'image', label: __('Campo de Imagem', 'tainacan-explorador') },
                    { key: 'link', label: __('Campo de Link', 'tainacan-explorador') },
                    { key: 'category', label: __('Campo de Categoria', 'tainacan-explorador') }
                ],
                timeline: [
                    { key: 'date', label: __('Campo de Data', 'tainacan-explorador'), required: true },
                    { key: 'title', label: __('Campo de Título', 'tainacan-explorador'), required: true },
                    { key: 'description', label: __('Campo de Descrição', 'tainacan-explorador'), required: true },
                    { key: 'image', label: __('Campo de Imagem', 'tainacan-explorador') },
                    { key: 'category', label: __('Campo de Categoria', 'tainacan-explorador') },
                    { key: 'link', label: __('Campo de Link', 'tainacan-explorador') }
                ],
                story: [
                    { key: 'title', label: __('Campo de Título', 'tainacan-explorador'), required: true },
                    { key: 'description', label: __('Campo de Descrição', 'tainacan-explorador'), required: true },
                    { key: 'image', label: __('Campo de Imagem', 'tainacan-explorador'), required: true },
                    { key: 'subtitle', label: __('Campo de Subtítulo', 'tainacan-explorador') },
                    { key: 'background', label: __('Campo de Background', 'tainacan-explorador') },
                    { key: 'order', label: __('Campo de Ordem', 'tainacan-explorador') }
                ]
            };
            
            return fields[type] || [];
        };
        
        const renderMappingForm = (type) => {
            const fields = getFieldsForType(type);
            
            return el('div', { className: 'tei-mapping-form' },
                el('div', { className: 'tei-form-grid' },
                    fields.map(field => 
                        el('div', { key: field.key, className: 'tei-form-group' },
                            el(SelectControl, {
                                label: field.label + (field.required ? ' *' : ''),
                                value: mappings[type]?.[field.key] || '',
                                options: [
                                    { label: __('Selecione...', 'tainacan-explorador'), value: '' },
                                    ...metadata.map(m => ({
                                        label: `${m.name} (${m.type})`,
                                        value: m.id
                                    }))
                                ],
                                onChange: (value) => handleMappingChange(type, field.key, value)
                            })
                        )
                    )
                ),
                
                el('div', { className: 'tei-form-actions', style: { marginTop: '20px' } },
                    el(Button, {
                        isPrimary: true,
                        isBusy: saving,
                        disabled: saving || !selectedCollection,
                        onClick: () => saveMappings(type),
                        style: { marginRight: '10px' }
                    }, saving ? __('Salvando...', 'tainacan-explorador') : __('Salvar Configurações', 'tainacan-explorador')),
                    
                    el(Button, {
                        isSecondary: true,
                        onClick: () => testVisualization(type),
                        disabled: !selectedCollection
                    }, __('Visualizar Prévia', 'tainacan-explorador'))
                )
            );
        };
        
        const tabs = [
            {
                name: 'map',
                title: __('Mapa', 'tainacan-explorador'),
                className: 'tab-map',
            },
            {
                name: 'timeline',
                title: __('Linha do Tempo', 'tainacan-explorador'),
                className: 'tab-timeline',
            },
            {
                name: 'story',
                title: __('Storytelling', 'tainacan-explorador'),
                className: 'tab-story',
            }
        ];
        
        return el('div', { className: 'tei-admin-container' },
            notification && el(Notice, {
                status: notification.type,
                isDismissible: true,
                onRemove: () => setNotification(null)
            }, notification.message),
            
            el('div', { className: 'tei-admin-header' },
                el('h1', null, __('Explorador Interativo', 'tainacan-explorador')),
                el('p', null, __('Configure visualizações interativas para suas coleções do Tainacan', 'tainacan-explorador'))
            ),
            
            el('div', { className: 'tei-admin-content' },
                el(Card, null,
                    el(CardHeader, null,
                        el('h2', null, __('Selecione uma Coleção', 'tainacan-explorador'))
                    ),
                    el(CardBody, null,
                        loading ? el(Spinner) :
                        el(SelectControl, {
                            value: selectedCollection?.id || '',
                            options: [
                                { label: __('Selecione...', 'tainacan-explorador'), value: '' },
                                ...collections.map(c => ({
                                    label: `${c.name} (${c.items_count || 0} itens)`,
                                    value: c.id
                                }))
                            ],
                            onChange: handleCollectionSelect
                        })
                    )
                ),
                
                selectedCollection && el(Card, { style: { marginTop: '20px' } },
                    el(CardHeader, null,
                        el('h2', null, __('Configurar Visualizações', 'tainacan-explorador'))
                    ),
                    el(CardBody, null,
                        loading ? el(Spinner) :
                        el(TabPanel, {
                            className: 'tei-tabs',
                            activeClass: 'is-active',
                            tabs: tabs,
                            children: (tab) => renderMappingForm(tab.name)
                        })
                    )
                )
            )
        );
    };
    
    // Inicialização
    window.TEI_Admin = {
        init: function(elementId) {
            const element = document.getElementById(elementId);
            if (element && wp.element.render) {
                wp.element.render(el(AdminPanel), element);
            }
        }
    };
    
    // Auto-inicialização
    document.addEventListener('DOMContentLoaded', function() {
        const root = document.getElementById('tei-admin-root');
        if (root) {
            window.TEI_Admin.init('tei-admin-root');
        }
    });

})(window.wp, window, window.jQuery);
