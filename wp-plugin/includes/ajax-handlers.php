<?php
/**
 * Handlers WordPress AJAX
 */

if ( ! defined( 'WPINC' ) ) die;

/* ── helper de segurança ─────────────────────────────────────── */
function fc_dl_verify() {
    nocache_headers();
    if ( ! check_ajax_referer( 'fc_dl_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Nonce inválido' ], 403 );
    }
}

/**
 * Adapta alt_sidebar do scraper para o shape do renderer.
 */
function fc_dl_adapt_player( array $p ): array {
    if ( isset( $p['alt_sidebar']['positions'] ) ) {
        $p['alt_sidebar'] = [
            'right' => [
                'positions' => $p['alt_sidebar']['positions'] ?? [],
                'css_vars'  => $p['alt_sidebar']['css_vars']  ?? [],
            ],
        ];
    }
    return $p;
}

/**
 * Traduz expires_in de EN para PT-BR.
 */
function fc_dl_translate_expires( string $str ): string {
    if ( ! $str ) return '';
    return str_ireplace(
        [ 'days', 'day', 'weeks', 'week', 'months', 'month', 'hours', 'hour', 'minutes', 'minute' ],
        [ 'dias', 'dia', 'semanas', 'semana', 'meses',  'mês',  'horas', 'hora', 'minutos',  'minuto' ],
        $str
    );
}

/* ═══════════════════════════════════════════════════════════════
   fc_dl_get_squads
   ═══════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_fc_dl_get_squads',        'fc_dl_ajax_get_squads' );
add_action( 'wp_ajax_nopriv_fc_dl_get_squads', 'fc_dl_ajax_get_squads' );

function fc_dl_ajax_get_squads() {
    fc_dl_verify();
    $squads = FC_DL_VPS_Api::get_squads();
    if ( ! $squads ) wp_send_json_error( [ 'message' => 'Erro ao buscar squads da API' ] );
    foreach ( $squads as &$squad ) {
        $label           = FC_DL_VPS_Api::label_from_url( $squad['url'] );
        $squad['label']  = $label;
        $data            = FC_DL_VPS_Api::get_squad( $label );
        $squad['loaded'] = ( $data !== null && empty( $data['error'] ) );
        $squad['total']  = $squad['loaded'] ? ( $data['meta']['total'] ?? 0 ) : 0;
    }
    unset( $squad );
    wp_send_json_success( $squads );
}

/* ═══════════════════════════════════════════════════════════════
   fc_dl_get_squad
   ═══════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_fc_dl_get_squad',        'fc_dl_ajax_get_squad' );
add_action( 'wp_ajax_nopriv_fc_dl_get_squad', 'fc_dl_ajax_get_squad' );

function fc_dl_ajax_get_squad() {
    fc_dl_verify();
    $label = sanitize_text_field( $_POST['label'] ?? '' );
    if ( ! $label ) wp_send_json_error( [ 'message' => 'label obrigatório' ] );
    $data = FC_DL_VPS_Api::get_squad( $label );
    if ( $data === null ) wp_send_json_success( [ 'loaded' => false, 'players' => [] ] );
    if ( ! $data || ! empty( $data['error'] ) ) wp_send_json_error( [ 'message' => 'Squad não encontrado' ] );

    $can_render = class_exists( 'FC_Card_Visual_Renderer' ) && class_exists( 'FC_Card_Normalizer' );
    $players    = [];
    foreach ( $data['data'] as $item ) {
        $p         = fc_dl_adapt_player( $item['player'] ?? [] );
        $card_html = '';
        if ( $can_render ) {
            $normalized = FC_Card_Normalizer::normalize( $p );
            $card_html  = FC_Card_Visual_Renderer::render_card( $normalized, [
                'width' => 250, 'show_playstyles' => true,
                'show_extra_info' => true, 'responsive' => true,
            ] );
        }
        $players[] = [
            'name'      => $p['name']     ?? '',
            'rating'    => $p['rating']   ?? '',
            'position'  => $p['position'] ?? '',
            'card_html' => $card_html,
        ];
    }
    wp_send_json_success( [ 'loaded' => true, 'meta' => $data['meta'], 'players' => $players ] );
}

/* ═══════════════════════════════════════════════════════════════
   fc_dl_run_scrape
   ═══════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_fc_dl_run_scrape',        'fc_dl_ajax_run_scrape' );
add_action( 'wp_ajax_nopriv_fc_dl_run_scrape', 'fc_dl_ajax_run_scrape' );

function fc_dl_ajax_run_scrape() {
    fc_dl_verify();
    $label  = sanitize_text_field( $_POST['label'] ?? '' );
    $url    = esc_url_raw( $_POST['url'] ?? '' );
    if ( ! $label || ! $url ) wp_send_json_error( [ 'message' => 'label e url obrigatórios' ] );
    $result = FC_DL_VPS_Api::run_scrape( $label, $url );
    if ( ! $result ) wp_send_json_error( [ 'message' => 'Erro ao iniciar scrape' ] );
    wp_send_json_success( $result );
}

/* ═══════════════════════════════════════════════════════════════
   fc_dl_scrape_status
   ═══════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_fc_dl_scrape_status',        'fc_dl_ajax_scrape_status' );
add_action( 'wp_ajax_nopriv_fc_dl_scrape_status', 'fc_dl_ajax_scrape_status' );

function fc_dl_ajax_scrape_status() {
    fc_dl_verify();
    $label = sanitize_text_field( $_POST['label'] ?? '' );
    if ( ! $label ) wp_send_json_error( [ 'message' => 'label obrigatório' ] );
    $status = FC_DL_VPS_Api::get_status( $label );
    if ( ! $status ) wp_send_json_error( [ 'message' => 'Erro ao consultar status' ] );
    wp_send_json_success( $status );
}

/* ═══════════════════════════════════════════════════════════════
   fc_dl_generate_png  (squads)
   ═══════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_fc_dl_generate_png',        'fc_dl_ajax_generate_png' );
add_action( 'wp_ajax_nopriv_fc_dl_generate_png', 'fc_dl_ajax_generate_png' );

function fc_dl_ajax_generate_png() {
    fc_dl_verify();
    set_time_limit( 180 );
    $label   = sanitize_text_field( $_POST['label'] ?? '' );
    $indices = array_map( 'intval', (array) ( $_POST['indices'] ?? [] ) );
    if ( ! $label )          wp_send_json_error( [ 'message' => 'label obrigatório' ] );
    if ( empty( $indices ) ) wp_send_json_error( [ 'message' => 'Selecione ao menos um jogador' ] );
    $data = FC_DL_VPS_Api::get_squad( $label );
    if ( ! $data || ! empty( $data['error'] ) ) wp_send_json_error( [ 'message' => 'Squad não encontrado' ] );
    $can_render = class_exists( 'FC_Card_Visual_Renderer' ) && class_exists( 'FC_Card_Normalizer' );
    if ( ! $can_render ) wp_send_json_error( [ 'message' => 'FC Card Renderer não instalado' ] );
    $players = [];
    foreach ( $indices as $idx ) {
        $item = $data['data'][ $idx ] ?? null;
        if ( ! $item ) continue;
        $p          = fc_dl_adapt_player( $item['player'] ?? [] );
        $normalized = FC_Card_Normalizer::normalize( $p );
        $card_html  = FC_Card_Visual_Renderer::render_card( $normalized, [
            'width' => FC_DL_Png_Builder::CARD_WIDTH, 'show_playstyles' => true,
            'show_extra_info' => true, 'responsive' => false,
        ] );
        $players[] = [ 'name' => $p['name'] ?? "player_$idx", 'card_html' => $card_html ];
    }
    if ( empty( $players ) ) wp_send_json_error( [ 'message' => 'Nenhum jogador válido' ] );
    $result = FC_DL_Png_Builder::generate( $players, FC_DL_Png_Builder::get_card_css() );
    if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    wp_send_json_success( $result );
}

/* ═══════════════════════════════════════════════════════════════
   fc_dl_get_dme_items
   Retorna jogadores (com card renderizado) E recompensas.
   Players têm global_idx para PNG (índice no array de players-only).
   Rewards são apenas visuais (sem checkbox/PNG).
   ═══════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_fc_dl_get_dme_items',        'fc_dl_ajax_get_dme_items' );
add_action( 'wp_ajax_nopriv_fc_dl_get_dme_items', 'fc_dl_ajax_get_dme_items' );

function fc_dl_ajax_get_dme_items() {
    fc_dl_verify();

    if ( ! class_exists( 'FC_DME_API' ) ) {
        wp_send_json_error( [ 'message' => 'Plugin FC DME Solver não está ativo' ] );
    }
    $can_render = class_exists( 'FC_Card_Visual_Renderer' ) && class_exists( 'FC_Card_Normalizer' );
    if ( ! $can_render ) {
        wp_send_json_error( [ 'message' => 'FC Card Renderer não instalado' ] );
    }

    $items = FC_DME_API::fetch_sbcs( true );
    if ( is_wp_error( $items ) ) {
        wp_send_json_error( [ 'message' => $items->get_error_message() ] );
    }

    // ── Separa arrays mantendo o global_idx dos players idêntico
    //    ao usado em fc_dl_generate_dme_png (índice no players-only array)
    $players_raw = array_values( array_filter( $items, function ( $i ) {
        return ! empty( $i['is_player'] ) && ! empty( $i['player_details'] );
    } ) );

    $result = [];

    // Players primeiro (com checkbox + PNG)
    foreach ( $players_raw as $pidx => $item ) {
        $p = fc_dl_adapt_player( $item['player_details'] );
        if ( empty( $p ) ) continue;

        $normalized = FC_Card_Normalizer::normalize( $p );
        $card_html  = FC_Card_Visual_Renderer::render_card( $normalized, [
            'width'           => 250,
            'show_playstyles' => true,
            'show_extra_info' => true,
            'responsive'      => true,
        ] );

        $result[] = [
            'type'       => 'player',
            'global_idx' => $pidx,
            'name'       => $p['name']     ?? ( $item['name'] ?? '' ),
            'rating'     => $p['rating']   ?? '',
            'position'   => $p['position'] ?? '',
            'card_html'  => $card_html,
            'expires_in' => fc_dl_translate_expires( trim( (string) ( $item['expires_in'] ?? '' ) ) ),
        ];
    }

    // Recompensas depois (apenas visual, sem checkbox)
    foreach ( $items as $item ) {
        if ( ! empty( $item['is_player'] ) ) continue; // pula players

        $name    = trim( (string) ( $item['name'] ?? '' ) );
        $img     = trim( (string) ( $item['reward_img'] ?? '' ) );
        $expires = fc_dl_translate_expires( trim( (string) ( $item['expires_in'] ?? '' ) ) );

        if ( ! $name && ! $img ) continue; // item sem dados visíveis

        $result[] = [
            'type'       => 'reward',
            'name'       => $name,
            'reward_img' => $img,
            'expires_in' => $expires,
        ];
    }

    wp_send_json_success( [
        'items'         => $result,
        'total_players' => count( $players_raw ),
        'total'         => count( $result ),
    ] );
}

/* ═══════════════════════════════════════════════════════════════
   fc_dl_refresh_squads
   Dispara a descoberta de squads novos na VPS e retorna o índice atualizado.
   ═══════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_fc_dl_refresh_squads',        'fc_dl_ajax_refresh_squads' );
add_action( 'wp_ajax_nopriv_fc_dl_refresh_squads', 'fc_dl_ajax_refresh_squads' );

function fc_dl_ajax_refresh_squads() {
    fc_dl_verify();
    set_time_limit( 120 );

    $result = FC_DL_VPS_Api::refresh_squads();
    if ( ! $result ) {
        wp_send_json_error( [ 'message' => 'Erro ao buscar squads na VPS' ] );
    }

    // Enriquece cada squad com status loaded/total (mesmo que fc_dl_get_squads)
    $squads = $result['squads'] ?? [];
    foreach ( $squads as &$squad ) {
        $label           = FC_DL_VPS_Api::label_from_url( $squad['url'] );
        $squad['label']  = $label;
        $data            = FC_DL_VPS_Api::get_squad( $label );
        $squad['loaded'] = ( $data !== null && empty( $data['error'] ) );
        $squad['total']  = $squad['loaded'] ? ( $data['meta']['total'] ?? 0 ) : 0;
    }
    unset( $squad );

    wp_send_json_success( $squads );
}

/* ═══════════════════════════════════════════════════════════════
   fc_dl_generate_dme_png
   Índices = posição no array players-only (mesmo filtro acima)
   ═══════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_fc_dl_generate_dme_png',        'fc_dl_ajax_generate_dme_png' );
add_action( 'wp_ajax_nopriv_fc_dl_generate_dme_png', 'fc_dl_ajax_generate_dme_png' );

function fc_dl_ajax_generate_dme_png() {
    fc_dl_verify();
    set_time_limit( 180 );

    if ( ! class_exists( 'FC_DME_API' ) )      wp_send_json_error( [ 'message' => 'Plugin FC DME Solver não está ativo' ] );
    if ( ! class_exists( 'FC_Card_Visual_Renderer' ) || ! class_exists( 'FC_Card_Normalizer' ) )
        wp_send_json_error( [ 'message' => 'FC Card Renderer não instalado' ] );

    $indices = array_map( 'intval', (array) ( $_POST['indices'] ?? [] ) );
    if ( empty( $indices ) ) wp_send_json_error( [ 'message' => 'Selecione ao menos um jogador' ] );

    $items = FC_DME_API::fetch_sbcs( true );
    if ( is_wp_error( $items ) ) wp_send_json_error( [ 'message' => $items->get_error_message() ] );

    // Mesmo filtro do get_dme_items → garante que global_idx bate
    $players_raw = array_values( array_filter( $items, function ( $i ) {
        return ! empty( $i['is_player'] ) && ! empty( $i['player_details'] );
    } ) );

    $players = [];
    foreach ( $indices as $idx ) {
        $item = $players_raw[ $idx ] ?? null;
        if ( ! $item ) continue;
        $p          = fc_dl_adapt_player( $item['player_details'] );
        $normalized = FC_Card_Normalizer::normalize( $p );
        $card_html  = FC_Card_Visual_Renderer::render_card( $normalized, [
            'width'           => FC_DL_Png_Builder::CARD_WIDTH,
            'show_playstyles' => true,
            'show_extra_info' => true,
            'responsive'      => false,
        ] );

        // Monta o card wrapper com nome (header) e expiração (footer) — capturado pelo .fc-card-wrapper no Puppeteer
        $expires_in  = fc_dl_translate_expires( trim( (string) ( $item['expires_in'] ?? '' ) ) );
        $name_esc    = htmlspecialchars( $p['name'] ?? '', ENT_QUOTES, 'UTF-8' );
        $exp_esc     = htmlspecialchars( $expires_in, ENT_QUOTES, 'UTF-8' );
        $card_w      = FC_DL_Png_Builder::CARD_WIDTH;

        // Aspas duplas dentro de single-quote PHP são literais — sem escape, sem barras no HTML
        $header_html = '<div style="padding:11px 14px;text-align:center;font-size:13px;font-weight:700;'
            . 'color:#f1f1f1;background:#222222;border-bottom:1px solid #2e2e2e;white-space:nowrap;'
            . 'overflow:hidden;text-overflow:ellipsis;'
            . 'font-family:-apple-system,BlinkMacSystemFont,Roboto,sans-serif;">'
            . $name_esc . '</div>';

        $footer_html = $expires_in
            ? '<div style="padding:8px 14px;display:flex;align-items:center;justify-content:center;gap:6px;'
              . 'font-size:11px;font-weight:700;color:#f97316;background:rgba(249,115,22,0.07);'
              . 'border-top:1px solid rgba(249,115,22,0.18);'
              . 'font-family:-apple-system,BlinkMacSystemFont,Roboto,sans-serif;">'
              . '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24"'
              . ' fill="none" stroke="#f97316" stroke-width="2"><circle cx="12" cy="12" r="10"/>'
              . '<polyline points="12 6 12 12 16 14"/></svg>'
              . $exp_esc . '</div>'
            : '';

        $wrapped_html = '<div class="fc-card-wrapper" style="display:inline-flex;flex-direction:column;'
            . 'align-items:stretch;background:#1a1a1a;border:1px solid #2e2e2e;border-radius:14px;'
            . 'overflow:hidden;width:' . $card_w . 'px;">'
            . $header_html . $card_html . $footer_html . '</div>';

        $players[] = [ 'name' => $p['name'] ?? "player_$idx", 'card_html' => $wrapped_html ];
    }

    if ( empty( $players ) ) wp_send_json_error( [ 'message' => 'Nenhum jogador válido' ] );
    $result = FC_DL_Png_Builder::generate( $players, FC_DL_Png_Builder::get_card_css() );
    if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    wp_send_json_success( $result );
}
