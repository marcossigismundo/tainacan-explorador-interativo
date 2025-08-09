/**
 * Explorador Interativo - Timeline
 * Extensões e customizações para TimelineJS
 * 
 * @package TainacanExplorador
 * @version 1.0.0
 */

(function(window, document) {
    'use strict';

    /**
     * Classe para gerenciar timelines
     */
    class TEI_Timeline {
        constructor(elementId, data, config) {
            this.elementId = elementId;
            this.element = document.getElementById(elementId);
            this.data = data;
            this.config = config;
            this.timeline = null;
            this.currentSlide = 0;
            
            if (!this.element) {
                console.error('Timeline container not found:', elementId);
                return;
            }
            
            this.init();
        }

        /**
         * Inicializa a timeline
         */
        init() {
            // Aguarda TimelineJS carregar
            if (typeof TL === 'undefined' || !TL.Timeline) {
                setTimeout(() => this.init(), 100);
                return;
            }
            
            // Processa dados se necessário
            this.processData();
            
            // Cria a timeline
            this.createTimeline();
            
            // Configura eventos
            this.setupEvents();
            
            // Adiciona controles customizados
            this.addCustomControls();
            
            // Marca como carregada
            this.element.classList.add('tei-timeline-loaded');
        }

        /**
         * Processa dados para formato TimelineJS
         */
        processData() {
            // Adiciona IDs únicos se não existirem
            if (this.data.events) {
                this.data.events.forEach((event, index) => {
                    if (!event.unique_id) {
                        event.unique_id = 'tei-event-' + index;
                    }
                });
            }
            
            // Adiciona eras se configuradas
            if (this.config.eras) {
                this.data.eras = this.config.eras;
            }
        }

        /**
         * Cria a timeline
         */
        createTimeline() {
            try {
                // Configurações padrão do TimelineJS
                const options = {
                    hash_bookmark: this.config.hash_bookmark || false,
                    initial_zoom: this.config.initial_zoom || 2,
                    language: this.config.language || 'pt',
                    timenav_position: this.config.timenav_position || 'bottom',
                    optimal_tick_width: 100,
                    scale_factor: 2,
                    debug: this.config.debug || false,
                    timenav_height: 150,
                    timenav_height_percentage: 25,
                    marker_height_min: 30,
                    marker_width_min: 100,
                    marker_padding: 5,
                    start_at_slide: 0,
                    menubar_height: 0,
                    use_bc: true,
                    duration: this.config.duration || 500,
                    ease: this.config.ease || TL.Ease.easeInOutQuint,
                    dragging: this.config.dragging !== false,
                    trackResize: true,
                    slide_padding_lr: this.config.slide_padding_lr || 100,
                    slide_default_fade: this.config.slide_default_fade || '0%',
                    zoom_sequence: [0.5, 1, 2, 3, 5, 8, 13, 21, 34, 55, 89]
                };
                
                // Cria instância da timeline
                this.timeline = new TL.Timeline(this.elementId, this.data, options);
                
                // Armazena referência global
                window.TEI_Timelines = window.TEI_Timelines || {};
                window.TEI_Timelines[this.elementId] = this;
                
            } catch (error) {
                console.error('Error creating timeline:', error);
                this.showError('Erro ao criar linha do tempo');
            }
        }

        /**
         * Configura eventos
         */
        setupEvents() {
            if (!this.timeline) return;
            
            // Evento de mudança de slide
            this.timeline.on('change', (data) => {
                this.currentSlide = data.unique_id || 0;
                this.onSlideChange(data);
            });
            
            // Evento de carregamento
            this.timeline.on('loaded', () => {
                this.onLoaded();
            });
            
            // Evento de navegação
            this.timeline.on('nav_next', () => {
                this.trackEvent('navigation', 'next');
            });
            
            this.timeline.on('nav_previous', () => {
                this.trackEvent('navigation', 'previous');
            });
            
            // Keyboard navigation
            if (this.config.keyboard !== false) {
                document.addEventListener('keydown', (e) => this.handleKeyboard(e));
            }
            
            // Touch/swipe navigation
            if (this.config.touch !== false && 'ontouchstart' in window) {
                this.setupTouchEvents();
            }
        }

        /**
         * Adiciona controles customizados
         */
        addCustomControls() {
            const container = this.element.parentElement;
            
            // Adiciona barra de busca
            if (this.config.search !== false) {
                this.addSearchBar(container);
            }
            
            // Adiciona filtros
            if (this.config.filters && this.data.events) {
                this.addFilters(container);
            }
            
            // Adiciona zoom controls
            if (this.config.zoom_controls !== false) {
                this.addZoomControls(container);
            }
            
            // Adiciona botão de compartilhamento
            if (this.config.share !== false) {
                this.addShareButton(container);
            }
            
            // Adiciona contador de eventos
            if (this.config.event_counter !== false) {
                this.addEventCounter(container);
            }
        }

        /**
         * Adiciona barra de busca
         */
        addSearchBar(container) {
            const searchBar = document.createElement('div');
            searchBar.className = 'tei-timeline-search';
            searchBar.innerHTML = `
                <input type="text" 
                       class="tei-timeline-search-input" 
                       placeholder="Buscar na linha do tempo...">
                <button class="tei-timeline-search-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                    </svg>
                </button>
            `;
            
            container.insertBefore(searchBar, this.element);
            
            // Setup search functionality
            const input = searchBar.querySelector('.tei-timeline-search-input');
            const button = searchBar.querySelector('.tei-timeline-search-btn');
            
            const performSearch = () => {
                const query = input.value.toLowerCase().trim();
                this.searchEvents(query);
            };
            
            button.addEventListener('click', performSearch);
            input.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    performSearch();
                }
            });
            
            // Live search with debounce
            let searchTimeout;
            input.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.searchEvents(e.target.value.toLowerCase().trim());
                }, 300);
            });
        }

        /**
         * Busca eventos
         */
        searchEvents(query) {
            if (!query) {
                this.clearSearch();
                return;
            }
            
            const results = [];
            
            this.data.events.forEach((event, index) => {
                const headline = event.text?.headline || '';
                const text = event.text?.text || '';
                
                if (headline.toLowerCase().includes(query) || 
                    text.toLowerCase().includes(query)) {
                    results.push({
                        index: index,
                        event: event
                    });
                }
            });
            
            if (results.length > 0) {
                // Vai para o primeiro resultado
                this.timeline.goTo(results[0].index);
                
                // Mostra indicador de resultados
                this.showSearchResults(results);
            } else {
                this.showNoResults(query);
            }
        }

        /**
         * Limpa busca
         */
        clearSearch() {
            const highlights = this.element.querySelectorAll('.tei-search-highlight');
            highlights.forEach(el => el.classList.remove('tei-search-highlight'));
        }

        /**
         * Mostra resultados da busca
         */
        showSearchResults(results) {
            const message = `${results.length} resultado${results.length > 1 ? 's' : ''} encontrado${results.length > 1 ? 's' : ''}`;
            
            if (window.TEI && window.TEI.Toast) {
                window.TEI.Toast.info(message);
            }
            
            // Adiciona navegação entre resultados
            this.searchResults = results;
            this.currentSearchIndex = 0;
            this.addSearchNavigation();
        }

        /**
         * Adiciona navegação de busca
         */
        addSearchNavigation() {
            if (this.searchResults.length <= 1) return;
            
            const nav = document.createElement('div');
            nav.className = 'tei-timeline-search-nav';
            nav.innerHTML = `
                <button class="tei-search-prev" title="Resultado anterior">‹</button>
                <span class="tei-search-counter">
                    ${this.currentSearchIndex + 1} / ${this.searchResults.length}
                </span>
                <button class="tei-search-next" title="Próximo resultado">›</button>
            `;
            
            const searchBar = this.element.parentElement.querySelector('.tei-timeline-search');
            if (searchBar) {
                // Remove navegação anterior se existir
                const oldNav = searchBar.querySelector('.tei-timeline-search-nav');
                if (oldNav) oldNav.remove();
                
                searchBar.appendChild(nav);
                
                // Setup navigation
                nav.querySelector('.tei-search-prev').addEventListener('click', () => {
                    this.navigateSearchResults(-1);
                });
                
                nav.querySelector('.tei-search-next').addEventListener('click', () => {
                    this.navigateSearchResults(1);
                });
            }
        }

        /**
         * Navega entre resultados da busca
         */
        navigateSearchResults(direction) {
            this.currentSearchIndex += direction;
            
            if (this.currentSearchIndex < 0) {
                this.currentSearchIndex = this.searchResults.length - 1;
            } else if (this.currentSearchIndex >= this.searchResults.length) {
                this.currentSearchIndex = 0;
            }
            
            const result = this.searchResults[this.currentSearchIndex];
            this.timeline.goTo(result.index);
            
            // Atualiza contador
            const counter = this.element.parentElement.querySelector('.tei-search-counter');
            if (counter) {
                counter.textContent = `${this.currentSearchIndex + 1} / ${this.searchResults.length}`;
            }
        }

        /**
         * Mostra mensagem de sem resultados
         */
        showNoResults(query) {
            const message = `Nenhum resultado para "${query}"`;
            
            if (window.TEI && window.TEI.Toast) {
                window.TEI.Toast.warning(message);
            }
        }

        /**
         * Adiciona filtros
         */
        addFilters(container) {
            // Extrai categorias únicas
            const categories = new Set();
            this.data.events.forEach(event => {
                if (event.group) {
                    categories.add(event.group);
                }
            });
            
            if (categories.size === 0) return;
            
            const filterBar = document.createElement('div');
            filterBar.className = 'tei-timeline-filters';
            filterBar.innerHTML = `
                <label class="tei-filter-label">Filtrar por:</label>
                <select class="tei-timeline-filter-select">
                    <option value="">Todas as categorias</option>
                    ${Array.from(categories).map(cat => 
                        `<option value="${cat}">${cat}</option>`
                    ).join('')}
                </select>
            `;
            
            container.insertBefore(filterBar, this.element);
            
            // Setup filter functionality
            const select = filterBar.querySelector('.tei-timeline-filter-select');
            select.addEventListener('change', (e) => {
                this.filterByCategory(e.target.value);
            });
        }

        /**
         * Filtra por categoria
         */
        filterByCategory(category) {
            if (!category) {
                // Mostra todos os eventos
                this.showAllEvents();
                return;
            }
            
            // Filtra eventos
            const filteredEvents = this.data.events.filter(event => 
                event.group === category
            );
            
            if (filteredEvents.length > 0) {
                // Recria timeline com eventos filtrados
                const filteredData = {
                    ...this.data,
                    events: filteredEvents
                };
                
                // Destroi timeline atual
                if (this.timeline) {
                    this.element.innerHTML = '';
                }
                
                // Cria nova timeline
                this.timeline = new TL.Timeline(this.elementId, filteredData, this.config);
                
                // Mostra contador
                const message = `${filteredEvents.length} evento${filteredEvents.length > 1 ? 's' : ''} na categoria "${category}"`;
                if (window.TEI && window.TEI.Toast) {
                    window.TEI.Toast.info(message);
                }
            }
        }

        /**
         * Mostra todos os eventos
         */
        showAllEvents() {
            // Recria timeline com todos os eventos
            if (this.timeline) {
                this.element.innerHTML = '';
            }
            
            this.timeline = new TL.Timeline(this.elementId, this.data, this.config);
        }

        /**
         * Adiciona controles de zoom
         */
        addZoomControls(container) {
            const controls = document.createElement('div');
            controls.className = 'tei-timeline-zoom';
            controls.innerHTML = `
                <button class="tei-zoom-in" title="Aumentar zoom">+</button>
                <button class="tei-zoom-out" title="Diminuir zoom">-</button>
                <button class="tei-zoom-reset" title="Resetar zoom">⟲</button>
            `;
            
            container.appendChild(controls);
            
            // Setup zoom functionality
            controls.querySelector('.tei-zoom-in').addEventListener('click', () => {
                this.timeline.zoom_in();
            });
            
            controls.querySelector('.tei-zoom-out').addEventListener('click', () => {
                this.timeline.zoom_out();
            });
            
            controls.querySelector('.tei-zoom-reset').addEventListener('click', () => {
                this.timeline.setZoom(this.config.initial_zoom || 2);
            });
        }

        /**
         * Adiciona botão de compartilhamento
         */
        addShareButton(container) {
            const shareBtn = document.createElement('button');
            shareBtn.className = 'tei-timeline-share';
            shareBtn.innerHTML = `
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <circle cx="18" cy="5" r="3"/>
                    <circle cx="6" cy="12" r="3"/>
                    <circle cx="18" cy="19" r="3"/>
                    <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
                    <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                </svg>
                Compartilhar
            `;
            
            container.appendChild(shareBtn);
            
            shareBtn.addEventListener('click', () => {
                this.shareTimeline();
            });
        }

        /**
         * Compartilha timeline
         */
        shareTimeline() {
            const url = window.location.href;
            const title = this.data.title?.text?.headline || 'Timeline';
            
            if (navigator.share) {
                // Web Share API
                navigator.share({
                    title: title,
                    url: url
                }).catch(err => console.log('Share cancelled', err));
            } else {
                // Fallback - copia URL
                this.copyToClipboard(url);
                
                if (window.TEI && window.TEI.Toast) {
                    window.TEI.Toast.success('Link copiado para a área de transferência');
                }
            }
        }

        /**
         * Copia para clipboard
         */
        copyToClipboard(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }

        /**
         * Adiciona contador de eventos
         */
        addEventCounter(container) {
            const counter = document.createElement('div');
            counter.className = 'tei-timeline-counter';
            counter.innerHTML = `
                <span class="tei-counter-current">1</span>
                <span class="tei-counter-separator">/</span>
                <span class="tei-counter-total">${this.data.events.length}</span>
            `;
            
            container.appendChild(counter);
            this.eventCounter = counter;
        }

        /**
         * Atualiza contador
         */
        updateCounter(index) {
            if (this.eventCounter) {
                const current = this.eventCounter.querySelector('.tei-counter-current');
                if (current) {
                    current.textContent = index + 1;
                }
            }
        }

        /**
         * Setup touch events
         */
        setupTouchEvents() {
            let touchStartX = 0;
            let touchEndX = 0;
            
            this.element.addEventListener('touchstart', (e) => {
                touchStartX = e.changedTouches[0].screenX;
            });
            
            this.element.addEventListener('touchend', (e) => {
                touchEndX = e.changedTouches[0].screenX;
                this.handleSwipe(touchStartX, touchEndX);
            });
        }

        /**
         * Handle swipe
         */
        handleSwipe(startX, endX) {
            const threshold = 50;
            const diff = startX - endX;
            
            if (Math.abs(diff) > threshold) {
                if (diff > 0) {
                    // Swipe left - next
                    this.timeline.goToNext();
                } else {
                    // Swipe right - previous
                    this.timeline.goToPrevious();
                }
            }
        }

        /**
         * Handle keyboard navigation
         */
        handleKeyboard(e) {
            // Ignora se estiver digitando em input
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                return;
            }
            
            switch(e.key) {
                case 'ArrowLeft':
                    e.preventDefault();
                    this.timeline.goToPrevious();
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    this.timeline.goToNext();
                    break;
                case 'Home':
                    e.preventDefault();
                    this.timeline.goToStart();
                    break;
                case 'End':
                    e.preventDefault();
                    this.timeline.goToEnd();
                    break;
            }
        }

        /**
         * Evento de mudança de slide
         */
        onSlideChange(data) {
            // Atualiza contador
            if (this.data.events) {
                const index = this.data.events.findIndex(e => 
                    e.unique_id === data.unique_id
                );
                if (index !== -1) {
                    this.updateCounter(index);
                }
            }
            
            // Dispara evento customizado
            const event = new CustomEvent('tei:timeline:change', {
                detail: { 
                    slide: data,
                    index: this.currentSlide,
                    timeline: this
                },
                bubbles: true
            });
            this.element.dispatchEvent(event);
        }

        /**
         * Evento de carregamento
         */
        onLoaded() {
            // Remove loading indicator
            const loading = this.element.parentElement.querySelector('.tei-timeline-loading');
            if (loading) {
                loading.style.display = 'none';
            }
            
            // Dispara evento customizado
            const event = new CustomEvent('tei:timeline:loaded', {
                detail: { timeline: this },
                bubbles: true
            });
            this.element.dispatchEvent(event);
        }

        /**
         * Track event
         */
        trackEvent(category, action, label) {
            // Google Analytics
            if (typeof gtag !== 'undefined') {
                gtag('event', action, {
                    'event_category': 'Timeline',
                    'event_label': label || this.elementId
                });
            }
            
            // Custom tracking
            const event = new CustomEvent('tei:timeline:track', {
                detail: { category, action, label },
                bubbles: true
            });
            this.element.dispatchEvent(event);
        }

        /**
         * Mostra erro
         */
        showError(message) {
            this.element.innerHTML = `
                <div class="tei-timeline-error">
                    <p>${message}</p>
                </div>
            `;
        }

        /**
         * Destroi a timeline
         */
        destroy() {
            if (this.timeline) {
                this.timeline = null;
            }
            
            if (this.element) {
                this.element.innerHTML = '';
            }
            
            // Remove do registro global
            if (window.TEI_Timelines && window.TEI_Timelines[this.elementId]) {
                delete window.TEI_Timelines[this.elementId];
            }
        }
    }

    // Exporta para o escopo global
    window.TEI_Timeline = TEI_Timeline;

    // Auto-inicialização
    document.addEventListener('DOMContentLoaded', function() {
        const timelines = document.querySelectorAll('[data-tei-timeline]');
        timelines.forEach(element => {
            const config = JSON.parse(element.dataset.teiTimelineConfig || '{}');
            const data = JSON.parse(element.dataset.teiTimelineData || '{"events":[]}');
            new TEI_Timeline(element.id, data, config);
        });
    });

})(window, document);
