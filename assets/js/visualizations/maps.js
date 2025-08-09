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
            
            // Marca como carregado
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

            // Cria cluster se habilitado
            if (this.config.cluster) {
                this.markerCluster = L.markerClusterGroup({
                    ...this.config.cluster_options,
                    iconCreateFunction: this.createClusterIcon.bind(this)
                });
            }

            // Adiciona cada marcador
            this.data.features.forEach((feature, index) => {
                const marker = this.createMarker(feature);
                if (marker) {
                    this.markers.push(marker);
                    
                    // Adiciona ao índice de busca
                    this.searchIndex.push({
                        index: index,
                        marker: marker,
                        title: feature.properties.title,
                        description: feature.properties.description,
                        category: feature.properties.category
                    });

                    // Adiciona ao cluster ou diretamente ao mapa
                    if (this.config.cluster) {
                        this.markerCluster.addLayer(marker);
                    } else {
                        marker.addTo(this.map);
                    }
                }
            });

            // Adiciona cluster ao mapa
            if (this.config.cluster && this.markerCluster) {
                this.map.addLayer(this.markerCluster);
            }
        }

        /**
         * Cria um marcador
         */
        createMarker(feature) {
            const coords = feature.geometry.coordinates;
            if (!coords || coords.length < 2) {
                return null;
            }

            const latLng = [coords[1], coords[0]]; // GeoJSON usa [lon, lat]
            
            // Cria ícone customizado se configurado
            let icon = this.createCustomIcon(feature.properties.category);

            const marker = L.marker(latLng, { 
                icon: icon,
                title: feature.properties.title
            });

            // Adiciona popup
            if (feature.properties.popup_html) {
                marker.bindPopup(feature.properties.popup_html, {
                    maxWidth: 350,
                    className: 'tei-custom-popup'
                });
            }

            // Adiciona dados ao marcador
            marker.feature = feature;

            // Eventos do marcador
            marker.on('click', () => this.onMarkerClick(marker));
            marker.on('popupopen', () => this.onPopupOpen(marker));
            marker.on('popupclose', () => this.onPopupClose(marker));

            return marker;
        }

        /**
         * Cria ícone customizado
         */
        createCustomIcon(category) {
            const iconUrl = this.config.marker_options.icon_url;
            
            if (iconUrl) {
                return L.icon({
                    iconUrl: iconUrl,
                    iconSize: this.config.marker_options.icon_size,
                    iconAnchor: this.config.marker_options.icon_anchor,
                    popupAnchor: this.config.marker_options.popup_anchor
                });
            }

            // Ícone colorido baseado em categoria
            const colors = {
                'default': '#3b82f6',
                'cultural': '#8b5cf6',
                'historico': '#ef4444',
                'natural': '#10b981',
                'religioso': '#f59e0b'
            };

            const color = colors[category?.toLowerCase()] || colors.default;

            return L.divIcon({
                html: `
                    <div class="tei-marker-pin" style="background: ${color};">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="white">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                        </svg>
                    </div>
                `,
                className: 'tei-custom-marker',
                iconSize: [32, 42],
                iconAnchor: [16, 42],
                popupAnchor: [0, -42]
            });
        }

        /**
         * Cria ícone de cluster
         */
        createClusterIcon(cluster) {
            const count = cluster.getChildCount();
            let size = 'small';
            let className = 'marker-cluster marker-cluster-';

            if (count > 100) {
                size = 'large';
            } else if (count > 10) {
                size = 'medium';
            }

            return L.divIcon({
                html: `<div><span>${count}</span></div>`,
                className: className + size,
                iconSize: L.point(40, 40)
            });
        }

        /**
         * Configura controles do mapa
         */
        setupControls() {
            // Controle de escala
            if (this.config.controls.scale) {
                L.control.scale({
                    metric: true,
                    imperial: false
                }).addTo(this.map);
            }

            // Controle de tela cheia
            if (this.config.controls.fullscreen) {
                this.setupFullscreen();
            }

            // Controle de busca
            this.setupSearch();

            // Controle de reset
            this.addResetButton();
        }

        /**
         * Configura busca
         */
        setupSearch() {
            const searchContainer = document.querySelector(`#${this.mapId}`).parentElement.querySelector('.tei-map-search');
            if (!searchContainer) return;

            const input = searchContainer.querySelector('.tei-map-search-input');
            const button = searchContainer.querySelector('.tei-map-search-btn');

            if (input && button) {
                // Busca ao clicar no botão
                button.addEventListener('click', () => this.performSearch(input.value));

                // Busca ao pressionar Enter
                input.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        this.performSearch(input.value);
                    }
                });

                // Busca em tempo real (debounced)
                let searchTimeout;
                input.addEventListener('input', (e) => {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        this.performSearch(e.target.value);
                    }, 500);
                });
            }
        }

        /**
         * Realiza busca
         */
        performSearch(query) {
            if (!query || query.trim() === '') {
                this.clearSearch();
                return;
            }

            const normalizedQuery = query.toLowerCase().trim();
            const results = this.searchIndex.filter(item => {
                return item.title?.toLowerCase().includes(normalizedQuery) ||
                       item.description?.toLowerCase().includes(normalizedQuery) ||
                       item.category?.toLowerCase().includes(normalizedQuery);
            });

            if (results.length > 0) {
                this.highlightSearchResults(results);
                
                // Zoom para o primeiro resultado
                if (results[0].marker) {
                    const latLng = results[0].marker.getLatLng();
                    this.map.setView(latLng, 15);
                    results[0].marker.openPopup();
                }

                this.trigger('search:results', { query, results });
            } else {
                this.showNoResultsMessage(query);
            }
        }

        /**
         * Destaca resultados da busca
         */
        highlightSearchResults(results) {
            // Remove destaques anteriores
            this.markers.forEach(marker => {
                if (marker._icon) {
                    L.DomUtil.removeClass(marker._icon, 'tei-marker-highlight');
                }
            });

            // Adiciona destaque aos resultados
            results.forEach(result => {
                if (result.marker && result.marker._icon) {
                    L.DomUtil.addClass(result.marker._icon, 'tei-marker-highlight');
                }
            });
        }

        /**
         * Limpa busca
         */
        clearSearch() {
            this.markers.forEach(marker => {
                if (marker._icon) {
                    L.DomUtil.removeClass(marker._icon, 'tei-marker-highlight');
                }
            });
            this.fitBounds();
        }

        /**
         * Configura tela cheia
         */
        setupFullscreen() {
            const btn = document.querySelector(`#${this.mapId}`).parentElement.querySelector('.tei-map-fullscreen-btn');
            if (!btn) return;

            btn.addEventListener('click', () => {
                const container = document.querySelector(`#${this.mapId}`).parentElement;
                
                if (!document.fullscreenElement) {
                    container.requestFullscreen().then(() => {
                        L.DomUtil.addClass(container, 'tei-fullscreen');
                        this.map.invalidateSize();
                    }).catch(err => {
                        console.error('Erro ao entrar em tela cheia:', err);
                    });
                } else {
                    document.exitFullscreen().then(() => {
                        L.DomUtil.removeClass(container, 'tei-fullscreen');
                        this.map.invalidateSize();
                    });
                }
            });
        }

        /**
         * Adiciona botão de reset
         */
        addResetButton() {
            const ResetControl = L.Control.extend({
                options: {
                    position: 'topleft'
                },
                onAdd: (map) => {
                    const container = L.DomUtil.create('div', 'leaflet-bar leaflet-control tei-reset-control');
                    const button = L.DomUtil.create('a', 'tei-reset-btn', container);
                    
                    button.href = '#';
                    button.title = 'Resetar visualização';
                    button.innerHTML = `
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                            <path d="M3 3v5h5"/>
                        </svg>
                    `;
                    
                    L.DomEvent.on(button, 'click', (e) => {
                        L.DomEvent.preventDefault(e);
                        this.fitBounds();
                        this.clearSearch();
                    });
                    
                    return container;
                }
            });

            new ResetControl().addTo(this.map);
        }

        /**
         * Configura eventos
         */
        setupEvents() {
            // Redimensionamento da janela
            window.addEventListener('resize', this.debounce(() => {
                this.map.invalidateSize();
            }, 250));

            // Eventos do mapa
            this.map.on('moveend', () => {
                this.trigger('map:moveend', { 
                    center: this.map.getCenter(),
                    zoom: this.map.getZoom()
                });
            });

            this.map.on('zoomend', () => {
                this.trigger('map:zoomend', { 
                    zoom: this.map.getZoom()
                });
            });
        }

        /**
         * Ajusta limites do mapa
         */
        fitBounds() {
            if (this.markers.length === 0) return;

            const group = new L.featureGroup(this.markers);
            this.map.fitBounds(group.getBounds().pad(0.1));
        }

        /**
         * Evento de clique no marcador
         */
        onMarkerClick(marker) {
            this.selectedMarker = marker;
            this.trigger('marker:click', { marker });
        }

        /**
         * Evento de abertura do popup
         */
        onPopupOpen(marker) {
            // Adiciona animação ao popup
            setTimeout(() => {
                const popup = marker.getPopup().getElement();
                if (popup) {
                    popup.classList.add('tei-popup-animated');
                }
            }, 10);

            this.trigger('popup:open', { marker });
        }

        /**
         * Evento de fechamento do popup
         */
        onPopupClose(marker) {
            this.trigger('popup:close', { marker });
        }

        /**
         * Mostra mensagem de sem dados
         */
        showNoDataMessage() {
            const container = document.getElementById(this.mapId);
            const message = document.createElement('div');
            message.className = 'tei-no-data-message';
            message.innerHTML = `
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/>
                    <circle cx="12" cy="9" r="2.5"/>
                </svg>
                <p>Nenhum local encontrado</p>
            `;
            container.appendChild(message);
        }

        /**
         * Mostra mensagem de sem resultados
         */
        showNoResultsMessage(query) {
            // Cria toast notification
            const toast = document.createElement('div');
            toast.className = 'tei-toast tei-toast-warning';
            toast.innerHTML = `
                <div class="tei-toast-content">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <span>Nenhum resultado para "${query}"</span>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.add('tei-toast-show');
            }, 10);
            
            setTimeout(() => {
                toast.classList.remove('tei-toast-show');
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 3000);
        }

        /**
         * Dispara evento customizado
         */
        trigger(eventName, data) {
            const event = new CustomEvent(`tei:${eventName}`, {
                detail: data,
                bubbles: true
            });
            document.getElementById(this.mapId).dispatchEvent(event);
        }

        /**
         * Debounce function
         */
        debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        /**
         * Destrói o mapa
         */
        destroy() {
            if (this.map) {
                this.map.remove();
                this.map = null;
            }
            this.markers = [];
            this.markerCluster = null;
            this.searchIndex = [];
        }
    }

    // Exporta para o escopo global
    window.TEI_Map = TEI_Map;

    // Auto-inicialização para mapas com data-attributes
    document.addEventListener('DOMContentLoaded', function() {
        const maps = document.querySelectorAll('[data-tei-map]');
        maps.forEach(mapElement => {
            const config = JSON.parse(mapElement.dataset.teiMapConfig || '{}');
            const data = JSON.parse(mapElement.dataset.teiMapData || '{"features":[]}');
            new TEI_Map(mapElement.id, data, config);
        });
    });

})(window, document, window.L);
