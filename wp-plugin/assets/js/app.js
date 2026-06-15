/* global FC_DL */
(function () {
    'use strict';

    /* ── Ajax helper ─────────────────────────────────────────── */
    function ajax(action, data) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce',  FC_DL.nonce);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(FC_DL.ajaxUrl, { method: 'POST', body: fd })
               .then(function (r) { return r.json(); });
    }

    /* ── DOM helpers ─────────────────────────────────────────── */
    var $ = function (id) { return document.getElementById(id); };

    function setLoading(msg) {
        $('fc-dl-screen-grid').style.display  = 'none';
        $('fc-dl-screen-squad').style.display = 'none';
        $('fc-dl-screen-dme').style.display   = 'none';
        $('fc-dl-screen-post').style.display  = 'none';
        $('fc-dl-loading').style.display      = 'flex';
        var p = $('fc-dl-loading').querySelector('p');
        if (p) p.textContent = msg || 'Carregando...';
    }

    function showScreen(id) {
        $('fc-dl-loading').style.display = 'none';
        $(id).style.display = '';
    }

    /* ── Estado global ───────────────────────────────────────── */
    var state = { squads: null, pollTimer: null };

    function stopPoll() {
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    }

    /* ── Tabs helper ─────────────────────────────────────────── */
    function tabs(active) {
        return '<div class="fc-dl-tabs">'
            + '<a class="fc-dl-tab' + (active === 'squads' ? ' active' : '') + '" href="#squads">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>'
            + 'Squads</a>'
            + '<a class="fc-dl-tab' + (active === 'dme' ? ' active' : '') + '" href="#dme">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
            + 'DME Players</a>'
            + '<a class="fc-dl-tab' + (active === 'post' ? ' active' : '') + '" href="#post">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>'
            + 'Criar Post</a>'
            + '</div>';
    }

    /* ══════════════════════════════════════════════════════════
       ROTEADOR
    ══════════════════════════════════════════════════════════ */
    function route() {
        stopPoll();
        var hash = location.hash.replace('#', '') || 'squads';
        postCleanup();
        if      (hash === 'squads')              screenGrid();
        else if (hash.indexOf('squad-') === 0)   screenSquad(hash.slice(6));
        else if (hash === 'dme')                 screenDme();
        else if (hash === 'post')                screenPost();
    }

    window.addEventListener('hashchange', route);
    document.addEventListener('DOMContentLoaded', route);

    /* ══════════════════════════════════════════════════════════
       TELA 1 — Grid de squads
    ══════════════════════════════════════════════════════════ */
    function screenGrid() {
        setLoading('Carregando squads...');
        ajax('fc_dl_get_squads', {}).then(function (res) {
            if (!res.success) {
                $('fc-dl-screen-grid').innerHTML = errorHtml('Erro: ' + (res.data && res.data.message));
                showScreen('fc-dl-screen-grid'); return;
            }
            state.squads = res.data;
            renderGrid(res.data);
            showScreen('fc-dl-screen-grid');
        }).catch(function () {
            $('fc-dl-screen-grid').innerHTML = errorHtml('Falha de conexão.');
            showScreen('fc-dl-screen-grid');
        });
    }

    function renderGrid(squads) {
        var html = tabs('squads')
            + '<div class="fc-dl-topbar">'
            + '<h2>Squads</h2>'
            + '<div class="fc-dl-topbar-right">'
            + '<button id="fc-dl-refresh-btn" class="fc-dl-btn fc-dl-btn-ghost" onclick="fcdlRefresh()">↻ Atualizar</button>'
            + '</div></div>'
            + '<div class="fc-dl-squad-grid">';

        squads.forEach(function (sq) {
            var badge = sq.loaded
                ? '<span class="fc-dl-badge fc-dl-badge-ok">✓ ' + sq.total + ' jogadores</span>'
                : '<span class="fc-dl-badge fc-dl-badge-empty">Não carregado</span>';
            html += '<a class="fc-dl-squad-card" href="#squad-' + esc(sq.label) + '">'
                  + '<div class="fc-dl-squad-img" style="background-image:url(\'' + esc(sq.bg_image) + '\')"></div>'
                  + '<div class="fc-dl-squad-body">'
                  + '<div class="fc-dl-squad-name">' + esc(sq.name) + '</div>'
                  + '<div class="fc-dl-squad-meta">' + esc(sq.created) + '</div>'
                  + badge + '</div></a>';
        });
        html += '</div>';
        $('fc-dl-screen-grid').innerHTML = html;
    }

    /* ══════════════════════════════════════════════════════════
       TELA 2 — Detalhe do squad
    ══════════════════════════════════════════════════════════ */
    function screenSquad(label) {
        setLoading('Verificando squad...');
        ajax('fc_dl_scrape_status', { label: label }).then(function (res) {
            if (res.success && res.data.status === 'running') {
                renderScraping(label, res.data);
                showScreen('fc-dl-screen-squad');
                startPoll(label); return;
            }
            loadSquadData(label);
        }).catch(function () { loadSquadData(label); });
    }

    function loadSquadData(label) {
        ajax('fc_dl_get_squad', { label: label }).then(function (res) {
            if (!res.success) {
                $('fc-dl-screen-squad').innerHTML = errorHtml(res.data && res.data.message);
                showScreen('fc-dl-screen-squad'); return;
            }
            if (!res.data.loaded) renderNotLoaded(label);
            else renderPlayers(label, res.data);
            showScreen('fc-dl-screen-squad');
        }).catch(function () {
            $('fc-dl-screen-squad').innerHTML = errorHtml('Falha de conexão.');
            showScreen('fc-dl-screen-squad');
        });
    }

    function renderNotLoaded(label) {
        $('fc-dl-screen-squad').innerHTML = squadHeader(label, null)
            + '<div class="fc-dl-center-box"><p>Este squad ainda não foi carregado.</p>'
            + '<button class="fc-dl-btn fc-dl-btn-primary" onclick="fcdlLoad(\'' + esc(label) + '\')">⬇ Carregar Jogadores</button>'
            + '</div>';
    }

    function renderScraping(label, s) {
        var pct = (s.total > 0) ? Math.round((s.current / s.total) * 100) : 0;
        $('fc-dl-screen-squad').innerHTML = squadHeader(label, null)
            + '<div class="fc-dl-center-box">'
            + '<div class="fc-dl-spinner"></div>'
            + (s.total  > 0 ? '<div class="fc-dl-progress-counter">' + s.current + ' / ' + s.total + '</div>' : '')
            + (s.total  > 0 ? '<div class="fc-dl-progress-track"><div class="fc-dl-progress-bar" style="width:' + pct + '%"></div></div>' : '')
            + (s.player     ? '<p class="fc-dl-progress-player">' + esc(s.player) + '</p>' : '')
            + '<p class="fc-dl-hint">Aguarde, isso pode levar alguns minutos...</p>'
            + '</div>';
    }

    function renderPlayers(label, data) {
        var total = data.meta && data.meta.total ? data.meta.total : data.players.length;
        var html  = squadHeader(label, total)
            + '<div class="fc-dl-actions-bar">'
            + '<button class="fc-dl-btn fc-dl-btn-ghost" onclick="fcdlToggleAll()">☑ Todos</button>'
            + '<button class="fc-dl-btn fc-dl-btn-primary" id="fc-dl-gen-squad-btn" onclick="fcdlGenPng()">🖼 Gerar PNG</button>'
            + '</div>'
            + '<div class="fc-dl-players-grid">';

        data.players.forEach(function (p, i) {
            html += '<label class="fc-dl-player-wrap">'
                  + '<input type="checkbox" class="fc-dl-chk" data-idx="' + i + '" data-name="' + esc(p.name) + '">'
                  + '<div class="fc-dl-card-shell">' + p.card_html + '</div>'
                  + '<span class="fc-dl-player-label">' + esc(p.name) + '</span>'
                  + '</label>';
        });
        html += '</div>';
        $('fc-dl-screen-squad').innerHTML = html;
    }

    function squadHeader(label, total) {
        var count = total !== null && total !== undefined
            ? ' <span class="fc-dl-count">' + total + '</span>' : '';
        return '<div class="fc-dl-topbar">'
            + '<a class="fc-dl-back" href="#squads">← Voltar</a>'
            + '<h2>' + esc(label) + count + '</h2>'
            + '</div>';
    }

    /* ── Polling ─────────────────────────────────────────────── */
    function startPoll(label) {
        stopPoll();
        state.pollTimer = setInterval(function () {
            ajax('fc_dl_scrape_status', { label: label }).then(function (res) {
                if (!res.success) return;
                var st = res.data.status;
                if      (st === 'running') { renderScraping(label, res.data); }
                else if (st === 'done')    { stopPoll(); screenSquad(label); }
                else if (st === 'error')   {
                    stopPoll();
                    var box = $('fc-dl-screen-squad') && $('fc-dl-screen-squad').querySelector('.fc-dl-center-box');
                    if (box) { var el = document.createElement('p'); el.className = 'fc-dl-err-msg'; el.textContent = 'Erro: ' + (res.data.message || '?'); box.appendChild(el); }
                }
            });
        }, 3000);
    }

    /* ══════════════════════════════════════════════════════════
       TELA 3 — DME (jogadores + recompensas)
    ══════════════════════════════════════════════════════════ */
    function screenDme() {
        setLoading('Carregando DME...');
        ajax('fc_dl_get_dme_items', {}).then(function (res) {
            if (!res.success) {
                $('fc-dl-screen-dme').innerHTML = errorHtml(res.data && res.data.message);
                showScreen('fc-dl-screen-dme'); return;
            }
            renderDme(res.data);
            showScreen('fc-dl-screen-dme');
        }).catch(function () {
            $('fc-dl-screen-dme').innerHTML = errorHtml('Falha de conexão.');
            showScreen('fc-dl-screen-dme');
        });
    }

    function renderDme(data) {
        var items         = data.items || [];
        var totalPlayers  = data.total_players || 0;
        var totalAll      = data.total || 0;

        var html = tabs('dme');

        // Topbar
        html += '<div class="fc-dl-topbar">';
        html += '<h2>DME <span class="fc-dl-count">' + totalAll + '</span></h2>';
        html += '<button class="fc-dl-btn fc-dl-btn-ghost fc-dl-toggle-btn" onclick="fcdlToggleAllDme()">☑ Todos</button>';
        html += '</div>';

        if (!items.length) {
            html += '<div class="fc-dl-center-box"><p>Nenhum item DME disponível.</p></div>';
            $('fc-dl-screen-dme').innerHTML = html;
            return;
        }

        // ── Secção JOGADORES ──────────────────────────────────
        var players = items.filter(function (i) { return i.type === 'player'; });
        if (players.length) {
            html += '<div class="fc-dl-section-title">Jogadores <span class="fc-dl-count">' + players.length + '</span></div>';
            html += '<div class="fc-dme-section-controls">'
                  + '<div class="fc-dme-plat-toggle">'
                  + '<button class="fc-dme-plat-btn' + (dmePlatform === 'console' ? ' active' : '') + '" data-plat="console" onclick="window.dmeSwitchPlatform(this.dataset.plat)">Console</button>'
                  + '<button class="fc-dme-plat-btn' + (dmePlatform === 'pc'      ? ' active' : '') + '" data-plat="pc"      onclick="window.dmeSwitchPlatform(this.dataset.plat)">PC</button>'
                  + '</div></div>';
            html += '<div class="fc-dl-players-grid">';
            players.forEach(function (p) {
                var consoleP = p.preco_console_brl || 0;
                var pcP      = p.preco_pc_brl      || 0;
                var priceVal = dmePlatform === 'pc' ? pcP : consoleP;
                var priceHtml = (consoleP || pcP)
                    ? '<div class="fc-dl-dme-card-price"'
                      + ' data-console="' + consoleP + '"'
                      + ' data-pc="' + pcP + '">'
                      + ( priceVal > 0 ? 'R$ ' + priceVal.toFixed(2).replace('.', ',') : '' )
                      + '</div>'
                    : '';
                var footerHtml = p.expires_in
                    ? '<div class="fc-dl-dme-card-footer">'
                      + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
                      + esc(p.expires_in)
                      + '</div>'
                    : '';
                html += '<label class="fc-dl-player-wrap">'
                      + '<input type="checkbox" class="fc-dl-dme-chk"'
                      + ' data-global-idx="' + p.global_idx + '"'
                      + ' data-name="' + esc(p.name) + '">'
                      + '<div class="fc-dl-dme-card">'
                      + '<div class="fc-dl-dme-card-header">' + esc(p.name) + '</div>'
                      + '<div class="fc-dl-card-shell">' + p.card_html + '</div>'
                      + priceHtml
                      + footerHtml
                      + '</div>'
                      + '</label>';
            });
            html += '</div>';
        }

        // ── Secção RECOMPENSAS ────────────────────────────────
        var rewards = items.filter(function (i) { return i.type === 'reward'; });
        if (rewards.length) {
            html += '<div class="fc-dl-section-title" style="margin-top:28px;">Recompensas <span class="fc-dl-count">' + rewards.length + '</span></div>';
            html += '<div class="fc-dl-reward-grid">';
            rewards.forEach(function (r) {
                var expiresBadge = r.expires_in
                    ? '<div class="fc-dl-expires-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' + esc(r.expires_in) + '</div>'
                    : '';
                var imgContent = r.reward_img
                    ? '<img src="' + esc(r.reward_img) + '" alt="' + esc(r.name) + '" loading="lazy">'
                    : '<div class="fc-dl-reward-placeholder">📦</div>';

                html += '<div class="fc-dl-reward-item">'
                      + '<div class="fc-dl-reward-card">' + imgContent + '</div>'
                      + expiresBadge
                      + '<span class="fc-dl-reward-label">' + esc(r.name) + '</span>'
                      + '</div>';
            });
            html += '</div>';
        }

        $('fc-dl-screen-dme').innerHTML = html;

        // Ouve mudanças nos checkboxes para atualizar a selbar
        $('fc-dl-screen-dme').addEventListener('change', function (e) {
            if (e.target.classList.contains('fc-dl-dme-chk')) updateDmeSelBar();
        });
    }

    /* ── Barra sticky de seleção ─────────────────────────────── */
    function ensureDmeSelBar() {
        if ($('fc-dl-dme-selbar')) return;
        var bar = document.createElement('div');
        bar.id        = 'fc-dl-dme-selbar';
        bar.className = 'fc-dl-selbar';
        bar.style.display = 'none';
        bar.innerHTML =
            '<span class="fc-dl-selbar-info">'
            + '<strong class="fc-dl-selbar-count">0</strong>'
            + ' <span class="fc-dl-selbar-label">jogador(es)</span>'
            + '</span>'
            + '<div class="fc-dl-selbar-actions">'
            + '<button class="fc-dl-btn fc-dl-btn-ghost" onclick="fcdlClearDme()">Limpar</button>'
            + '<button class="fc-dl-btn fc-dl-btn-primary" id="fc-dl-dme-gen-btn" onclick="fcdlGenDmePng()">🖼 Gerar PNG</button>'
            + '</div>';
        document.body.appendChild(bar);
    }

    function updateDmeSelBar() {
        ensureDmeSelBar();
        var checked = document.querySelectorAll('.fc-dl-dme-chk:checked');
        var bar     = $('fc-dl-dme-selbar');
        if (!bar) return;
        if (checked.length > 0) {
            bar.querySelector('.fc-dl-selbar-count').textContent = checked.length;
            bar.style.display = 'flex';
        } else {
            bar.style.display = 'none';
        }
    }

    /* ══════════════════════════════════════════════════════════
       Ações globais
    ══════════════════════════════════════════════════════════ */
    window.fcdlRefresh = function () {
        var btn = document.getElementById('fc-dl-refresh-btn');
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Buscando...'; }

        ajax('fc_dl_refresh_squads', {}).then(function (res) {
            if (btn) { btn.disabled = false; btn.textContent = '↻ Atualizar'; }
            if (!res.success) {
                alert('Erro ao atualizar squads: ' + (res.data && res.data.message || '?'));
                return;
            }
            state.squads = res.data;
            renderGrid(res.data);
            showScreen('fc-dl-screen-grid');
        }).catch(function () {
            if (btn) { btn.disabled = false; btn.textContent = '↻ Atualizar'; }
            // fallback: relê o índice existente sem discovery
            state.squads = null;
            screenGrid();
        });
    };

    window.fcdlLoad = function (label) {
        var squad = state.squads && state.squads.find(function (s) { return s.label === label; });
        if (!squad) {
            ajax('fc_dl_get_squads', {}).then(function (res) {
                if (!res.success) return;
                state.squads = res.data;
                var s = res.data.find(function (s) { return s.label === label; });
                if (s) triggerScrape(label, s.url);
            });
            return;
        }
        triggerScrape(label, squad.url);
    };

    function triggerScrape(label, url) {
        renderScraping(label, { current: 0, total: 0, player: '' });
        ajax('fc_dl_run_scrape', { label: label, url: url }).then(function (res) {
            if (res.success) startPoll(label);
            else {
                var el = document.createElement('p');
                el.className = 'fc-dl-err-msg';
                el.textContent = 'Erro: ' + (res.data && res.data.message);
                $('fc-dl-screen-squad').appendChild(el);
            }
        });
    }

    window.fcdlToggleAll = function () {
        var checks = document.querySelectorAll('.fc-dl-chk');
        var all    = Array.from(checks).every(function (c) { return c.checked; });
        checks.forEach(function (c) { c.checked = !all; });
    };

    window.fcdlToggleAllDme = function () {
        var checks = document.querySelectorAll('.fc-dl-dme-chk');
        var all    = Array.from(checks).every(function (c) { return c.checked; });
        checks.forEach(function (c) { c.checked = !all; });
        updateDmeSelBar();
    };

    window.fcdlClearDme = function () {
        document.querySelectorAll('.fc-dl-dme-chk').forEach(function (c) { c.checked = false; });
        updateDmeSelBar();
    };

    window.fcdlGenPng = function () {
        var checks  = document.querySelectorAll('.fc-dl-chk:checked');
        var indices = Array.from(checks).map(function (c) { return c.dataset.idx; });
        if (!indices.length) { alert('Selecione ao menos um jogador.'); return; }
        var label = location.hash.replace('#squad-', '').replace('#', '');
        if (!label) { alert('Squad não identificado.'); return; }
        var btn = $('fc-dl-gen-squad-btn');
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Gerando...'; }
        var fd = new FormData();
        fd.append('action', 'fc_dl_generate_png');
        fd.append('nonce',  FC_DL.nonce);
        fd.append('label',  label);
        indices.forEach(function (i) { fd.append('indices[]', i); });
        fetch(FC_DL.ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (btn) { btn.disabled = false; btn.textContent = '🖼 Gerar PNG'; }
                if (!res.success) { alert('Erro: ' + (res.data && res.data.message || '?')); return; }
                triggerDownload(res.data, 'fc-dl-squad-dl-btn', btn);
            })
            .catch(function (err) {
                if (btn) { btn.disabled = false; btn.textContent = '🖼 Gerar PNG'; }
                alert('Falha: ' + err.message);
            });
    };

    window.fcdlGenDmePng = function () {
        var checks  = document.querySelectorAll('.fc-dl-dme-chk:checked');
        var indices = Array.from(checks).map(function (c) { return c.dataset.globalIdx; });
        if (!indices.length) { alert('Selecione ao menos um jogador.'); return; }
        var btn = $('fc-dl-dme-gen-btn');
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Gerando...'; }
        var fd = new FormData();
        fd.append('action', 'fc_dl_generate_dme_png');
        fd.append('nonce',  FC_DL.nonce);
        indices.forEach(function (i) { fd.append('indices[]', i); });
        fetch(FC_DL.ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (btn) { btn.disabled = false; btn.textContent = '🖼 Gerar PNG'; }
                if (!res.success) { alert('Erro: ' + (res.data && res.data.message || '?')); return; }
                triggerDownload(res.data, 'fc-dl-dme-dl-btn', btn);
            })
            .catch(function (err) {
                if (btn) { btn.disabled = false; btn.textContent = '🖼 Gerar PNG'; }
                alert('Falha: ' + err.message);
            });
    };

    function triggerDownload(data, dlBtnId, nearBtn) {
        var isPng  = data.type === 'png';
        var sizeKB = Math.round(data.size / 1024);
        var label  = (isPng ? '⬇ PNG' : '⬇ ZIP') + ' (' + sizeKB + ' KB)';
        var a = document.createElement('a');
        a.href = data.url; a.download = data.filename; a.style.display = 'none';
        document.body.appendChild(a); a.click();
        setTimeout(function () { document.body.removeChild(a); }, 200);
        var existing = document.getElementById(dlBtnId);
        if (existing) existing.remove();
        var dlBtn = document.createElement('a');
        dlBtn.id = dlBtnId; dlBtn.href = data.url; dlBtn.download = data.filename;
        dlBtn.className = 'fc-dl-btn fc-dl-btn-success'; dlBtn.textContent = label;
        if (nearBtn && nearBtn.parentNode) nearBtn.parentNode.insertBefore(dlBtn, nearBtn.nextSibling);
    }

    function esc(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                          .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function errorHtml(msg) {
        return '<div class="fc-dl-err-msg">' + esc(msg || 'Erro desconhecido') + '</div>';
    }


    /* ══════════════════════════════════════════════════════════
       TELA 4 — CRIAÇÃO DE POST
       Canvas HTML5 mobile-first:
         - Fundo: imagem base (default ou upload do usuário)
         - Elementos: PNGs dos cards DME + textos Montserrat
         - Touch: drag (1 dedo) + pinch-to-resize (2 dedos)
         - Export: canvas.toDataURL() → download direto (Fase 2)
    ══════════════════════════════════════════════════════════ */

    var dmePlatform = 'console';  // plataforma de preço ativa: 'console' | 'pc'

    var POST_MODES = {
        stories: {
            w:  941,
            h:  1672,
            bg: 'https://chamacoins.com.br/wp-content/uploads/2026/06/ChatGPT-Image-13-de-jun.-de-2026-16_54_23-1.png'
        },
        feed: {
            w:  1122,
            h:  1402,
            bg: 'https://chamacoins.com.br/wp-content/uploads/2026/06/ChatGPT-Image-13-de-jun.-de-2026-18_26_51.png'
        }
    };

    var PC = {   /* PostCanvas state */
        el:          null,   // <canvas>
        ctx:         null,   // CanvasRenderingContext2D
        elements:    [],     // [{type, img?, text?, x, y, w, h, ...}]
        selected:    -1,     // índice do elemento selecionado
        dragging:    false,
        dragOffX:    0,
        dragOffY:    0,
        pinching:    false,
        pinchDist0:  0,
        pinchW0:     0,
        pinchH0:     0,
        history:     [],     // undo stack (máx 20)
        withWrapper: true,   // toggle no sheet — padrão = 'Com info' (coincide com o botão active no HTML)
        mode:        'stories', // 'stories' | 'feed'
        w:           941,        // largura canvas (muda com o modo)
        h:           1672,       // altura canvas  (muda com o modo)
        dmeItems:    null,   // cache dos players DME
        fontReady:   false,
    };

    /* ── Tela principal ──────────────────────────────────────── */
    function screenPost() {
        // Remove padding do app container para canvas edge-to-edge
        var appEl = document.getElementById('fc-dl-app');
        if (appEl) appEl.dataset.prevPad = appEl.style.padding || '';

        setLoading('Preparando editor...');

        // Carrega items DME se ainda não tiver em cache
        if (PC.dmeItems) {
            renderPostScreen();
        } else {
            ajax('fc_dl_get_dme_items', {}).then(function(res) {
                PC.dmeItems = res.success
                    ? (res.data.items || []).filter(function(i) { return i.type === 'player'; })
                    : [];
                renderPostScreen();
            }).catch(function() {
                PC.dmeItems = [];
                renderPostScreen();
            });
        }
    }

    function renderPostScreen() {
        var s = $('fc-dl-screen-post');

        s.innerHTML =
            /* Top bar */
            '<div class="fc-post-topbar">' +
                '<a class="fc-dl-back" href="#squads" style="min-width:48px;">← Voltar</a>' +
                '<div class="fc-post-mode-toggle">' +
                    '<button class="fc-post-mode-btn' + (PC.mode === 'stories' ? ' active' : '') + '" id="pc-mode-stories" data-mode="stories" onclick="window.pcSwitchMode(this.dataset.mode)">Stories</button>' +
                    '<button class="fc-post-mode-btn' + (PC.mode === 'feed'    ? ' active' : '') + '" id="pc-mode-feed"    data-mode="feed"    onclick="window.pcSwitchMode(this.dataset.mode)">Feed</button>' +
                '</div>' +
                '<button class="fc-dl-btn fc-dl-btn-ghost" style="padding:6px 10px;font-size:0.8em;" onclick="window.pcUndo()">↩ Desfazer</button>' +
            '</div>' +

            /* Canvas */
            '<div class="fc-post-canvas-wrap">' +
                '<canvas id="fc-post-cv" class="fc-post-canvas"></canvas>' +
                '<div id="fc-post-load" class="fc-post-canvas-loading" style="display:none;">' +
                    '<div class="fc-dl-spinner"></div>' +
                '</div>' +
            '</div>' +

            /* Toolbar inferior */
            '<div class="fc-post-toolbar">' +
                '<button class="fc-post-tool-btn" onclick="window.pcOpenCardSheet()">' +
                    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>' +
                    'Card' +
                '</button>' +
                '<button class="fc-post-tool-btn" onclick="window.pcOpenText()">' +
                    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>' +
                    'Texto' +
                '</button>' +
                '<button class="fc-post-tool-btn" onclick="window.pcChangeBg()">' +
                    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>' +
                    'Fundo' +
                '</button>' +
                '<button class="fc-post-tool-btn fc-dl-btn-primary" onclick="window.pcDownload()">' +
                    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>' +
                    'Baixar' +
                '</button>' +
                '<button class="fc-post-tool-btn danger" id="pc-del-btn" onclick="window.pcDeleteSelected()" style="display:none;">' +
                    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>' +
                    'Deletar' +
                '</button>' +
            '</div>' +

            /* Input de arquivo para BG (hidden) */
            '<input type="file" id="pc-bg-file" accept="image/*" style="display:none;">' +

            /* Overlay do sheet */
            '<div id="pc-sheet-overlay" class="fc-post-sheet-overlay" onclick="window.pcCloseSheet()"></div>' +

            /* Bottom sheet — picker de cards */
            '<div id="pc-card-sheet" class="fc-post-sheet">' +
                '<div class="fc-post-sheet-handle"></div>' +
                '<div class="fc-post-sheet-header">' +
                    '<div class="fc-post-sheet-header-row">' +
                        '<span class="fc-post-sheet-title">Escolher Card</span>' +
                        '<div class="fc-post-plat-toggle">' +
                            '<button class="fc-post-plat-btn' + (dmePlatform === 'console' ? ' active' : '') + '" data-plat="console" onclick="window.dmeSwitchPlatform(this.dataset.plat)">Console</button>' +
                            '<button class="fc-post-plat-btn' + (dmePlatform === 'pc'      ? ' active' : '') + '" data-plat="pc"      onclick="window.dmeSwitchPlatform(this.dataset.plat)">PC</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="fc-post-wrap-toggle">' +
                        '<button class="fc-post-wrap-btn" id="pc-wrap-off" onclick="window.pcSetWrapper(false)">Só card</button>' +
                        '<button class="fc-post-wrap-btn active" id="pc-wrap-on" onclick="window.pcSetWrapper(true)">Com info</button>' +
                    '</div>' +
                '</div>' +
                '<div class="fc-post-sheet-list">' + buildCardList() + '</div>' +
            '</div>' +

            /* Painel de texto */
            '<div id="pc-text-panel" class="fc-post-text-panel">' +
                '<div class="fc-post-text-controls">' +
                    '<input type="text" id="pc-txt" class="fc-post-text-field" placeholder="Digite o texto...">' +
                    '<div class="fc-post-text-options">' +
                        '<select id="pc-txt-size" class="fc-post-select">' +
                            '<option value="36">36</option>' +
                            '<option value="48">48</option>' +
                            '<option value="64" selected>64</option>' +
                            '<option value="80">80</option>' +
                            '<option value="100">100</option>' +
                        '</select>' +
                        '<select id="pc-txt-weight" class="fc-post-select">' +
                            '<option value="400">Regular</option>' +
                            '<option value="600">Semi Bold</option>' +
                            '<option value="700" selected>Bold</option>' +
                            '<option value="800">Extra Bold</option>' +
                        '</select>' +
                        '<input type="color" id="pc-txt-color" value="#ffffff" class="fc-post-color-pick">' +
                    '</div>' +
                '</div>' +
                '<div class="fc-post-text-actions">' +
                    '<button class="fc-dl-btn fc-dl-btn-ghost" onclick="window.pcCloseText()">Cancelar</button>' +
                    '<button class="fc-dl-btn fc-dl-btn-primary" onclick="window.pcConfirmText()">Adicionar</button>' +
                '</div>' +
            '</div>';

        showScreen('fc-dl-screen-post');
        initPostCanvas($('fc-post-cv'));

        /* Listener no input de BG */
        $('pc-bg-file').addEventListener('change', function() {
            var file = this.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function(e) { pcLoadBg(e.target.result); };
            reader.readAsDataURL(file);
            this.value = '';
        });
    }

    function buildCardList() {
        if (!PC.dmeItems || !PC.dmeItems.length) {
            return '<p style="padding:20px 16px;color:var(--clr-muted);font-size:0.85em;">Nenhum card DME disponível.</p>';
        }
        return PC.dmeItems.map(function(p) {
            var faceHtml = p.face
                ? '<img src="' + esc(p.face) + '" alt="" class="fc-post-card-face" loading="lazy">'
                : '<div class="fc-post-card-face fc-post-card-face-fallback"></div>';
            var cP = p.preco_console_brl || 0;
            var pP = p.preco_pc_brl      || 0;
            var pv = dmePlatform === 'pc' ? pP : cP;
            var priceSpan = (cP || pP)
                ? '<span class="fc-post-card-price" data-console="' + cP + '" data-pc="' + pP + '">'
                  + ( pv > 0 ? 'R$\u00a0' + pv.toFixed(2).replace('.', ',') : '' )
                  + '</span>'
                : '';
            return '<div class="fc-post-card-row" onclick="window.pcAddCard(' + p.global_idx + ')">' +
                faceHtml +
                '<div class="fc-post-card-row-info">' +
                    '<span class="fc-post-card-rating">' + esc(String(p.rating)) + '</span>' +
                    '<span class="fc-post-card-pos">' + esc(p.position) + '</span>' +
                    '<span class="fc-post-card-name">' + esc(p.name) + '</span>' +
                '</div>' +
                priceSpan +
                '<span class="fc-post-card-add-btn">+</span>' +
            '</div>';
        }).join('');
    }

    /* ── Inicialização do canvas ──────────────────────────────── */
    function initPostCanvas(canvasEl) {
        PC.el  = canvasEl;
        PC.ctx = canvasEl.getContext('2d');
        PC.elements  = [];
        PC.selected  = -1;
        PC.history   = [];
        canvasEl.width  = PC.w;
        canvasEl.height = PC.h;

        canvasEl.addEventListener('touchstart', pcTouchStart, { passive: false });
        canvasEl.addEventListener('touchmove',  pcTouchMove,  { passive: false });
        canvasEl.addEventListener('touchend',   pcTouchEnd,   { passive: false });

        /* Carrega fonte Montserrat para ctx.fillText */
        if (!PC.fontReady && 'fonts' in document) {
            Promise.all([
                document.fonts.load('700 64px Montserrat'),
                document.fonts.load('800 64px Montserrat'),
                document.fonts.load('400 64px Montserrat'),
            ]).then(function() { PC.fontReady = true; });
        }

        pcLoadBg(POST_MODES[PC.mode].bg);
    }

    /* ── Limpeza ao sair da tela ─────────────────────────────── */
    function postCleanup() {
        if (PC.el) {
            PC.el.removeEventListener('touchstart', pcTouchStart);
            PC.el.removeEventListener('touchmove',  pcTouchMove);
            PC.el.removeEventListener('touchend',   pcTouchEnd);
            PC.el = null;
            PC.ctx = null;
        }
        /* Restaura padding do app */
        var appEl = document.getElementById('fc-dl-app');
        if (appEl && appEl.dataset.prevPad !== undefined) {
            appEl.style.padding = appEl.dataset.prevPad;
            delete appEl.dataset.prevPad;
        }
    }

    /* ── Utilitários de coordenadas ──────────────────────────── */
    function pcScale() {
        if (!PC.el) return 1;
        return PC.el.getBoundingClientRect().width / PC.w;
    }

    function pcToCanvas(clientX, clientY) {
        var rect = PC.el.getBoundingClientRect();
        var s    = pcScale();
        return { x: (clientX - rect.left) / s, y: (clientY - rect.top) / s };
    }

    /* ── Hit test (ordem inversa = elemento do topo primeiro) ─── */
    function pcHit(x, y) {
        for (var i = PC.elements.length - 1; i >= 0; i--) {
            if (PC.elements[i].type === 'bg') continue;
            var el = PC.elements[i];
            if (x >= el.x && x <= el.x + el.w && y >= el.y && y <= el.y + el.h) return i;
        }
        return -1;
    }

    /* Testa se toque bateu no handle de deletar (canto sup-dir do selecionado) */
    function pcHitDelete(x, y) {
        if (PC.selected < 0) return false;
        var el = PC.elements[PC.selected];
        var hx = el.x + el.w, hy = el.y;
        return Math.sqrt(Math.pow(x - hx, 2) + Math.pow(y - hy, 2)) < 28;
    }

    /* ── Redraw ──────────────────────────────────────────────── */
    function pcRedraw() {
        if (!PC.ctx) return;
        var ctx = PC.ctx;
        ctx.clearRect(0, 0, PC.w, PC.h);

        PC.elements.forEach(function(el, i) {
            if ((el.type === 'bg' || el.type === 'card') && el.img) {
                ctx.drawImage(el.img, el.x, el.y, el.w, el.h);
            } else if (el.type === 'text') {
                ctx.save();
                ctx.font       = (el.weight || '700') + ' ' + (el.size || 64) + 'px Montserrat,Arial,sans-serif';
                ctx.fillStyle  = el.color || '#ffffff';
                ctx.textBaseline = 'top';
                ctx.shadowColor  = 'rgba(0,0,0,0.6)';
                ctx.shadowBlur   = 12;
                ctx.fillText(el.text, el.x, el.y);
                ctx.restore();
            }

            if (i === PC.selected) {
                var lw = Math.max(2, 3 / pcScale());
                ctx.save();
                ctx.strokeStyle = '#f97316';
                ctx.lineWidth   = lw;
                ctx.setLineDash([10 / pcScale(), 5 / pcScale()]);
                ctx.strokeRect(el.x - 4, el.y - 4, el.w + 8, el.h + 8);
                ctx.setLineDash([]);

                /* Handle de deletar — círculo vermelho no canto sup-dir */
                var hx = el.x + el.w, hy = el.y;
                ctx.fillStyle = '#ef4444';
                ctx.beginPath();
                ctx.arc(hx, hy, 20, 0, Math.PI * 2);
                ctx.fill();
                ctx.fillStyle    = '#fff';
                ctx.font         = 'bold 26px Arial';
                ctx.textAlign    = 'center';
                ctx.textBaseline = 'middle';
                ctx.shadowBlur   = 0;
                ctx.fillText('\u00d7', hx, hy);
                ctx.restore();
            }
        });

        /* Atualiza botão deletar na toolbar */
        var delBtn = $('pc-del-btn');
        if (delBtn) delBtn.style.display = PC.selected >= 0 ? '' : 'none';
    }

    /* ── Touch handlers ──────────────────────────────────────── */
    function pcTouchStart(e) {
        e.preventDefault();

        if (e.touches.length === 1) {
            var t   = e.touches[0];
            var pos = pcToCanvas(t.clientX, t.clientY);

            if (pcHitDelete(pos.x, pos.y)) {
                pcPushHistory();
                PC.elements.splice(PC.selected, 1);
                PC.selected = -1;
                pcRedraw();
                return;
            }

            var hit = pcHit(pos.x, pos.y);
            PC.selected = hit;

            if (hit >= 0) {
                PC.dragging  = true;
                PC.dragOffX  = pos.x - PC.elements[hit].x;
                PC.dragOffY  = pos.y - PC.elements[hit].y;
            }
            pcRedraw();

        } else if (e.touches.length === 2 && PC.selected >= 0) {
            PC.dragging = false;
            PC.pinching = true;
            var t0 = e.touches[0], t1 = e.touches[1];
            PC.pinchDist0 = Math.hypot(t1.clientX - t0.clientX, t1.clientY - t0.clientY);
            PC.pinchW0    = PC.elements[PC.selected].w;
            PC.pinchH0    = PC.elements[PC.selected].h;
        }
    }

    function pcTouchMove(e) {
        e.preventDefault();

        if (PC.dragging && e.touches.length === 1 && PC.selected >= 0) {
            var t   = e.touches[0];
            var pos = pcToCanvas(t.clientX, t.clientY);
            PC.elements[PC.selected].x = pos.x - PC.dragOffX;
            PC.elements[PC.selected].y = pos.y - PC.dragOffY;
            pcRedraw();

        } else if (PC.pinching && e.touches.length === 2 && PC.selected >= 0) {
            var t0   = e.touches[0], t1 = e.touches[1];
            var dist = Math.hypot(t1.clientX - t0.clientX, t1.clientY - t0.clientY);
            var sc   = dist / PC.pinchDist0;
            var el   = PC.elements[PC.selected];
            el.w = Math.max(60,  PC.pinchW0 * sc);
            el.h = Math.max(90,  PC.pinchH0 * sc);
            pcRedraw();
        }
    }

    function pcTouchEnd(e) {
        e.preventDefault();
        if (PC.dragging)  PC.dragging = false;
        if (PC.pinching)  PC.pinching = false;
    }

    /* ── Background ──────────────────────────────────────────── */
    function pcLoadBg(url) {
        var img = new Image();
        img.onload = function() {
            PC.elements = PC.elements.filter(function(el) { return el.type !== 'bg'; });
            PC.elements.unshift({ type: 'bg', img: img, x: 0, y: 0, w: PC.w, h: PC.h });
            pcRedraw();
        };
        img.onerror = function() { pcRedraw(); };
        img.src = url;
    }

    window.pcChangeBg = function() {
        var f = $('pc-bg-file');
        if (f) f.click();
    };

    /* ── Card sheet ──────────────────────────────────────────── */
    window.pcOpenCardSheet = function() {
        var s = $('pc-card-sheet'), o = $('pc-sheet-overlay');
        if (s) s.classList.add('open');
        if (o) o.classList.add('open');
    };
    window.pcCloseSheet = function() {
        var s = $('pc-card-sheet'), o = $('pc-sheet-overlay');
        if (s) s.classList.remove('open');
        if (o) o.classList.remove('open');
    };
    window.pcSetWrapper = function(val) {
        PC.withWrapper = val;
        var on = $('pc-wrap-on'), off = $('pc-wrap-off');
        if (on)  on.classList.toggle('active',  val);
        if (off) off.classList.toggle('active', !val);
    };

    window.pcAddCard = function(globalIdx) {
        window.pcCloseSheet();
        var loadEl = $('fc-post-load');
        if (loadEl) loadEl.style.display = 'flex';

        var fd = new FormData();
        fd.append('action',       'fc_dl_generate_dme_png');
        fd.append('nonce',        FC_DL.nonce);
        fd.append('indices[]',    globalIdx);
        fd.append('with_wrapper', PC.withWrapper ? 'true' : 'false');
        fd.append('platform',     dmePlatform);

        fetch(FC_DL.ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (loadEl) loadEl.style.display = 'none';
                if (!res.success) { alert('Erro: ' + ((res.data && res.data.message) || '?')); return; }

                pcPushHistory();
                var img = new Image();
                img.onload = function() {
                    /* 33% da largura do canvas como tamanho inicial */
                    var cw = Math.round(PC.w * 0.33);
                    var ch = Math.round(cw * (img.naturalHeight / img.naturalWidth));
                    PC.elements.push({
                        type: 'card',
                        img:  img,
                        x:    Math.round((PC.w - cw) / 2),
                        y:    Math.round((PC.h - ch) / 2),
                        w:    cw,
                        h:    ch,
                    });
                    PC.selected = PC.elements.length - 1;
                    pcRedraw();
                };
                img.src = res.data.url;
            })
            .catch(function(err) {
                if (loadEl) loadEl.style.display = 'none';
                alert('Falha ao gerar card: ' + err.message);
            });
    };

    /* ── Texto ───────────────────────────────────────────────── */
    window.pcOpenText = function() {
        var p = $('pc-text-panel');
        if (!p) return;
        p.classList.add('open');
        var inp = $('pc-txt');
        if (inp) { inp.value = ''; setTimeout(function() { inp.focus(); }, 350); }
    };
    window.pcCloseText = function() {
        var p = $('pc-text-panel');
        if (p) p.classList.remove('open');
    };
    window.pcConfirmText = function() {
        var inp   = $('pc-txt');
        var sizeEl= $('pc-txt-size');
        var wEl   = $('pc-txt-weight');
        var cEl   = $('pc-txt-color');
        var text  = inp ? inp.value.trim() : '';
        if (!text) return;

        var size   = parseInt((sizeEl && sizeEl.value) || '64', 10);
        var weight = (wEl && wEl.value) || '700';
        var color  = (cEl && cEl.value) || '#ffffff';

        /* Mede largura no ctx para posicionamento central */
        var ctx = PC.ctx;
        ctx.font = weight + ' ' + size + 'px Montserrat,Arial,sans-serif';
        var tw = ctx.measureText(text).width;
        var th = size * 1.25;

        pcPushHistory();
        PC.elements.push({
            type:   'text',
            text:   text,
            x:      Math.max(0, Math.round((PC.w - tw) / 2)),
            y:      Math.round(PC.h * 0.78),
            w:      Math.ceil(tw),
            h:      Math.ceil(th),
            size:   size,
            color:  color,
            weight: weight,
        });
        PC.selected = PC.elements.length - 1;
        window.pcCloseText();
        pcRedraw();
    };

    /* ── Deletar / Undo ──────────────────────────────────────── */
    window.pcDeleteSelected = function() {
        if (PC.selected < 0) return;
        pcPushHistory();
        PC.elements.splice(PC.selected, 1);
        PC.selected = -1;
        pcRedraw();
    };

    function pcPushHistory() {
        /* Armazena snapshot leve (referências de img, não cópias) */
        PC.history.push(PC.elements.map(function(el) { return Object.assign({}, el); }));
        if (PC.history.length > 20) PC.history.shift();
    }

    /* ── Plataforma de preços (Console ↔ PC) ──────────────────── */
    window.dmeSwitchPlatform = function(plat) {
        dmePlatform = plat;
        // Atualiza todos os botões de plataforma visíveis
        document.querySelectorAll('.fc-dme-plat-btn, .fc-post-plat-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.plat === plat);
        });
        // Atualiza todos os badges de preço sem re-render
        document.querySelectorAll('.fc-dl-dme-card-price, .fc-post-card-price').forEach(function(el) {
            var raw = plat === 'pc' ? el.dataset.pc : el.dataset.console;
            el.textContent = raw && parseFloat(raw) > 0
                ? 'R$ ' + parseFloat(raw).toFixed(2).replace('.', ',')
                : '';
        });
    };

    /* ── Trocar modo (Stories ↔ Feed) ──────────────────────────── */
    window.pcSwitchMode = function(mode) {
        if (PC.mode === mode) return;

        // Confirma se já há elementos no canvas além do bg
        var hasEls = PC.elements.some(function(el) { return el.type !== 'bg'; });
        if (hasEls && !confirm('Trocar o formato vai limpar o canvas. Continuar?')) return;

        PC.mode = mode;
        var cfg  = POST_MODES[mode];
        PC.w = cfg.w;
        PC.h = cfg.h;

        // Redimensiona o canvas
        if (PC.el) {
            PC.el.width  = PC.w;
            PC.el.height = PC.h;
        }

        // Limpa elementos e carrega novo fundo
        PC.elements  = [];
        PC.selected  = -1;
        PC.history   = [];
        pcLoadBg(cfg.bg);

        // Atualiza visuais dos botões
        var bs = document.getElementById('pc-mode-stories');
        var bf = document.getElementById('pc-mode-feed');
        if (bs) { bs.classList.toggle('active', mode === 'stories'); }
        if (bf) { bf.classList.toggle('active', mode === 'feed'); }
    };

    /* ── Download do post ───────────────────────────────────── */
    window.pcDownload = function() {
        if (!PC.el) return;

        // Remove seleção para o PNG não ter o dashed border laranja
        var prev = PC.selected;
        PC.selected = -1;
        pcRedraw();

        try {
            var dataUrl = PC.el.toDataURL('image/png');
            var a = document.createElement('a');
            a.href     = dataUrl;
            a.download = 'post_' + Date.now() + '.png';
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            setTimeout(function() { document.body.removeChild(a); }, 300);
        } catch (err) {
            alert('Erro ao baixar imagem: ' + err.message);
            console.error('[pcDownload]', err);
        }

        // Restaura seleção
        PC.selected = prev;
        pcRedraw();
    };

    window.pcUndo = function() {
        if (!PC.history.length) return;
        PC.elements = PC.history.pop();
        PC.selected = -1;
        pcRedraw();
    };

})();
