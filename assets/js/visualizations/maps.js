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
                this
