<?php
/**
 * Plugin Name: FC Card Renderer
 * Plugin URI: https://chamax1.com.br
 * Description: Sistema de normalização e renderização de cards de jogadores do Futbin para WordPress
 * Version: 1.0.0
 * Author: Chamax Team
 * Author URI: https://chamax1.com.br
 * License: GPL-2.0+
 * Text Domain: fc-card-renderer
 * Domain Path: /languages
 *
 * @package FCCardRenderer
 */

// Se este arquivo for chamado diretamente, abortar.
if (!defined('WPINC')) {
    die;
}

/**
 * Versão atual do plugin
 */
define('FC_CARD_RENDERER_VERSION', '1.0.0');

/**
 * Path do plugin
 */
define('FC_CARD_RENDERER_PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * URL do plugin
 */
define('FC_CARD_RENDERER_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Carregar as classes principais
 */
require_once FC_CARD_RENDERER_PLUGIN_DIR . 'includes/class-normalizer.php';
require_once FC_CARD_RENDERER_PLUGIN_DIR . 'includes/class-api.php';
require_once FC_CARD_RENDERER_PLUGIN_DIR . 'includes/class-visual-renderer.php';
require_once FC_CARD_RENDERER_PLUGIN_DIR . 'includes/class-price-converter.php';

/**
 * Ativação do plugin
 */
function fc_card_renderer_activate() {
    // Ações futuras de ativação (criar tabelas, etc)
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'fc_card_renderer_activate');

/**
 * Desativação do plugin
 */
function fc_card_renderer_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'fc_card_renderer_deactivate');

/**
 * Inicializar o plugin
 */
function fc_card_renderer_init() {
    // Inicializar a API interna
    FC_Card_Renderer_API::init();
}
add_action('plugins_loaded', 'fc_card_renderer_init');

/**
 * Adicionar links de ação na página de plugins
 */
function fc_card_renderer_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=fc-card-renderer') . '">Configurações</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'fc_card_renderer_action_links');

/**
 * Adicionar menu de administração (preparação para futuras configurações)
 */
function fc_card_renderer_admin_menu() {
    add_menu_page(
        'FC Card Renderer',
        'FC Cards',
        'manage_options',
        'fc-card-renderer',
        'fc_card_renderer_admin_page',
        'dashicons-id-alt',
        30
    );
}
add_action('admin_menu', 'fc_card_renderer_admin_menu');

/**
 * Página de administração (placeholder)
 */
function fc_card_renderer_admin_page() {
    ?>
    <div class="wrap">
        <h1>FC Card Renderer</h1>
        <p>Sistema de normalização e renderização de cards de jogadores.</p>
        <hr>
        <h2>Status do Plugin</h2>
        <ul>
            <li>✅ Sistema de normalização: Ativo</li>
            <li>✅ API interna: Disponível</li>
            <li>⏳ Sistema de renderização: Em desenvolvimento</li>
            <li>⏳ Sistema de zonas: Em desenvolvimento</li>
        </ul>
        
        <hr>
        <h2>Como Usar</h2>
        <p>Para consumir dados normalizados em outro plugin:</p>
        <pre style="background: #f5f5f5; padding: 15px; border-radius: 5px;">
// Exemplo de uso
$player_data = apply_filters('fc_card_get_player', null, $player_id);
if ($player_data) {
    echo '&lt;pre&gt;';
    print_r($player_data);
    echo '&lt;/pre&gt;';
}
        </pre>
    </div>
    <?php
}
