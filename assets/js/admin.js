import React, { useState, useEffect, useCallback } from 'react';
import { AlertCircle, MapPin, Clock, BookOpen, Settings, Save, Trash2, Eye, Copy, Check, X, Plus, Loader2, Search, Filter } from 'lucide-react';

const AdminPanel = () => {
  const [collections, setCollections] = useState([]);
  const [selectedCollection, setSelectedCollection] = useState(null);
  const [metadata, setMetadata] = useState([]);
  const [mappings, setMappings] = useState({});
  const [visualizationSettings, setVisualizationSettings] = useState({});
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [activeTab, setActiveTab] = useState('map');
  const [savedMappings, setSavedMappings] = useState([]);
  const [notification, setNotification] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [filterType, setFilterType] = useState('all');

  // Simulação de carregamento de dados
  useEffect(() => {
    loadCollections();
    loadSavedMappings();
  }, []);

  const loadCollections = async () => {
    setLoading(true);
    // Simulação de dados do Tainacan
    setTimeout(() => {
      setCollections([
        { id: 1, name: 'Patrimônio Cultural', items_count: 245 },
        { id: 2, name: 'Arquivo Histórico', items_count: 532 },
        { id: 3, name: 'Acervo Fotográfico', items_count: 1823 },
        { id: 4, name: 'Documentos Raros', items_count: 89 },
      ]);
      setLoading(false);
    }, 1000);
  };

  const loadSavedMappings = async () => {
    // Simulação de mapeamentos salvos
    setTimeout(() => {
      setSavedMappings([
        { 
          id: 1, 
          collection_name: 'Patrimônio Cultural', 
          type: 'map', 
          updated_at: '2025-08-07',
          status: 'active'
        },
        { 
          id: 2, 
          collection_name: 'Arquivo Histórico', 
          type: 'timeline', 
          updated_at: '2025-08-06',
          status: 'active'
        },
      ]);
    }, 500);
  };

  const loadMetadata = async (collectionId) => {
    setLoading(true);
    // Simulação de metadados da coleção
    setTimeout(() => {
      setMetadata([
        { id: 'title', name: 'Título', type: 'text', cardinality: 1 },
        { id: 'description', name: 'Descrição', type: 'textarea', cardinality: 1 },
        { id: 'date', name: 'Data', type: 'date', cardinality: 1 },
        { id: 'location', name: 'Localização', type: 'text', cardinality: 1 },
        { id: 'coordinates', name: 'Coordenadas', type: 'compound', cardinality: 1 },
        { id: 'image', name: 'Imagem', type: 'relationship', cardinality: 'n' },
        { id: 'category', name: 'Categoria', type: 'taxonomy', cardinality: 'n' },
        { id: 'external_link', name: 'Link Externo', type: 'url', cardinality: 1 },
      ]);
      setLoading(false);
    }, 800);
  };

  const handleCollectionSelect = (collection) => {
    setSelectedCollection(collection);
    loadMetadata(collection.id);
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

  const saveMappings = async () => {
    setSaving(true);
    // Simulação de salvamento
    setTimeout(() => {
      setSaving(false);
      showNotification('Mapeamentos salvos com sucesso!', 'success');
      loadSavedMappings();
    }, 1500);
  };

  const deleteMappng = async (mappingId) => {
    if (window.confirm('Tem certeza que deseja excluir este mapeamento?')) {
      setSavedMappings(prev => prev.filter(m => m.id !== mappingId));
      showNotification('Mapeamento excluído', 'info');
    }
  };

  const cloneMapping = async (mappingId) => {
    const mapping = savedMappings.find(m => m.id === mappingId);
    if (mapping) {
      const newMapping = { 
        ...mapping, 
        id: Date.now(), 
        collection_name: `${mapping.collection_name} (Cópia)`,
        updated_at: new Date().toISOString().split('T')[0]
      };
      setSavedMappings(prev => [...prev, newMapping]);
      showNotification('Mapeamento clonado com sucesso', 'success');
    }
  };

  const showNotification = (message, type = 'info') => {
    setNotification({ message, type });
    setTimeout(() => setNotification(null), 5000);
  };

  const testVisualization = (type) => {
    const url = `${window.location.origin}/preview?type=${type}&collection=${selectedCollection?.id}`;
    window.open(url, '_blank');
  };

  const getVisualizationIcon = (type) => {
    const icons = {
      map: <MapPin className="w-5 h-5" />,
      timeline: <Clock className="w-5 h-5" />,
      story: <BookOpen className="w-5 h-5" />
    };
    return icons[type];
  };

  const filteredMappings = savedMappings.filter(mapping => {
    const matchesSearch = mapping.collection_name.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesFilter = filterType === 'all' || mapping.type === filterType;
    return matchesSearch && matchesFilter;
  });

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 via-white to-purple-50">
      {/* Header */}
      <div className="bg-white shadow-sm border-b border-gray-200">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex items-center justify-between h-16">
            <div className="flex items-center space-x-4">
              <div className="p-2 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg">
                <MapPin className="w-6 h-6 text-white" />
              </div>
              <h1 className="text-2xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">
                Explorador Interativo
              </h1>
            </div>
            <button
              onClick={saveMappings}
              disabled={saving || !selectedCollection}
              className="flex items-center space-x-2 px-4 py-2 bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-lg hover:from-blue-600 hover:to-purple-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all duration-200 shadow-md hover:shadow-lg"
            >
              {saving ? (
                <Loader2 className="w-5 h-5 animate-spin" />
              ) : (
                <Save className="w-5 h-5" />
              )}
              <span>{saving ? 'Salvando...' : 'Salvar Configurações'}</span>
            </button>
          </div>
        </div>
      </div>

      {/* Notification */}
      {notification && (
        <div className={`fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg animate-slide-in-right ${
          notification.type === 'success' ? 'bg-green-50 border border-green-200' :
          notification.type === 'error' ? 'bg-red-50 border border-red-200' :
          'bg-blue-50 border border-blue-200'
        }`}>
          <div className="flex items-center space-x-2">
            {notification.type === 'success' ? (
              <Check className="w-5 h-5 text-green-600" />
            ) : notification.type === 'error' ? (
              <X className="w-5 h-5 text-red-600" />
            ) : (
              <AlertCircle className="w-5 h-5 text-blue-600" />
            )}
            <p className={`${
              notification.type === 'success' ? 'text-green-800' :
              notification.type === 'error' ? 'text-red-800' :
              'text-blue-800'
            }`}>{notification.message}</p>
          </div>
        </div>
      )}

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Coluna Esquerda - Seleção de Coleção */}
          <div className="lg:col-span-1">
            <div className="bg-white rounded-xl shadow-md overflow-hidden">
              <div className="p-6 bg-gradient-to-r from-blue-500 to-purple-600">
                <h2 className="text-xl font-semibold text-white">Coleções Disponíveis</h2>
                <p className="text-blue-100 text-sm mt-1">Selecione uma coleção para configurar</p>
              </div>
              
              <div className="p-4">
                {loading ? (
                  <div className="flex items-center justify-center py-8">
                    <Loader2 className="w-8 h-8 animate-spin text-blue-500" />
                  </div>
                ) : (
                  <div className="space-y-2">
                    {collections.map((collection) => (
                      <button
                        key={collection.id}
                        onClick={() => handleCollectionSelect(collection)}
                        className={`w-full text-left p-4 rounded-lg transition-all duration-200 ${
                          selectedCollection?.id === collection.id
                            ? 'bg-gradient-to-r from-blue-50 to-purple-50 border-2 border-blue-400 shadow-sm'
                            : 'bg-gray-50 hover:bg-gray-100 border-2 border-transparent'
                        }`}
                      >
                        <div className="font-medium text-gray-900">{collection.name}</div>
                        <div className="text-sm text-gray-500 mt-1">
                          {collection.items_count} itens
                        </div>
                      </button>
                    ))}
                  </div>
                )}
              </div>
            </div>

            {/* Mapeamentos Salvos */}
            <div className="mt-6 bg-white rounded-xl shadow-md overflow-hidden">
              <div className="p-4 border-b border-gray-200">
                <h3 className="font-semibold text-gray-900">Mapeamentos Salvos</h3>
                <div className="mt-3 relative">
                  <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" />
                  <input
                    type="text"
                    placeholder="Buscar mapeamentos..."
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    className="w-full pl-10 pr-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  />
                </div>
                <div className="mt-2 flex gap-2">
                  {['all', 'map', 'timeline', 'story'].map((type) => (
                    <button
                      key={type}
                      onClick={() => setFilterType(type)}
                      className={`px-3 py-1 text-xs rounded-full transition-colors ${
                        filterType === type
                          ? 'bg-blue-500 text-white'
                          : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                      }`}
                    >
                      {type === 'all' ? 'Todos' : type}
                    </button>
                  ))}
                </div>
              </div>
              <div className="p-4 space-y-2 max-h-64 overflow-y-auto">
                {filteredMappings.map((mapping) => (
                  <div
                    key={mapping.id}
                    className="p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors"
                  >
                    <div className="flex items-center justify-between">
                      <div className="flex items-center space-x-2">
                        {getVisualizationIcon(mapping.type)}
                        <div>
                          <div className="font-medium text-sm text-gray-900">
                            {mapping.collection_name}
                          </div>
                          <div className="text-xs text-gray-500">
                            {mapping.updated_at}
                          </div>
                        </div>
                      </div>
                      <div className="flex space-x-1">
                        <button
                          onClick={() => cloneMapping(mapping.id)}
                          className="p-1 text-gray-400 hover:text-blue-600 transition-colors"
                          title="Clonar"
                        >
                          <Copy className="w-4 h-4" />
                        </button>
                        <button
                          onClick={() => deleteMappng(mapping.id)}
                          className="p-1 text-gray-400 hover:text-red-600 transition-colors"
                          title="Excluir"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Coluna Direita - Configuração de Mapeamentos */}
          <div className="lg:col-span-2">
            {selectedCollection ? (
              <div className="bg-white rounded-xl shadow-md overflow-hidden">
                <div className="border-b border-gray-200">
                  <nav className="flex -mb-px">
                    {[
                      { id: 'map', label: 'Mapa', icon: <MapPin className="w-4 h-4" /> },
                      { id: 'timeline', label: 'Linha do Tempo', icon: <Clock className="w-4 h-4" /> },
                      { id: 'story', label: 'Storytelling', icon: <BookOpen className="w-4 h-4" /> },
                    ].map((tab) => (
                      <button
                        key={tab.id}
                        onClick={() => setActiveTab(tab.id)}
                        className={`flex items-center space-x-2 px-6 py-4 border-b-2 font-medium text-sm transition-colors ${
                          activeTab === tab.id
                            ? 'border-blue-500 text-blue-600'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                        }`}
                      >
                        {tab.icon}
                        <span>{tab.label}</span>
                      </button>
                    ))}
                  </nav>
                </div>

                <div className="p-6">
                  {/* Configuração do Mapa */}
                  {activeTab === 'map' && (
                    <div className="space-y-6">
                      <div className="bg-blue-50 rounded-lg p-4">
                        <h3 className="font-semibold text-gray-900 mb-2">
                          Configuração do Mapa Interativo
                        </h3>
                        <p className="text-sm text-gray-600">
                          Associe os metadados da coleção aos campos necessários para gerar o mapa.
                        </p>
                      </div>

                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                          <label className="block text-sm font-medium text-gray-700 mb-2">
                            Campo de Localização *
                          </label>
                          <select
                            onChange={(e) => handleMappingChange('map', 'location', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                          >
                            <option value="">Selecione...</option>
                            {metadata.map((field) => (
                              <option key={field.id} value={field.id}>
                                {field.name} ({field.type})
                              </option>
                            ))}
                          </select>
                        </div>

                        <div>
                          <label className="block text-sm font-medium text-gray-700 mb-2">
                            Campo de Título *
                          </label>
                          <select
                            onChange={(e) => handleMappingChange('map', 'title', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                          >
                            <option value="">Selecione...</option>
                            {metadata.map((field) => (
                              <option key={field.id} value={field.id}>
                                {field.name} ({field.type})
                              </option>
                            ))}
                          </select>
                        </div>

                        <div>
                          <label className="block text-sm font-medium text-gray-700 mb-2">
                            Campo de Descrição
                          </label>
                          <select
                            onChange={(e) => handleMappingChange('map', 'description', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                          >
                            <option value="">Selecione...</option>
                            {metadata.map((field) => (
                              <option key={field.id} value={field.id}>
                                {field.name} ({field.type})
                              </option>
                            ))}
                          </select>
                        </div>

                        <div>
                          <label className="block text-sm font-medium text-gray-700 mb-2">
                            Campo de Imagem
                          </label>
                          <select
                            onChange={(e) => handleMappingChange('map', 'image', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                          >
                            <option value="">Selecione...</option>
                            {metadata.map((field) => (
                              <option key={field.id} value={field.id}>
                                {field.name} ({field.type})
                              </option>
                            ))}
                          </select>
                        </div>
                      </div>

                      <div className="border-t pt-6">
                        <h4 className="font-medium text-gray-900 mb-4">Configurações de Visualização</h4>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                          <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                              Estilo do Mapa
                            </label>
                            <select
                              onChange={(e) => handleSettingChange('map', 'style', e.target.value)}
                              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            >
                              <option value="streets">Ruas</option>
                              <option value="satellite">Satélite</option>
                              <option value="hybrid">Híbrido</option>
                              <option value="terrain">Terreno</option>
                            </select>
                          </div>

                          <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                              Zoom Inicial
                            </label>
                            <input
                              type="number"
                              min="1"
                              max="20"
                              defaultValue="10"
                              onChange={(e) => handleSettingChange('map', 'zoom', e.target.value)}
                              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                          </div>

                          <div className="flex items-center space-x-2">
                            <input
                              type="checkbox"
                              id="cluster"
                              onChange={(e) => handleSettingChange('map', 'cluster', e.target.checked)}
                              className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                            />
                            <label htmlFor="cluster" className="text-sm text-gray-700">
                              Agrupar marcadores próximos
                            </label>
                          </div>

                          <div className="flex items-center space-x-2">
                            <input
                              type="checkbox"
                              id="fullscreen"
                              onChange={(e) => handleSettingChange('map', 'fullscreen', e.target.checked)}
                              className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                            />
                            <label htmlFor="fullscreen" className="text-sm text-gray-700">
                              Permitir tela cheia
                            </label>
                          </div>
                        </div>
                      </div>

                      <div className="flex justify-end">
                        <button
                          onClick={() => testVisualization('map')}
                          className="flex items-center space-x-2 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors"
                        >
                          <Eye className="w-4 h-4" />
                          <span>Visualizar Prévia</span>
                        </button>
                      </div>
                    </div>
                  )}

                  {/* Configuração da Timeline */}
                  {activeTab === 'timeline' && (
                    <div className="space-y-6">
                      <div className="bg-purple-50 rounded-lg p-4">
                        <h3 className="font-semibold text-gray-900 mb-2">
                          Configuração da Linha do Tempo
                        </h3>
                        <p className="text-sm text-gray-600">
                          Configure os campos para criar uma linha do tempo interativa.
                        </p>
                      </div>

                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                          <label className="block text-sm font-medium text-gray-700 mb-2">
                            Campo de Data *
                          </label>
                          <select
                            onChange={(e) => handleMappingChange('timeline', 'date', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                          >
                            <option value="">Selecione...</option>
                            {metadata.map((field) => (
                              <option key={field.id} value={field.id}>
                                {field.name} ({field.type})
                              </option>
                            ))}
                          </select>
                        </div>

                        <div>
                          <label className="block text-sm font-medium text-gray-700 mb-2">
                            Campo de Título *
                          </label>
                          <select
                            onChange={(e) => handleMappingChange('timeline', 'title', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                          >
                            <option value="">Selecione...</option>
                            {metadata.map((field) => (
                              <option key={field.id} value={field.id}>
                                {field.name} ({field.type})
                              </option>
                            ))}
                          </select>
                        </div>

                        <div className="md:col-span-2">
                          <label className="block text-sm font-medium text-gray-700 mb-2">
                            Campo de Descrição *
                          </label>
                          <select
                            onChange={(e) => handleMappingChange('timeline', 'description', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                          >
                            <option value="">Selecione...</option>
                            {metadata.map((field) => (
                              <option key={field.id} value={field.id}>
                                {field.name} ({field.type})
                              </option>
                            ))}
                          </select>
                        </div>
                      </div>

                      <div className="flex justify-end">
                        <button
                          onClick={() => testVisualization('timeline')}
                          className="flex items-center space-x-2 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors"
                        >
                          <Eye className="w-4 h-4" />
                          <span>Visualizar Prévia</span>
                        </button>
                      </div>
                    </div>
                  )}

                  {/* Configuração do Storytelling */}
                  {activeTab === 'story' && (
                    <div className="space-y-6">
                      <div className="bg-green-50 rounded-lg p-4">
                        <h3 className="font-semibold text-gray-900 mb-2">
                          Configuração do Storytelling
                        </h3>
                        <p className="text-sm text-gray-600">
                          Configure os campos para criar uma narrativa visual interativa.
                        </p>
                      </div>

                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                          <label className="block text-sm font-medium text-gray-700 mb-2">
                            Campo de Título *
                          </label>
                          <select
                            onChange={(e) => handleMappingChange('story', 'title', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                          >
                            <option value="">Selecione...</option>
                            {metadata.map((field) => (
                              <option key={field.id} value={field.id}>
                                {field.name} ({field.type})
                              </option>
                            ))}
                          </select>
                        </div>

                        <div>
                          <label className="block text-sm font-medium text-gray-700 mb-2">
                            Campo de Imagem *
                          </label>
                          <select
                            onChange={(e) => handleMappingChange('story', 'image', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                          >
                            <option value="">Selecione...</option>
                            {metadata.map((field) => (
                              <option key={field.id} value={field.id}>
                                {field.name} ({field.type})
                              </option>
                            ))}
                          </select>
                        </div>

                        <div className="md:col-span-2">
                          <label className="block text-sm font-medium text-gray-700 mb-2">
                            Campo de Descrição *
                          </label>
                          <select
                            onChange={(e) => handleMappingChange('story', 'description', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                          >
                            <option value="">Selecione...</option>
                            {metadata.map((field) => (
                              <option key={field.id} value={field.id}>
                                {field.name} ({field.type})
                              </option>
                            ))}
                          </select>
                        </div>
                      </div>

                      <div className="flex justify-end">
                        <button
                          onClick={() => testVisualization('story')}
                          className="flex items-center space-x-2 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors"
                        >
                          <Eye className="w-4 h-4" />
                          <span>Visualizar Prévia</span>
                        </button>
                      </div>
                    </div>
                  )}
                </div>
              </div>
            ) : (
              <div className="bg-white rounded-xl shadow-md p-12 text-center">
                <div className="mx-auto w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                  <Settings className="w-12 h-12 text-gray-400" />
                </div>
                <h3 className="text-xl font-semibold text-gray-900 mb-2">
                  Selecione uma Coleção
                </h3>
                <p className="text-gray-500">
                  Escolha uma coleção do Tainacan na lista ao lado para começar a configurar as visualizações interativas.
                </p>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default AdminPanel;
