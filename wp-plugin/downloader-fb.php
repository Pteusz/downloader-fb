<?php
/**
 * Plugin Name: FC Player Downloader
 * Description: Gestão e download de jogadores FUT via squads scrapeados
 * Version:     1.0.0
 * Author:      Chamax Team
 */

if ( ! defined( 'WPINC' ) ) die;

define( 'FC_DL_VERSION',  '1.0.0' );
define( 'FC_DL_DIR',      plugin_dir_path( __FILE__ ) );
define( 'FC_DL_URL',      plugin_dir_url( __FILE__ ) );
define( 'FC_DL_API_BASE', 'https://mobalfutbin.chamacoins.com.br' );

require_once FC_DL_DIR . 'includes/class-vps-api.php';
require_once FC_DL_DIR . 'includes/ajax-handlers.php';

/* ── Shortcode ─────────────────────────────────────────────── */
add_shortcode( 'player_downloader', 'fc_dl_shortcode' );

function fc_dl_shortcode() {
    // Versão dinâmica = timestamp do arquivo → sem cache stale após update
    $css_ver = filemtime( FC_DL_DIR . 'assets/css/downloader.css' );
    $js_ver  = filemtime( FC_DL_DIR . 'assets/js/app.js' );

    wp_enqueue_style(
        'fc-dl-css',
        FC_DL_URL . 'assets/css/downloader.css',
        [],
        $css_ver
    );
    wp_enqueue_script(
        'fc-dl-js',
        FC_DL_URL . 'assets/js/app.js',
        [],
        $js_ver,
        true
    );
    wp_localize_script( 'fc-dl-js', 'FC_DL', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'fc_dl_nonce' ),
    ] );

    // CSS dos cards (inline — garantido mesmo com WP Rocket combinando CSS)
    $card_css = '';
    if ( class_exists( 'FC_Card_Visual_Renderer' ) ) {
        $card_css = FC_Card_Visual_Renderer::get_card_css();
    }

    ob_start();
    ?>
    <?php echo $card_css; ?>
    <div id="fc-dl-app">
        <div id="fc-dl-loading" class="fc-dl-loading">
            <div class="fc-dl-spinner"></div>
            <p>Carregando squads...</p>
        </div>
        <div id="fc-dl-screen-grid"  class="fc-dl-screen" style="display:none;"></div>
        <div id="fc-dl-screen-squad" class="fc-dl-screen" style="display:none;"></div>
    </div>
    <?php
    return ob_get_clean();
}

/* ── WP Rocket: evitar cache/minify nos assets do plugin ───── */

// Exclui o JS do combine/minify do Rocket
add_filter( 'rocket_exclude_js', function ( $excluded ) {
    $excluded[] = str_replace(
        ABSPATH, '/', FC_DL_DIR
    ) . 'assets/js/app.js';
    return $excluded;
} );

// Exclui o CSS do combine/minify do Rocket
add_filter( 'rocket_exclude_css', function ( $excluded ) {
    $excluded[] = str_replace(
        ABSPATH, '/', FC_DL_DIR
    ) . 'assets/css/downloader.css';
    return $excluded;
} );

// Exclui o CSS do lazyload do Rocket
add_filter( 'rocket_exclude_lazyload_css', function ( $excluded ) {
    $excluded[] = str_replace(
        ABSPATH, '/', FC_DL_DIR
    ) . 'assets/css/downloader.css';
    return $excluded;
} );

// Desativa cache de página quando o shortcode estiver presente
add_action( 'wp', function () {
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) ) return;
    if ( has_shortcode( $post->post_content, 'player_downloader' ) ) {
        // WP Rocket: não cachear esta página
        if ( function_exists( 'rocket_clean_post' ) ) {
            add_filter( 'do_rocket_generate_caching_files', '__return_false' );
        }
        // Cabeçalho HTTP para proxies/CDN
        if ( ! headers_sent() ) {
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        }
    }
} );
