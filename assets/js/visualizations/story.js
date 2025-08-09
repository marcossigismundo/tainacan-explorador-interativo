/**
 * Explorador Interativo - Storytelling
 * Classe para gerenciar narrativas visuais interativas
 * 
 * @package TainacanExplorador
 * @version 1.0.0
 */

(function(window, document) {
    'use strict';

    class TEI_Story {
        constructor(storyId, data, config) {
            this.storyId = storyId;
            this.container = document.querySelector(`[data-story-id="${storyId}"]`);
            this.wrapper = document.getElementById(storyId);
            this.data = data;
            this.config = config;
            this.currentChapter = 0;
            this.scroller = null;
            this.isAutoPlaying = false;
            this.autoPlayTimer = null;
            
            if (!this.container || !this.wrapper) {
                console.error('Story container not found:', storyId);
                return;
            }
            
            this.init();
        }

        init() {
            this.setupChapters();
            this.setupScrollama();
            this.setupNavigation();
            this.setupKeyboard();
            this.setupFullscreen();
            this.setupAutoplay();
            this.setupProgress();
            
            // Marca como carregado
            this.container.classList.add('tei-story-loaded');
            
            // Dispara evento
            this.trigger('loaded', { story: this });
        }

        setupChapters() {
            this.chapters = this.wrapper.querySelectorAll('.tei-story-chapter');
            this.totalChapters = this.chapters.length;
            
            // Adiciona índices aos elementos
            this.chapters.forEach((chapter, index) => {
                chapter.dataset.index = index;
                
                // Setup background parallax
                if (this.config.parallax) {
                    this.setupParallax(chapter);
                }
            });
        }

        setupScrollama() {
            if (typeof scrollama === 'undefined') {
                console.warn('Scrollama not loaded, falling back to scroll events');
                this.setupFallbackScroll();
                return;
            }
            
            // Inicializa Scrollama
            this.scroller = scrollama();
            
            this.scroller
                .setup({
                    step: '.tei-story-chapter',
                    offset: 0.5,
                    progress: true,
                    debug: false
                })
                .onStepEnter(response => {
                    this.onChapterEnter(response);
                })
                .onStepExit(response => {
                    this.onChapterExit(response);
                })
                .onStepProgress(response => {
                    this.onChapterProgress(response);
                });
            
            // Resize handler
            window.addEventListener('resize', () => {
                this.scroller.resize();
            });
        }

        setupFallbackScroll() {
            let ticking = false;
            
            const handleScroll = () => {
                if (!ticking) {
                    window.requestAnimationFrame(() => {
                        this.checkVisibleChapter();
                        ticking = false;
                    });
                    ticking = true;
                }
            };
            
            window.addEventListener('scroll', handleScroll);
        }

        checkVisibleChapter() {
            const windowHeight = window.innerHeight;
            const scrollTop = window.pageYOffset;
            const center = scrollTop + windowHeight / 2;
            
            this.chapters.forEach((chapter, index) => {
                const rect = chapter.getBoundingClientRect();
                const chapterTop = rect.top + scrollTop;
                const chapterBottom = chapterTop + rect.height;
                
                if (center >= chapterTop && center <= chapterBottom) {
                    if (this.currentChapter !== index) {
                        this.setCurrentChapter(index);
                    }
                }
            });
        }

        onChapterEnter(response) {
            const { element, index, direction } = response;
            
            element.classList.add('active');
            this.currentChapter = index;
            
            // Anima conteúdo
            this.animateChapterContent(element, 'enter');
            
            // Atualiza navegação
            this.updateNavigation(index);
            
            // Atualiza progresso
            this.updateProgress(index);
            
            // Para autoplay se estiver rolando manualmente
            if (this.isAutoPlaying && direction) {
                this.stopAutoplay();
            }
            
            this.trigger('chapter:enter', { 
                chapter: element, 
                index: index,
                data: this.data.chapters[index]
            });
        }

        onChapterExit(response) {
            const { element, index, direction } = response;
            
            element.classList.remove('active');
            
            // Anima saída
            this.animateChapterContent(element, 'exit');
            
            this.trigger('chapter:exit', { 
                chapter: element, 
                index: index,
                direction: direction
            });
        }

        onChapterProgress(response) {
            const { element, progress, index } = response;
            
            // Atualiza parallax se habilitado
            if (this.config.parallax) {
                this.updateParallax(element, progress);
            }
            
            this.trigger('chapter:progress', { 
                chapter: element, 
                index: index,
                progress: progress
            });
        }

        setupParallax(chapter) {
            const background = chapter.querySelector('.tei-story-background');
            if (!background) return;
            
            background.style.willChange = 'transform';
            background.dataset.parallax = 'true';
        }

        updateParallax(chapter, progress) {
            const background = chapter.querySelector('.tei-story-background[data-parallax="true"]');
            if (!background) return;
            
            const speed = 0.5;
            const yPos = -(progress * 100 * speed);
            background.style.transform = `translateY(${yPos}px)`;
        }

        animateChapterContent(chapter, type) {
            const content = chapter.querySelector('.tei-story-content-inner');
            if (!content) return;
            
            if (type === 'enter') {
                content.style.opacity = '0';
                content.style.transform = 'translateY(30px)';
                
                setTimeout(() => {
                    content.style.transition = `opacity ${this.config.transition_speed}ms ease, transform ${this.config.transition_speed}ms ease`;
                    content.style.opacity = '1';
                    content.style.transform = 'translateY(0)';
                }, 100);
            } else {
                content.style.opacity = '0.3';
            }
        }

        setupNavigation() {
            // Dots navigation
            if (this.config.navigation === 'dots') {
                this.setupDotsNavigation();
            }
            
            // Arrows navigation
            if (this.config.navigation === 'arrows') {
                this.setupArrowsNavigation();
            }
            
            // Touch/swipe navigation
            if (this.config.touch) {
                this.setupTouchNavigation();
            }
        }

        setupDotsNavigation() {
            const dots = this.container.querySelectorAll('.tei-story-dot');
            
            dots.forEach((dot, index) => {
                dot.addEventListener('click', () => {
                    this.goToChapter(index);
                });
            });
        }

        setupArrowsNavigation() {
            const prevBtn = this.container.querySelector('.tei-story-prev');
            const nextBtn = this.container.querySelector('.tei-story-next');
            
            if (prevBtn) {
                prevBtn.addEventListener('click', () => this.previousChapter());
            }
            
            if (nextBtn) {
                nextBtn.addEventListener('click', () => this.nextChapter());
            }
        }

        setupTouchNavigation() {
            let touchStartY = 0;
            let touchEndY = 0;
            
            this.wrapper.addEventListener('touchstart', (e) => {
                touchStartY = e.changedTouches[0].screenY;
            }, { passive: true });
            
            this.wrapper.addEventListener('touchend', (e) => {
                touchEndY = e.changedTouches[0].screenY;
                this.handleSwipe(touchStartY, touchEndY);
            }, { passive: true });
        }

        handleSwipe(startY, endY) {
            const threshold = 50;
            const diff = startY - endY;
            
            if (Math.abs(diff) > threshold) {
                if (diff > 0) {
                    // Swipe up - next chapter
                    this.nextChapter();
                } else {
                    // Swipe down - previous chapter
                    this.previousChapter();
                }
            }
        }

        setupKeyboard() {
            if (!this.config.keyboard) return;
            
            document.addEventListener('keydown', (e) => {
                // Ignora se estiver digitando
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                    return;
                }
                
                switch(e.key) {
                    case 'ArrowDown':
                    case 'PageDown':
                    case ' ':
                        e.preventDefault();
                        this.nextChapter();
                        break;
                    case 'ArrowUp':
                    case 'PageUp':
                        e.preventDefault();
                        this.previousChapter();
                        break;
                    case 'Home':
                        e.preventDefault();
                        this.goToChapter(0);
                        break;
                    case 'End':
                        e.preventDefault();
                        this.goToChapter(this.totalChapters - 1);
                        break;
                    case 'Escape':
                        if (document.fullscreenElement) {
                            this.exitFullscreen();
                        }
                        break;
                }
            });
        }

        setupFullscreen() {
            if (!this.config.fullscreen) return;
            
            const btn = this.container.querySelector('.tei-story-fullscreen');
            if (!btn) return;
            
            btn.addEventListener('click', () => {
                this.toggleFullscreen();
            });
            
            // Eventos de fullscreen
            document.addEventListener('fullscreenchange', () => {
                if (document.fullscreenElement) {
                    this.container.classList.add('tei-story-fullscreen-active');
                } else {
                    this.container.classList.remove('tei-story-fullscreen-active');
                }
            });
        }

        toggleFullscreen() {
            if (!document.fullscreenElement) {
                this.container.requestFullscreen().catch(err => {
                    console.error('Error entering fullscreen:', err);
                });
            } else {
                document.exitFullscreen();
            }
        }

        exitFullscreen() {
            if (document.fullscreenElement) {
                document.exitFullscreen();
            }
        }

        setupAutoplay() {
            if (!this.config.autoplay) return;
            
            // Inicia autoplay
            this.startAutoplay();
            
            // Para ao interagir
            this.container.addEventListener('click', () => {
                if (this.isAutoPlaying) {
                    this.stopAutoplay();
                }
            });
            
            // Para ao rolar manualmente
            let scrollTimeout;
            window.addEventListener('wheel', () => {
                if (this.isAutoPlaying) {
                    clearTimeout(scrollTimeout);
                    scrollTimeout = setTimeout(() => {
                        this.stopAutoplay();
                    }, 150);
                }
            }, { passive: true });
        }

        startAutoplay() {
            this.isAutoPlaying = true;
            this.container.classList.add('tei-story-autoplay');
            
            const advance = () => {
                if (!this.isAutoPlaying) return;
                
                if (this.currentChapter < this.totalChapters - 1) {
                    this.nextChapter();
                    this.autoPlayTimer = setTimeout(advance, this.config.autoplay_speed);
                } else if (this.config.loop) {
                    this.goToChapter(0);
                    this.autoPlayTimer = setTimeout(advance, this.config.autoplay_speed);
                } else {
                    this.stopAutoplay();
                }
            };
            
            this.autoPlayTimer = setTimeout(advance, this.config.autoplay_speed);
        }

        stopAutoplay() {
            this.isAutoPlaying = false;
            this.container.classList.remove('tei-story-autoplay');
            
            if (this.autoPlayTimer) {
                clearTimeout(this.autoPlayTimer);
                this.autoPlayTimer = null;
            }
        }

        setupProgress() {
            if (!this.config.progress) return;
            
            this.progressBar = this.container.querySelector('.tei-story-progress-bar');
        }

        updateProgress(index) {
            if (!this.progressBar) return;
            
            const progress = ((index + 1) / this.totalChapters) * 100;
            this.progressBar.style.width = `${progress}%`;
        }

        goToChapter(index) {
            if (index < 0 || index >= this.totalChapters) return;
            
            const chapter = this.chapters[index];
            if (!chapter) return;
            
            // Smooth scroll to chapter
            const top = chapter.offsetTop;
            
            window.scrollTo({
                top: top,
                behavior: 'smooth'
            });
            
            this.currentChapter = index;
            this.updateNavigation(index);
            this.updateProgress(index);
        }

        nextChapter() {
            const next = this.currentChapter + 1;
            if (next < this.totalChapters) {
                this.goToChapter(next);
            } else if (this.config.loop) {
                this.goToChapter(0);
            }
        }

        previousChapter() {
            const prev = this.currentChapter - 1;
            if (prev >= 0) {
                this.goToChapter(prev);
            } else if (this.config.loop) {
                this.goToChapter(this.totalChapters - 1);
            }
        }

        setCurrentChapter(index) {
            this.currentChapter = index;
            this.updateNavigation(index);
            this.updateProgress(index);
            
            // Marca capítulo ativo
            this.chapters.forEach((chapter, i) => {
                if (i === index) {
                    chapter.classList.add('active');
                } else {
                    chapter.classList.remove('active');
                }
            });
        }

        updateNavigation(index) {
            // Atualiza dots
            const dots = this.container.querySelectorAll('.tei-story-dot');
            dots.forEach((dot, i) => {
                if (i === index) {
                    dot.classList.add('active');
                } else {
                    dot.classList.remove('active');
                }
            });
            
            // Atualiza arrows
            const prevBtn = this.container.querySelector('.tei-story-prev');
            const nextBtn = this.container.querySelector('.tei-story-next');
            
            if (prevBtn) {
                prevBtn.disabled = (index === 0 && !this.config.loop);
            }
            
            if (nextBtn) {
                nextBtn.disabled = (index === this.totalChapters - 1 && !this.config.loop);
            }
        }

        trigger(eventName, data) {
            const event = new CustomEvent(`tei:story:${eventName}`, {
                detail: data,
                bubbles: true
            });
            this.container.dispatchEvent(event);
        }

        destroy() {
            // Remove scrollama
            if (this.scroller) {
                this.scroller.destroy();
            }
            
            // Para autoplay
            this.stopAutoplay();
            
            // Remove event listeners
            // (implementar conforme necessário)
            
            // Limpa elementos
            this.container.classList.remove('tei-story-loaded');
        }
    }

    // Exporta para escopo global
    window.TEI_Story = TEI_Story;

    // Auto-inicialização
    document.addEventListener('DOMContentLoaded', function() {
        const stories = document.querySelectorAll('[data-tei-story]');
        stories.forEach(element => {
            const config = JSON.parse(element.dataset.teiStoryConfig || '{}');
            const data = JSON.parse(element.dataset.teiStoryData || '{}');
            new TEI_Story(element.dataset.storyId, data, config);
        });
    });

})(window, document);
