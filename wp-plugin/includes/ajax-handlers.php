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
 * Adapta os dados do jogador da nossa API para o shape
 * esperado pelo FC_Card_Normalizer / FC_Card_Visual_Renderer.
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

/* ═══════════════════════════════════════════════════════════════
   fc_dl_get_squads
   ═══════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_fc_dl_get_squads',        'fc_dl_ajax_get_squads' );
add_action( 'wp_ajax_nopriv_fc_dl_get_squads', 'fc_dl_ajax_get_squads' );

function fc_dl_ajax_get_squads() {
    fc_dl_verify();

    $squads = FC_DL_VPS_Api::get_squads();
    if ( ! $squads ) {
        wp_send_json_error( [ 'message' => 'Erro ao buscar squads da API' ] );
    }

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
    if ( $data === null ) {
        wp_send_json_success( [ 'loaded' => false, 'players' => [] ] );
    }
    if ( ! $data || ! empty( $data['error'] ) ) {
        wp_send_json_error( [ 'message' => 'Squad não encontrado' ] );
    }

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
            'name' => $p['name'] ?? '', 'rating' => $p['rating'] ?? '',
            'position' => $p['position'] ?? '', 'card_html' => $card_html,
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
    $label = sanitize_text_field( $_POST['label'] ?? '' );
    $url   = esc_url_raw( $_POST['url'] ?? '' );
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
   Retorna TODOS os jogadores DME (sem paginação)
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

    // Filtra apenas jogadores com player_details válido
    $players_raw = array_values( array_filter( $items, function ( $i ) {
        return ! empty( $i['is_player'] ) && ! empty( $i['player_details'] );
    } ) );

    $players = [];
    foreach ( $players_raw as $idx => $item ) {
        $p = fc_dl_adapt_player( $item['player_details'] );
        if ( empty( $p ) ) continue;

        $normalized = FC_Card_Normalizer::normalize( $p );
        $card_html  = FC_Card_Visual_Renderer::render_card( $normalized, [
            'width'           => 250,
            'show_playstyles' => true,
            'show_extra_info' => true,
            'responsive'      => true,
        ] );

        $players[] = [
            'global_idx' => $idx,
            'name'       => $p['name']     ?? ( $item['name'] ?? '' ),
            'rating'     => $p['rating']   ?? '',
            'position'   => $p['position'] ?? '',
            'card_html'  => $card_html,
        ];
    }

    wp_send_json_success( [
        'players' => $players,
        'total'   => count( $players ),
    ] );
}

/* ═══════════════════════════════════════════════════════════════
   fc_dl_generate_dme_png
   Gera PNGs dos jogadores DME selecionados por índice global
   ═══════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_fc_dl_generate_dme_png',        'fc_dl_ajax_generate_dme_png' );
add_action( 'wp_ajax_nopriv_fc_dl_generate_dme_png', 'fc_dl_ajax_generate_dme_png' );

function fc_dl_ajax_generate_dme_png() {
    fc_dl_verify();
    set_time_limit( 180 );

    if ( ! class_exists( 'FC_DME_API' ) ) {
        wp_send_json_error( [ 'message' => 'Plugin FC DME Solver não está ativo' ] );
    }
    $can_render = class_exists( 'FC_Card_Visual_Renderer' ) && class_exists( 'FC_Card_Normalizer' );
    if ( ! $can_render ) {
        wp_send_json_error( [ 'message' => 'FC Card Renderer não instalado' ] );
    }

    $indices = array_map( 'intval', (array) ( $_POST['indices'] ?? [] ) );
    if ( empty( $indices ) ) wp_send_json_error( [ 'message' => 'Selecione ao menos um jogador' ] );

    $items = FC_DME_API::fetch_sbcs( true );
    if ( is_wp_error( $items ) ) wp_send_json_error( [ 'message' => $items->get_error_message() ] );

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
        $players[] = [ 'name' => $p['name'] ?? "player_$idx", 'card_html' => $card_html ];
    }

    if ( empty( $players ) ) wp_send_json_error( [ 'message' => 'Nenhum jogador válido' ] );

    $result = FC_DL_Png_Builder::generate( $players, FC_DL_Png_Builder::get_card_css() );
    if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    wp_send_json_success( $result );
}
