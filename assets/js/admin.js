/**
 * Explorador Interativo - Admin Interface Completa
 * 
 * @package TainacanExplorador
 * @version 1.0.0
 */

import React, { useState, useEffect, useCallback } from 'react';

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
    const [activeTab, setActiveTab] = useState('mappings');
    const [activeVisualization, setActiveVisualization] = useState('map');
    
    useEffect(() => {
        loadCollections();
        loadAllMappings();
    }, []);
    
    const loadCollections = async () => {
        setLoading(true);
        try {
            const response = await jQuery.ajax({
                url: window.teiAdmin?.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'tei_get_collections',
                    nonce: window.teiAdmin?.ajaxNonce || tei_admin_nonce
                }
            });
            
            if (response.success && response.data) {
                setCollections(response.data);
            }
        } catch (error) {
            console.error('Erro:', error);
            showNotification('Erro ao carregar coleções', 'error');
        } finally {
            setLoading(false);
        }
    };
    
    const loadAllMappings = async () => {
        try {
            const response = await jQuery.ajax({
                url: window.teiAdmin?.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'tei_get_all_mappings',
                    nonce: window.teiAdmin?.ajaxNonce || tei_admin_nonce
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
                url: window.teiAdmin?.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'tei_get_metadata',
                    collection_id: collectionId,
                    nonce: window.teiAdmin?.ajaxNonce || tei_admin_nonce
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
            console.error('Erro:', error);
            showNotification('Erro ao carregar metadados', 'error');
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
            showNotification('Selecione uma coleção primeiro', 'warning');
            return;
        }
        
        setSaving(true);
        try {
            const response = await jQuery.ajax({
                url: window.teiAdmin?.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'tei_save_mapping',
                    collection_id: selectedCollection.id,
                    collection_name: selectedCollection.name,
                    mapping_type: mappingType,
                    mapping_data: mappings[mappingType] || {},
                    visualization_settings: visualizationSettings[mappingType] || {},
                    filter_rules: filterRules[mappingType] || [],
                    nonce: window.teiAdmin?.ajaxNonce || tei_admin_nonce
                }
            });
            
            if (response.success) {
                showNotification('Mapeamento salvo com sucesso!', 'success');
                loadAllMappings(); // Recarrega lista
            } else {
                showNotification(response.data?.message || 'Erro ao salvar', 'error');
            }
        } catch (error) {
            console.error('Erro ao salvar:', error);
            showNotification('Erro ao salvar mapeamento', 'error');
        } finally {
            setSaving(false);
        }
    };
    
    const deleteMappingHandler = async (mappingId) => {
        if (!confirm('Tem certeza que deseja excluir este mapeamento?')) {
            return;
        }
        
        try {
            const response = await jQuery.ajax({
                url: window.teiAdmin?.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'tei_delete_mapping',
                    mapping_id: mappingId,
                    nonce: window.teiAdmin?.ajaxNonce || tei_admin_nonce
                }
            });
            
            if (response.success) {
                showNotification('Mapeamento excluído!', 'success');
                loadAllMappings();
            }
        } catch (error) {
            console.error('Erro ao excluir:', error);
            showNotification('Erro ao excluir mapeamento', 'error');
        }
    };
    
    const testVisualization = (type) => {
        if (!selectedCollection) {
            showNotification('Selecione uma coleção primeiro', 'warning');
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
                { key: 'location', label: 'Campo de Localização', required: true },
                { key: 'title', label: 'Campo de Título', required: true },
                { key: 'description', label: 'Campo de Descrição' },
                { key: 'image', label: 'Campo de Imagem' },
                { key: 'link', label: 'Campo de Link' },
                { key: 'category', label: 'Campo de Categoria' }
            ],
            timeline: [
                { key: 'date', label: 'Campo de Data', required: true },
                { key: 'title', label: 'Campo de Título', required: true },
                { key: 'description', label: 'Campo de Descrição', required: true },
                { key: 'image', label: 'Campo de Imagem' },
                { key: 'category', label: 'Campo de Categoria' },
                { key: 'link', label: 'Campo de Link' }
            ],
            story: [
                { key: 'title', label: 'Campo de Título', required: true },
                { key: 'description', label: 'Campo de Descrição', required: true },
                { key: 'image', label: 'Campo de Imagem', required: true },
                { key: 'subtitle', label: 'Campo de Subtítulo' },
                { key: 'background', label: 'Campo de Background' },
                { key: 'order', label: 'Campo de Ordem' }
            ]
        };
        
        return fields[type] || [];
    };
    
    const renderMappingsList = () => (
        <div className="wrap">
            <h2>Mapeamentos Salvos</h2>
            {savedMappings.length === 0 ? (
                <div className="notice notice-info">
                    <p>Nenhum mapeamento configurado ainda.</p>
                </div>
            ) : (
                <table className="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Coleção</th>
                            <th>Tipo</th>
                            <th>Atualizado em</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        {savedMappings.map(mapping => (
                            <tr key={mapping.id}>
                                <td>{mapping.collection_name}</td>
                                <td>
                                    <span className="dashicons dashicons-{mapping.mapping_type === 'map' ? 'location-alt' : mapping.mapping_type === 'timeline' ? 'clock' : 'book'}"></span>
                                    {' ' + mapping.mapping_type.charAt(0).toUpperCase() + mapping.mapping_type.slice(1)}
                                </td>
                                <td>{new Date(mapping.updated_at).toLocaleDateString('pt-BR')}</td>
                                <td>
                                    <button 
                                        className="button button-small"
                                        onClick={() => {
                                            const col = collections.find(c => c.id == mapping.collection_id);
                                            if (col) handleCollectionSelect(col.id);
                                        }}
                                    >
                                        Editar
                                    </button>
                                    {' '}
                                    <button 
                                        className="button button-small button-link-delete"
                                        onClick={() => deleteMappingHandler(mapping.id)}
                                    >
                                        Excluir
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
    
    const renderMappingForm = (type) => {
        const fields = getFieldsForType(type);
        const rules = filterRules[type] || [];
        
        return (
            <div className="postbox">
                <div className="postbox-header">
                    <h2>Configuração de {type === 'map' ? 'Mapa' : type === 'timeline' ? 'Linha do Tempo' : 'Storytelling'}</h2>
                </div>
                <div className="inside">
                    <h3>Mapeamento de Campos</h3>
                    <table className="form-table">
                        <tbody>
                            {fields.map(field => (
                                <tr key={field.key}>
                                    <th scope="row">
                                        <label htmlFor={`${type}_${field.key}`}>
                                            {field.label}
                                            {field.required && <span className="required">*</span>}
                                        </label>
                                    </th>
                                    <td>
                                        <select 
                                            id={`${type}_${field.key}`}
                                            className="regular-text"
                                            value={mappings[type]?.[field.key] || ''}
                                            onChange={(e) => handleMappingChange(type, field.key, e.target.value)}
                                        >
                                            <option value="">Selecione...</option>
                                            {metadata.map(m => (
                                                <option key={m.id} value={m.id}>
                                                    {m.name} ({m.type})
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    
                    <h3>Filtros de Exibição</h3>
                    <p className="description">Configure filtros para limitar quais itens serão exibidos</p>
                    
                    {rules.map((rule, index) => (
                        <div key={index} className="filter-rule" style={{ 
                            display: 'flex', 
                            gap: '10px', 
                            marginBottom: '10px',
                            padding: '10px',
                            background: '#f6f7f7',
                            border: '1px solid #c3c4c7'
                        }}>
                            <select 
                                value={rule.metadatum || ''}
                                onChange={(e) => handleFilterRuleChange(type, index, 'metadatum', e.target.value)}
                                style={{ flex: 2 }}
                            >
                                <option value="">Selecione metadado...</option>
                                {metadata.map(m => (
                                    <option key={m.id} value={m.id}>
                                        {m.name} ({m.type})
                                    </option>
                                ))}
                            </select>
                            
                            <select 
                                value={rule.operator || '='}
                                onChange={(e) => handleFilterRuleChange(type, index, 'operator', e.target.value)}
                                style={{ flex: 1 }}
                            >
                                <option value="=">Igual a</option>
                                <option value="!=">Diferente de</option>
                                <option value="IN">Contém</option>
                                <option value="NOT IN">Não contém</option>
                                <option value="LIKE">Contém texto</option>
                            </select>
                            
                            <input 
                                type="text"
                                value={rule.value || ''}
                                placeholder="Valor"
                                onChange={(e) => handleFilterRuleChange(type, index, 'value', e.target.value)}
                                style={{ flex: 2 }}
                                className="regular-text"
                            />
                            
                            <button 
                                type="button"
                                className="button button-link-delete"
                                onClick={() => removeFilterRule(type, index)}
                            >
                                Remover
                            </button>
                        </div>
                    ))}
                    
                    <button 
                        type="button"
                        className="button"
                        onClick={() => addFilterRule(type)}
                    >
                        Adicionar Filtro
                    </button>
                    
                    <div style={{ marginTop: '20px', paddingTop: '20px', borderTop: '1px solid #c3c4c7' }}>
                        <button 
                            type="button"
                            className="button button-primary"
                            onClick={() => saveMappings(type)}
                            disabled={saving || !selectedCollection}
                        >
                            {saving ? 'Salvando...' : 'Salvar Configurações'}
                        </button>
                        {' '}
                        <button 
                            type="button"
                            className="button"
                            onClick={() => testVisualization(type)}
                            disabled={!selectedCollection}
                        >
                            Visualizar Prévia
                        </button>
                    </div>
                </div>
            </div>
        );
    };
    
    return (
        <div className="wrap">
            <h1>Explorador Interativo</h1>
            <p>Configure visualizações interativas para suas coleções do Tainacan</p>
            
            {notification && (
                <div className={`notice notice-${notification.type} is-dismissible`}>
                    <p>{notification.message}</p>
                </div>
            )}
            
            <nav className="nav-tab-wrapper wp-clearfix">
                <a 
                    href="#" 
                    className={`nav-tab ${activeTab === 'mappings' ? 'nav-tab-active' : ''}`}
                    onClick={(e) => { e.preventDefault(); setActiveTab('mappings'); }}
                >
                    Mapeamentos Salvos
                </a>
                <a 
                    href="#" 
                    className={`nav-tab ${activeTab === 'configure' ? 'nav-tab-active' : ''}`}
                    onClick={(e) => { e.preventDefault(); setActiveTab('configure'); }}
                >
                    Configurar Novo
                </a>
            </nav>
            
            <div className="tab-content">
                {activeTab === 'mappings' && renderMappingsList()}
                
                {activeTab === 'configure' && (
                    <div>
                        <div className="postbox">
                            <div className="postbox-header">
                                <h2>Selecione uma Coleção</h2>
                            </div>
                            <div className="inside">
                                {loading ? (
                                    <span className="spinner is-active"></span>
                                ) : (
                                    <select 
                                        className="large-text"
                                        value={selectedCollection?.id || ''}
                                        onChange={(e) => handleCollectionSelect(e.target.value)}
                                    >
                                        <option value="">Selecione...</option>
                                        {collections.map(c => (
                                            <option key={c.id} value={c.id}>
                                                {c.name} ({c.items_count || 0} itens)
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </div>
                        </div>
                        
                        {selectedCollection && (
                            <div>
                                <h2 className="nav-tab-wrapper">
                                    <a 
                                        href="#"
                                        className={`nav-tab ${activeVisualization === 'map' ? 'nav-tab-active' : ''}`}
                                        onClick={(e) => { e.preventDefault(); setActiveVisualization('map'); }}
                                    >
                                        <span className="dashicons dashicons-location-alt"></span> Mapa
                                    </a>
                                    <a 
                                        href="#"
                                        className={`nav-tab ${activeVisualization === 'timeline' ? 'nav-tab-active' : ''}`}
                                        onClick={(e) => { e.preventDefault(); setActiveVisualization('timeline'); }}
                                    >
                                        <span className="dashicons dashicons-clock"></span> Linha do Tempo
                                    </a>
                                    <a 
                                        href="#"
                                        className={`nav-tab ${activeVisualization === 'story' ? 'nav-tab-active' : ''}`}
                                        onClick={(e) => { e.preventDefault(); setActiveVisualization('story'); }}
                                    >
                                        <span className="dashicons dashicons-book"></span> Storytelling
                                    </a>
                                </h2>
                                
                                {loading ? (
                                    <span className="spinner is-active"></span>
                                ) : (
                                    renderMappingForm(activeVisualization)
                                )}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
};

export default AdminPanel;
