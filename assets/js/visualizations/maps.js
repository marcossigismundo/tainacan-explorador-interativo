/**
 * Explorador Interativo - Mapa
 * Classe para gerenciar mapas interativos com Leaflet
 * 
 * @package TainacanExplorador
 * @version 1.0.0
 */

(function(window, document, L) {
    'use strict';

    class TEI_Map {
        constructor(mapId, data, config) {
            this.mapId = mapId;
            this.data = data;
            this.config = config;
            this.map = null;
            this.markers = [];
            this.markerCluster = null;
            this.searchIndex = [];
            this.selectedMarker = null;
            this.filters = {};
            this.bounds = null;
            
            this.init();
        }

        /**
         * Inicializa o mapa
         */
        init() {
            const container = document.getElementById(this.mapId);
            if (!container) {
                console.error('Container do mapa não encontrado:', this.mapId);
                return;
            }

            // Remove loading overlay
            const loadingOverlay = container.parentElement.querySelector('.tei-loading-overlay');
            
            // Cria o mapa
            this.createMap(container);
            
            // Adiciona camada de tiles
            this.addTileLayer();
            
            // Adiciona marcadores
            this.addMarkers();
            
            // Configura controles
            this.setupControls();
            
            // Configura eventos
            this.setupEvents();
            
            // Ajusta visualização
            this.fitBounds();
            
            // Remove loading e marca como carregado
            if (loadingOverlay) {
                loadingOverlay.style.display = 'none';
            }
            container.classList.add('loaded');
            
            // Dispara evento customizado
            this.trigger('map:loaded', { map: this.map });
        }

        /**
         * Cria o mapa Leaflet
         */
        createMap(container) {
            const center = this.config.center || [-15.7801, -47.9292]; // Brasil como padrão
            const zoom = this.config.zoom || 10;

            this.map = L.map(container, {
                center: center,
                zoom: zoom,
                zoomControl: this.config.controls.zoom,
                scrollWheelZoom: true,
                doubleClickZoom: true,
                touchZoom: true
            });

            // Adiciona classe CSS para estilização
            L.DomUtil.addClass(this.map.getContainer(), 'tei-leaflet-map');
        }

        /**
         * Adiciona camada de tiles
         */
        addTileLayer() {
            const tileConfig = this.config.tile_layer;
            
            L.tileLayer(tileConfig.url, {
                attribution: tileConfig.attribution,
                maxZoom: 19,
                minZoom: 2
            }).addTo(this.map);
        }

        /**
         * Adiciona marcadores ao mapa
         */
        addMarkers() {
            if (!this.data.features || this.data.features.length === 0) {
                this.showNoDataMessage();
                return;
            }

            // Limpa marcadores existentes
            this.clearMarkers();

            // Cria grupo de marcadores para clustering
            if (this.config.cluster) {
                this.markerCluster = L.markerClusterGroup(this.config.cluster_options);
            }

            // Adiciona cada feature como marcador
            this.data.features.forEach((feature, index) => {
                const marker = this.createMarker(feature, index);
                if (marker) {
                    this.markers.push(marker);
                    
                    if (this.config.cluster) {
                        this.markerCluster.addLayer(marker);
                    } else {
                        marker.addTo(this.map);
                    }
                    
                    // Adiciona ao índice de busca
                    this.searchIndex.push({
                        index: index,
                        title: feature.properties.title,
                        description: feature.properties.description,
                        category: feature.properties.category,
                        marker: marker
                    });
                }
            });

            // Adiciona cluster ao mapa se habilitado
            if (this.config.cluster && this.markerCluster) {
                this.map.addLayer(this.markerCluster);
            }
        }

        /**
         * Cria um marcador
         */
        createMarker(feature, index) {
            if (!feature.geometry || !feature.geometry.coordinates) {
                return null;
            }

            const coords = [
                feature.geometry.coordinates[1], // lat
                feature.geometry.coordinates[0]  // lon
            ];

            // Cria ícone customizado se configurado
            let markerOptions = {};
            if (this.config.marker_options.icon_url) {
                markerOptions.icon = L.icon({
                    iconUrl: this.config.marker_options.icon_url,
                    iconSize: this.config.marker_options.icon_size,
                    iconAnchor: this.config.marker_options.icon_anchor,
                    popupAnchor: this.config.marker_options.popup_anchor
                });
            }

            const marker = L.marker(coords, markerOptions);

            // Adiciona popup
            if (feature.properties.popup_html) {
                marker.bindPopup(feature.properties.popup_html, {
                    maxWidth: 300,
                    className: 'tei-map-popup-container'
                });
            }

            // Adiciona dados ao marcador
            marker.feature = feature;
            marker.index = index;

            // Eventos do marcador
            marker.on('click', () => this.onMarkerClick(marker));
            marker.on('mouseover', () => this.onMarkerHover(marker));

            return marker;
        }

        /**
         * Configura controles do mapa
         */
        setupControls() {
            // Controle de zoom
            if (this.config.controls.zoom) {
                L.control.zoom({
                    position: 'topright'
                }).addTo(this.map);
            }

            // Controle de fullscreen
            if (this.config.controls.fullscreen) {
                this.addFullscreenControl();
            }

            // Controle de escala
            if (this.config.controls.scale) {
                L.control.scale({
                    position: 'bottomleft',
                    metric: true,
                    imperial: false
                }).addTo(this.map);
            }

            // Adiciona barra de busca
            this.addSearchControl();

            // Adiciona filtros
            this.addFilterControls();
        }

        /**
         * Adiciona controle de fullscreen
         */
        addFullscreenControl() {
            const FullscreenControl = L.Control.extend({
                options: {
                    position: 'topright'
                },
                onAdd: (map) => {
                    const container = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
                    const button = L.DomUtil.create('a', 'tei-fullscreen-button', container);
                    button.href = '#';
                    button.title = 'Tela cheia';
                    button.innerHTML = '⛶';
                    
                    L.DomEvent.on(button, 'click', (e) => {
                        L.DomEvent.stopPropagation(e);
                        L.DomEvent.preventDefault(e);
                        this.toggleFullscreen();
                    });
                    
                    return container;
                }
            });

            this.map.addControl(new FullscreenControl());
        }

        /**
         * Adiciona controle de busca
         */
        addSearchControl() {
            const SearchControl = L.Control.extend({
                options: {
                    position: 'topleft'
                },
                onAdd: (map) => {
                    const container = L.DomUtil.create('div', 'tei-map-search-control');
                    
                    container.innerHTML = `
                        <input type="text" 
                               class="tei-map-search-input" 
                               placeholder="Buscar no mapa...">
                        <button class="tei-map-search-btn">🔍</button>
                        <div class="tei-map-search-results"></div>
                    `;
                    
                    const input = container.querySelector('.tei-map-search-input');
                    const button = container.querySelector('.tei-map-search-btn');
                    const results = container.querySelector('.tei-map-search-results');
                    
                    // Previne propagação de eventos do mapa
                    L.DomEvent.disableClickPropagation(container);
                    L.DomEvent.disableScrollPropagation(container);
                    
                    // Evento de busca
                    const performSearch = () => {
                        const query = input.value.toLowerCase().trim();
                        this.searchMarkers(query, results);
                    };
                    
                    L.DomEvent.on(input, 'keyup', (e) => {
                        if (e.key === 'Enter') {
                            performSearch();
                        } else {
                            // Live search com debounce
                            clearTimeout(this.searchTimeout);
                            this.searchTimeout = setTimeout(() => {
                                performSearch();
                            }, 300);
                        }
                    });
                    
                    L.DomEvent.on(button, 'click', performSearch);
                    
                    return container;
                }
            });

            this.map.addControl(new SearchControl());
        }

        /**
         * Adiciona controles de filtro
         */
        addFilterControls() {
            // Extrai categorias únicas
            const categories = new Set();
            this.data.features.forEach(feature => {
                if (feature.properties.category) {
                    categories.add(feature.properties.category);
                }
            });

            if (categories.size === 0) return;

            const FilterControl = L.Control.extend({
                options: {
                    position: 'topright'
                },
                onAdd: (map) => {
                    const container = L.DomUtil.create('div', 'tei-map-filter-control');
                    
                    let html = '<select class="tei-map-filter-select">';
                    html += '<option value="">Todas as categorias</option>';
                    categories.forEach(cat => {
                        html += `<option value="${cat}">${cat}</option>`;
                    });
                    html += '</select>';
                    
                    container.innerHTML = html;
                    
                    const select = container.querySelector('.tei-map-filter-select');
                    
                    L.DomEvent.disableClickPropagation(container);
                    
                    L.DomEvent.on(select, 'change', (e) => {
                        this.filterByCategory(e.target.value);
                    });
                    
                    return container;
                }
            });

            this.map.addControl(new FilterControl());
        }

        /**
         * Busca marcadores
         */
        searchMarkers(query, resultsContainer) {
            if (!query) {
                resultsContainer.style.display = 'none';
                this.showAllMarkers();
                return;
            }

            const results = this.searchIndex.filter(item => {
                return item.title.toLowerCase().includes(query) ||
                       (item.description && item.description.toLowerCase().includes(query)) ||
                       (item.category && item.category.toLowerCase().includes(query));
            });

            if (results.length > 0) {
                // Mostra resultados
                let html = '<div class="tei-search-results-list">';
                results.forEach(result => {
                    html += `
                        <div class="tei-search-result-item" data-index="${result.index}">
                            <strong>${result.title}</strong>
                            ${result.category ? `<span class="category">${result.category}</span>` : ''}
                        </div>
                    `;
                });
                html += '</div>';
                
                resultsContainer.innerHTML = html;
                resultsContainer.style.display = 'block';
                
                // Adiciona eventos aos resultados
                resultsContainer.querySelectorAll('.tei-search-result-item').forEach(item => {
                    item.addEventListener('click', () => {
                        const index = parseInt(item.dataset.index);
                        const result = results.find(r => r.index === index);
                        if (result && result.marker) {
                            this.focusOnMarker(result.marker);
                            resultsContainer.style.display = 'none';
                        }
                    });
                });
                
                // Foca no primeiro resultado
                if (results[0].marker) {
                    this.focusOnMarker(results[0].marker);
                }
            } else {
                resultsContainer.innerHTML = '<div class="no-results">Nenhum resultado encontrado</div>';
                resultsContainer.style.display = 'block';
            }
        }

        /**
         * Filtra por categoria
         */
        filterByCategory(category) {
            this.clearMarkers();
            
            const filteredFeatures = category ? 
                this.data.features.filter(f => f.properties.category === category) :
                this.data.features;
            
            // Recria marcadores com dados filtrados
            const tempData = { ...this.data, features: filteredFeatures };
            const originalData = this.data;
            this.data = tempData;
            this.addMarkers();
            this.data = originalData;
            
            // Ajusta bounds
            if (filteredFeatures.length > 0) {
                this.fitBounds();
            }
        }

        /**
         * Configura eventos
         */
        setupEvents() {
            // Evento de resize
            window.addEventListener('resize', () => {
                this.map.invalidateSize();
            });

            // Evento de mudança de bounds
            this.map.on('moveend', () => {
                this.trigger('bounds:changed', {
                    bounds: this.map.getBounds()
                });
            });

            // Evento de zoom
            this.map.on('zoomend', () => {
                this.trigger('zoom:changed', {
                    zoom: this.map.getZoom()
                });
            });
        }

        /**
         * Evento de clique no marcador
         */
        onMarkerClick(marker) {
            this.selectedMarker = marker;
            this.trigger('marker:click', {
                marker: marker,
                feature: marker.feature
            });
        }

        /**
         * Evento de hover no marcador
         */
        onMarkerHover(marker) {
            this.trigger('marker:hover', {
                marker: marker,
                feature: marker.feature
            });
        }

        /**
         * Foca em um marcador
         */
        focusOnMarker(marker) {
            if (!marker) return;
            
            const latlng = marker.getLatLng();
            this.map.setView(latlng, 15, {
                animate: true,
                duration: 0.5
            });
            
            // Abre popup
            marker.openPopup();
            
            // Destaca marcador
            this.highlightMarker(marker);
        }

        /**
         * Destaca um marcador
         */
        highlightMarker(marker) {
            // Remove destaque anterior
            if (this.selectedMarker) {
                this.selectedMarker.setZIndexOffset(0);
            }
            
            // Adiciona destaque
            marker.setZIndexOffset(1000);
            this.selectedMarker = marker;
        }

        /**
         * Ajusta bounds do mapa
         */
        fitBounds() {
            if (this.markers.length === 0) return;
            
            const group = new L.featureGroup(this.markers);
            this.bounds = group.getBounds();
            
            this.map.fitBounds(this.bounds, {
                padding: [50, 50],
                maxZoom: 15
            });
        }

        /**
         * Limpa marcadores
         */
        clearMarkers() {
            // Remove marcadores individuais
            this.markers.forEach(marker => {
                this.map.removeLayer(marker);
            });
            
            // Remove cluster
            if (this.markerCluster) {
                this.map.removeLayer(this.markerCluster);
                this.markerCluster.clearLayers();
            }
            
            this.markers = [];
            this.searchIndex = [];
        }

        /**
         * Mostra todos os marcadores
         */
        showAllMarkers() {
            this.filterByCategory('');
        }

        /**
         * Toggle fullscreen
         */
        toggleFullscreen() {
            const container = this.map.getContainer();
            
            if (!document.fullscreenElement) {
                container.requestFullscreen().then(() => {
                    this.map.invalidateSize();
                    container.classList.add('fullscreen');
                });
            } else {
                document.exitFullscreen().then(() => {
                    this.map.invalidateSize();
                    container.classList.remove('fullscreen');
                });
            }
        }

        /**
         * Mostra mensagem de sem dados
         */
        showNoDataMessage() {
            const container = this.map.getContainer();
            const message = L.DomUtil.create('div', 'tei-map-no-data', container);
            message.innerHTML = `
                <div class="tei-no-data-content">
                    <p>Nenhum item com localização foi encontrado nesta coleção.</p>
                    <p>Verifique se o campo de localização está mapeado corretamente.</p>
                </div>
            `;
        }

        /**
         * Dispara evento customizado
         */
        trigger(eventName, data) {
            const event = new CustomEvent(`tei:map:${eventName}`, {
                detail: data,
                bubbles: true
            });
            
            const container = this.map.getContainer();
            container.dispatchEvent(event);
        }

        /**
         * Atualiza dados do mapa
         */
        updateData(newData) {
            this.data = newData;
            this.addMarkers();
            this.fitBounds();
        }

        /**
         * Obtém estado atual do mapa
         */
        getState() {
            return {
                center: this.map.getCenter(),
                zoom: this.map.getZoom(),
                bounds: this.map.getBounds(),
                markersCount: this.markers.length,
                selectedMarker: this.selectedMarker ? this.selectedMarker.feature : null
            };
        }

        /**
         * Destroi o mapa
         */
        destroy() {
            // Remove eventos
            window.removeEventListener('resize', () => {
                this.map.invalidateSize();
            });
            
            // Limpa marcadores
            this.clearMarkers();
            
            // Remove mapa
            if (this.map) {
                this.map.remove();
                this.map = null;
            }
        }
    }

    // Exporta para escopo global
    window.TEI_Map = TEI_Map;

    // Auto-inicialização
    document.addEventListener('DOMContentLoaded', function() {
        // Aguarda Leaflet carregar
        if (typeof L === 'undefined') {
            console.error('Leaflet não está carregado');
            return;
        }
        
        // Busca elementos com data-tei-map
        const mapElements = document.querySelectorAll('[data-tei-map="true"]');
        
        mapElements.forEach(element => {
            try {
                const config = JSON.parse(element.dataset.teiMapConfig || '{}');
                const data = JSON.parse(element.dataset.teiMapData || '{"features":[]}');
                
                // Cria instância do mapa
                new TEI_Map(element.id, data, config);
            } catch (error) {
                console.error('Erro ao inicializar mapa:', error);
            }
        });
    });

})(window, document, window.L);
