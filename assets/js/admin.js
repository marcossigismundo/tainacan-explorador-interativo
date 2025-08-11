/**
 * Explorador Interativo - Admin Interface WordPress Native
 * 
 * @package TainacanExplorador
 * @version 1.0.0
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
                showNotification(__('Erro ao carregar coleções', 'tainacan-explorador'), 'error');
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
            
            // Limpa cache primeiro
            await jQuery.ajax({
                url: teiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'tei_clear_collection_cache',
                    collection_id: collectionId,
                    nonce: teiAdmin.ajaxNonce
                }
            }).catch(() => {});
            
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
                    // Remove duplicatas por ID
                    const uniqueMetadata = [];
                    const seen = new Set();
                    
                    response.data.metadata.forEach(meta => {
                        const key = meta.id + '_' + meta.slug;
                        if (!seen.has(key)) {
                            seen.add(key);
                            uniqueMetadata.push(meta);
                        }
                    });
                    
                    setMetadata(uniqueMetadata);
                    
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
        
        const handleFilterRuleChange = (visualizationType, index, field, value) => {
            setFilterRules(prev => {
                const newRules = [...(prev[visualizationType] || [])];
                newRules[index] = {
                    ...newRules[index],
                    [field]: value
                };
                return {
                    ...prev,
                    [visualizationType]: newRules
                };
            });
        };
        
        const addFilterRule = (visualizationType) => {
            setFilterRules(prev => ({
                ...prev,
                [visualizationType]: [
                    ...(prev[visualizationType] || []),
                    { metadatum: '', operator: '=', value: '' }
                ]
            }));
        };
        
        const removeFilterRule = (visualizationType, index) => {
            setFilterRules(prev => ({
                ...prev,
                [visualizationType]: prev[visualizationType].filter((_, i) => i !== index)
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
                        filter_rules: filterRules[mappingType] || [],
                        nonce: teiAdmin.ajaxNonce
                    }
                });
                
                if (response.success) {
                    showNotification(__('Mapeamento salvo com sucesso!', 'tainacan-explorador'), 'success');
                    loadAllMappings();
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
        
        const deleteMappingHandler = async (mappingId) => {
            if (!confirm(__('Tem certeza que deseja excluir este mapeamento?', 'tainacan-explorador'))) {
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
                    showNotification(__('Mapeamento excluído!', 'tainacan-explorador'), 'success');
                    loadAllMappings();
                }
            } catch (error) {
                showNotification(__('Erro ao excluir', 'tainacan-explorador'), 'error');
            }
        };
        
        const editMapping = (mapping) => {
            const collection = collections.find(c => c.id == mapping.collection_id);
            if (collection) {
                setSelectedCollection(collection);
                loadMetadata(collection.id);
                setActiveTab(mapping.mapping_type);
            }
        };
        
        const testVisualization = (type) => {
            if (!selectedCollection) {
                showNotification(__('Selecione uma coleção primeiro', 'tainacan-explorador'), 'warning');
                return;
            }
            
            saveMappings(type).then(() => {
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
        
        const renderMappingsList = () => {
            if (savedMappings.length === 0) {
                return el(Card, null,
                    el(CardBody, null,
                        el(Notice, { status: 'info', isDismissible: false },
                            __('Nenhum mapeamento configurado ainda. Clique em "Novo Mapeamento" para começar.', 'tainacan-explorador')
                        )
                    )
                );
            }
            
            return el(Card, null,
                el(CardHeader, null,
                    el('h2', null, __('Mapeamentos Salvos', 'tainacan-explorador'))
                ),
                el(CardBody, null,
                    el('table', { className: 'wp-list-table widefat fixed striped' },
                        el('thead', null,
                            el('tr', null,
                                el('th', null, __('Coleção', 'tainacan-explorador')),
                                el('th', null, __('Tipo', 'tainacan-explorador')),
                                el('th', null, __('Atualizado', 'tainacan-explorador')),
                                el('th', null, __('Ações', 'tainacan-explorador'))
                            )
                        ),
                        el('tbody', null,
                            savedMappings.map(mapping =>
                                el('tr', { key: mapping.id },
                                    el('td', null, mapping.collection_name),
                                    el('td', null,
                                        el('span', { 
                                            className: `dashicons dashicons-${
                                                mapping.mapping_type === 'map' ? 'location-alt' : 
                                                mapping.mapping_type === 'timeline' ? 'clock' : 'book'
                                            }` 
                                        }),
                                        ' ' + mapping.mapping_type.charAt(0).toUpperCase() + mapping.mapping_type.slice(1)
                                    ),
                                    el('td', null, new Date(mapping.updated_at).toLocaleDateString('pt-BR')),
                                    el('td', null,
                                        el(Button, {
                                            isSecondary: true,
                                            isSmall: true,
                                            onClick: () => editMapping(mapping)
                                        }, __('Editar', 'tainacan-explorador')),
                                        ' ',
                                        el(Button, {
                                            isDestructive: true,
                                            isSmall: true,
                                            onClick: () => deleteMappingHandler(mapping.id)
                                        }, __('Excluir', 'tainacan-explorador'))
                                    )
                                )
                            )
                        )
                    )
                )
            );
        };
        
        const renderMappingForm = (type) => {
            const fields = getFieldsForType(type);
            const rules = filterRules[type] || [];
            
            return el(Card, null,
                el(CardHeader, null,
                    el('h2', null, 
                        type === 'map' ? __('Configuração de Mapa', 'tainacan-explorador') :
                        type === 'timeline' ? __('Configuração de Linha do Tempo', 'tainacan-explorador') :
                        __('Configuração de Storytelling', 'tainacan-explorador')
                    )
                ),
                el(CardBody, null,
                    el(PanelBody, { title: __('Mapeamento de Campos', 'tainacan-explorador'), initialOpen: true },
                        fields.map(field =>
                            el(SelectControl, {
                                key: field.key,
                                label: field.label + (field.required ? ' *' : ''),
                                value: mappings[type]?.[field.key] || '',
                                options: [
                                    { label: __('Selecione...', 'tainacan-explorador'), value: '' },
                                    ...metadata.map(m => ({
                                        label: `${m.name} (${m.type})`,
                                        value: m.id.toString()
                                    }))
                                ],
                                onChange: (value) => handleMappingChange(type, field.key, value)
                            })
                        )
                    ),
                    
                    el(PanelBody, { title: __('Filtros de Exibição', 'tainacan-explorador'), initialOpen: false },
                        el('p', { style: { marginBottom: '15px' } },
                            __('Configure filtros para limitar quais itens serão exibidos', 'tainacan-explorador')
                        ),
                        rules.map((rule, index) =>
                            el('div', {
                                key: index,
                                style: {
                                    display: 'flex',
                                    gap: '10px',
                                    marginBottom: '10px',
                                    padding: '10px',
                                    background: '#f0f0f1',
                                    borderRadius: '4px'
                                }
                            },
                                el(SelectControl, {
                                    value: rule.metadatum || '',
                                    options: [
                                        { label: __('Selecione metadado...', 'tainacan-explorador'), value: '' },
                                        ...metadata.map(m => ({
                                            label: `${m.name} (${m.type})`,
                                            value: m.id.toString()
                                        }))
                                    ],
                                    onChange: (value) => handleFilterRuleChange(type, index, 'metadatum', value),
                                    style: { flex: 2 }
                                }),
                                el(SelectControl, {
                                    value: rule.operator || '=',
                                    options: [
                                        { value: '=', label: 'Igual a' },
                                        { value: '!=', label: 'Diferente de' },
                                        { value: 'IN', label: 'Contém' },
                                        { value: 'NOT IN', label: 'Não contém' },
                                        { value: 'LIKE', label: 'Contém texto' }
                                    ],
                                    onChange: (value) => handleFilterRuleChange(type, index, 'operator', value),
                                    style: { flex: 1 }
                                }),
                                el(TextControl, {
                                    value: rule.value || '',
                                    placeholder: __('Valor', 'tainacan-explorador'),
                                    onChange: (value) => handleFilterRuleChange(type, index, 'value', value),
                                    style: { flex: 2 }
                                }),
                                el(Button, {
                                    isDestructive: true,
                                    isSmall: true,
                                    onClick: () => removeFilterRule(type, index)
                                }, __('Remover', 'tainacan-explorador'))
                            )
                        ),
                        el(Button, {
                            isSecondary: true,
                            onClick: () => addFilterRule(type)
                        }, __('Adicionar Filtro', 'tainacan-explorador'))
                    ),
                    
                    el('div', { style: { marginTop: '20px' } },
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
                )
            );
        };
        
        const tabs = [
            {
                name: 'list',
                title: __('Mapeamentos Salvos', 'tainacan-explorador'),
                className: 'tab-list',
            },
            {
                name: 'map',
                title: __('Novo Mapa', 'tainacan-explorador'),
                className: 'tab-map',
            },
            {
                name: 'timeline',
                title: __('Nova Timeline', 'tainacan-explorador'),
                className: 'tab-timeline',
            },
            {
                name: 'story',
                title: __('Novo Story', 'tainacan-explorador'),
                className: 'tab-story',
            }
        ];
        
        return el('div', { className: 'tei-admin-container' },
            notification && el(Notice, {
                status: notification.type,
                isDismissible: true,
                onRemove: () => setNotification(null)
            }, notification.message),
            
            el('div', { className: 'tei-admin-header', style: { marginBottom: '20px' } },
                el('h1', null, __('Explorador Interativo', 'tainacan-explorador')),
                el('p', null, __('Configure visualizações interativas para suas coleções do Tainacan', 'tainacan-explorador'))
            ),
            
            el(TabPanel, {
                className: 'tei-tabs',
                activeClass: 'is-active',
                tabs: tabs,
                onSelect: (tab) => setActiveTab(tab.name),
                children: (tab) => {
                    if (tab.name === 'list') {
                        return renderMappingsList();
                    }
                    
                    return el('div', null,
                        el(Card, { style: { marginBottom: '20px' } },
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
                        
                        selectedCollection && (loading ? el(Spinner) : renderMappingForm(tab.name))
                    );
                }
            })
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
