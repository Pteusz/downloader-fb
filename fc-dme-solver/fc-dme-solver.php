<?php
/**
 * Plugin Name: FC DME Solver
 * Description: Exibe SBCs com jogadores renderizados e recompensas, com sistema de resolver desafios DME
 * Version: 1.2.1
 * Author: DME Team
 * Text Domain: fc-dme-solver
 */

if (!defined('ABSPATH')) exit;

define('FC_DME_VERSION', '1.2.1');
define('FC_DME_PATH', plugin_dir_path(__FILE__));
define('FC_DME_URL', plugin_dir_url(__FILE__));

/**
 * =========================
 * REGRAS AJUSTÁVEIS (pedido)
 * =========================
 * - Mínimo por item em BRL (centavos): 19,99 => 1999
 * - Se plataforma selecionada for CONSOLE e soma (console) > 100,00 => usa BASE PC
 */
define('FC_DME_MIN_TOTAL_BRL_CENTS', 1599);

/**
 * ============================================================
 * FAIXA DE PREÇO DO DME
 * ============================================================
 * A partir de FC_DME_TIER_MIN_COINS, o preço por 100k passa a
 * ser FC_DME_TIER_PRICE_100K (em reais).
 * Abaixo desse valor usa a taxa flat da cs_config normalmente.
 *
 * Exemplos:
 *   1kk   = 1000000
 *   2.6kk = 2600000
 */
define('FC_DME_TIER_MIN_COINS',  1000000); // a partir de 1kk
define('FC_DME_TIER_PRICE_100K', 8.49);   // R$ 14,49 a cada 100k

/**
 * URL de checkout/form
 */
define('FC_DME_BUY_URL', 'https://chamacoins.com.br/dme-form/');

/**
 * ==========================================================
 * CACHE BUSTING AUTOMÁTICO (pra não precisar limpar cache)
 * ==========================================================
 * Versão do asset baseada no filemtime() do arquivo.
 * Quando você edita o CSS/JS, a versão muda automaticamente.
 */
if (!function_exists('fc_dme_asset_version')) {
    function fc_dme_asset_version($relative_path) {
        $relative_path = ltrim((string)$relative_path, '/');
        $full = FC_DME_PATH . $relative_path;

        // Se o arquivo existe, usa filemtime (cache-busting perfeito)
        if (file_exists($full)) {
            return (string) filemtime($full);
        }

        // Fallback: versão do plugin
        return FC_DME_VERSION;
    }
}

require_once FC_DME_PATH . 'includes/class-settings.php';
require_once FC_DME_PATH . 'includes/class-price-rules.php';
require_once FC_DME_PATH . 'includes/class-api.php';
require_once FC_DME_PATH . 'includes/class-orders.php';
require_once FC_DME_PATH . 'includes/class-shortcode.php';

class FC_DME_Solver {
    public function __construct() {
        add_action('init', [$this, 'init']);
    }

    public function init() {
        new FC_DME_Settings();
        new FC_DME_API();
        new FC_DME_Orders();
        new FC_DME_Shortcode();
    }

    public static function activate() {
        // garante tabela
        if (class_exists('FC_DME_Orders')) {
            FC_DME_Orders::maybe_create_table();
        }
    }
}

register_activation_hook(__FILE__, ['FC_DME_Solver', 'activate']);

new FC_DME_Solver();
