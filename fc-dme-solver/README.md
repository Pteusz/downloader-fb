# FC DME Solver - Plugin WordPress

Plugin WordPress para exibir SBCs (Squad Building Challenges) do EA FC com sistema de resolver desafios DME.

## 📋 Características

- ✅ Exibe jogadores renderizados e recompensas
- ✅ Toggle Console (PS/Xbox) vs PC para preços
- ✅ Botão "Resolver DME" com popup de desafios
- ✅ Design moderno e responsivo
- ✅ Sistema de cache configurável
- ✅ Integração com FC Card Renderer (opcional)

## 📁 Estrutura

```
fc-dme-solver/
├── fc-dme-solver.php          # Arquivo principal do plugin
├── includes/
│   ├── class-settings.php     # Página de configurações
│   ├── class-api.php          # Handler da API
│   └── class-shortcode.php    # Renderização do shortcode
├── assets/
│   ├── css/
│   │   └── style.css          # Estilos
│   └── js/
│       └── script.js          # JavaScript
└── README.md
```

## 🚀 Instalação

1. Faça upload da pasta `fc-dme-solver/` para `/wp-content/plugins/`
2. Ative o plugin no WordPress admin
3. Configure em **Configurações > FC DME Solver**
4. Use o shortcode `[fc_dme_solver]` em qualquer página

## ⚙️ Configuração

### Painel Admin

Acesse **Configurações > FC DME Solver** para:

- **API Endpoint**: URL da API de SBCs (padrão: https://dmescrapy.chamax1.com.br/api/sbcs)
- **Cache TTL**: Tempo de cache em segundos (30-3600)
- **Plataforma Padrão**: Console ou PC

### Shortcode

```
[fc_dme_solver]
```

**Parâmetros:**

- `limit="80"` - Número máximo de SBCs (1-300)
- `only="all"` - Filtro: `all` | `players` | `rewards`

**Exemplos:**

```
[fc_dme_solver limit="50"]
[fc_dme_solver only="players"]
[fc_dme_solver limit="30" only="rewards"]
```

## 🎮 Funcionalidades

### 1. Toggle de Plataforma

Barra superior com botões **Console** e **PC**:
- Console = preços PS/Xbox (`price_ps`)
- PC = preços PC (`price_pc`)

### 2. Cards de SBCs

Cada card exibe:
- **Jogadores**: Renderizados via FC Card Renderer (se disponível) ou preview simples
- **Recompensas**: Imagem + nome da recompensa
- **Preço**: Atualizado dinamicamente baseado na plataforma selecionada
- **Botão "Resolver DME"**: Abre popup com desafios

### 3. Modal de Desafios

Popup que mostra:
- Nome do SBC
- Lista de desafios (challenges)
- Preços por desafio (Console/PC)
- Requisitos de cada desafio

## 🔌 Dependências Opcionais

### FC Card Renderer

Para renderização completa de jogadores, instale o plugin **FC Card Renderer** que fornece:

- `FC_Card_Normalizer`
- `FC_Card_Visual_Renderer`

**Fallback**: Sem o renderer, jogadores são exibidos em formato simplificado (foto + rating + nome).

## 📊 Formato de Dados da API

O plugin espera a API retornar um array de SBCs no formato:

```json
[
  {
    "id": "12345",
    "name": "Mbappé TOTY",
    "is_player": true,
    "price_ps": "125.5K",
    "price_pc": "180K",
    "player_details": {
      "name": "Kylian Mbappé",
      "rating": "91",
      "position": "ST",
      "images": {
        "face": "url",
        "bg": "url"
      }
    },
    "challenges": [
      {
        "name": "Born Legend",
        "price_ps": "25K",
        "price_pc": "35K",
        "requirements": [
          "Min. Team Rating: 84",
          "Exactly 11 Gold Players"
        ]
      }
    ]
  }
]
```

## 🎨 Personalização

### CSS

Edite `/assets/css/style.css` para customizar cores, tamanhos e espaçamentos.

**Principais variáveis:**

- Cards: `.fc-dme-card`
- Toggle: `.fc-dme-platform-toggle`
- Modal: `.fc-dme-modal`
- Botão resolver: `.fc-dme-solve-btn`

### JavaScript

Edite `/assets/js/script.js` para modificar comportamentos como:

- Lógica de preços
- Abertura/fechamento de modal
- Atualização de cache

## 🔄 Cache

O plugin usa WordPress Transients para cache:

- Chave: `fc_dme_sbcs_cache`
- TTL configurável (30-3600 segundos)
- Botão manual de refresh (admin)

## 🐛 Debug

Para verificar erros:

1. Ative **WP_DEBUG** no `wp-config.php`
2. Verifique console do navegador (F12)
3. Teste endpoint da API diretamente

## 📝 Changelog

### Version 1.1.0 - MODAL RENOVADO
- ✅ **Layout em grid**: 3 colunas (desktop) / 2 colunas (mobile)
- ✅ **Cards simplificados**: Apenas imagem + nome + preço
- ✅ **Design modernizado**: Seguindo padrão visual das referências
- ✅ **Cabeçalho melhorado**: Contador de desafios + total automático

### Version 1.0.1 - CORREÇÕES
- ✅ **Renderização corrigida**: Usa FC Card Renderer igual ao plugin de referência
- ✅ **Imagens dos desafios**: Agora exibe a imagem de cada challenge no modal
- ✅ **Toggle universal**: Console/PC agora é regra universal (não duplica preços)

### Version 1.0.0
- Lançamento inicial
- Sistema de toggle Console/PC
- Modal de desafios
- Integração com FC Card Renderer
- Cache configurável

## 👨‍💻 Autor

DME Team

## 📄 Licença

Este plugin é fornecido "como está" sem garantias.
