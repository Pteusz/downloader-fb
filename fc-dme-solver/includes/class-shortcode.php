<?php
/**
 * Shortcode handler for displaying SBCs
 */

if (!defined('ABSPATH')) exit;

class FC_DME_Shortcode {

    public function __construct() {
        // ✅ Shortcode principal (tudo)
        add_shortcode('fc_dme_solver', [$this, 'render_shortcode']);

        // ✅ Shortcode “featured”: apenas 4 primeiros jogadores
        add_shortcode('fc_dme_featured_players', [$this, 'render_featured_players_shortcode']);

        // ✅ Enfileirar assets só quando realmente precisa
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    private function normalize_platform($p) {
        $p = strtolower(trim((string)$p));
        if ($p === 'pc') return 'pc';
        if (in_array($p, ['ps', 'ps4', 'ps5', 'xbox', 'xb', 'xbs', 'xone', 'console'], true)) return 'console';
        return 'console';
    }
private function ensure_assets() {

    // Evita duplicar enqueue (quando o shortcode aparece mais de uma vez)
    if (wp_style_is('fc-dme-style', 'enqueued') && wp_script_is('fc-dme-script', 'enqueued')) {
        return;
    }

    $css_rel = 'assets/css/style.css';
    $js_rel  = 'assets/js/script.js';

    // ✅ DEV MODE: admin sempre quebra cache automaticamente
    $is_admin_dev = current_user_can('manage_options');

    $css_ver = $is_admin_dev
        ? (string) time()
        : (file_exists(FC_DME_PATH . $css_rel) ? (string) filemtime(FC_DME_PATH . $css_rel) : FC_DME_VERSION);

    $js_ver = $is_admin_dev
        ? (string) time()
        : (file_exists(FC_DME_PATH . $js_rel) ? (string) filemtime(FC_DME_PATH . $js_rel) : FC_DME_VERSION);

    wp_enqueue_style(
        'fc-dme-style',
        FC_DME_URL . $css_rel,
        [],
        $css_ver
    );

    wp_enqueue_script(
        'fc-dme-script',
        FC_DME_URL . $js_rel,
        ['jquery'],
        $js_ver,
        true
    );

    $minCents   = defined('FC_DME_MIN_TOTAL_BRL_CENTS') ? intval(FC_DME_MIN_TOTAL_BRL_CENTS) : 1999;

    $buyUrl     = defined('FC_DME_BUY_URL') ? FC_DME_BUY_URL : home_url('/dme-form/');

    $priceMode = class_exists('FC_DME_Price_Rules')
        ? FC_DME_Price_Rules::get_price_mode()
        : get_option('fc_player_shop_mode', 'lance');

    $default_platform = $this->normalize_platform(FC_DME_Settings::get_default_platform());

    wp_localize_script('fc-dme-script', 'fcDME', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('fc_dme_nonce'),
        'defaultPlatform' => $default_platform,
        'minTotalBrlCents' => $minCents,
        'buyUrl' => $buyUrl,
        'priceMode' => $priceMode,
        'isLoggedIn'    => is_user_logged_in() ? 1 : 0,
        'itemOverrides' => class_exists('FC_DME_Settings') ? FC_DME_Settings::get_item_overrides() : [],
    ]);
}

    /**
     * ✅ Enfileira CSS/JS com cache busting:
     * - Admin (dev): time() muda a cada refresh (sem limpar cache)
     * - Visitante: filemtime() muda quando você edita o arquivo
     *
     * ✅ E só enfileira quando algum shortcode do plugin aparece na página
     */
   public function enqueue_assets() {
    // Intencionalmente vazio.
    // Os assets são garantidos quando os shortcodes rodam via ensure_assets().
}
    /**
     * ✅ Shortcode principal
     * Uso: [fc_dme_solver] ou [fc_dme_solver limit="80" only="all|players|rewards"]
     */
public function render_shortcode($atts) {
    $this->ensure_assets();

    $atts = shortcode_atts([
        'limit' => 80,
        'only'  => 'all',
    ], $atts);


        $limit = max(1, min(300, intval($atts['limit'])));
        $only  = strtolower(trim($atts['only']));

        $items = FC_DME_API::fetch_sbcs(true);

        if (is_wp_error($items)) {
            return $this->render_error($items->get_error_message());
        }

        if (!is_array($items)) {
            $items = [];
        }

        if ($only === 'players') {
            $items = array_filter($items, function($item) {
                return !empty($item['is_player']);
            });
        } elseif ($only === 'rewards') {
            $items = array_filter($items, function($item) {
                return empty($item['is_player']);
            });
        }

        $items = array_slice($items, 0, $limit);

        $renderer_available = class_exists('FC_Card_Visual_Renderer') && class_exists('FC_Card_Normalizer');

        ob_start();
        $this->render_html($items, $renderer_available);
        return ob_get_clean();
    }

    /**
     * ✅ Shortcode “featured”
     * Exibe só os 4 primeiros JOGADORES (sem recompensas)
     * Uso: [fc_dme_featured_players]
     */
public function render_featured_players_shortcode($atts) {
    $this->ensure_assets();

    $atts = shortcode_atts([
        'limit' => 4,
    ], $atts);


        $limit = max(1, min(20, intval($atts['limit'])));

        $items = FC_DME_API::fetch_sbcs(true);

        if (is_wp_error($items)) {
            return $this->render_error($items->get_error_message());
        }

        if (!is_array($items)) {
            $items = [];
        }

        // Só jogadores
        $items = array_values(array_filter($items, function($item) {
            return !empty($item['is_player']);
        }));

        $items = array_slice($items, 0, $limit);

        $renderer_available = class_exists('FC_Card_Visual_Renderer') && class_exists('FC_Card_Normalizer');

        // ✅ Reaproveita o mesmo HTML, mas você pode (se quiser) criar um render_html_featured depois
ob_start();
$this->render_html_featured($items, $renderer_available);
return ob_get_clean();

    }


    // =========================
    // ✅ Daqui pra baixo: seu código original (mantido)
    // =========================

    private function render_html($items, $renderer_available) {
        $default_platform = $this->normalize_platform(FC_DME_Settings::get_default_platform());
        $default_display = ($default_platform === 'console') ? 'ps' : 'pc';

        $platforms = [
            'ps' => [
                'name' => 'PlayStation',
                'img' => 'https://img.icons8.com/?size=100&id=13629&format=png&color=000000',
                'type' => 'console'
            ],
            'xbox' => [
                'name' => 'Xbox',
                'img' => 'https://chamacoins.com.br/wp-content/uploads/2025/10/Logo-xbox.png',
                'type' => 'console'
            ],
            'pc' => [
                'name' => 'PC',
                'img' => 'https://chamacoins.com.br/wp-content/uploads/2025/10/Origin-1-1.png',
                'type' => 'pc'
            ]
        ];

        $current = $platforms[$default_display];
        ?>
        <div class="fc-dme-wrapper">

            <div class="fc-dme-header">
                <h2 class="fc-dme-title">SBCs Disponíveis</h2>

                <div class="fc-dme-controls">
                    <div class="fc-dme-platform-toggle" data-current="<?php echo esc_attr($default_display); ?>" data-open="0">
                        <div class="fc-dme-toggle-current">
                            <img class="fc-dme-current-icon"
                                 src="<?php echo esc_url($current['img']); ?>"
                                 alt="<?php echo esc_attr($current['name']); ?>" />

                            <span class="fc-dme-current-label">Plataforma:</span>

                            <span class="fc-dme-current-name"><?php echo esc_html($current['name']); ?></span>

                            <img class="fc-dme-current-caret"
                                 src="https://img.icons8.com/?size=100&id=16773&format=png&color=FFFFFF"
                                 alt="Abrir" />
                        </div>

                        <div class="fc-dme-platform-menu">
                            <?php foreach ($platforms as $key => $platform): ?>
                                <div class="fc-dme-toggle-option"
                                     data-platform="<?php echo esc_attr($key); ?>"
                                     data-type="<?php echo esc_attr($platform['type']); ?>">
                                    <img src="<?php echo esc_url($platform['img']); ?>"
                                         alt="<?php echo esc_attr($platform['name']); ?>" />
                                    <span><?php echo esc_html($platform['name']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if (current_user_can('manage_options')): ?>
                        <button class="fc-dme-refresh-btn" id="fc-dme-refresh">🔄 Atualizar</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$renderer_available): ?>
                <div class="fc-dme-warning">
                    ⚠️ Plugin FC Card Renderer não detectado. Jogadores serão exibidos de forma simplificada.
                </div>
            <?php endif; ?>

            <div class="fc-dme-grid">
                <?php foreach ($items as $idx => $item): ?>
                    <?php $this->render_sbc_card($item, $renderer_available, $idx); ?>
                <?php endforeach; ?>

                <?php if (empty($items)): ?>
                    <div class="fc-dme-empty"><p>Nenhum SBC disponível no momento.</p></div>
                <?php endif; ?>
            </div>

        </div>

        <div class="fc-dme-modal" id="fc-dme-modal">
            <div class="fc-dme-modal-overlay"></div>
            <div class="fc-dme-modal-content">
                <button class="fc-dme-modal-close">&times;</button>
                <div class="fc-dme-modal-body"></div>
            </div>
        </div>
        <?php
    }
private function render_html_featured($items, $renderer_available) {
    static $modal_printed = false;
    ?>
    <div class="fc-dme-wrapper fc-dme-wrapper--featured">

        <?php if (!$renderer_available): ?>
            <div class="fc-dme-warning">
                ⚠️ Plugin FC Card Renderer não detectado. Jogadores serão exibidos de forma simplificada.
            </div>
        <?php endif; ?>

        <div class="fc-dme-grid">
            <?php foreach ($items as $idx => $item): ?>
                <?php $this->render_sbc_card($item, $renderer_available, $idx); ?>
            <?php endforeach; ?>

            <?php if (empty($items)): ?>
                <div class="fc-dme-empty"><p>Nenhum jogador disponível no momento.</p></div>
            <?php endif; ?>
        </div>

    </div>

    <?php if (!$modal_printed): $modal_printed = true; ?>
        <!-- ✅ Modal (necessário pro JS funcionar) -->
        <div class="fc-dme-modal" id="fc-dme-modal">
            <div class="fc-dme-modal-overlay"></div>
            <div class="fc-dme-modal-content">
                <button class="fc-dme-modal-close" type="button">&times;</button>
                <div class="fc-dme-modal-body"></div>
            </div>
        </div>
    <?php endif; ?>

    <?php
}


    private function pick_face_url_from_player($player_details, $player_preview) {
        $candidates = [];

        if (is_array($player_details)) {
            if (!empty($player_details['images']['face'])) $candidates[] = $player_details['images']['face'];
            if (!empty($player_details['images']['face_img'])) $candidates[] = $player_details['images']['face_img'];
            if (!empty($player_details['face_img'])) $candidates[] = $player_details['face_img'];
            if (!empty($player_details['face'])) $candidates[] = $player_details['face'];
        }

        if (is_array($player_preview)) {
            if (!empty($player_preview['face_img'])) $candidates[] = $player_preview['face_img'];
            if (!empty($player_preview['images']['face'])) $candidates[] = $player_preview['images']['face'];
        }

        foreach ($candidates as $u) {
            $u = trim((string)$u);
            if ($u) return $u;
        }

        return '';
    }

    private function make_unique_sbc_key($item, $idx, $face_url, $reward_img) {
        $is_player = !empty($item['is_player']);
        $name = $item['name'] ?? 'SBC';
        $full_url = $item['full_url'] ?? '';

        if ($full_url && trim($full_url) !== '') {
            $hash = substr(md5($full_url), 0, 12);
            return 'sbc-' . $idx . '-' . $hash;
        }

        $challenges = $item['challenges'] ?? [];

        $signature_parts = [
            $name,
            $is_player ? 'player' : 'reward',
            $face_url,
            $reward_img,
            count($challenges)
        ];

        for ($i = 0; $i < min(3, count($challenges)); $i++) {
            if (isset($challenges[$i]['name'])) {
                $signature_parts[] = $challenges[$i]['name'];
            }
            if (isset($challenges[$i]['price_ps'])) {
                $signature_parts[] = $challenges[$i]['price_ps'];
            }
        }

        $signature = implode('|', $signature_parts);
        $hash = substr(md5($signature), 0, 12);

        return 'sbc-' . $idx . '-' . $hash;
    }

    private function render_sbc_card($item, $renderer_available, $idx) {
        $name      = $item['name'] ?? 'SBC';
        $is_player = !empty($item['is_player']);

        $price_ps = $item['price_ps'] ?? '';
        $price_pc = $item['price_pc'] ?? '';

        $price_ps_coins = isset($item['price_ps_coins']) ? intval($item['price_ps_coins']) : '';
        $price_pc_coins = isset($item['price_pc_coins']) ? intval($item['price_pc_coins']) : '';

        $price_ps_brl_cents = isset($item['price_ps_brl_cents']) ? intval($item['price_ps_brl_cents']) : '';
        $price_pc_brl_cents = isset($item['price_pc_brl_cents']) ? intval($item['price_pc_brl_cents']) : '';

        $player_details = $item['player_details'] ?? null;
        $player_preview = $item['player_preview'] ?? null;

        $reward_img  = $item['reward_img'] ?? '';
        $reward_text = $item['reward_text'] ?? '';

        $challenges = $item['challenges'] ?? [];
        $challenges_json = htmlspecialchars(json_encode($challenges), ENT_QUOTES, 'UTF-8');

        $face_url = '';
        if ($is_player) {
            $face_url = $this->pick_face_url_from_player($player_details, $player_preview);
        } else {
            $face_url = $reward_img;
        }

        $sbc_key = $this->make_unique_sbc_key($item, $idx, $face_url, $reward_img);
        $override_key = md5($item['full_url'] ?? ($name . ($is_player ? 'player' : 'reward')));
        ?>
        <div class="fc-dme-card"
             data-sbc-key="<?php echo esc_attr($sbc_key); ?>"
             data-selected="0"
             data-type="<?php echo $is_player ? 'player' : 'reward'; ?>"
             data-face-url="<?php echo esc_attr($face_url); ?>"
             data-price-console="<?php echo esc_attr($price_ps); ?>"
             data-price-pc="<?php echo esc_attr($price_pc); ?>"
             data-price-console-coins="<?php echo esc_attr($price_ps_coins); ?>"
             data-price-pc-coins="<?php echo esc_attr($price_pc_coins); ?>"
             data-price-console-brl-cents="<?php echo esc_attr($price_ps_brl_cents); ?>"
             data-price-pc-brl-cents="<?php echo esc_attr($price_pc_brl_cents); ?>"
             data-challenges='<?php echo $challenges_json; ?>'
             data-name="<?php echo esc_attr($name); ?>"
             data-completed="<?php echo esc_attr($item['completed'] ?? ''); ?>"
             data-override-key="<?php echo esc_attr($override_key); ?>">
            <div class="fc-dme-card-name">
                <?php echo esc_html($name); ?>
            </div>

            <div class="fc-dme-card-content">
                <?php if ($is_player): ?>
                    <?php $this->render_player($player_details, $player_preview, $renderer_available); ?>
                <?php else: ?>
                    <?php $this->render_reward($reward_img, $reward_text, $name); ?>
                <?php endif; ?>
            </div>


            <?php if (!empty($item['completed'])): ?>
            <div class="fc-dme-completed-badge">
                <svg class="fc-dme-completed-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74"/><polyline points="3 3 3 9 9 9"/></svg>
                <span class="fc-dme-completed-value"><?php echo esc_html($item['completed']); ?></span>
            </div>
            <?php endif; ?>
            <div class="fc-dme-card-footer">
                <div class="fc-dme-price">
                    <span class="fc-dme-price-value">-</span>
                </div>

                <div class="fc-dme-actions">
                    <button class="fc-dme-solve-btn" data-sbc-name="<?php echo esc_attr($name); ?>">
                        Resolver
                    </button>

                    <button class="fc-dme-edit-btn" style="display:none;" data-sbc-name="<?php echo esc_attr($name); ?>">
                        Editar
                    </button>

                    <button class="fc-dme-add-btn" style="display:none;" data-sbc-name="<?php echo esc_attr($name); ?>">
                        Adicionar
                    </button>

                    <button class="fc-dme-remove-btn" style="display:none;" data-sbc-name="<?php echo esc_attr($name); ?>">
                        Remover
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_player($player_details, $player_preview, $renderer_available) {
        if (!$renderer_available) {
            echo '<div class="fc-dme-error">⚠️ FC Card Renderer não instalado</div>';
            return;
        }

        $mode     = get_option('fc_player_shop_mode', 'lance');
        $platform = get_option('fc_player_shop_platform', 'ps');

        $raw_player = $player_details ?: $this->adapt_player_preview($player_preview);

        if (!$raw_player) {
            echo '<div class="fc-dme-error">Dados do jogador não disponíveis</div>';
            return;
        }

        $normalized = FC_Card_Normalizer::normalize($raw_player, [
            'default_mode'     => $mode,
            'default_platform' => $platform,
            'convert_prices'   => false,
        ]);

        if (!is_array($normalized) || !empty($normalized['error'])) {
            echo '<div class="fc-dme-error">Falha ao normalizar jogador</div>';
            return;
        }

        echo FC_Card_Visual_Renderer::render_card($normalized, [
            'width'           => 260,
            'show_playstyles' => true,
            'show_extra_info' => true,
            'responsive'      => true,
        ]);
    }

    private function render_reward($img, $text, $name) {
        ?>
        <div class="fc-dme-reward">
            <div class="fc-dme-reward-img">
                <?php if ($img): ?>
                    <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($text ?: $name); ?>" />
                <?php else: ?>
                    <div class="fc-dme-reward-placeholder">📦</div>
                <?php endif; ?>
            </div>
            <div class="fc-dme-reward-text"><?php echo esc_html($text ?: $name); ?></div>
        </div>
        <?php
    }

    private function adapt_player_preview($preview) {
        if (!is_array($preview)) return null;

        return [
            'name'     => $preview['name'] ?? '',
            'rating'   => $preview['rating'] ?? '0',
            'position' => $preview['position'] ?? 'N/A',
            'images'   => [
                'bg'   => $preview['bg_img'] ?? null,
                'face' => $preview['face_img'] ?? null,
            ],
        ];
    }

    private function render_error($message) {
        return sprintf('<div class="fc-dme-error">❌ Erro: %s</div>', esc_html($message));
    }
}
