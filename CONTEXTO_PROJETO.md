# CONTEXTO DO PROJETO — FC Player Downloader
> Documento gerado para continuidade em novo chat.
> Data: 03/06/2026

---

## 1. VISÃO GERAL DO PROJETO

Sistema de download e gestão de jogadores FUT (FC 26) para WordPress.

**Fluxo completo:**
1. Scraper Android/WireGuard captura jogadores do Futbin via dispositivo real
2. VPS expõe os dados via API nginx
3. Plugin WordPress (`[player_downloader]`) exibe grid de squads, permite disparar scrapes e renderiza os cards usando o FC Card Renderer
4. Puppeteer (headless Chromium) converte os cards HTML em PNG e entrega ZIP para download

---

## 2. INFRAESTRUTURA — VPS DO SCRAPER

**Servidor:** `srv873347` (diferente do servidor WordPress)
**Domínio público:** `https://mobalfutbin.chamacoins.com.br`
**Stack:** Docker + Traefik + nginx

### Diretório principal
```
/opt/scraper-mobile-geral/futbin-scraper/
├── core/
│   ├── player_parser.py     ← parser do HTML do Futbin
│   ├── sender.py            ← salva result.json + result_{label}.json
│   └── job_queue.py
├── modules/
│   └── squads.py
├── scraper-api/
│   ├── Dockerfile           ← docker:24-cli + python3
│   └── server.py            ← HTTP server para disparar scrapes
├── puppeteer-svc/
│   ├── Dockerfile           ← node:20-slim + chromium + fonts-noto
│   ├── package.json
│   └── server.js            ← render-batch endpoint
├── nginx.conf
├── docker-compose.yml
└── runner.py
```

### Dados
```
/opt/scraper-mobile-geral/dispatcher/data/
├── squads_index.json        ← índice de todos os squads scrapeados
├── result.json              ← último scrape (backward compat)
├── result_{label}.json      ← por squad (ex: result_UCLWinners.json)
└── status_{label}.json      ← status do scrape em andamento
```

### Containers Docker rodando
| Container | Imagem | Função |
|---|---|---|
| `futbin-mob-api` | nginx:alpine | Serve JSON + proxy para scraper-api e puppeteer |
| `futbin-scraper-api` | futbin-scraper_scraper-api | Dispara scrapes via HTTP |
| `futbin-puppeteer` | futbin-puppeteer | Renderiza cards em PNG |
| `scraper` | build local | Roda sob demanda via docker run |

**ATENÇÃO:** O `futbin-scraper-api` e `futbin-puppeteer` foram criados via `docker run` direto (não docker-compose up) devido a um bug do docker-compose v1.29.2 com imagens modernas (KeyError: 'ContainerConfig').

### Endpoints da API
| Endpoint | Método | Descrição |
|---|---|---|
| `/squads` | GET | Lista todos os squads (squads_index.json) |
| `/squad/{label}` | GET | Dados de um squad (result_{label}.json) |
| `/run-scrape` | POST | Dispara scrape `{"label":"...", "url":"..."}` |
| `/status/{label}` | GET | Status do scrape em andamento |
| `/render-batch` | POST | Renderiza cards em PNG via Puppeteer |
| `/health` | GET | Health check |

---

## 3. REPOSITÓRIO GIT

`git@github.com:Pteusz/downloader-fb.git`

```
downloader-fb/
├── vps/
│   ├── docker-compose.yml    ← versão de referência (deploy via docker run)
│   ├── nginx.conf            ← configuração atual com todos os proxy_pass
│   ├── scraper-api/
│   │   ├── Dockerfile
│   │   └── server.py
│   └── player_parser.py      ← versão corrigida com svg_raw nos playstyles
├── puppeteer-svc/
│   ├── Dockerfile
│   ├── package.json
│   └── server.js
└── wp-plugin/
    ├── downloader-fb.php
    ├── includes/
    │   ├── class-vps-api.php
    │   ├── class-png-builder.php
    │   └── ajax-handlers.php
    └── assets/
        ├── css/downloader.css
        └── js/app.js
```

---

## 4. PLUGIN WORDPRESS

**Shortcode:** `[player_downloader]`
**Caminho:** `/var/www/html/wp-content/plugins/downloader-fb/`
**Dependência obrigatória:** plugin `fc-card-renderer` instalado e ativo

### Constantes (downloader-fb.php)
```php
FC_DL_API_BASE        = 'https://mobalfutbin.chamacoins.com.br'
FC_DL_PUPPETEER_TOKEN = getenv('FC_TOKEN') ?: ''
```

### Telas do plugin

**Tela 1 — Grid de squads**
- Busca `GET /squads`, verifica cada `/squad/{label}` para status loaded
- Cards com bg_image, nome, data, badge de jogadores
- Botão Atualizar

**Tela 2 — Detalhe do squad**
- Se não carregado: botão "Carregar Jogadores" → `POST /run-scrape` → polling `GET /status/{label}` a cada 4s
- Se carregado: grid de cards renderizados com `FC_Card_Visual_Renderer::render_card()` + checkboxes
- Botões: Selecionar Todos, Gerar PNG

**Tela 3 — Geração de PNG**
- Clique em "Gerar PNG" → AJAX `fc_dl_generate_png`
- PHP renderiza HTMLs dos cards selecionados → POST `/render-batch` → recebe ZIP
- Salva em `wp-content/uploads/fc-exports/`
- Botão de download aparece com tamanho do arquivo

### AJAX handlers
| Action | Função |
|---|---|
| `fc_dl_get_squads` | Lista squads com status |
| `fc_dl_get_squad` | Retorna players com card HTML renderizado |
| `fc_dl_run_scrape` | Dispara scrape na VPS |
| `fc_dl_scrape_status` | Consulta status do scrape |
| `fc_dl_generate_png` | Gera PNGs e retorna URL do ZIP |

### WP Rocket
Filtros adicionados em `downloader-fb.php`:
- `rocket_exclude_js` e `rocket_exclude_css`: excluem app.js e downloader.css
- `do_rocket_generate_caching_files`: false em páginas com `[player_downloader]`
- `nocache_headers()` em todas as respostas AJAX

---

## 5. PUPPETEER SERVICE

**Container:** `futbin-puppeteer`
**Porta interna:** 3000
**Imagem:** node:20-slim + chromium + fonts-liberation + fonts-noto + fonts-noto-cjk

### Endpoint POST /render-batch
```json
{
  "width": 400,
  "players": [
    { "html": "<div class='fc-player-card'>...</div>", "css": "...", "name": "Marquinhos" }
  ]
}
```
Retorna: `application/zip` com `{nome}.png` por jogador.

### Autenticação
Header `X-FC-Token` com valor da env `FC_TOKEN` (pode ser vazio em dev).

---

## 6. PROBLEMAS ENFRENTADOS E SOLUÇÕES

### 6.1 nginx.conf não recarregava após edição
**Causa:** `cat >` cria novo inode; processo nginx mantinha o antigo file descriptor.
**Solução:** `docker restart futbin-mob-api` (restart completo, não reload).

### 6.2 `location ~ ^/squad/` com `alias $1` não funcionava
**Causa:** nginx alpine rejeita `alias` com capture groups em location regex.
**Solução:** Usar `try_files /data/result_$1.json @squad_not_found` com `root`.

### 6.3 `docker-compose up` quebrava com KeyError: 'ContainerConfig'
**Causa:** docker-compose v1.29.2 incompatível com imagens Docker modernas (sem `ContainerConfig` no manifest).
**Solução:** Criar containers via `docker run` diretamente, evitando o `--force-recreate`.

### 6.4 runner.py exigia `--ajax` e `--nonce` obrigatórios
**Causa:** Verificação no runner.py antes de chamar o módulo.
**Solução:** Passar `--ajax "http://localhost" --nonce "local"` como placeholders. O `sender.py` já absorve via `**kwargs` sem usar.

### 6.5 `/squads` retornando 404 mesmo após nginx correto
**Causa:** Mesmo problema de inode — nginx reload usava config antiga em memória.
**Solução:** `docker restart futbin-mob-api`.

### 6.6 `alt_sidebar` com posições alternativas não renderizando
**Causa:** Scraper salva `alt_sidebar.positions` na raiz, mas `FC_Card_Visual_Renderer` espera `alt_sidebar.right.positions`.
**Solução:** Função `fc_dl_adapt_player()` no `ajax-handlers.php` que wrapa antes do `normalize()`:
```php
$p['alt_sidebar'] = [
    'right' => [
        'positions' => $p['alt_sidebar']['positions'] ?? [],
        'css_vars'  => $p['alt_sidebar']['css_vars']  ?? [],
    ],
];
```

### 6.7 Playstyle SVGs não renderizando (caixas vazias na esquerda)
**Causa:** `player_parser.py` captura atributos do SVG (class, style, viewBox) mas NÃO o markup interno (`svg_raw`).
**Solução:** Adicionar `"svg_raw": str(svg)` no dict de cada item em `_parse_playstyles()`.
**Status:** Fix preparado em `vps/player_parser.py` no repo. Após aplicar, rebuild do scraper + re-scrape necessário.
```bash
# Aplicar na VPS real:
cp vps/player_parser.py /opt/scraper-mobile-geral/futbin-scraper/core/player_parser.py
docker-compose build scraper
# Depois re-scrape os squads
```

### 6.8 WP Rocket cacheando assets desatualizados
**Solução:**
- Assets enqueued com `filemtime()` como versão
- Filtros `rocket_exclude_js/css` adicionados
- `nocache_headers()` em todas as respostas AJAX

---

## 7. PROBLEMA ATUAL NÃO RESOLVIDO

### Puppeteer: proporções e fontes diferentes do frontend

**Sintoma:** PNGs gerados pelo Puppeteer têm proporções ligeiramente distorcidas e fontes diferentes do que o card aparece no browser WordPress.

**Causa identificada:**
1. A CSS variable `--cardWidthPx` não estava sendo setada no HTML enviado ao Puppeteer, causando distorção na `height: calc(var(--cardWidthPx) * 1.38888889)` da imagem de fundo.
2. Docker usa fontes do sistema Linux (Liberation Sans) vs. browser do usuário (system fonts do OS).

**Última tentativa de correção (não aplicada/não testada):**

Em `puppeteer-svc/server.js`:
```javascript
// Seta a CSS var explicitamente
`:root { --cardWidthPx: ${w}; }`

// Card com tamanho fixo garantido
`.fc-player-card { width: ${w}px !important; max-width: none !important; }`

// Screenshot com clip exato do boundingBox (sem padding extra)
const box = await el.boundingBox();
const png = await page.screenshot({
    type: 'png',
    omitBackground: true,
    clip: {
        x: Math.floor(box.x), y: Math.floor(box.y),
        width: Math.ceil(box.width), height: Math.ceil(box.height),
    },
});
```

Em `puppeteer-svc/Dockerfile`:
```dockerfile
# Adicionado fonts-noto (conjunto completo) + fonts-noto-cjk
RUN apt-get install -y fonts-liberation fonts-noto fonts-noto-color-emoji fonts-noto-cjk
```

**A atualização foi commitada** (`commit 67638f1`) mas **ainda não foi aplicada na VPS** (rebuild pendente).

**Próximos passos para resolver:**
1. Atualizar os arquivos na VPS via curl do raw.githubusercontent.com
2. `docker stop futbin-puppeteer && docker rm futbin-puppeteer`
3. `docker build -t futbin-puppeteer ./puppeteer-svc`
4. `docker run -d --name futbin-puppeteer --restart unless-stopped --network web --shm-size=256m -e FC_TOKEN="" -e CONCURRENCY=3 futbin-puppeteer`
5. Testar gerando PNG de 1 jogador e comparar com frontend

**Se o problema persistir após o rebuild:**
- Investigar se as fontes do card são web fonts carregadas de CDN externo (verificar `get_card_css()` no fc-card-renderer)
- Considerar usar `page.evaluateHandle` para inspecionar computed styles
- Considerar `waitForFunction` com timeout para aguardar carregamento de fontes antes do screenshot: `await page.evaluateHandle(() => document.fonts.ready)`
- Se for questão de fonte específica, pode ser necessário usar `@import url(https://fonts.googleapis.com/...)` no HTML do Puppeteer

---

## 8. ESTRUTURA DO DADO DO JOGADOR (result_{label}.json)

```json
{
  "meta": {
    "timestamp": "2026-06-03T16:21:09Z",
    "label": "UCLWinners",
    "total": 11
  },
  "data": [
    {
      "type": "jogador",
      "player": {
        "name": "Marquinhos",
        "rating": "96",
        "position": "CB",
        "role_plus": "...",
        "images": { "bg": "https://cdn3.futbin.com/...", "face": null },
        "stats": { "PAC": "91", "SHO": "66", "PAS": "86", "DRI": "87", "DEF": "96", "PHY": "91" },
        "info": {
          "nation": { "title": "Brazil", "src": "https://cdn3.futbin.com/..." },
          "club":   { "title": "Paris SG", "src": "https://cdn3.futbin.com/..." },
          "league": { "title": "Ligue 1", "src": "https://cdn3.futbin.com/..." }
        },
        "card_css_vars": { "cardColor": "#ffffff", "lineColor": "#ffffff", "ratingColor": "#ffffff" },
        "alt_sidebar": {
          "positions": [{ "pos": "CDM", "plus_plus": false }],
          "css_vars": { "alt-pos-background": "#02249C", "alt-pos-border": "#ffffff" }
        },
        "playstyles": {
          "count": 3,
          "classes": ["playstylePlusDiamond null", ...],
          "items": [
            {
              "class": "playstylePlusDiamond null",
              "style_raw": "--diamondBackgroundColor: #02249C;...",
              "css_vars": { "diamondBackgroundColor": "#02249C", "diamondForegroundColor": "#ffffff" },
              "viewBox": "0 0 560 560"
              // "svg_raw" AUSENTE — fix pendente no player_parser.py
            }
          ]
        },
        "extra_info": {
          "foot": "L", "skill_moves": 5, "weak_foot": 5, "futbin_rating": "92.4",
          "css_vars": { "extra-info-bg": "#02249C", "extra-info-border": "#ffffff" }
        }
      }
    }
  ]
}
```

---

## 9. SQUADS INDEX (squads_index.json)

```json
[
  {
    "name": "UCL Winners",
    "url": "https://www.futbin.com/26/totw/UCLWinners",
    "created": "02.06.2026",
    "bg_image": "https://cdn3.futbin.com/..."
  }
]
```

**Label** = último segmento da URL = `UCLWinners`

---

## 10. COMO DISPARAR UM SCRAPE MANUALMENTE

```bash
# Na VPS, dentro do container scraper-api ou via docker run:
docker run --rm \
  --network host \
  -v /opt/scraper-mobile-geral/dispatcher/data:/data \
  futbin-scraper_scraper \
  python runner.py \
    --module squads \
    --op scrape \
    --url "https://www.futbin.com/26/totw/UCLWinners" \
    --ajax "http://localhost" \
    --nonce "local"
```

---

## 11. COMANDOS ÚTEIS NA VPS

```bash
# Ver containers rodando
docker ps --format "table {{.Names}}\t{{.Status}}"

# Logs do scraper-api
docker logs futbin-scraper-api --tail 20

# Logs do puppeteer
docker logs futbin-puppeteer --tail 20

# Restartar nginx (após mudar nginx.conf)
docker restart futbin-mob-api

# Testar endpoints
curl https://mobalfutbin.chamacoins.com.br/squads | python3 -m json.tool | head -10
curl https://mobalfutbin.chamacoins.com.br/squad/UCLWinners | python3 -m json.tool | head -5
curl https://mobalfutbin.chamacoins.com.br/health
curl -X POST https://mobalfutbin.chamacoins.com.br/run-scrape \
  -H "Content-Type: application/json" \
  -d '{"label":"UCLWinners","url":"https://www.futbin.com/26/totw/UCLWinners"}'
```

---

## 12. NOTA SOBRE O FC CARD RENDERER

Plugin WordPress separado (`fc-card-renderer`) já instalado no servidor.

**Uso no downloader-fb:**
```php
// Antes de normalize(), adaptar o alt_sidebar:
$p = fc_dl_adapt_player($item['player']);

// Normalizar e renderizar:
$normalized = FC_Card_Normalizer::normalize($p);
$html       = FC_Card_Visual_Renderer::render_card($normalized, [
    'width'           => 250,  // frontend | 400 para PNG
    'show_playstyles' => true,
    'show_extra_info' => true,
    'responsive'      => true, // frontend | false para PNG
]);

// CSS (para Puppeteer, remover tags <style>):
$css_com_tags = FC_Card_Visual_Renderer::get_card_css();
$css_puro     = preg_replace('/<\/?style[^>]*>/i', '', $css_com_tags);
```

**Mapeamento importante:**
- `info.nation.src` e `info.nation.title` → normalizer usa essas chaves (não `icon`/`name`)
- `alt_sidebar` precisa ser wrappado em `.right` antes do normalize (feito por `fc_dl_adapt_player()`)
- `playstyles.items[].svg_raw` precisa existir para ícones renderizarem (fix pendente no scraper)
