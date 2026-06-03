<?php
/**
 * API handler for fetching SBCs data
 */

if (!defined('ABSPATH')) exit;

class FC_DME_API {

    // v6: adaptado para novo scraper — shape direto sem wrapper sbc/player
    const TRANSIENT_KEY = 'fc_dme_sbcs_cache_v6';

    // URL da API do scraper
    // Pode ser sobrescrita em FC_DME_Settings::get_api_url()
    const DEFAULT_API_URL = 'https://dmecors.chamax1.com.br/api/sbcs';

    public function __construct() {
        add_action('wp_ajax_fc_dme_refresh_cache',        [$this, 'ajax_refresh_cache']);
        // DEBUG TEMPORÁRIO — remover após diagnosticar
        add_action('wp_ajax_fc_dme_debug_price',          [$this, 'ajax_debug_price']);
        add_action('wp_ajax_nopriv_fc_dme_debug_price',   [$this, 'ajax_debug_price']);
    }

    public static function fetch_sbcs($use_cache = true) {
        if ($use_cache) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if ($cached !== false) {
                return $cached;
            }
        }

        $api_url = FC_DME_Settings::get_api_url();
        if (empty($api_url)) {
            $api_url = self::DEFAULT_API_URL;
        }

        $response = wp_remote_get($api_url, [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('api_error', sprintf('API retornou status %d', $code));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Resposta inválida da API');
        }

        // API retorna { total, last_update, data: [...] }
        $raw_items = self::extract_items_list($data);

        if (empty($raw_items)) {
            return new WP_Error('empty_data', 'API retornou lista vazia');
        }

        // Normaliza cada item do formato do scraper para o formato interno do plugin
        $items = array_values(array_map([__CLASS__, 'normalize_item'], $raw_items));

        // Enriquecer com coins + BRL (centavos) usando cs_config
        if (class_exists('FC_DME_Price_Rules')) {
            $items = FC_DME_Price_Rules::enrich_items_with_prices($items);
        }

        // Filtrar itens com TODOS os challenges zerados
        $items = self::filter_items_with_zero_challenges($items);

        $ttl = FC_DME_Settings::get_cache_ttl();
        set_transient(self::TRANSIENT_KEY, $items, $ttl);

        return $items;
    }

    // ============================================================
    // NORMALIZE: converte item do novo scraper → formato interno do plugin
    //
    // Scraper entrega:
    //   {
    //     id, name, url, full_url, badge, is_player,
    //     reward_img, reward_text,
    //     price_ps, price_pc,
    //     expires_in, repeatable, completed,
    //     votes, scraped_at,
    //     challenges: [{ name, img_url, reward, description, price_ps, price_pc, requirements }],
    //     player_details: {
    //       player_url, name, rating, position, role_plus,
    //       images: { bg, face },
    //       stats, info, card_css_vars, card_style_raw,
    //       alt_sidebar, playstyles, extra_info,
    //       prices: { console, pc, estimated }
    //     },
    //     player_preview: { name, rating, position, bg_img, face_img, player_path, player_url }
    //   }
    //
    // Plugin espera:
    //   { name, is_player, price_ps, price_pc, player_details, challenges, full_url, ... }
    // ============================================================
    private static function normalize_item($raw) {
        $is_player      = !empty($raw['is_player']);
        $player_details = $raw['player_details'] ?? null;
        $challenges_raw = $raw['challenges']     ?? [];

        // ── Nome
        $name = $raw['name'] ?? '';
        if (empty($name) && !empty($raw['url'])) {
            $parts = explode('/', rtrim($raw['url'], '/'));
            $name  = ucwords(str_replace('-', ' ', end($parts)));
        }

        // ── Preços do SBC (string formatada, ex: "1.09M")
        $price_ps = $raw['price_ps'] ?? '';
        $price_pc = $raw['price_pc'] ?? '';

        // ── Imagem/texto de recompensa (apenas para SBCs sem jogador)
        $reward_img  = $raw['reward_img']  ?? '';
        $reward_text = $raw['reward_text'] ?? '';

        // ── Challenges: mapeia do shape do scraper para o shape do plugin
        //    Scraper: img_url    → Plugin: reward_img
        //    Scraper: price_ps/pc já são strings compatíveis
        $challenges = array_values(array_map(function($ch) {
            return [
                'name'               => $ch['name']        ?? '',
                'reward_img'         => $ch['img_url']     ?? '',   // ← campo renomeado
                'reward'             => $ch['reward']      ?? '',
                'description'        => $ch['description'] ?? '',
                'requirements'       => $ch['requirements'] ?? [],
                'price_ps'           => $ch['price_ps']    ?? '',
                'price_pc'           => $ch['price_pc']    ?? '',
                // Campos numéricos preenchidos depois pelo Price_Rules
                'price_ps_coins'     => 0,
                'price_pc_coins'     => 0,
                'price_ps_brl_cents' => 0,
                'price_pc_brl_cents' => 0,
            ];
        }, $challenges_raw));

        // ── player_details: o scraper já entrega no shape que
        //    FC_Card_Normalizer e FC_Card_Visual_Renderer esperam.
        //    Garante que player_details só existe se is_player for true.
        if (!$is_player) {
            $player_details = null;
        }

        return [
            // ── Identificação
            'id'         => $raw['id']       ?? '',
            'name'       => $name,
            'is_player'  => $is_player,
            'full_url'   => $raw['full_url'] ?? '',
            'badge'      => $raw['badge']    ?? '',

            // ── Metadados
            'expires_in'  => $raw['expires_in']  ?? '',
            'repeatable'  => $raw['repeatable']  ?? '',
            'completed'   => $raw['completed']   ?? '',
            'scraped_at'  => $raw['scraped_at']  ?? '',
            'votes'       => $raw['votes']       ?? null,

            // ── Preços formatados (string)
            'price_ps'   => $price_ps,
            'price_pc'   => $price_pc,

            // ── Preços numéricos (preenchidos pelo Price_Rules depois)
            'price_ps_coins'     => 0,
            'price_pc_coins'     => 0,
            'price_ps_brl_cents' => 0,
            'price_pc_brl_cents' => 0,

            // ── Conteúdo
            'challenges'     => $challenges,
            'player_details' => $player_details,
            'player_preview' => $raw['player_preview'] ?? null,
            'reward_img'     => $reward_img,
            'reward_text'    => $reward_text,
        ];
    }

    // ============================================================
    // Extrai a lista de itens do payload da API
    // Suporta: { total, last_update, data: [...] }  ou array direto
    // ============================================================
    private static function extract_items_list($payload) {
        if (is_array($payload) && self::is_indexed_array($payload)) {
            return $payload;
        }

        $keys = ['data', 'items', 'sbcs', 'result', 'results'];
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && self::is_indexed_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return [];
    }

    private static function is_indexed_array($arr) {
        if (!is_array($arr)) return false;
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    // ============================================================
    // Filtra itens que têm TODOS os challenges com preço zero
    // ============================================================
    private static function filter_items_with_zero_challenges($items) {
        return array_values(array_filter($items, function($item) {
            if (empty($item['challenges']) || !is_array($item['challenges'])) {
                return true;
            }

            foreach ($item['challenges'] as $ch) {
                if (!is_array($ch)) continue;

                $psCoins = intval($ch['price_ps_coins']     ?? 0);
                $pcCoins = intval($ch['price_pc_coins']     ?? 0);
                $psCents = intval($ch['price_ps_brl_cents'] ?? 0);
                $pcCents = intval($ch['price_pc_brl_cents'] ?? 0);

                if ($psCoins > 0 || $pcCoins > 0 || $psCents > 0 || $pcCents > 0) {
                    return true;
                }
            }

            return false;
        }));
    }

    // ============================================================
    // DEBUG TEMPORÁRIO: diagnóstico de preço via console
    // Chamar no browser: fetch('/wp-admin/admin-ajax.php?action=fc_dme_debug_price').then(r=>r.json()).then(d=>console.table(d.data))
    // REMOVER APÓS DIAGNOSTICAR
    // ============================================================
    public function ajax_debug_price() {
        $test_coins = 1600000;
        $mode       = FC_DME_Price_Rules::get_price_mode();

        $flat_cents  = FC_DME_Price_Rules::get_price_100k_cents($mode, 'ps');
        $tier_cents  = FC_DME_Price_Rules::get_tiered_price_100k_cents($mode, 'ps', $test_coins);
        $brl_cents   = FC_DME_Price_Rules::coins_to_brl_cents($test_coins, $tier_cents ?? 0);

        wp_send_json_success([
            'FC_DME_TIER_MIN_COINS'  => defined('FC_DME_TIER_MIN_COINS')  ? FC_DME_TIER_MIN_COINS  : 'NAO DEFINIDO',
            'FC_DME_TIER_PRICE_100K' => defined('FC_DME_TIER_PRICE_100K') ? FC_DME_TIER_PRICE_100K : 'NAO DEFINIDO',
            'mode'                   => $mode,
            'flat_rate_ps_cents'     => $flat_cents,
            'flat_rate_ps_reais'     => $flat_cents  ? 'R$ ' . number_format($flat_cents  / 100, 2, ',', '.') : null,
            'tier_rate_ps_cents'     => $tier_cents,
            'tier_rate_ps_reais'     => $tier_cents  ? 'R$ ' . number_format($tier_cents  / 100, 2, ',', '.') : null,
            'brl_1_6kk_cents'        => $brl_cents,
            'brl_1_6kk_reais'        => $brl_cents   ? 'R$ ' . number_format($brl_cents   / 100, 2, ',', '.') : null,
        ]);
    }

    // ============================================================
    // AJAX: força refresh do cache via painel admin
    // ============================================================
    public function ajax_refresh_cache() {
        check_ajax_referer('fc_dme_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada']);
        }

        delete_transient(self::TRANSIENT_KEY);

        // Limpa caches de página mais comuns
        if (function_exists('rocket_clean_domain'))       rocket_clean_domain();
        if (function_exists('w3tc_flush_all'))            w3tc_flush_all();
        if (function_exists('wp_cache_flush'))            wp_cache_flush();
        if (function_exists('litespeed_purge_all'))       litespeed_purge_all();
        if (function_exists('sg_cachepress_purge_cache')) sg_cachepress_purge_cache();

        $result = self::fetch_sbcs(false);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => 'Cache atualizado com sucesso',
            'count'   => count($result),
        ]);
    }
}