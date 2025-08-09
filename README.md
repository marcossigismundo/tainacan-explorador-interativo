=== Explorador Interativo para Tainacan ===
Contributors: seu-usuario
Donate link: https://exemplo.com/doar
Tags: tainacan, maps, timeline, storytelling, visualization, interactive, geocoding, museum, gallery, cultural heritage
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Crie visualizações interativas impressionantes para suas coleções do Tainacan com mapas, linhas do tempo e narrativas visuais.

== Description ==

O **Explorador Interativo para Tainacan** transforma suas coleções digitais em experiências visuais envolventes. Este plugin adiciona três poderosas visualizações ao Tainacan:

= 🗺️ Mapas Interativos =
* Visualize itens geograficamente com Leaflet.js
* Clustering automático de marcadores
* Geocodificação de endereços (Nominatim, Google Maps, Mapbox)
* Popups personalizados com imagens e descrições
* Busca e filtros em tempo real

= ⏰ Linhas do Tempo =
* Crie cronologias visuais com TimelineJS
* Suporte para diferentes formatos de data
* Navegação intuitiva por períodos
* Agrupamento por categorias
* Zoom e navegação fluidos

= 📖 Storytelling =
* Narrativas visuais imersivas com Scrollama.js
* Efeitos parallax e animações
* Navegação por capítulos
* Modo tela cheia
* Autoplay configurável

= Recursos Principais =

* **Interface Administrativa Moderna**: Painel React intuitivo para configuração
* **Cache Inteligente**: Sistema multicamadas para performance otimizada
* **REST API Completa**: Endpoints customizados para integrações
* **Responsivo**: Funciona perfeitamente em desktop, tablet e mobile
* **Internacionalização**: Pronto para tradução
* **Segurança**: Validação e sanitização em todos os pontos
* **Extensível**: Hooks e filtros para customização

= Requisitos =

* WordPress 6.0 ou superior
* PHP 7.4 ou superior
* Plugin Tainacan ativo e configurado
* Pelo menos uma coleção com metadados configurados

== Installation ==

= Instalação Automática =

1. No painel do WordPress, vá para **Plugins > Adicionar Novo**
2. Busque por "Explorador Interativo Tainacan"
3. Clique em **Instalar Agora** e depois **Ativar**
4. Vá para **Tainacan > Explorador Interativo** para configurar

= Instalação Manual =

1. Baixe o arquivo ZIP do plugin
2. Faça upload para `/wp-content/plugins/tainacan-explorador-interativo`
3. Ative o plugin através do menu **Plugins**
4. Configure em **Tainacan > Explorador Interativo**

= Configuração Inicial =

1. Selecione uma coleção do Tainacan
2. Mapeie os metadados para cada tipo de visualização:
   * **Mapa**: Campo de localização, título, descrição, imagem
   * **Timeline**: Campo de data, título, descrição, imagem
   * **Story**: Título, descrição, imagem, ordem
3. Salve as configurações
4. Use os shortcodes em suas páginas

== Frequently Asked Questions ==

= Como uso os shortcodes? =

Insira em qualquer página ou post:
* `[tainacan_explorador_mapa collection="123"]`
* `[tainacan_explorador_timeline collection="123"]`
* `[tainacan_explorador_story collection="123"]`

Substitua "123" pelo ID da sua coleção.

= Posso personalizar o visual? =

Sim! O plugin inclui:
* Classes CSS customizáveis
* Filtros WordPress para modificar output
* Configurações visuais no admin
* Suporte a temas child

= Como configuro a geocodificação? =

1. Vá para **Configurações** no plugin
2. Escolha o serviço (Nominatim é gratuito)
3. Para Google ou Mapbox, adicione sua API key
4. Teste com alguns endereços

= O plugin funciona com cache? =

Sim, possui sistema de cache inteligente:
* Cache de requisições API
* Cache de geocodificação
* Cache de visualizações
* Limpeza automática configurável

= Posso usar múltiplas visualizações na mesma página? =

Sim, você pode combinar diferentes visualizações e coleções na mesma página.

= Como faço backup dos mapeamentos? =

Use a ferramenta **Importar/Exportar** no admin para:
* Exportar configurações em JSON
* Importar configurações
* Migrar entre sites

== Screenshots ==

1. Painel administrativo com seleção de coleções
2. Configuração de mapeamento de metadados
3. Mapa interativo com clusters
4. Linha do tempo visual
5. Storytelling imersivo
6. Configurações e personalização
7. Visualização mobile responsiva
8. Modo tela cheia

== Changelog ==

= 1.0.0 - 2024-01-15 =
* Lançamento inicial
* Três visualizações: Mapa, Timeline, Storytelling
* Interface administrativa React
* Sistema de cache multicamadas
* REST API customizada
* Suporte a geocodificação
* Internacionalização completa

== Upgrade Notice ==

= 1.0.0 =
Primeira versão estável. Instale e comece a criar visualizações incríveis!

== Roadmap ==

Próximas funcionalidades planejadas:

* Visualização em grade/galeria
* Gráficos e estatísticas
* Exportação PDF
* Mais opções de customização
* Integração com redes sociais
* Modo offline
* Realidade aumentada

== Suporte ==

* **Documentação**: [GitHub Wiki](https://github.com/seu-usuario/tainacan-explorador)
* **Reportar Bugs**: [GitHub Issues](https://github.com/seu-usuario/tainacan-explorador/issues)
* **Fórum**: [WordPress.org Support](https://wordpress.org/support/plugin/tainacan-explorador-interativo)

== Créditos ==

Este plugin utiliza as seguintes bibliotecas open source:

* [Leaflet.js](https://leafletjs.com/) - Mapas interativos
* [TimelineJS](https://timeline.knightlab.com/) - Linhas do tempo
* [Scrollama.js](https://github.com/russellgoldenberg/scrollama) - Scroll storytelling
* [React](https://reactjs.org/) - Interface administrativa

== Privacy Policy ==

Este plugin:
* Não coleta dados pessoais
* Usa cookies apenas para funcionalidade (não tracking)
* Geocodificação é processada conforme política do serviço escolhido
* Dados ficam armazenados apenas no seu banco WordPress

== Contribuindo ==

Contribuições são bem-vindas! Veja nosso [guia de contribuição](https://github.com/seu-usuario/tainacan-explorador/CONTRIBUTING.md).

== Licença ==

Este plugin é software livre: você pode redistribuí-lo e/ou modificá-lo sob os termos da GNU General Public License conforme publicada pela Free Software Foundation, versão 3 ou posterior.
