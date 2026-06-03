<?php
/**
 * FC DME Solver - Price Rules (Universal)
 * - Converte preço textual ("27,45k", "1,01kk") => coins (int)
 * - Converte coins => BRL (centavos) usando wp_cs_config (prefix cs_config)
 * - Suporte a faixas de preço via cs_price_tiers (mesmo plugin de coins)
 */

if (!defined('ABSPATH')) exit;

class FC_DME_Price_Rules {

    /** Cache local por request — taxa flat (mode|platform) */
    private static $cs_config_cache = [];

    /** Cache local por request — faixas (mode|platform|coins) */
    private static $cs_tiers_cache = [];

    /**
     * Converte um preço textual para coins reais (int).
     * Exemplos:
     * - "100k" => 100000
     * - "27.45k" => 27450
     * - "27,45k" => 27450
     * - "1,01kk" => 1010000
     * - "1.01kk" => 1010000
     * - "1m" => 1000000
     *
     * Retorna null se não conseguir converter.
     */
    public static function parse_price_to_coins($value) {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_float($value)) {
            $v = (int) round($value);
            return $v > 0 ? $v : null;
        }
        if (!is_string($value)) {
            return null;
        }

        $s = trim($value);
        if ($s === '' || $s === '-' || $s === '—') return null;

        $s = strtolower($s);
        $s = preg_replace('/\s+/', '', $s);

        // remove símbolos monetários comuns
        $s = str_replace(['r$', '$', '€', '£'], '', $s);

        // detecta sufixo e define multiplicador
        $mult = 1;

        if (preg_match('/kk$/', $s)) {
            $mult = 1000000;
            $s = substr($s, 0, -2);
        } elseif (preg_match('/k$/', $s)) {
            $mult = 1000;
            $s = substr($s, 0, -1);
        } elseif (preg_match('/m$/', $s)) {
            $mult = 1000000;
            $s = substr($s, 0, -1);
        }

        // mantém apenas dígitos e separadores decimais
        $num = preg_replace('/[^0-9\.,]/', '', $s);
        if ($num === '') return null;

        // Se tem . e , juntos: assume pt-BR típico (.) milhar e (,) decimal
        if (strpos($num, ',') !== false && strpos($num, '.') !== false) {
            $num = str_replace('.', '', $num);
            $num = str_replace(',', '.', $num);
        } else {
            // se só tiver vírgula, vira decimal
            $num = str_replace(',', '.', $num);
        }

        $f = floatval($num);
        if (!is_finite($f)) return null;

        $coins = (int) round($f * $mult);
        if ($coins <= 0) return null;

        return $coins;
    }

    /**
     * Decide o "mode" de preço (lance/sniper).
     * - Por padrão, tenta seguir o mesmo do ecossistema (fc_player_shop_mode).
     * - Você pode sobrescrever via filtro.
     */
    public static function get_price_mode() {
        $mode = get_option('fc_player_shop_mode', 'lance');
        $mode = is_string($mode) ? strtolower(trim($mode)) : 'lance';
        if (!in_array($mode, ['lance', 'sniper'], true)) $mode = 'lance';

        return apply_filters('fc_dme_price_mode', $mode);
    }

    /**
     * Mapeia a plataforma do DME ("console"/"pc") para a plataforma do wp_cs_config ("ps"/"pc").
     * - Regra pedida: console => ps | pc => pc
     * - Dá pra mudar depois via filtro.
     */
    public static function map_platform_for_config($dmePlatform) {
        $dmePlatform = is_string($dmePlatform) ? strtolower(trim($dmePlatform)) : '';

        $map = [
            'console' => 'ps',
            'pc'      => 'pc',
        ];

        $map = apply_filters('fc_dme_cs_platform_map', $map);

        return isset($map[$dmePlatform]) ? $map[$dmePlatform] : 'ps';
    }

    /**
     * Busca price_100k da tabela cs_config e devolve em centavos (int).
     * Retorna null se não encontrar.
     */
    public static function get_price_100k_cents($mode, $platform) {
        global $wpdb;

        $mode = is_string($mode) ? strtolower(trim($mode)) : 'lance';
        $platform = is_string($platform) ? strtolower(trim($platform)) : 'ps';

        $cacheKey = $mode . '|' . $platform;
        if (array_key_exists($cacheKey, self::$cs_config_cache)) {
            return self::$cs_config_cache[$cacheKey];
        }

        $table = $wpdb->prefix . 'cs_config';

        // Se a tabela não existe, evita query quebrando
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            self::$cs_config_cache[$cacheKey] = null;
            return null;
        }

        // Pega a config mais recente para aquele mode+platform
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT price_100k, updated_at
                 FROM {$table}
                 WHERE mode = %s AND platform = %s
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1",
                $mode,
                $platform
            ),
            ARRAY_A
        );

        if (!$row || !isset($row['price_100k'])) {
            self::$cs_config_cache[$cacheKey] = null;
            return null;
        }

        $p = (string) $row['price_100k'];
        $p = str_replace(',', '.', trim($p));

        $f = floatval($p);
        if (!is_finite($f) || $f <= 0) {
            self::$cs_config_cache[$cacheKey] = null;
            return null;
        }

        $cents = (int) round($f * 100);
        if ($cents <= 0) {
            self::$cs_config_cache[$cacheKey] = null;
            return null;
        }

        self::$cs_config_cache[$cacheKey] = $cents;
        return $cents;
    }

    /**
     * Busca o price_100k em centavos aplicando a faixa definida nas constantes do plugin.
     * - Se coins >= FC_DME_TIER_MIN_COINS → retorna FC_DME_TIER_PRICE_100K (em centavos)
     * - Caso contrário → fallback para taxa flat da cs_config
     *
     * @param string $mode     lance|sniper
     * @param string $platform ps|pc
     * @param int    $coins    quantidade de coins do item a ser precificado
     * @return int|null price_100k em centavos, ou null se não encontrar nada
     */
    public static function get_tiered_price_100k_cents($mode, $platform, $coins) {
        $mode     = is_string($mode)     ? strtolower(trim($mode))     : 'lance';
        $platform = is_string($platform) ? strtolower(trim($platform)) : 'ps';
        $coins    = intval($coins);

        if ($coins <= 0) {
            return self::get_price_100k_cents($mode, $platform);
        }

        $cacheKey = $mode . '|' . $platform . '|' . $coins;
        if (array_key_exists($cacheKey, self::$cs_tiers_cache)) {
            return self::$cs_tiers_cache[$cacheKey];
        }

        $tier_min   = defined('FC_DME_TIER_MIN_COINS')  ? intval(FC_DME_TIER_MIN_COINS)    : 0;
        $tier_price = defined('FC_DME_TIER_PRICE_100K') ? floatval(FC_DME_TIER_PRICE_100K) : 0.0;

        if ($tier_min > 0 && $tier_price > 0 && $coins >= $tier_min) {
            $cents = (int) round($tier_price * 100);
            self::$cs_tiers_cache[$cacheKey] = $cents;
            return $cents;
        }

        // Fallback: taxa flat da cs_config
        $flat = self::get_price_100k_cents($mode, $platform);
        self::$cs_tiers_cache[$cacheKey] = $flat;
        return $flat;
    }

    /**
     * Converte coins (int) em BRL centavos (int) usando price_100k_cents.
     * brl_cents = round(coins * price_100k_cents / 100000)
     */
public static function coins_to_brl_cents($coins, $price100kCents) {
    $coins = intval($coins);
    $price100kCents = intval($price100kCents);

    if ($coins <= 0 || $price100kCents <= 0) return null;

    // NOVA LÓGICA:
    // 1. Calcula quantas unidades de 100k
    $unidades_100k = $coins / 100000.0;
    
    // 2. Converte price100kCents para reais (divide por 100)
    $price_100k_reais = $price100kCents / 100.0;
    
    // 3. Multiplica unidades × preço
    $brl_reais = $unidades_100k * $price_100k_reais;
    
    // 4. Converte para centavos e arredonda
    $brlCents = (int) round($brl_reais * 100);

    return $brlCents > 0 ? $brlCents : null;
}
    /**
     * Enriquecer um item (SBC) com:
     * - *_coins
     * - *_brl_cents (com base em cs_price_tiers se houver faixa, senão cs_config)
     */
    public static function enrich_item_with_prices(&$item) {
        if (!is_array($item)) return;

        $mode = self::get_price_mode();

        // SBC coins (total do item)
        $item['price_ps_coins'] = self::parse_price_to_coins($item['price_ps'] ?? null);
        $item['price_pc_coins'] = self::parse_price_to_coins($item['price_pc'] ?? null);

        // Determina o total de coins para escolher a faixa de preço.
        // Usa o preço direto do item; se não existir, soma os challenges.
        $total_ps_for_tier = intval($item['price_ps_coins'] ?? 0);
        $total_pc_for_tier = intval($item['price_pc_coins'] ?? 0);

        if ($total_ps_for_tier <= 0 && !empty($item['challenges']) && is_array($item['challenges'])) {
            foreach ($item['challenges'] as $ch) {
                $total_ps_for_tier += intval(self::parse_price_to_coins($ch['price_ps'] ?? null) ?? 0);
                $total_pc_for_tier += intval(self::parse_price_to_coins($ch['price_pc'] ?? null) ?? 0);
            }
        }

        // Taxa definida pelo TOTAL do item — se o total bater o threshold, todos os
        // challenges desse item usam a mesma taxa tier (não cada um individualmente).
        $rate_ps = self::get_tiered_price_100k_cents($mode, 'ps', $total_ps_for_tier) ?? 0;
        $rate_pc = self::get_tiered_price_100k_cents($mode, 'pc', $total_pc_for_tier) ?? 0;

        // SBC BRL
        $item['price_ps_brl_cents'] = self::coins_to_brl_cents($item['price_ps_coins'] ?? 0, $rate_ps);
        $item['price_pc_brl_cents'] = self::coins_to_brl_cents($item['price_pc_coins'] ?? 0, $rate_pc);

        // Challenges — todos usam a taxa determinada pelo total do item
        if (!empty($item['challenges']) && is_array($item['challenges'])) {
            foreach ($item['challenges'] as $i => $ch) {
                if (!is_array($ch)) continue;

                $item['challenges'][$i]['price_ps_coins'] = self::parse_price_to_coins($ch['price_ps'] ?? null);
                $item['challenges'][$i]['price_pc_coins'] = self::parse_price_to_coins($ch['price_pc'] ?? null);

                $ch_ps_coins = $item['challenges'][$i]['price_ps_coins'] ?? 0;
                $ch_pc_coins = $item['challenges'][$i]['price_pc_coins'] ?? 0;

                $item['challenges'][$i]['price_ps_brl_cents'] = self::coins_to_brl_cents($ch_ps_coins, $rate_ps);
                $item['challenges'][$i]['price_pc_brl_cents'] = self::coins_to_brl_cents($ch_pc_coins, $rate_pc);
            }
        }
    }

    /**
     * Enriquecer lista.
     */
    public static function enrich_items_with_prices($items) {
        if (!is_array($items)) return [];

        foreach ($items as $idx => $item) {
            if (!is_array($item)) continue;
            self::enrich_item_with_prices($items[$idx]);
        }

        return $items;
    }
}
