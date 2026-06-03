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
    wp_enqueue_style(
        'fc-dl-css',
        FC_DL_URL . 'assets/css/downloader.css',
        [],
        FC_DL_VERSION
    );
    wp_enqueue_script(
        'fc-dl-js',
        FC_DL_URL . 'assets/js/app.js',
        [],
        FC_DL_VERSION,
        true
    );
    wp_localize_script( 'fc-dl-js', 'FC_DL', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'fc_dl_nonce' ),
    ] );

    // CSS dos cards (injetado inline para garantir estilo correto)
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
