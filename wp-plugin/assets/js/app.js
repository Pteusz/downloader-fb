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

    function show(id) { $(id).style.display = ''; }
    function hide(id) { $(id).style.display = 'none'; }

    function setLoading(msg) {
        $('fc-dl-screen-grid').style.display  = 'none';
        $('fc-dl-screen-squad').style.display = 'none';
        $('fc-dl-loading').style.display      = 'flex';
        var p = $('fc-dl-loading').querySelector('p');
        if (p) p.textContent = msg || 'Carregando...';
    }

    /* ── Estado global ───────────────────────────────────────── */
    var state = {
        squads:  null,   // cache da lista de squads
        pollTimer: null  // intervalo de polling
    };

    function stopPoll() {
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    }

    /* ══════════════════════════════════════════════════════════
       ROTEADOR — hash-based SPA
    ══════════════════════════════════════════════════════════ */
    function route() {
        stopPoll();
        var hash = location.hash.replace('#', '') || 'squads';

        if (hash === 'squads') {
            screenGrid();
        } else if (hash.indexOf('squad-') === 0) {
            screenSquad(hash.slice(6));
        }
    }

    window.addEventListener('hashchange', route);
    document.addEventListener('DOMContentLoaded', route);

    /* ══════════════════════════════════════════════════════════
       TELA 1 — Grid de squads
    ══════════════════════════════════════════════════════════ */
    function screenGrid() {
        setLoading('Carregando squads...');

        ajax('fc_dl_get_squads', {}).then(function (res) {
            $('fc-dl-loading').style.display = 'none';

            if (!res.success) {
                renderError('fc-dl-screen-grid', 'Erro ao carregar squads: ' + (res.data && res.data.message));
                show('fc-dl-screen-grid');
                return;
            }

            state.squads = res.data;
            renderGrid(res.data);
            show('fc-dl-screen-grid');
        }).catch(function () {
            renderError('fc-dl-screen-grid', 'Falha de conexão.');
            $('fc-dl-loading').style.display = 'none';
            show('fc-dl-screen-grid');
        });
    }

    function renderGrid(squads) {
        var html = '<div class="fc-dl-topbar">'
            + '<h2>Squads Disponíveis</h2>'
            + '<div class="fc-dl-topbar-actions">'
            + '<button class="fc-dl-btn fc-dl-btn-secondary" onclick="fcdlRefresh()">↻ Atualizar</button>'
            + '</div></div>'
            + '<div class="fc-dl-grid">';

        squads.forEach(function (sq) {
            var badge = sq.loaded
                ? '<span class="fc-dl-badge fc-dl-badge-ok">✓ ' + sq.total + ' jogadores</span>'
                : '<span class="fc-dl-badge fc-dl-badge-empty">Não carregado</span>';

            html += '<a class="fc-dl-squad-card" href="#squad-' + esc(sq.label) + '">'
                  + '<div class="fc-dl-squad-img" style="background-image:url(\'' + esc(sq.bg_image) + '\')"></div>'
                  + '<div class="fc-dl-squad-body">'
                  + '<div class="fc-dl-squad-name">' + esc(sq.name) + '</div>'
                  + '<div class="fc-dl-squad-date">' + esc(sq.created) + '</div>'
                  + badge
                  + '</div></a>';
        });

        html += '</div>';
        $('fc-dl-screen-grid').innerHTML = html;
    }

    /* ══════════════════════════════════════════════════════════
       TELA 2 — Detalhe do squad
    ══════════════════════════════════════════════════════════ */
    function screenSquad(label) {
        setLoading('Verificando squad...');

        /* 1. Verifica se há scrape em andamento */
        ajax('fc_dl_scrape_status', { label: label }).then(function (res) {
            if (res.success && res.data.status === 'running') {
                $('fc-dl-loading').style.display = 'none';
                renderScraping(label, res.data.message || 'Scraping em andamento...');
                show('fc-dl-screen-squad');
                startPoll(label);
                return;
            }
            loadSquadData(label);
        }).catch(function () {
            loadSquadData(label);
        });
    }

    function loadSquadData(label) {
        ajax('fc_dl_get_squad', { label: label }).then(function (res) {
            $('fc-dl-loading').style.display = 'none';

            if (!res.success) {
                renderError('fc-dl-screen-squad', res.data && res.data.message);
                show('fc-dl-screen-squad');
                return;
            }

            if (!res.data.loaded) {
                renderNotLoaded(label);
            } else {
                renderPlayers(label, res.data);
            }
            show('fc-dl-screen-squad');
        }).catch(function () {
            renderError('fc-dl-screen-squad', 'Falha de conexão.');
            $('fc-dl-loading').style.display = 'none';
            show('fc-dl-screen-squad');
        });
    }

    /* ── Sub-renders da tela 2 ───────────────────────────────── */
    function renderNotLoaded(label) {
        $('fc-dl-screen-squad').innerHTML =
            header(label, null) +
            '<div class="fc-dl-center-box">'
            + '<p>Este squad ainda não foi carregado.</p>'
            + '<button class="fc-dl-btn fc-dl-btn-primary" onclick="fcdlLoad(\'' + esc(label) + '\')">'
            + '⬇ Carregar Jogadores</button>'
            + '</div>';
    }

    function renderScraping(label, msg) {
        $('fc-dl-screen-squad').innerHTML =
            header(label, null) +
            '<div class="fc-dl-center-box">'
            + '<div class="fc-dl-spinner"></div>'
            + '<p id="fc-dl-poll-msg" class="fc-dl-progress-msg">' + esc(msg) + '</p>'
            + '<p class="fc-dl-hint">Aguarde, isso pode levar alguns minutos...</p>'
            + '</div>';
    }

    function renderPlayers(label, data) {
        var total = data.meta && data.meta.total ? data.meta.total : data.players.length;

        var html = header(label, total)
            + '<div style="margin-bottom:20px;" class="fc-dl-topbar-actions">'
            + '<button class="fc-dl-btn fc-dl-btn-secondary" onclick="fcdlToggleAll()">☑ Selecionar Todos</button>'
            + '<button class="fc-dl-btn fc-dl-btn-primary" onclick="fcdlGenPng()">🖼 Gerar PNG</button>'
            + '</div>'
            + '<div class="fc-dl-players-grid">';

        data.players.forEach(function (p, i) {
            html += '<label class="fc-dl-player-wrap">'
                  + '<input type="checkbox" class="fc-dl-chk" data-idx="' + i + '" data-name="' + esc(p.name) + '">'
                  + '<div class="fc-dl-player-card-shell">' + p.card_html + '</div>'
                  + '<span class="fc-dl-player-label">' + esc(p.name) + '</span>'
                  + '</label>';
        });

        html += '</div>';
        $('fc-dl-screen-squad').innerHTML = html;
    }

    function header(label, total) {
        var countStr = (total !== null && total !== undefined)
            ? '<span class="fc-dl-count">' + total + ' jogadores</span>'
            : '';
        return '<div class="fc-dl-topbar">'
            + '<a class="fc-dl-back" href="#squads">← Voltar</a>'
            + '<h2>' + esc(label) + countStr + '</h2>'
            + '</div>';
    }

    /* ── Polling ─────────────────────────────────────────────── */
    function startPoll(label) {
        stopPoll();
        state.pollTimer = setInterval(function () {
            ajax('fc_dl_scrape_status', { label: label }).then(function (res) {
                if (!res.success) return;

                var st  = res.data.status;
                var msg = res.data.message || st;
                var el  = $('fc-dl-poll-msg');
                if (el) el.textContent = msg;

                if (st === 'done') {
                    stopPoll();
                    screenSquad(label);
                } else if (st === 'error') {
                    stopPoll();
                    var errBox = document.createElement('p');
                    errBox.className = 'fc-dl-error';
                    errBox.textContent = 'Erro no scrape: ' + msg;
                    var box = $('fc-dl-screen-squad').querySelector('.fc-dl-center-box');
                    if (box) box.appendChild(errBox);
                }
            });
        }, 4000);
    }

    /* ══════════════════════════════════════════════════════════
       Ações globais (chamadas via onclick inline)
    ══════════════════════════════════════════════════════════ */

    /* Atualiza grid */
    window.fcdlRefresh = function () {
        state.squads = null;
        location.hash = 'squads';
        if (location.hash === '#squads') screenGrid();
    };

    /* Dispara scrape */
    window.fcdlLoad = function (label) {
        var squad = state.squads && state.squads.find(function (s) { return s.label === label; });

        if (!squad) {
            /* fallback: busca lista para obter URL */
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
        renderScraping(label, 'Iniciando scraping...');
        ajax('fc_dl_run_scrape', { label: label, url: url }).then(function (res) {
            if (res.success) {
                startPoll(label);
            } else {
                var errBox = document.createElement('p');
                errBox.className = 'fc-dl-error';
                errBox.textContent = 'Erro: ' + (res.data && res.data.message);
                $('fc-dl-screen-squad').appendChild(errBox);
            }
        });
    }

    /* Selecionar / deselecionar todos */
    window.fcdlToggleAll = function () {
        var checks = document.querySelectorAll('.fc-dl-chk');
        var allChecked = Array.from(checks).every(function (c) { return c.checked; });
        checks.forEach(function (c) { c.checked = !allChecked; });
    };

    /* Gerar PNG — placeholder (ponto 4) */
    window.fcdlGenPng = function () {
        var sel = Array.from(document.querySelectorAll('.fc-dl-chk:checked'))
                       .map(function (c) { return c.dataset.name; });
        if (!sel.length) {
            alert('Selecione ao menos um jogador.');
            return;
        }
        alert(sel.length + ' jogadores selecionados.\nGeração de PNG será implementada em breve!');
    };

    /* ── Utils ───────────────────────────────────────────────── */
    function esc(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderError(screenId, msg) {
        $(screenId).innerHTML = '<p class="fc-dl-error">' + esc(msg || 'Erro desconhecido') + '</p>';
    }

})();
