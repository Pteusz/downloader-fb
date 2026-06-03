(function($) {
  'use strict';

  // ✅ DEBUG: Sistema de rastreamento
  window.fcDMEDebug = {
    selectedSbcs: null,
    cardState: null,
    logSelection: function() {
      console.group('🔍 FC DME Debug - Estado Atual');
      console.log('Platform:', currentPlatform);
      console.log('Display Platform:', displayPlatform);
      console.log('Selected SBCs:', Array.from(this.selectedSbcs.keys()));
      console.log('Card States:', Array.from(this.cardState.entries()));
      console.groupEnd();
    }
  };











  // ✅ NOVO: Mapa de plataformas visuais para funcionais
  const PLATFORM_MAP = {
    'ps': { type: 'console', name: 'Ps', img: 'https://img.icons8.com/?size=100&id=13629&format=png&color=000000' },
    'xbox': { type: 'console', name: 'Xbox', img: 'https://chamacoins.com.br/wp-content/uploads/2025/10/Logo-xbox.png' },
    'pc': { type: 'pc', name: 'PC', img: 'https://chamacoins.com.br/wp-content/uploads/2025/10/Origin-1-1.png' }
  };

  function normalizePlatform(p) {
    p = String(p || '').toLowerCase().trim();
    if (p === 'pc') return 'pc';
    if (p === 'console') return 'console';
    if (['ps','ps4','ps5','xbox','xb','xbs','xone'].includes(p)) return 'console';
    return 'console';
  }

  let currentPlatform = normalizePlatform(window.fcDME && fcDME.defaultPlatform ? fcDME.defaultPlatform : 'console');
  let displayPlatform = currentPlatform === 'console' ? 'ps' : 'pc'; // Plataforma visual padrão

  const RULE_MIN_BRL_CENTS = (window.fcDME && Number.isFinite(Number(fcDME.minTotalBrlCents)))
    ? parseInt(fcDME.minTotalBrlCents, 10)
    : 1999;



  const BUY_URL = (window.fcDME && fcDME.buyUrl) ? String(fcDME.buyUrl) : '/dme-form/';
  const KNOW_MODE = (window.fcDME && fcDME.priceMode) ? String(fcDME.priceMode) : 'lance';
  const ITEM_OVERRIDES = (window.fcDME && fcDME.itemOverrides && typeof fcDME.itemOverrides === 'object')
    ? fcDME.itemOverrides : {};

  const cardState = new Map();
  const selectedSbcs = new Map();

  // ✅ Expor Maps para debug
  if (typeof window.fcDMEDebug !== 'undefined') {
    window.fcDMEDebug.selectedSbcs = selectedSbcs;
    window.fcDMEDebug.cardState = cardState;
  }

  const BASKET_ID = 'fc-dme-basketbar';
  
  function lockBackgroundScroll() {
  const scrollY = window.scrollY || window.pageYOffset || 0;

  // salva posição
  document.documentElement.dataset.fcLockScrollY = String(scrollY);

  // adiciona classe (seu CSS já tem regras pra html/body/#view)
  document.documentElement.classList.add('fc-modal-open');
  document.body.classList.add('fc-modal-open');

  const view = document.getElementById('view');
  if (view) view.classList.add('fc-modal-open');

  // lock mais confiável (principalmente mobile)
  document.body.style.position = 'fixed';
  document.body.style.top = '-' + scrollY + 'px';
  document.body.style.left = '0';
  document.body.style.right = '0';
  document.body.style.width = '100%';
  document.body.style.overflow = 'hidden';
}

function unlockBackgroundScroll() {
  const y = parseInt(document.documentElement.dataset.fcLockScrollY || '0', 10) || 0;

  document.documentElement.classList.remove('fc-modal-open');
  document.body.classList.remove('fc-modal-open');

  const view = document.getElementById('view');
  if (view) view.classList.remove('fc-modal-open');

  document.body.style.position = '';
  document.body.style.top = '';
  document.body.style.left = '';
  document.body.style.right = '';
  document.body.style.width = '';
  document.body.style.overflow = '';

  delete document.documentElement.dataset.fcLockScrollY;

  window.scrollTo(0, y);
}
document.addEventListener('touchmove', function(e) {
  const modal = document.getElementById('fc-dme-modal');
  if (!modal || !modal.classList.contains('active')) return;

  // permite rolar apenas dentro do body do modal
  const inside = e.target.closest && e.target.closest('#fc-dme-modal .fc-dme-modal-body');
  if (!inside) e.preventDefault();
}, { passive: false });


  $(document).ready(function() {
    console.log('🚀 FC DME Script iniciado');
    
    syncPlatformUI();

    initPlatformDropdown();
    initPriceDisplay();
    updateAllBadgesFromOverride();
    initAddButtons();
    initModalOpenButtons();
    initRemoveButtons();
    initRefreshButton();
    initModalClose();
    initModalSelectionHandlers();
    ensureBasketBar();
    renderBasketBar();
    
    console.log('✅ FC DME Script pronto');
    console.log('Total de cards na página:', $('.fc-dme-card').length);
  });

function syncPlatformUI() {
  const $toggle = $('.fc-dme-platform-toggle');
  $toggle.attr('data-current', displayPlatform);

  const platform = PLATFORM_MAP[displayPlatform];
  if (!platform) return;

  const $current = $toggle.find('.fc-dme-toggle-current');

  $current.find('.fc-dme-current-icon')
    .attr('src', platform.img)
    .attr('alt', platform.name);

  $current.find('.fc-dme-current-name')
    .text(platform.name);
}


  function initPlatformDropdown() {
    // Abrir/fechar dropdown
    $(document).on('click', '.fc-dme-toggle-current', function(e) {
      e.stopPropagation();
      const $toggle = $(this).closest('.fc-dme-platform-toggle');
      const isOpen = $toggle.attr('data-open') === '1';
      $toggle.attr('data-open', isOpen ? '0' : '1');
    });

    // Fechar dropdown ao clicar fora
    $(document).on('click', function(e) {
      if (!$(e.target).closest('.fc-dme-platform-toggle').length) {
        $('.fc-dme-platform-toggle').attr('data-open', '0');
      }
    });

    // Selecionar opção
    $(document).on('click', '.fc-dme-toggle-option', function(e) {
      e.stopPropagation();
      
      const visual = $(this).data('platform'); // ps, xbox ou pc
      const functional = $(this).data('type'); // console ou pc
      
      displayPlatform = visual;
      currentPlatform = functional;
      
      console.log('🔄 Plataforma alterada:', { visual: displayPlatform, functional: currentPlatform });
      
      $('.fc-dme-platform-toggle').attr('data-open', '0');
      syncPlatformUI();

      updateAllPrices();
      renderBasketBar();

      if ($('#fc-dme-modal').hasClass('active')) {
        refreshOpenModalTotals();
      }
    });
  }

  // Atualiza badges no carregamento inicial com base nos overrides
  function updateAllBadgesFromOverride() {
    $('.fc-dme-card').each(function() {
      var $card = $(this);
      var ov = getCardOverride($card);
      if (!ov) return;
      if (Number.isFinite(ov.max_qty) && ov.max_qty > 0) {
        var current = $card.attr('data-completed') || '0/0';
        var done = parseInt((current.split('/')[0] || '0').trim(), 10) || 0;
        $card.find('.fc-dme-completed-value').text(done + '/' + ov.max_qty);
      }
    });
  }

  function initPriceDisplay() {
    $('.fc-dme-card').each(function() {
      const $card = $(this);
      ensureCardState($card);
      recomputeAndStoreCardTotals($card);
      applySelectedUi($card, isCardSelected($card));
    });
    updateAllPrices();
  }

  function ensureBasketBar() {
    if ($('#' + BASKET_ID).length) return;

    const html =
      '<div id="' + BASKET_ID + '" style="display:none; position:fixed; left:0; right:0; bottom:0; z-index:99999; padding:12px 14px; background:rgba(0,0,0,0.92); color:#fff; border-top:1px solid rgba(255,255,255,0.12);">' +
      '  <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">' +
      '    <div style="display:flex; flex-direction:column; gap:4px;">' +
      '      <div style="opacity:.9;">Selecionados: <strong class="fc-dme-basket-count">0</strong></div>' +
      '      <div style="opacity:.95;">Total:  <strong class="fc-dme-basket-coins">-</strong> · <strong class="fc-dme-basket-brl">-</strong></div>' +
      '    </div>' +
      '    <div style="display:flex; gap:8px; align-items:center; margin-left:auto;">' +
      '      <button type="button" class="fc-dme-basket-clear" style="cursor:pointer; padding:10px 12px; border-radius:10px;">Limpar</button>' +
      '      <button type="button" class="fc-dme-basket-buy" style="cursor:pointer; padding:10px 14px; border-radius:10px; font-weight:700;">Comprar</button>' +
      '    </div>' +
      '  </div>' +
      '</div>';

    $('body').append(html);

    $(document).on('click', '.fc-dme-basket-clear', function() {
      console.log('🗑️ Limpando todos os cards selecionados');
      $('.fc-dme-card').each(function() {
        const $card = $(this);
        if (isCardSelected($card)) {
          deselectCard($card, { resetSelectionToDefault: true });
        }
      });
      renderBasketBar();
      updateAllPrices();
    });

    $(document).on('click', '.fc-dme-basket-buy', function() {
      const $btn = $(this);
      if ($btn.prop('disabled')) return;

      const keys = Array.from(selectedSbcs.keys());

      // Valida min_qty por item
      var minQtyErrors = [];
      keys.forEach(function(k) {
        var $c = findCardByKey(k);
        if (!$c.length) return;
        var ov = getCardOverride($c);
        if (!ov || !ov.min_qty) return;
        var st2 = cardState.get(k);
        var qty = (st2 && Number.isFinite(st2.quantity)) ? st2.quantity : 1;
        if (qty < ov.min_qty) {
          minQtyErrors.push('"' + ($c.data('name') || k) + '": mínimo ' + ov.min_qty + ', selecionado ' + qty);
        }
      });
      if (minQtyErrors.length) {
        alert('Quantidade mínima não atingida:\n' + minQtyErrors.join('\n'));
        $btn.prop('disabled', false);
        return;
      }

      console.group('🛒 Iniciando Compra');
      console.log('Keys selecionadas:', keys);
      console.log('Total:', keys.length);
      console.groupEnd();
      
      if (!keys.length) {
        console.warn('⚠️ Nenhuma key selecionada');
        return;
      }

      $btn.prop('disabled', true);
      const original = $btn.text();
      $btn.text('Gerando...');

      const payload = buildOrderPayload(keys);
      
      if (!payload || !payload.items || payload.items.length === 0) {
        console.error('❌ Payload vazio ou inválido');
        alert('Erro: Nenhum item válido encontrado');
        $btn.prop('disabled', false).text(original);
        return;
      }
      
      if (payload.items.length !== keys.length) {
        console.error('⚠️ Discrepância:', {
          esperado: keys.length,
          processado: payload.items.length
        });
        alert('Aviso: Alguns itens não puderam ser processados (' + payload.items.length + ' de ' + keys.length + ')');
      }

      console.log('📤 Enviando payload para o servidor');
      console.log('Payload completo:', JSON.stringify(payload, null, 2));
      
      $.ajax({
        url: fcDME.ajaxUrl,
        type: 'POST',
        data: {
          action: 'fc_dme_create_order',
          nonce: fcDME.nonce,
          payload: JSON.stringify(payload)
        },
        success: function(resp) {
          console.log('📥 Resposta do servidor:', resp);
          
          if (resp && resp.success && resp.data && resp.data.redirect_url) {
            console.log('✅ Pedido criado, redirecionando para:', resp.data.redirect_url);
            setTimeout(function() {
              window.location.href = resp.data.redirect_url;
            }, 1000);
            return;
          }
          
          const msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Falha ao criar pedido';
          console.error('❌ Erro na resposta:', msg);
          alert('Erro: ' + msg);
          $btn.prop('disabled', false).text(original);
        },
        error: function(xhr, status, error) {
          console.error('❌ Erro AJAX:', { xhr, status, error });
          alert('Erro ao conectar com o servidor');
          $btn.prop('disabled', false).text(original);
        }
      });
    });
  }

  function renderBasketBar() {
    const $bar = $('#' + BASKET_ID);
    if (!$bar.length) return;

    const keys = Array.from(selectedSbcs.keys());
    const count = keys.length;

    if (count === 0) {
      $bar.hide();
      return;
    }

    let sumCoins = 0;
    let sumBrlCents = 0;

    keys.forEach(function(key) {
      const $card = findCardByKey(key);
      if (!$card || !$card.length) return;

      const coins = getCardSelectedCoinsForCurrentPlatform($card);
      const brl   = getCardSelectedBrlCentsForCurrentPlatform($card);
      const stQty = cardState.get(key);
      const qty   = (stQty && Number.isFinite(stQty.quantity) && stQty.quantity > 0) ? stQty.quantity : 1;

      if (Number.isFinite(coins) && coins > 0) sumCoins += coins * qty;
      if (Number.isFinite(brl) && brl > 0) sumBrlCents += brl * qty;
    });

    let totalQty = 0;
    keys.forEach(function(k) {
      const stk = cardState.get(k);
      totalQty += (stk && Number.isFinite(stk.quantity) && stk.quantity > 0) ? stk.quantity : 1;
    });
    $bar.find('.fc-dme-basket-count').text(String(totalQty));
    $bar.find('.fc-dme-basket-coins').text(formatCoins(sumCoins));
    $bar.find('.fc-dme-basket-brl').text(formatBRLCents(sumBrlCents));
    $bar.show();
  }

  function buildOrderPayload(keys) {
    console.group('📦 Construindo Payload');
    console.log('Keys recebidas:', keys);

    const items = [];

    keys.forEach(function(key) {
      const $card = findCardByKey(key);
      
      if (!$card || !$card.length) {
        console.error('❌ Card NÃO encontrado para key:', key);
        return;
      }

      ensureCardState($card);
      recomputeAndStoreCardTotals($card);

      const st = cardState.get(key);
      const challenges = getChallenges($card);

      const eff = computeEffectiveTotalsForPlatform(challenges, st ? st.selected : new Set(), currentPlatform);
      const pricingPlatform = eff.pricingPlatform;

      const totalCoins = (eff && Number.isFinite(eff.totalCoins)) ? eff.totalCoins : getCardSelectedCoinsForCurrentPlatform($card);
      let totalBrlCents = (eff && Number.isFinite(eff.totalBrlCents)) ? eff.totalBrlCents : getCardSelectedBrlCentsForCurrentPlatform($card);

      const itemType = String($card.attr('data-type') || $card.data('type') || '');
      const itemName = String($card.attr('data-name') || $card.data('name') || 'SBC');
      const faceUrl  = String($card.attr('data-face-url') || '');

      const selectedArr = [];
      if (Array.isArray(challenges) && st && st.selected) {
        for (let i = 0; i < challenges.length; i++) {
          if (!st.selected.has(i)) continue;
          const ch = challenges[i] || {};
          const priceText = (pricingPlatform === 'pc') ? (ch.price_pc || '') : (ch.price_ps || '');
          selectedArr.push({
            index: i,
            name: ch.name || 'Desafio',
            coins: String(priceText || ''),
            image: String(ch.img_url || '')
          });
        }
      }

      const selectionJson = {
        selected_platform: currentPlatform,
        pricing_platform: pricingPlatform,
        selected: selectedArr
      };

      const quantity = (st && Number.isFinite(st.quantity) && st.quantity > 0) ? st.quantity : 1;
      const ov = getCardOverride($card);
      if (ov && Number.isFinite(ov.price_brl_cents) && ov.price_brl_cents > 0) {
        totalBrlCents = ov.price_brl_cents;
      }

      const item = {
        item_key: key,
        quantity: quantity,
        item_type: itemType,
        item_name: itemName,
        face_url: faceUrl,
        platform: currentPlatform,
        pricing_platform: pricingPlatform,
        mode: KNOW_MODE,
        total_coins: totalCoins,
        total_brl_cents: totalBrlCents,
        selection_json: selectionJson
      };

      items.push(item);
    });

    console.log('Total processado:', items.length);
    console.groupEnd();

    return {
      platform: currentPlatform,
      mode: KNOW_MODE,
      items: items
    };
  }

  function formatCoins(n) {
    const v = Number(n);
    if (!isFinite(v) || v <= 0) return '-';
    try { return Math.round(v).toLocaleString('pt-BR'); }
    catch (e) { return String(Math.round(v)); }
  }

  function formatBRLCents(cents) {
    const v = Number(cents);
    if (!isFinite(v) || v <= 0) return '-';
    const brl = v / 100;
    try {
      return brl.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    } catch (e) {
      return 'R$ ' + brl.toFixed(2).replace('.', ',');
    }
  }

  function getCardKey($card) {
    return String($card.attr('data-sbc-key') || '');
  }

  function findCardByKey(key) {
    if (!key) return $();
    
    let $card = $('.fc-dme-card[data-sbc-key="' + cssEscape(key) + '"]');
    if ($card.length) return $card.first();
    
    $card = $('.fc-dme-card').filter(function() {
      return $(this).attr('data-sbc-key') === key;
    });
    
    if ($card.length) return $card.first();
    return $();
  }

  function getChallenges($card) {
    const list = $card.data('challenges');
    return Array.isArray(list) ? list : [];
  }

  function cssEscape(str) {
    return String(str).replace(/"/g, '\\"');
  }

  function escapeHtml(text) {
    if (!text) return '';
    const map = { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
  }

  function ensureCardState($card) {
    const key = getCardKey($card);
    if (!key) return;

    const challenges = getChallenges($card);
    const len = challenges.length;

    if (!cardState.has(key)) {
      const sel = new Set();
      for (let i = 0; i < len; i++) sel.add(i);
      cardState.set(key, { selected: sel, len, quantity: 1 });
      $card.attr('data-selected-len', String(len));
      $card.attr('data-selected-count', String(sel.size));
    } else {
      const st = cardState.get(key);
      if (st && st.len !== len) {
        const sel = new Set();
        for (let i = 0; i < len; i++) sel.add(i);
        cardState.set(key, { selected: sel, len, quantity: 1 });
        $card.attr('data-selected-len', String(len));
        $card.attr('data-selected-count', String(sel.size));
      }
    }
  }

  function resetCardSelectionToAll($card) {
    const key = getCardKey($card);
    if (!key) return;

    const challenges = getChallenges($card);
    const len = challenges.length;

    const sel = new Set();
    for (let i = 0; i < len; i++) sel.add(i);
    cardState.set(key, { selected: sel, len, quantity: 1 });
    $card.attr('data-selected-len', String(len));
    $card.attr('data-selected-count', String(sel.size));
  }

  function sumTotals(challenges, selectedSet, platformUsed) {
    let totalCoins = 0;
    let totalBrlCents = 0;
    let hasBrlData = false;

    for (let i = 0; i < challenges.length; i++) {
      if (selectedSet && !selectedSet.has(i)) continue;

      const ch = challenges[i] || {};

      const coins = (platformUsed === 'console') ? ch.price_ps_coins : ch.price_pc_coins;
      const brl  = (platformUsed === 'console') ? ch.price_ps_brl_cents : ch.price_pc_brl_cents;

      const nCoins = parseInt(coins || '0', 10);
      if (Number.isFinite(nCoins) && nCoins > 0) totalCoins += nCoins;

      const nBrl = parseInt(brl || '0', 10);
      if (Number.isFinite(nBrl) && nBrl > 0) {
        totalBrlCents += nBrl;
        hasBrlData = true;
      }
    }

    return { totalCoins, totalBrlCents, hasBrlData };
  }

  function computeEffectiveTotalsForPlatform(challenges, selectedSet, platform) {
    const baseConsole = sumTotals(challenges, selectedSet, 'console');
    const basePc = sumTotals(challenges, selectedSet, 'pc');

    let eff = (platform === 'console')
      ? { ...baseConsole, pricingPlatform: 'console', baseConsole: baseConsole, basePc: basePc }
      : { ...basePc, pricingPlatform: 'pc', baseConsole: baseConsole, basePc: basePc };

  

    const hasAnyBrl = (eff.hasBrlData === true);
    if (hasAnyBrl && Number.isFinite(eff.totalBrlCents) && eff.totalBrlCents > 0 && eff.totalBrlCents < RULE_MIN_BRL_CENTS) {
      eff.totalBrlCents = RULE_MIN_BRL_CENTS;
    }

    return eff;
  }

  function storeCardTotals($card, effConsole, effPc) {
    $card.attr('data-selected-total-console-coins', String(effConsole.totalCoins || 0));
    $card.attr('data-selected-total-console-brl-cents', String(effConsole.totalBrlCents || 0));
    $card.attr('data-selected-total-pc-coins', String(effPc.totalCoins || 0));
    $card.attr('data-selected-total-pc-brl-cents', String(effPc.totalBrlCents || 0));

    $card.attr('data-selected-pricing-platform-console', String(effConsole.pricingPlatform || 'console'));
    $card.attr('data-selected-pricing-platform-pc', String(effPc.pricingPlatform || 'pc'));
  }

  function recomputeAndStoreCardTotals($card) {
    const key = getCardKey($card);
    if (!key) return;

    ensureCardState($card);

    const st = cardState.get(key);
    const challenges = getChallenges($card);

    if (!challenges.length) {
      $card.attr('data-selected-total-console-coins', '0');
      $card.attr('data-selected-total-console-brl-cents', '0');
      $card.attr('data-selected-total-pc-coins', '0');
      $card.attr('data-selected-total-pc-brl-cents', '0');
      $card.attr('data-selected-pricing-platform-console', 'console');
      $card.attr('data-selected-pricing-platform-pc', 'pc');
      return;
    }

    const effConsole = computeEffectiveTotalsForPlatform(challenges, st.selected, 'console');
    const effPc      = computeEffectiveTotalsForPlatform(challenges, st.selected, 'pc');

    storeCardTotals($card, effConsole, effPc);
    $card.attr('data-selected-count', String(st.selected.size));

    // Override de preco: sobrescreve brl e bypassa regra de minimo
    var ovCard = getCardOverride($card);
    if (ovCard && Number.isFinite(ovCard.price_brl_cents) && ovCard.price_brl_cents > 0) {
      $card.attr('data-selected-total-console-brl-cents', String(ovCard.price_brl_cents));
      $card.attr('data-selected-total-pc-brl-cents',      String(ovCard.price_brl_cents));
    }
  }

  function getCardSelectedCoinsForCurrentPlatform($card) {
    const v = currentPlatform === 'console'
      ? parseInt($card.attr('data-selected-total-console-coins') || '0', 10)
      : parseInt($card.attr('data-selected-total-pc-coins') || '0', 10);
    return Number.isFinite(v) ? v : 0;
  }

  function getCardSelectedBrlCentsForCurrentPlatform($card) {
    const v = currentPlatform === 'console'
      ? parseInt($card.attr('data-selected-total-console-brl-cents') || '0', 10)
      : parseInt($card.attr('data-selected-total-pc-brl-cents') || '0', 10);
    return Number.isFinite(v) ? v : 0;
  }

  function getCardDisplayValue($card) {
    const selBrl = getCardSelectedBrlCentsForCurrentPlatform($card);
    const selCoins = getCardSelectedCoinsForCurrentPlatform($card);

    const rawConsole = $card.attr('data-price-console') || '';
    const rawPc      = $card.attr('data-price-pc') || '';

    const itemBrlConsole   = parseInt($card.attr('data-price-console-brl-cents') || '0', 10);
    const itemBrlPc        = parseInt($card.attr('data-price-pc-brl-cents') || '0', 10);
    const itemCoinsConsole = parseInt($card.attr('data-price-console-coins') || '0', 10);
    const itemCoinsPc      = parseInt($card.attr('data-price-pc-coins') || '0', 10);

    if (Number.isFinite(selBrl) && selBrl > 0) return { type: 'brl', value: selBrl };
    if (Number.isFinite(selCoins) && selCoins > 0) return { type: 'coins', value: selCoins };

    if (currentPlatform === 'console') {
      if (Number.isFinite(itemBrlConsole) && itemBrlConsole > 0) return { type: 'brl', value: itemBrlConsole };
      if (Number.isFinite(itemCoinsConsole) && itemCoinsConsole > 0) return { type: 'coins', value: itemCoinsConsole };
      if (rawConsole) return { type: 'raw', value: rawConsole };
    } else {
      if (Number.isFinite(itemBrlPc) && itemBrlPc > 0) return { type: 'brl', value: itemBrlPc };
      if (Number.isFinite(itemCoinsPc) && itemCoinsPc > 0) return { type: 'coins', value: itemCoinsPc };
      if (rawPc) return { type: 'raw', value: rawPc };
    }

    return { type: 'none', value: '-' };
  }

  function updateAllPrices() {
    $('.fc-dme-card').each(function() {
      const $card = $(this);
      ensureCardState($card);
      recomputeAndStoreCardTotals($card);

      const d = getCardDisplayValue($card);

      let display = '-';
      if (d.type === 'brl') display = formatBRLCents(d.value);
      else if (d.type === 'coins') display = '🪙 ' + formatCoins(d.value);
      else if (d.type === 'raw') display = d.value;

      $card.find('.fc-dme-price-value').text(display);
    });
  }

  function isCardSelected($card) {
    return String($card.attr('data-selected') || '0') === '1';
  }

  function getCardOverride($card) {
    var ovKey = $card.attr('data-override-key') || '';
    return (ovKey && ITEM_OVERRIDES[ovKey]) ? ITEM_OVERRIDES[ovKey] : null;
  }

  function getCardMaxQty($card) {
    var ov = getCardOverride($card);
    if (ov && Number.isFinite(ov.max_qty) && ov.max_qty > 0) return ov.max_qty;
    var completed = $card.attr('data-completed') || '';
    if (!completed) return 99;
    var parts = completed.split('/');
    var max = parseInt((parts[1] || '99').trim(), 10);
    return (Number.isFinite(max) && max > 0) ? max : 99;
  }

  function updateCardQtyDisplay($card) {
    var key = getCardKey($card);
    var st  = cardState.get(key);
    var qty = (st && Number.isFinite(st.quantity) && st.quantity > 0) ? st.quantity : 0;
    var max = getCardMaxQty($card);
    $card.find('.fc-dme-completed-value').text(qty + '/' + max);
  }

  function applySelectedUi($card, selected) {
    const $solve  = $card.find('.fc-dme-solve-btn');
    const $edit   = $card.find('.fc-dme-edit-btn');
    const $remove = $card.find('.fc-dme-remove-btn');
    const $add    = $card.find('.fc-dme-add-btn');

    if (selected) {
      const key    = getCardKey($card);
      const st     = cardState.get(key);
      const qty    = (st && Number.isFinite(st.quantity) && st.quantity > 0) ? st.quantity : 1;
      const maxQty = getCardMaxQty($card);

      $card.attr('data-selected', '1');
      $solve.hide();
      $edit.hide();
      updateCardQtyDisplay($card);

      if (qty >= maxQty) {
        $add.hide();
        $remove.show();
      } else {
        $add.show();
        $remove.show();
      }
    } else {
      $card.attr('data-selected', '0');
      $solve.show();
      $edit.hide();
      $add.hide();
      $remove.hide();
      // restaura texto original do completed
      var original = $card.attr('data-completed') || '';
      if (original) $card.find('.fc-dme-completed-value').text(original);
    }
  }

  function selectCard($card) {
    const key = getCardKey($card);
    if (!key) return;

    const name = $card.data('name') || 'SBC';

    ensureCardState($card);
    recomputeAndStoreCardTotals($card);

    const stSel = cardState.get(key);
    if (stSel && (!Number.isFinite(stSel.quantity) || stSel.quantity < 1)) stSel.quantity = 1;

    selectedSbcs.set(key, { name: name });
    
    console.log('✅ Card selecionado:', key);

    applySelectedUi($card, true);
    renderBasketBar();
  }

  function deselectCard($card, opts) {
    const key = getCardKey($card);
    if (!key) return;

    selectedSbcs.delete(key);

    const reset = opts && opts.resetSelectionToDefault;
    if (reset) {
      resetCardSelectionToAll($card);
      recomputeAndStoreCardTotals($card);
    }

    applySelectedUi($card, false);
    renderBasketBar();
  }

  function initAddButtons() {
    $(document).on('click', '.fc-dme-add-btn', function(e) {
      e.preventDefault();
      const $card  = $(this).closest('.fc-dme-card');
      const key    = getCardKey($card);
      if (!key || !cardState.has(key)) return;
      const st     = cardState.get(key);
      const maxQty = getCardMaxQty($card);
      if (st.quantity < maxQty) {
        st.quantity++;
        recomputeAndStoreCardTotals($card);
        applySelectedUi($card, true);
        renderBasketBar();
        updateAllPrices();
      }
    });
  }

  function initModalOpenButtons() {
    $(document).on('click', '.fc-dme-solve-btn, .fc-dme-edit-btn', function(e) {
      e.preventDefault();

      const $card = $(this).closest('.fc-dme-card');
      const key = getCardKey($card);
      
      ensureCardState($card);
      recomputeAndStoreCardTotals($card);

      const sbcName = $card.data('name') || 'SBC';
      const challenges = getChallenges($card);

      openModal($card, sbcName, challenges);
    });
  }

  function initRemoveButtons() {
    $(document).on('click', '.fc-dme-remove-btn', function(e) {
      e.preventDefault();
      const $card = $(this).closest('.fc-dme-card');
      const key   = getCardKey($card);
      if (!key || !cardState.has(key)) return;
      const st = cardState.get(key);
      st.quantity = (Number.isFinite(st.quantity) && st.quantity > 1) ? st.quantity - 1 : 0;
      if (st.quantity === 0) {
        deselectCard($card, { resetSelectionToDefault: true });
      } else {
        recomputeAndStoreCardTotals($card);
        applySelectedUi($card, true);
        renderBasketBar();
      }
      updateAllPrices();
    });
  }

  function openModal($card, sbcName, challenges) {
    const $modal = $('#fc-dme-modal');
    const $body  = $modal.find('.fc-dme-modal-body');
const COIN_ICON = 'https://chamacoins.com.br/wp-content/uploads/2025/11/ChatGPT-Image-22-de-out.-de-2025-18_17_45-1-1.png';

    const key = getCardKey($card);
    const st  = cardState.get(key) || { selected: new Set(), len: 0 };

    $modal.attr('data-open-key', key);

    const list = Array.isArray(challenges) ? challenges : [];
    const count = list.length;

    const eff = computeEffectiveTotalsForPlatform(list, st.selected, currentPlatform);
    const pricingPlatform = eff.pricingPlatform;

    let html = '';
    html += '<div class="fc-dme-modal-header">';
    html += '  <h3 class="fc-dme-modal-title">' + escapeHtml(sbcName) + '</h3>';
    html += '  <div class="fc-dme-modal-meta">';
    html += '    <span class="fc-dme-modal-count"><span class="fc-dme-count-val">' + count + '</span> elencos</span>';
html += '    <span class="fc-dme-modal-total">';
html += '      <img class="fc-dme-coin-icon" src="' + COIN_ICON + '" alt="Coins" />' +
        ' <span class="fc-dme-total-coins">' + formatCoins(eff.totalCoins) + '</span>';
html += '      · <span class="fc-dme-total-brl">' + formatBRLCents(eff.totalBrlCents) + '</span>';
html += '    </span>';

    html += '  </div>';
    html += '</div>';

    if (!list.length) {
      html += '<div class="fc-dme-empty-challenges"><p>Nenhum desafio encontrado para este SBC.</p></div>';
      $body.html(html);
    // === ABRIR MODAL (travando scroll do fundo) ===
    $modal.addClass('active');

    // trava o scroll do fundo (método robusto: mantém a posição)
    const scrollY = window.scrollY || window.pageYOffset || 0;
    document.body.dataset.fcLockScrollY = String(scrollY);

    document.body.style.position = 'fixed';
    document.body.style.top = '-' + scrollY + 'px';
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.width = '100%';
    document.body.style.overflow = 'hidden';

    // garante que o scroll aconteça DENTRO do modal
    // (se seu modal body tiver outro seletor, mantenha este)
    $modal.find('.fc-dme-modal-body').css({
      overflowY: 'auto',
      WebkitOverflowScrolling: 'touch'
    });

      return;
    }

    html += '<div class="fc-dme-selectall-row">';
    html += '  <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">';
    html += '    <input type="checkbox" class="fc-dme-selectall" checked />';
    html += '    <span>Selecionar todos</span>';
    html += '  </label>';
    html += '</div>';

    html += '<div class="fc-dme-challenges-list">';
    for (let i = 0; i < list.length; i++) {
      html += buildChallengeHtml(list[i], i, st.selected.has(i), pricingPlatform);
    }
    html += '</div>';

html += '<div class="fc-dme-totalbar fc-dme-totalbar--modal" style="margin-top:14px; padding:12px; border-top:1px solid rgba(255,255,255,0.08); display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">';

    html += '  <div class="fc-dme-totalbar-left" style="display:flex; flex-direction:column; gap:4px;">';
    html += '    <div style="opacity:.9;">Selecionados: <strong class="fc-dme-selected-count">' + st.selected.size + '</strong> / ' + count + '</div>';
html += '    <div style="opacity:.9;">Total: ' +
        '<img class="fc-dme-coin-icon" src="' + COIN_ICON + '" alt="Coins" /> ' +

        '<strong class="fc-dme-totalbar-brl">' + formatBRLCents(eff.totalBrlCents) + '</strong>' +
        '</div>';

    html += '  </div>';
    html += '  <button class="fc-dme-conclude-btn" style="cursor:pointer; padding:10px 14px; border-radius:10px; font-weight:700;">Concluído</button>';
    html += '</div>';

    $body.html(html);

    syncSelectAllCheckbox($modal, count);

$modal.addClass('active');
lockBackgroundScroll();

// garante scroll só dentro do modal
$modal.find('.fc-dme-modal-body').css({
  overflowY: 'auto',
  WebkitOverflowScrolling: 'touch'
});

  }

  function buildChallengeHtml(ch, idx, checked, platformUsed) {
    const challenge = ch || {};
    const name = challenge.name || 'Desafio';
const img = challenge.reward_img || challenge.img_url || '';

    const raw = (platformUsed === 'console') ? challenge.price_ps : challenge.price_pc;
    const coins = (platformUsed === 'console') ? challenge.price_ps_coins : challenge.price_pc_coins;
    const brlCents = (platformUsed === 'console') ? challenge.price_ps_brl_cents : challenge.price_pc_brl_cents;

    const coinsInt = parseInt(coins || '', 10);
    const brlInt   = parseInt(brlCents || '', 10);

    let html = '<div class="fc-dme-challenge-card" data-idx="' + idx + '">';
        const COIN_ICON = 'https://chamacoins.com.br/wp-content/uploads/2025/11/ChatGPT-Image-22-de-out.-de-2025-18_17_45-1-1.png';

    // badge flutuante (coins) no topo direito
    html += '<div class="fc-dme-coins-badge" title="Preço em coins">';
    html += '  <img class="fc-dme-coin-icon" src="' + COIN_ICON + '" alt="Coins" />';
    html += '  <span class="fc-dme-coins-badge-text">' + (raw ? escapeHtml(raw) : '-') + '</span>';
    html += '</div>';


    // ✅ Checkbox + Nome do Elenco (no topo)
    html += '<div class="fc-dme-chk-row" style="display:flex;align-items:center;gap:10px;">';
    html += '  <input type="checkbox" class="fc-dme-chk" data-idx="' + idx + '" ' + (checked ? 'checked' : '') + ' />';
    html += '  <span class="fc-dme-challenge-name-inline">' + escapeHtml(name) + '</span>';
    html += '</div>';

    // ✅ Imagem do elenco (maior, sem compressão)
    html += '<div class="fc-dme-challenge-image">';
    if (img) html += '  <img src="' + escapeHtml(img) + '" alt="' + escapeHtml(name) + '" />';
    else html += '  <div class="fc-dme-challenge-placeholder">?</div>';
    html += '</div>';

    html += '<div class="fc-dme-challenge-footer">';




    html += '<div class="fc-dme-challenge-price-row">';
    html += '  <span class="fc-dme-challenge-coins">Preço:</span>';
    html += '  <span class="fc-dme-challenge-price-value">' + (Number.isFinite(brlInt) && brlInt > 0 ? formatBRLCents(brlInt) : '-') + '</span>';
    html += '</div>';

    html += '</div>';
    html += '</div>';

    return html;
  }

  function initModalSelectionHandlers() {
    $(document).on('change', '.fc-dme-chk', function() {
      const $modal = $('#fc-dme-modal');
      if (!$modal.hasClass('active')) return;

      const idx = parseInt($(this).attr('data-idx') || '', 10);
      if (!Number.isFinite(idx)) return;

      const key = $modal.attr('data-open-key') || '';
      if (!key || !cardState.has(key)) return;

      const st = cardState.get(key);
      if ($(this).is(':checked')) st.selected.add(idx);
      else st.selected.delete(idx);

      updateModalTotalsFromState();
      syncSelectAllCheckbox($modal, st.len);
    });

    $(document).on('change', '.fc-dme-selectall', function() {
      const $modal = $('#fc-dme-modal');
      if (!$modal.hasClass('active')) return;

      const key = $modal.attr('data-open-key') || '';
      if (!key || !cardState.has(key)) return;

      const st = cardState.get(key);
      const checked = $(this).is(':checked');

      st.selected.clear();
      if (checked) {
        for (let i = 0; i < st.len; i++) st.selected.add(i);
      }

      $modal.find('.fc-dme-chk').each(function() {
        $(this).prop('checked', checked);
      });

      updateModalTotalsFromState();
      syncSelectAllCheckbox($modal, st.len);
    });

    $(document).on('click', '.fc-dme-conclude-btn', function(e) {
      e.preventDefault();

      const $modal = $('#fc-dme-modal');
      if (!$modal.hasClass('active')) return;

      const key = $modal.attr('data-open-key') || '';
      if (!key) return;

      const $card = findCardByKey(key);
      if (!$card.length) {
        closeModal();
        return;
      }

      recomputeAndStoreCardTotals($card);
      selectCard($card);
      updateAllPrices();
      closeModal();
    });
  }

  function updateModalTotalsFromState() {
    const $modal = $('#fc-dme-modal');
    const key = $modal.attr('data-open-key') || '';
    if (!key || !cardState.has(key)) return;

    const st = cardState.get(key);
    const $card = findCardByKey(key);
    const challenges = $card.length ? getChallenges($card) : [];

    const eff = computeEffectiveTotalsForPlatform(challenges, st.selected, currentPlatform);

    $modal.find('.fc-dme-total-coins').text(formatCoins(eff.totalCoins));
    $modal.find('.fc-dme-total-brl').text(formatBRLCents(eff.totalBrlCents));

    $modal.find('.fc-dme-selected-count').text(String(st.selected.size));
    $modal.find('.fc-dme-totalbar-coins').text(formatCoins(eff.totalCoins));
    $modal.find('.fc-dme-totalbar-brl').text(formatBRLCents(eff.totalBrlCents));
  }

  function syncSelectAllCheckbox($modal, totalLen) {
    const key = $modal.attr('data-open-key') || '';
    if (!key || !cardState.has(key)) return;

    const st = cardState.get(key);
    const $selAll = $modal.find('.fc-dme-selectall');
    if (!$selAll.length) return;

    const selectedCount = st.selected.size;
    const el = $selAll.get(0);
    if (!el) return;

    if (selectedCount === 0) {
      $selAll.prop('checked', false);
      el.indeterminate = false;
    } else if (selectedCount === totalLen) {
      $selAll.prop('checked', true);
      el.indeterminate = false;
    } else {
      $selAll.prop('checked', true);
      el.indeterminate = true;
    }
  }

  function refreshOpenModalTotals() {
    const $modal = $('#fc-dme-modal');
    const key = $modal.attr('data-open-key') || '';
    if (!key) return;

    const $card = findCardByKey(key);
    if (!$card.length) return;

    const sbcName = $card.data('name') || 'SBC';
    const challenges = getChallenges($card);

    openModal($card, sbcName, challenges);
  }

  function initModalClose() {
    $(document).on('click', '.fc-dme-modal-close', function() { closeModal(); });
    $(document).on('click', '.fc-dme-modal-overlay', function() { closeModal(); });

    $(document).on('keydown', function(e) {
      if (e.key === 'Escape' && $('#fc-dme-modal').hasClass('active')) closeModal();
    });
  }

function closeModal() {
  const $modal = $('#fc-dme-modal');
  $modal.removeClass('active');
  $modal.removeAttr('data-open-key');

  unlockBackgroundScroll();

  $modal.find('.fc-dme-modal-body').css({
    overflowY: '',
    WebkitOverflowScrolling: ''
  });
}



  function initRefreshButton() {
    $('#fc-dme-refresh').on('click', function(e) {
      e.preventDefault();

      const $btn = $(this);
      if ($btn.prop('disabled')) return;

      if (selectedSbcs.size > 0) {
        const confirmMsg = 'Você tem ' + selectedSbcs.size + ' item(ns) selecionado(s). Ao atualizar, suas seleções serão perdidas. Continuar?';
        if (!confirm(confirmMsg)) {
          return;
        }
      }

      $btn.prop('disabled', true);
      const originalText = $btn.text();
      $btn.text('🔄 Atualizando...');

      $.ajax({
        url: fcDME.ajaxUrl,
        type: 'POST',
        data: { action: 'fc_dme_refresh_cache', nonce: fcDME.nonce },
        success: function(response) {
          if (response.success) {
            location.reload();
          } else {
            alert('Erro: ' + (response.data.message || 'Falha ao atualizar'));
            $btn.prop('disabled', false).text(originalText);
          }
        },
        error: function() {
          alert('Erro ao conectar com o servidor');
          $btn.prop('disabled', false).text(originalText);
        }
      });
    });
  }

})(jQuery);