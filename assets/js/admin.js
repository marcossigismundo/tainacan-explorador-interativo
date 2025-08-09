/**
 * Explorador Interativo - Admin Interface
 * Interface administrativa usando WordPress React
 * 
 * @package TainacanExplorador
 * @version 1.0.0
 */

(function(wp, window) {
    'use strict';
    
    const { createElement: el, Fragment, useState, useEffect, useCallback } = wp.element;
    const { Button, Card, CardBody, CardHeader, SelectControl, TextControl, CheckboxControl, Spinner, Notice, TabPanel, Modal, SearchControl } = wp.components;
    const apiFetch = wp.apiFetch;
    const { __ } = wp.i18n;
    
    const AdminPanel = () => {
        const [collections, setCollections] = useState([]);
        const [selectedCollection, setSelectedCollection] = useState(null);
        const [metadata, setMetadata] = useState([]);
        const [mappings, setMappings] = useState({});
        const [visualizationSettings, setVisualizationSettings] = useState({});
        const [loading, setLoading] = useState(false);
        const [saving, setSaving] = useState(false);
        const [savedMappings, setSavedMappings] = useState([]);
        const [notification, setNotification] = useState(null);
        const [searchTerm, setSearchTerm] = useState('');
        const [filterType, setFilterType] = useState('all');
        const [showDeleteModal, setShowDeleteModal] = useState(false);
        const [mappingToDelete, setMappingToDelete] = useState(null);
        
        useEffect(() => {
            loadCollections();
            loadSavedMappings();
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
                
                if (response.success) {
                    setCollections(response.data);
                }
            } catch (error) {
                showNotification(__('Erro ao carregar coleções', 'tainacan-explorador'), 'error');
            } finally {
                setLoading(false);
            }
        };
        
        const loadSavedMappings = async () => {
            try {
                const response = await jQuery.ajax({
                    url: teiAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'tei_get_all_mappings',
                        nonce: teiAdmin.ajaxNonce
                    }
                });
                
                if (response.success) {
                    setSavedMappings(response.data.mappings);
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
                
                if (response.success) {
                    setMetadata(response.data.metadata);
                    if (response.data.mappings) {
                        setMappings(response.data.mappings);
                    }
                }
            } catch (error) {
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
            if (!selectedCollection) return;
            
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
                    loadSavedMappings();
                }
            } catch (error) {
                showNotification(__('Erro ao salvar mapeamento', 'tainacan-explorador'), 'error');
            } finally {
                setSaving(false);
            }
        };
        
        const deleteMapping = async () => {
            if (!mappingToDelete) return;
            
            try {
                const response = await jQuery.ajax({
                    url: teiAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'tei_delete_mapping',
                        mapping_id: mappingToDelete,
                        nonce: teiAdmin.ajaxNonce
                    }
                });
                
                if (response.success) {
                    showNotification(__('Mapeamento excluído', 'tainacan-explorador'), 'info');
                    setSavedMappings(prev => prev.filter(m => m.id !== mappingToDelete));
                }
            } catch (error) {
                showNotification(__('Erro ao excluir mapeamento', 'tainacan-explorador'), 'error');
            } finally {
                setShowDeleteModal(false);
                setMappingToDelete(null);
            }
        };
        
        const cloneMapping = async (mappingId) => {
            try {
                const response = await jQuery.ajax({
                    url: teiAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'tei_clone_mapping',
                        mapping_id: mappingId,
                        nonce: teiAdmin.ajaxNonce
                    }
                });
                
                if (response.success) {
                    showNotification(__('Mapeamento clonado com sucesso', 'tainacan-explorador'), 'success');
                    loadSavedMappings();
                }
            } catch (error) {
                showNotification(__('Erro ao clonar mapeamento', 'tainacan-explorador'), 'error');
            }
        };
        
        const showNotification = (message, type = 'info') => {
            setNotification({ message, type });
            setTimeout(() => setNotification(null), 5000);
        };
        
        const testVisualization = (type) => {
            if (!selectedCollection) return;
            const url = `${window.location.origin}/preview?type=${type}&collection=${selectedCollection.id}`;
            window.open(url, '_blank');
        };
        
        const filteredMappings = savedMappings.filter(mapping => {
            const matchesSearch = mapping.collection_name?.toLowerCase().includes(searchTerm.toLowerCase());
            const matchesFilter = filterType === 'all' || mapping.mapping_type === filterType;
            return matchesSearch && matchesFilter;
        });
        
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
                    ),
                    
                    el('div', { className: 'tei-form-group tei-form-settings' },
                        el('h4', null, __('Configurações de Visualização', 'tainacan-explorador')),
                        renderVisualizationSettings(type)
                    )
                ),
                
                el('div', { className: 'tei-form-actions' },
                    el(Button, {
                        isPrimary: true,
                        isBusy: saving,
                        disabled: saving || !selectedCollection,
                        onClick: () => saveMappings(type),
                        icon: 'saved'
                    }, saving ? __('Salvando...', 'tainacan-explorador') : __('Salvar Configurações', 'tainacan-explorador')),
                    
                    el(Button, {
                        isSecondary: true,
                        onClick: () => testVisualization(type),
                        icon: 'visibility',
                        style: { marginLeft: '10px' }
                    }, __('Visualizar Prévia', 'tainacan-explorador'))
                )
            );
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
        
        const renderVisualizationSettings = (type) => {
            if (type === 'map') {
                return el(Fragment, null,
                    el(SelectControl, {
                        label: __('Estilo do Mapa', 'tainacan-explorador'),
                        value: visualizationSettings.map?.style || 'streets',
                        options: [
                            { label: __('Ruas', 'tainacan-explorador'), value: 'streets' },
                            { label: __('Satélite', 'tainacan-explorador'), value: 'satellite' },
                            { label: __('Terreno', 'tainacan-explorador'), value: 'terrain' },
                            { label: __('Dark', 'tainacan-explorador'), value: 'dark' }
                        ],
                        onChange: (value) => handleSettingChange('map', 'style', value)
                    }),
                    
                    el(TextControl, {
                        label: __('Zoom Inicial', 'tainacan-explorador'),
                        type: 'number',
                        min: 1,
                        max: 20,
                        value: visualizationSettings.map?.zoom || 10,
                        onChange: (value) => handleSettingChange('map', 'zoom', parseInt(value))
                    }),
                    
                    el(CheckboxControl, {
                        label: __('Agrupar marcadores próximos', 'tainacan-explorador'),
                        checked: visualizationSettings.map?.cluster !== false,
                        onChange: (value) => handleSettingChange('map', 'cluster', value)
                    }),
                    
                    el(CheckboxControl, {
                        label: __('Permitir tela cheia', 'tainacan-explorador'),
                        checked: visualizationSettings.map?.fullscreen !== false,
                        onChange: (value) => handleSettingChange('map', 'fullscreen', value)
                    })
                );
            }
            
            if (type === 'timeline') {
                return el(Fragment, null,
                    el(SelectControl, {
                        label: __('Posição da Navegação', 'tainacan-explorador'),
                        value: visualizationSettings.timeline?.timenav_position || 'bottom',
                        options: [
                            { label: __('Embaixo', 'tainacan-explorador'), value: 'bottom' },
                            { label: __('Em cima', 'tainacan-explorador'), value: 'top' }
                        ],
                        onChange: (value) => handleSettingChange('timeline', 'timenav_position', value)
                    }),
                    
                    el(TextControl, {
                        label: __('Zoom Inicial', 'tainacan-explorador'),
                        type: 'number',
                        min: 0,
                        max: 10,
                        value: visualizationSettings.timeline?.initial_zoom || 2,
                        onChange: (value) => handleSettingChange('timeline', 'initial_zoom', parseInt(value))
                    })
                );
            }
            
            if (type === 'story') {
                return el(Fragment, null,
                    el(SelectControl, {
                        label: __('Tipo de Animação', 'tainacan-explorador'),
                        value: visualizationSettings.story?.animation || 'fade',
                        options: [
                            { label: __('Fade', 'tainacan-explorador'), value: 'fade' },
                            { label: __('Slide', 'tainacan-explorador'), value: 'slide' },
                            { label: __('Zoom', 'tainacan-explorador'), value: 'zoom' }
                        ],
                        onChange: (value) => handleSettingChange('story', 'animation', value)
                    }),
                    
                    el(SelectControl, {
                        label: __('Navegação', 'tainacan-explorador'),
                        value: visualizationSettings.story?.navigation || 'dots',
                        options: [
                            { label: __('Pontos', 'tainacan-explorador'), value: 'dots' },
                            { label: __('Setas', 'tainacan-explorador'), value: 'arrows' },
                            { label: __('Ambos', 'tainacan-explorador'), value: 'both' },
                            { label: __('Nenhum', 'tainacan-explorador'), value: 'none' }
                        ],
                        onChange: (value) => handleSettingChange('story', 'navigation', value)
                    }),
                    
                    el(CheckboxControl, {
                        label: __('Autoplay', 'tainacan-explorador'),
                        checked: visualizationSettings.story?.autoplay === true,
                        onChange: (value) => handleSettingChange('story', 'autoplay', value)
                    }),
                    
                    el(CheckboxControl, {
                        label: __('Efeito Parallax', 'tainacan-explorador'),
                        checked: visualizationSettings.story?.parallax !== false,
                        onChange: (value) => handleSettingChange('story', 'parallax', value)
                    })
                );
            }
            
            return null;
        };
        
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
                el('div', { className: 'tei-admin-sidebar' },
                    el(Card, null,
                        el(CardHeader, null,
                            el('h2', null, __('Coleções Disponíveis', 'tainacan-explorador'))
                        ),
                        el(CardBody, null,
                            loading ? el(Spinner) :
                            el(SelectControl, {
                                label: __('Selecione uma coleção', 'tainacan-explorador'),
                                value: selectedCollection?.id || '',
                                options: [
                                    { label: __('Selecione...', 'tainacan-explorador'), value: '' },
                                    ...collections.map(c => ({
                                        label: `${c.name} (${c.items_count} itens)`,
                                        value: c.id
                                    }))
                                ],
                                onChange: handleCollectionSelect
                            })
                        )
                    ),
                    
                    el(Card, { style: { marginTop: '20px' } },
                        el(CardHeader, null,
                            el('h2', null, __('Mapeamentos Salvos', 'tainacan-explorador'))
                        ),
                        el(CardBody, null,
                            el(SearchControl, {
                                label: __('Buscar mapeamentos', 'tainacan-explorador'),
                                value: searchTerm,
                                onChange: setSearchTerm
                            }),
                            
                            el('div', { className: 'tei-filter-buttons' },
                                ['all', 'map', 'timeline', 'story'].map(type =>
                                    el(Button, {
                                        key: type,
                                        isSmall: true,
                                        isPrimary: filterType === type,
                                        onClick: () => setFilterType(type)
                                    }, type === 'all' ? __('Todos', 'tainacan-explorador') : type)
                                )
                            ),
                            
                            el('div', { className: 'tei-mappings-list' },
                                filteredMappings.length === 0 ?
                                    el('p', null, __('Nenhum mapeamento encontrado', 'tainacan-explorador')) :
                                    filteredMappings.map(mapping =>
                                        el('div', { key: mapping.id, className: 'tei-mapping-item' },
                                            el('div', { className: 'tei-mapping-info' },
                                                el('strong', null, mapping.collection_name),
                                                el('span', null, ` (${mapping.mapping_type})`)
                                            ),
                                            el('div', { className: 'tei-mapping-actions' },
                                                el(Button, {
                                                    isSmall: true,
                                                    icon: 'admin-page',
                                                    onClick: () => cloneMapping(mapping.id),
                                                    label: __('Clonar', 'tainacan-explorador')
                                                }),
                                                el(Button, {
                                                    isSmall: true,
                                                    isDestructive: true,
                                                    icon: 'trash',
                                                    onClick: () => {
                                                        setMappingToDelete(mapping.id);
                                                        setShowDeleteModal(true);
                                                    },
                                                    label: __('Excluir', 'tainacan-explorador')
                                                })
                                            )
                                        )
                                    )
                            )
                        )
                    )
                ),
                
                el('div', { className: 'tei-admin-main' },
                    selectedCollection ?
                        el(Card, null,
                            el(CardHeader, null,
                                el('h2', null, 
                                    __('Configurar: ', 'tainacan-explorador') + selectedCollection.name
                                )
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
                        ) :
                        el(Card, null,
                            el(CardBody, null,
                                el('div', { className: 'tei-empty-state' },
                                    el('p', null, __('Selecione uma coleção para começar a configurar as visualizações.', 'tainacan-explorador'))
                                )
                            )
                        )
                )
            ),
            
            showDeleteModal && el(Modal, {
                title: __('Confirmar Exclusão', 'tainacan-explorador'),
                onRequestClose: () => setShowDeleteModal(false),
                icon: 'warning'
            },
                el('p', null, __('Tem certeza que deseja excluir este mapeamento?', 'tainacan-explorador')),
                el('div', { style: { marginTop: '20px' } },
                    el(Button, {
                        isDestructive: true,
                        onClick: deleteMapping
                    }, __('Excluir', 'tainacan-explorador')),
                    el(Button, {
                        isSecondary: true,
                        onClick: () => setShowDeleteModal(false),
                        style: { marginLeft: '10px' }
                    }, __('Cancelar', 'tainacan-explorador'))
                )
            )
        );
    };
    
    // Inicialização
    window.TEI_Admin = {
        init: function(elementId) {
            const element = document.getElementById(elementId);
            if (element) {
                wp.element.render(
                    el(AdminPanel),
                    element
                );
            }
        }
    };
    
    // Auto-inicialização
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('tei-admin-root')) {
            window.TEI_Admin.init('tei-admin-root');
        }
    });

})(window.wp, window);
