/**
 * Explorador Interativo - JavaScript Comum
 * Funcionalidades compartilhadas entre visualizações
 * 
 * @package TainacanExplorador
 * @version 1.0.0
 */

(function(window, document) {
    'use strict';

    /**
     * Namespace principal do plugin
     */
    window.TEI = window.TEI || {};

    /**
     * Utilitários comuns
     */
    TEI.Utils = {
        /**
         * Debounce function
         */
        debounce: function(func, wait, immediate) {
            let timeout;
            return function() {
                const context = this, args = arguments;
                const later = function() {
                    timeout = null;
                    if (!immediate) func.apply(context, args);
                };
                const callNow = immediate && !timeout;
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
                if (callNow) func.apply(context, args);
            };
        },

        /**
         * Throttle function
         */
        throttle: function(func, limit) {
            let inThrottle;
            return function() {
                const args = arguments;
                const context = this;
                if (!inThrottle) {
                    func.apply(context, args);
                    inThrottle = true;
                    setTimeout(() => inThrottle = false, limit);
                }
            };
        },

        /**
         * Parse query string
         */
        parseQueryString: function(queryString) {
            const params = {};
            const queries = (queryString || document.location.search.substring(1)).split("&");
            
            for (let i = 0; i < queries.length; i++) {
                const temp = queries[i].split('=');
                if (temp[0]) {
                    params[temp[0]] = decodeURIComponent(temp[1] || '');
                }
            }
            
            return params;
        },

        /**
         * Format date
         */
        formatDate: function(date, format = 'DD/MM/YYYY') {
            const d = new Date(date);
            const day = String(d.getDate()).padStart(2, '0');
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const year = d.getFullYear();
            
            return format
                .replace('DD', day)
                .replace('MM', month)
                .replace('YYYY', year);
        },

        /**
         * Truncate text
         */
        truncate: function(str, length = 100, ending = '...') {
            if (str.length > length) {
                return str.substring(0, length - ending.length) + ending;
            }
            return str;
        },

        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        },

        /**
         * Deep merge objects
         */
        deepMerge: function(target, ...sources) {
            if (!sources.length) return target;
            const source = sources.shift();

            if (this.isObject(target) && this.isObject(source)) {
                for (const key in source) {
                    if (this.isObject(source[key])) {
                        if (!target[key]) Object.assign(target, { [key]: {} });
                        this.deepMerge(target[key], source[key]);
                    } else {
                        Object.assign(target, { [key]: source[key] });
                    }
                }
            }

            return this.deepMerge(target, ...sources);
        },

        /**
         * Check if object
         */
        isObject: function(item) {
            return item && typeof item === 'object' && !Array.isArray(item);
        },

        /**
         * Generate unique ID
         */
        uniqueId: function(prefix = 'tei-') {
            return prefix + Math.random().toString(36).substr(2, 9);
        },

        /**
         * Load script dynamically
         */
        loadScript: function(src, callback) {
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            
            script.onload = function() {
                if (callback) callback(null, script);
            };
            
            script.onerror = function() {
                if (callback) callback(new Error('Failed to load script: ' + src));
            };
            
            document.head.appendChild(script);
            return script;
        },

        /**
         * Load CSS dynamically
         */
        loadCSS: function(href, callback) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            
            link.onload = function() {
                if (callback) callback(null, link);
            };
            
            link.onerror = function() {
                if (callback) callback(new Error('Failed to load CSS: ' + href));
            };
            
            document.head.appendChild(link);
            return link;
        }
    };

    /**
     * API Client
     */
    TEI.API = {
        baseUrl: teiConfig ? teiConfig.apiUrl : '/wp-json/tainacan-explorador/v1/',
        nonce: teiConfig ? teiConfig.nonce : '',

        /**
         * Make API request
         */
        request: function(endpoint, options = {}) {
            const url = this.baseUrl + endpoint;
            
            const defaultOptions = {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.nonce
                }
            };
            
            const config = Object.assign({}, defaultOptions, options);
            
            if (config.body && typeof config.body === 'object') {
                config.body = JSON.stringify(config.body);
            }
            
            return fetch(url, config)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .catch(error => {
                    console.error('API Error:', error);
                    throw error;
                });
        },

        /**
         * GET request
         */
        get: function(endpoint, params = {}) {
            const queryString = new URLSearchParams(params).toString();
            const url = queryString ? `${endpoint}?${queryString}` : endpoint;
            return this.request(url);
        },

        /**
         * POST request
         */
        post: function(endpoint, data) {
            return this.request(endpoint, {
                method: 'POST',
                body: data
            });
        },

        /**
         * PUT request
         */
        put: function(endpoint, data) {
            return this.request(endpoint, {
                method: 'PUT',
                body: data
            });
        },

        /**
         * DELETE request
         */
        delete: function(endpoint) {
            return this.request(endpoint, {
                method: 'DELETE'
            });
        }
    };

    /**
     * Event Emitter
     */
    TEI.EventEmitter = function() {
        this.events = {};
    };

    TEI.EventEmitter.prototype = {
        on: function(event, listener) {
            if (!this.events[event]) {
                this.events[event] = [];
            }
            this.events[event].push(listener);
            return this;
        },

        off: function(event, listenerToRemove) {
            if (!this.events[event]) return this;
            
            this.events[event] = this.events[event].filter(listener => {
                return listener !== listenerToRemove;
            });
            
            return this;
        },

        emit: function(event, ...args) {
            if (!this.events[event]) return this;
            
            this.events[event].forEach(listener => {
                listener.apply(this, args);
            });
            
            return this;
        },

        once: function(event, listener) {
            const onceWrapper = (...args) => {
                listener.apply(this, args);
                this.off(event, onceWrapper);
            };
            this.on(event, onceWrapper);
            return this;
        }
    };

    /**
     * Loading Manager
     */
    TEI.LoadingManager = {
        show: function(container, message = 'Carregando...') {
            const loader = document.createElement('div');
            loader.className = 'tei-loading-overlay';
            loader.innerHTML = `
                <div class="tei-loading-content">
                    <div class="tei-spinner"></div>
                    <p>${TEI.Utils.escapeHtml(message)}</p>
                </div>
            `;
            
            if (typeof container === 'string') {
                container = document.querySelector(container);
            }
            
            if (container) {
                container.style.position = 'relative';
                container.appendChild(loader);
            }
            
            return loader;
        },

        hide: function(loader) {
            if (loader && loader.parentNode) {
                loader.parentNode.removeChild(loader);
            }
        }
    };

    /**
     * Toast Notifications
     */
    TEI.Toast = {
        container: null,

        init: function() {
            if (!this.container) {
                this.container = document.createElement('div');
                this.container.className = 'tei-toast-container';
                document.body.appendChild(this.container);
            }
        },

        show: function(message, type = 'info', duration = 3000) {
            this.init();
            
            const toast = document.createElement('div');
            toast.className = `tei-toast tei-toast-${type}`;
            
            const icon = this.getIcon(type);
            
            toast.innerHTML = `
                <div class="tei-toast-content">
                    ${icon}
                    <span>${TEI.Utils.escapeHtml(message)}</span>
                </div>
            `;
            
            this.container.appendChild(toast);
            
            // Animate in
            setTimeout(() => {
                toast.classList.add('tei-toast-show');
            }, 10);
            
            // Auto hide
            if (duration > 0) {
                setTimeout(() => {
                    this.hide(toast);
                }, duration);
            }
            
            return toast;
        },

        hide: function(toast) {
            toast.classList.remove('tei-toast-show');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        },

        getIcon: function(type) {
            const icons = {
                success: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 6L9 17l-5-5"/></svg>',
                error: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
                warning: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                info: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
            };
            
            return icons[type] || icons.info;
        },

        success: function(message, duration) {
            return this.show(message, 'success', duration);
        },

        error: function(message, duration) {
            return this.show(message, 'error', duration);
        },

        warning: function(message, duration) {
            return this.show(message, 'warning', duration);
        },

        info: function(message, duration) {
            return this.show(message, 'info', duration);
        }
    };

    /**
     * Modal Manager
     */
    TEI.Modal = {
        create: function(options = {}) {
            const defaults = {
                title: '',
                content: '',
                size: 'medium',
                closeButton: true,
                overlay: true,
                className: '',
                onOpen: null,
                onClose: null
            };
            
            const config = Object.assign({}, defaults, options);
            
            // Create modal elements
            const modal = document.createElement('div');
            modal.className = `tei-modal ${config.className}`;
            
            if (config.overlay) {
                const overlay = document.createElement('div');
                overlay.className = 'tei-modal-overlay';
                overlay.addEventListener('click', () => this.close(modal));
                modal.appendChild(overlay);
            }
            
            const dialog = document.createElement('div');
            dialog.className = `tei-modal-dialog tei-modal-${config.size}`;
            
            const content = document.createElement('div');
            content.className = 'tei-modal-content';
            
            if (config.title) {
                const header = document.createElement('div');
                header.className = 'tei-modal-header';
                header.innerHTML = `
                    <h3 class="tei-modal-title">${TEI.Utils.escapeHtml(config.title)}</h3>
                    ${config.closeButton ? '<button class="tei-modal-close">&times;</button>' : ''}
                `;
                content.appendChild(header);
                
                if (config.closeButton) {
                    header.querySelector('.tei-modal-close').addEventListener('click', () => {
                        this.close(modal);
                    });
                }
            }
            
            const body = document.createElement('div');
            body.className = 'tei-modal-body';
            body.innerHTML = config.content;
            content.appendChild(body);
            
            dialog.appendChild(content);
            modal.appendChild(dialog);
            
            // Add to DOM
            document.body.appendChild(modal);
            
            // Open callback
            if (config.onOpen) {
                config.onOpen(modal);
            }
            
            // Store close callback
            modal._onClose = config.onClose;
            
            // Animate in
            setTimeout(() => {
                modal.classList.add('tei-modal-open');
            }, 10);
            
            return modal;
        },

        close: function(modal) {
            modal.classList.remove('tei-modal-open');
            
            // Close callback
            if (modal._onClose) {
                modal._onClose(modal);
            }
            
            // Remove from DOM
            setTimeout(() => {
                if (modal.parentNode) {
                    modal.parentNode.removeChild(modal);
                }
            }, 300);
        }
    };

    /**
     * Lazy Loading
     */
    TEI.LazyLoad = {
        init: function(selector = '.tei-lazy') {
            const images = document.querySelectorAll(selector);
            
            if ('IntersectionObserver' in window) {
                const imageObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            this.loadImage(img);
                            imageObserver.unobserve(img);
                        }
                    });
                });
                
                images.forEach(img => imageObserver.observe(img));
            } else {
                // Fallback for older browsers
                images.forEach(img => this.loadImage(img));
            }
        },

        loadImage: function(img) {
            const src = img.dataset.src;
            if (src) {
                img.src = src;
                img.classList.add('tei-lazy-loaded');
                delete img.dataset.src;
            }
        }
    };

    /**
     * Initialize common features
     */
    TEI.init = function() {
        // Initialize lazy loading
        TEI.LazyLoad.init();
        
        // Setup global error handler
        window.addEventListener('error', function(e) {
            console.error('TEI Error:', e.error);
        });
        
        // Setup AJAX error handler for WordPress
        if (window.jQuery) {
            jQuery(document).ajaxError(function(event, jqXHR, ajaxSettings, thrownError) {
                console.error('TEI AJAX Error:', thrownError);
            });
        }
        
        // Emit ready event
        document.dispatchEvent(new CustomEvent('tei:ready'));
    };

    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', TEI.init);
    } else {
        TEI.init();
    }

})(window, document);
