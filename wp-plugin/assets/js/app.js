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
            + '</div>';
    }

    /* ══════════════════════════════════════════════════════════
       ROTEADOR
    ══════════════════════════════════════════════════════════ */
    function route() {
        stopPoll();
        var hash = location.hash.replace('#', '') || 'squads';
        if      (hash === 'squads')              screenGrid();
        else if (hash.indexOf('squad-') === 0)   screenSquad(hash.slice(6));
        else if (hash === 'dme')                 screenDme();
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
            + '<button class="fc-dl-btn fc-dl-btn-ghost" onclick="fcdlRefresh()">↻ Atualizar</button>'
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
            html += '<div class="fc-dl-players-grid">';
            players.forEach(function (p) {
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
        state.squads = null;
        location.hash = '#squads';
        if (location.hash === '#squads') screenGrid();
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

})();
