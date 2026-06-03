<?php
/**
 * Classe responsável por normalizar dados de jogadores do Futbin
 * Versão 2.2 - Fallbacks para API1 (root-only) + compat de estruturas ausentes
 *
 * @package FCCardRenderer
 */

if (!defined('WPINC')) {
    die;
}

class FC_Card_Normalizer {

    /**
     * Mapeamento de chaves padrão
     * Suporta: raiz (API1) e meta.* (API2 via player.meta)
     *
     * @var array
     */
    private static $key_mapping = array(
        // Informações básicas (API2 geralmente tem; API1 pode não ter)
        'id' => 'id',
        'futbin_player_id' => 'futbin_player_id',
        'slug' => 'slug',
        'player_url' => 'player_url',

        // Nome, rating, posição (podem vir na raiz ou em meta)
        'name' => array('name', 'meta.name'),
        'rating' => array('rating', 'meta.rating'),
        'position' => array('position', 'meta.position'),
        'role_plus' => array('role_plus', 'meta.role_plus'),

        // Imagens
        'bg' => array('images.bg', 'meta.images.bg'),
        'face' => array('images.face', 'meta.images.face'),

        // Stats principais
        'stat_pace' => array('stats.PAC', 'meta.stats.PAC'),
        'stat_shooting' => array('stats.SHO', 'meta.stats.SHO'),
        'stat_passing' => array('stats.PAS', 'meta.stats.PAS'),
        'stat_dribbling' => array('stats.DRI', 'meta.stats.DRI'),
        'stat_defending' => array('stats.DEF', 'meta.stats.DEF'),
        'stat_physical' => array('stats.PHY', 'meta.stats.PHY'),

        // Clube, liga e nação
        'club_icon' => array('info.club.src', 'meta.info.club.src'),
        'club_name' => array('info.club.title', 'meta.info.club.title'),
        'league_icon' => array('info.league.src', 'meta.info.league.src'),
        'league_name' => array('info.league.title', 'meta.info.league.title'),
        'nation_icon' => array('info.nation.src', 'meta.info.nation.src'),
        'nation_name' => array('info.nation.title', 'meta.info.nation.title'),

        // Extra info
        'skill_moves' => array('extra_info.skill_moves', 'meta.extra_info.skill_moves'),
        'weak_foot' => array('extra_info.weak_foot', 'meta.extra_info.weak_foot'),
        'foot' => array('extra_info.foot', 'meta.extra_info.foot'),
        'futbin_rating' => array('extra_info.futbin_rating', 'meta.extra_info.futbin_rating'),

        // Cores e estilos
        'color_card' => array('card_css_vars.cardColor', 'meta.card_css_vars.cardColor'),
        'color_line' => array('card_css_vars.lineColor', 'meta.card_css_vars.lineColor'),
        'color_rating' => array('card_css_vars.ratingColor', 'meta.card_css_vars.ratingColor'),

        // Preços
        'price_console' => array('prices.console', 'meta.prices.console'),
        'price_pc' => array('prices.pc', 'meta.prices.pc'),
        'price_estimated' => array('prices.estimated', 'meta.prices.estimated'),
    );

    /**
     * Mapeamento de estruturas complexas (arrays/objetos completos)
     */
    private static $complex_mapping = array(
        // Playstyles completos
        'playstyles_full' => array('playstyles', 'meta.playstyles'),
        'playstyles_items' => array('playstyles.items', 'meta.playstyles.items'),
        'playstyles_svgs' => array('playstyles.svgs', 'meta.playstyles.svgs'),
        'playstyles_count' => array('playstyles.count', 'meta.playstyles.count'),
        'playstyles_classes' => array('playstyles.classes', 'meta.playstyles.classes'),

        // Alt sidebar
        'alt_sidebar_full' => array('alt_sidebar', 'meta.alt_sidebar'),
        'alt_sidebar_positions' => array('alt_sidebar.positions', 'meta.alt_sidebar.positions'),
        'alt_sidebar_styles' => array('alt_sidebar.styles', 'meta.alt_sidebar.styles'),
        'alt_sidebar_css_vars' => array('alt_sidebar.right.css_vars', 'meta.alt_sidebar.right.css_vars'),

        // Extra info completo
        'extra_info_full' => array('extra_info', 'meta.extra_info'),
        'extra_info_css_vars' => array('extra_info.css_vars', 'meta.extra_info.css_vars'),
        'extra_info_style_raw' => array('extra_info.style_raw', 'meta.extra_info.style_raw'),

        // API2 costuma ter; API1 geralmente NÃO
        'extra_info_basic_items' => array('extra_info.basic_items', 'meta.extra_info.basic_items'),
        'extra_info_foot_svg' => array('extra_info.foot_svg', 'meta.extra_info.foot_svg'),
    );

    /**
     * Valores padrão para campos ausentes
     *
     * @var array
     */
    private static $default_values = array(
        'rating' => '0',
        'position' => 'N/A',
        'role_plus' => '',
        'skill_moves' => null,
        'weak_foot' => null,
        'foot' => null,

        'stat_pace' => '0',
        'stat_shooting' => '0',
        'stat_passing' => '0',
        'stat_dribbling' => '0',
        'stat_defending' => '0',
        'stat_physical' => '0',

        'color_card' => '#000000',
        'color_line' => '#000000',
        'color_rating' => '#000000',

        'playstyles_count' => 0,
        'playstyles_items' => array(),
        'playstyles_svgs' => array(),
        'playstyles_classes' => array(),

        // IMPORTANTES pra API1 não quebrar loop no renderer
        'extra_info_basic_items' => array(),
        'extra_info_foot_svg' => array(),
        'alt_sidebar_positions' => array(),
    );

    /**
     * Normaliza dados brutos do Futbin
     *
     * @param array|object $raw_data
     * @param array $options
     * @return array
     */
    public static function normalize($raw_data, $options = array()) {
        if (is_object($raw_data)) {
            $raw_data = json_decode(json_encode($raw_data), true);
        }

        $defaults = array(
            'default_mode' => 'lance',
            'default_platform' => 'ps',
            'convert_prices' => true,
        );
        $options = array_merge($defaults, $options);

        $normalized = array(
            'raw_data' => $raw_data,
            'normalized_at' => current_time('mysql'),
        );

        // Campos simples
        foreach (self::$key_mapping as $normalized_key => $paths) {
            $value = self::get_value_from_paths($raw_data, $paths);

            if ($value === null && array_key_exists($normalized_key, self::$default_values)) {
                $value = self::$default_values[$normalized_key];
            }

            $normalized[$normalized_key] = $value;
        }

        // Estruturas complexas
        foreach (self::$complex_mapping as $normalized_key => $paths) {
            $value = self::get_value_from_paths($raw_data, $paths);

            if ($value === null && array_key_exists($normalized_key, self::$default_values)) {
                $value = self::$default_values[$normalized_key];
            }

            $normalized[$normalized_key] = $value;
        }

        // ===================================
        // FALLBACKS IMPORTANTES (API1)
        // ===================================

        // (1) id/slug/futbin_player_id (API1 não manda -> extrair de player_url)
        self::fill_ids_from_player_url($normalized);

        // (2) extra_info_basic_items / foot_svg (API1 só manda basic_items_text)
        self::ensure_extra_info_items($normalized);

        // (3) garantir consistência em playstyles (classes[], svgs[])
        self::ensure_playstyles_shape($normalized);

        // (4) garantir consistência em alt_sidebar (playstyles laterais)
        self::ensure_alt_sidebar_shape($normalized);

        // Stats agrupados
        $normalized['stats'] = array(
            'PAC' => $normalized['stat_pace'],
            'SHO' => $normalized['stat_shooting'],
            'PAS' => $normalized['stat_passing'],
            'DRI' => $normalized['stat_dribbling'],
            'DEF' => $normalized['stat_defending'],
            'PHY' => $normalized['stat_physical'],
        );

        // Info agrupado
        $normalized['info'] = array(
            'club' => array(
                'icon' => $normalized['club_icon'],
                'name' => $normalized['club_name'],
            ),
            'league' => array(
                'icon' => $normalized['league_icon'],
                'name' => $normalized['league_name'],
            ),
            'nation' => array(
                'icon' => $normalized['nation_icon'],
                'name' => $normalized['nation_name'],
            ),
        );

        // Cores agrupadas
        $normalized['colors'] = array(
            'card' => $normalized['color_card'],
            'line' => $normalized['color_line'],
            'rating' => $normalized['color_rating'],
        );

        // Preços agrupados (originais)
        $normalized['prices'] = array(
            'console' => $normalized['price_console'],
            'pc' => $normalized['price_pc'],
            'estimated' => $normalized['price_estimated'],
        );

        // Conversão de preços
        if ($options['convert_prices'] && class_exists('FC_Card_Price_Converter')) {
            $processed_prices = FC_Card_Price_Converter::process_prices(
                $normalized['prices'],
                $options['default_mode'],
                $options['default_platform']
            );

            $normalized['prices_formatted'] = $processed_prices['formatted'];
            $normalized['prices_brl'] = $processed_prices['brl'];
            $normalized['prices_meta'] = $processed_prices['meta'];

            $best_price = FC_Card_Price_Converter::get_best_price($processed_prices, 'formatted');
            $normalized['best_price'] = $best_price;

            $best_price_brl = FC_Card_Price_Converter::get_best_price(
                $processed_prices,
                'brl',
                $options['default_mode'],
                $options['default_platform']
            );
            $normalized['best_price_brl'] = $best_price_brl;
        }

        // Posições alternativas formatadas
        $normalized['alt_positions_formatted'] = self::format_alt_positions($normalized['alt_sidebar_positions']);

        // Metadados
        $normalized['meta'] = array(
            'version' => defined('FC_CARD_RENDERER_VERSION') ? FC_CARD_RENDERER_VERSION : 'unknown',
            'unmapped_keys' => self::find_unmapped_keys($raw_data),
            'price_conversion_enabled' => $options['convert_prices'] && class_exists('FC_Card_Price_Converter'),
        );

        return $normalized;
    }

    /**
     * Extrai id/slug do player_url quando ausentes (API1)
     */
    private static function fill_ids_from_player_url(&$normalized) {
        $player_url = isset($normalized['player_url']) ? $normalized['player_url'] : null;
        if (!is_string($player_url) || $player_url === '') {
            return;
        }

        // id
        if (empty($normalized['id'])) {
            if (preg_match('~\/player\/(\d+)\/~', $player_url, $m)) {
                $normalized['id'] = $m[1];
            }
        }

        // slug
        if (empty($normalized['slug'])) {
            if (preg_match('~\/player\/\d+\/([^\/\?\#]+)~', $player_url, $m)) {
                $normalized['slug'] = $m[1];
            }
        }

        // futbin_player_id (na prática, costuma ser o mesmo ID do player no Futbin)
        if (empty($normalized['futbin_player_id']) && !empty($normalized['id'])) {
            $normalized['futbin_player_id'] = $normalized['id'];
        }
    }

    /**
     * Garante extra_info_basic_items + foot_svg para não quebrar renderer com API1
     */
    private static function ensure_extra_info_items(&$normalized) {
        // Garantir arrays
        if (empty($normalized['extra_info_basic_items']) || !is_array($normalized['extra_info_basic_items'])) {
            $normalized['extra_info_basic_items'] = array();
        }
        if (empty($normalized['extra_info_foot_svg']) || !is_array($normalized['extra_info_foot_svg'])) {
            $normalized['extra_info_foot_svg'] = array();
        }

        // Se já tem basic_items (API2), ok.
        if (!empty($normalized['extra_info_basic_items'])) {
            return;
        }

        // API1: criar basic_items a partir de basic_items_text
        $raw = isset($normalized['raw_data']) && is_array($normalized['raw_data']) ? $normalized['raw_data'] : array();

        $basic_text = self::get_value_from_paths($raw, array(
            'extra_info.basic_items_text',
            'meta.extra_info.basic_items_text'
        ));

        if (!is_array($basic_text) || empty($basic_text)) {
            return;
        }

        $items = array();
        foreach ($basic_text as $t) {
            $items[] = array(
                'text' => is_scalar($t) ? strval($t) : '',
                'svgs' => array(),
            );
        }

        $normalized['extra_info_basic_items'] = $items;

        // Também injeta dentro de extra_info_full (se existir) pra quem lê direto dali
        if (!isset($normalized['extra_info_full']) || !is_array($normalized['extra_info_full'])) {
            $normalized['extra_info_full'] = array();
        }
        if (!isset($normalized['extra_info_full']['basic_items'])) {
            $normalized['extra_info_full']['basic_items'] = $items;
        }
    }

    /**
     * Garante shape consistente de playstyles (classes[], svgs[], items com classes[])
     */
    private static function ensure_playstyles_shape(&$normalized) {
        // playstyles_items
        if (!isset($normalized['playstyles_items']) || !is_array($normalized['playstyles_items'])) {
            $normalized['playstyles_items'] = array();
        }
        $normalized['playstyles_items'] = self::normalize_playstyle_items($normalized['playstyles_items']);

        // playstyles_svgs
        if (!isset($normalized['playstyles_svgs']) || !is_array($normalized['playstyles_svgs'])) {
            $normalized['playstyles_svgs'] = array();
        }

        // playstyles_classes
        if (!isset($normalized['playstyles_classes']) || !is_array($normalized['playstyles_classes'])) {
            $normalized['playstyles_classes'] = array();
        }

        // playstyles_full
        if (isset($normalized['playstyles_full']) && is_array($normalized['playstyles_full'])) {
            if (isset($normalized['playstyles_full']['items'])) {
                $normalized['playstyles_full']['items'] = self::normalize_playstyle_items($normalized['playstyles_full']['items']);
            }
            if (!isset($normalized['playstyles_full']['svgs']) || !is_array($normalized['playstyles_full']['svgs'])) {
                $normalized['playstyles_full']['svgs'] = array();
            }
            if (!isset($normalized['playstyles_full']['classes']) || !is_array($normalized['playstyles_full']['classes'])) {
                // tentar derivar das items
                $derived = array();
                if (isset($normalized['playstyles_full']['items']) && is_array($normalized['playstyles_full']['items'])) {
                    foreach ($normalized['playstyles_full']['items'] as $it) {
                        if (isset($it['class']) && is_string($it['class'])) {
                            $derived[] = $it['class'];
                        } elseif (isset($it['classes']) && is_array($it['classes'])) {
                            $derived = array_merge($derived, $it['classes']);
                        }
                    }
                }
                $normalized['playstyles_full']['classes'] = array_values(array_unique($derived));
            }
            if (!isset($normalized['playstyles_full']['count'])) {
                $normalized['playstyles_full']['count'] = isset($normalized['playstyles_full']['items']) && is_array($normalized['playstyles_full']['items'])
                    ? count($normalized['playstyles_full']['items'])
                    : 0;
            }
        }
    }

    /**
     * Garante shape consistente de alt_sidebar (playstyles laterais)
     */
    private static function ensure_alt_sidebar_shape(&$normalized) {
        if (!isset($normalized['alt_sidebar_full']) || !is_array($normalized['alt_sidebar_full'])) {
            return;
        }

        // Positions default
        if (!isset($normalized['alt_sidebar_positions']) || !is_array($normalized['alt_sidebar_positions'])) {
            $normalized['alt_sidebar_positions'] = array();
        }

        // left.playstyles.items classes[]
        if (isset($normalized['alt_sidebar_full']['left']['playstyles']['items']) && is_array($normalized['alt_sidebar_full']['left']['playstyles']['items'])) {
            $normalized['alt_sidebar_full']['left']['playstyles']['items'] = self::normalize_playstyle_items($normalized['alt_sidebar_full']['left']['playstyles']['items']);
        }
        if (isset($normalized['alt_sidebar_full']['left']['playstyles']) && is_array($normalized['alt_sidebar_full']['left']['playstyles'])) {
            if (!isset($normalized['alt_sidebar_full']['left']['playstyles']['svgs']) || !is_array($normalized['alt_sidebar_full']['left']['playstyles']['svgs'])) {
                $normalized['alt_sidebar_full']['left']['playstyles']['svgs'] = array();
            }
            if (!isset($normalized['alt_sidebar_full']['left']['playstyles']['count'])) {
                $normalized['alt_sidebar_full']['left']['playstyles']['count'] =
                    isset($normalized['alt_sidebar_full']['left']['playstyles']['items']) && is_array($normalized['alt_sidebar_full']['left']['playstyles']['items'])
                        ? count($normalized['alt_sidebar_full']['left']['playstyles']['items'])
                        : 0;
            }
        }

        // Também normaliza o campo alt_sidebar_full.positions se existir
        if (isset($normalized['alt_sidebar_full']['positions']) && is_array($normalized['alt_sidebar_full']['positions'])) {
            $normalized['alt_sidebar_positions'] = $normalized['alt_sidebar_full']['positions'];
        }
    }

    /**
     * Normaliza itens de playstyle para sempre ter classes[]
     */
    private static function normalize_playstyle_items($items) {
        if (!is_array($items)) {
            return array();
        }

        foreach ($items as $i => $it) {
            if (!is_array($it)) {
                continue;
            }

            // Garantir "classes" array
            if (!isset($it['classes']) || !is_array($it['classes'])) {
                if (isset($it['class']) && is_string($it['class'])) {
                    $it['classes'] = array($it['class']);
                } else {
                    $it['classes'] = array();
                }
            }

            $items[$i] = $it;
        }

        return $items;
    }

    /**
     * Obtém valor de múltiplos caminhos possíveis
     */
    private static function get_value_from_paths($array, $paths) {
        if (!is_array($paths)) {
            $paths = array($paths);
        }

        foreach ($paths as $path) {
            $value = self::get_nested_value($array, $path);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Obtém valor aninhado usando notação de ponto
     */
    private static function get_nested_value($array, $path) {
        $keys = explode('.', $path);
        $value = $array;

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Formata posições alternativas para exibição
     */
    private static function format_alt_positions($alt_positions) {
        if (empty($alt_positions) || !is_array($alt_positions)) {
            return array();
        }

        $formatted = array();
        foreach ($alt_positions as $pos) {
            if (isset($pos['pos'])) {
                $formatted[] = array(
                    'position' => $pos['pos'],
                    'plus_plus' => (isset($pos['plus_plus']) && $pos['plus_plus']) ? '++' : '',
                );
            }
        }

        return $formatted;
    }

    /**
     * Encontra chaves não mapeadas no JSON bruto
     */
    private static function find_unmapped_keys($raw_data) {
        $unmapped = array();

        $all_mapped_paths = array();

        foreach (self::$key_mapping as $paths) {
            if (is_array($paths)) {
                $all_mapped_paths = array_merge($all_mapped_paths, $paths);
            } else {
                $all_mapped_paths[] = $paths;
            }
        }

        foreach (self::$complex_mapping as $paths) {
            if (is_array($paths)) {
                $all_mapped_paths = array_merge($all_mapped_paths, $paths);
            } else {
                $all_mapped_paths[] = $paths;
            }
        }

        $find_keys = function($data, $prefix = '') use (&$find_keys, $all_mapped_paths, &$unmapped) {
            if (!is_array($data)) {
                return;
            }

            foreach ($data as $key => $value) {
                $current_path = $prefix ? $prefix . '.' . $key : $key;

                if (!in_array($current_path, $all_mapped_paths, true)) {
                    if (!is_array($value) || empty($value)) {
                        $unmapped[] = $current_path;
                    }
                }

                if (is_array($value)) {
                    $find_keys($value, $current_path);
                }
            }
        };

        $find_keys($raw_data);

        return array_values(array_unique($unmapped));
    }

    /**
     * Adiciona mapeamento customizado
     */
    public static function add_custom_mapping($normalized_key, $paths, $default_value = null) {
        self::$key_mapping[$normalized_key] = $paths;

        if ($default_value !== null) {
            self::$default_values[$normalized_key] = $default_value;
        }
    }

    /**
     * Lista de campos mapeados
     */
    public static function get_mapped_fields() {
        return array_merge(self::$key_mapping, self::$complex_mapping);
    }

    /**
     * Valores padrão
     */
    public static function get_default_values() {
        return self::$default_values;
    }
}
