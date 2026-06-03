<?php
/**
 * FC DME Solver - Orders
 */

if (!defined('ABSPATH')) exit;

class FC_DME_Orders {

    public function __construct() {
        add_action('wp_ajax_fc_dme_create_order', [$this, 'ajax_create_order']);
        add_action('wp_ajax_nopriv_fc_dme_create_order', [$this, 'ajax_create_order']);
    }

    private static function normalize_platform($p) {
        $p = strtolower(trim((string)$p));
        if ($p === 'pc') return 'pc';
        if (in_array($p, ['ps','ps4','ps5','xbox','xb','xbs','xone'], true)) return 'console';
        return 'console';
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'cs_dme_orders';
    }

    public static function maybe_create_table() {
        global $wpdb;

        $table = self::table_name();

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists === $table) {
            $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'quantidade'");
            if (empty($col)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN quantidade VARCHAR(255) NULL DEFAULT NULL AFTER selection_json");
            }
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'draft',
            item_type TEXT NOT NULL,
            item_name TEXT NOT NULL,
            face_url TEXT NULL,
            platform VARCHAR(16) NOT NULL DEFAULT '',
            mode VARCHAR(32) NOT NULL DEFAULT '',
            total_k VARCHAR(32) NOT NULL DEFAULT '',
            total_brl DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            selection_json LONGTEXT NULL,
            quantidade VARCHAR(255) NULL DEFAULT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY token (token),
            KEY user_id (user_id),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    private static function generate_token() {
        try {
            return bin2hex(random_bytes(12));
        } catch (Exception $e) {
            return substr(md5(uniqid('', true) . mt_rand()), 0, 24);
        }
    }

    private static function format_coins_to_k_string($coins) {
        $n = intval($coins);
        if ($n <= 0) return '';

        if ($n >= 1000000) {
            $v = $n / 1000000.0;
            $s = number_format($v, 2, ',', '');
            $s = rtrim(rtrim($s, '0'), ',');
            return $s . 'kk';
        }

        if ($n >= 1000) {
            $v = $n / 1000.0;
            $s = number_format($v, 1, ',', '');
            $s = rtrim(rtrim($s, '0'), ',');
            return $s . 'k';
        }

        return (string) $n;
    }

    public function ajax_create_order() {
        check_ajax_referer('fc_dme_nonce', 'nonce');

        self::maybe_create_table();

        error_log('========================================');
        error_log('FC DME Orders: Nova requisição recebida');
        error_log('User ID: ' . get_current_user_id());
        error_log('========================================');

        $payload_raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
        
        if (!$payload_raw) {
            error_log('FC DME Orders: ❌ Payload ausente');
            wp_send_json_error(['message' => 'Payload ausente']);
        }

        error_log('FC DME Orders: Payload raw recebido (length: ' . strlen($payload_raw) . ')');

        $payload = json_decode($payload_raw, true);
        
        if (!is_array($payload)) {
            error_log('FC DME Orders: ❌ Payload inválido (não é array)');
            error_log('FC DME Orders: JSON error: ' . json_last_error_msg());
            wp_send_json_error(['message' => 'Payload inválido']);
        }

        error_log('FC DME Orders: Payload decodificado com sucesso');

        $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
        
        if (empty($items)) {
            error_log('FC DME Orders: ❌ Nenhum item no payload');
            wp_send_json_error(['message' => 'Nenhum item selecionado']);
        }

        error_log('FC DME Orders: Total de items recebidos: ' . count($items));

        $selected_platform = self::normalize_platform($payload['platform'] ?? 'console');
        $mode = isset($payload['mode']) ? strtolower(trim((string)$payload['mode'])) : '';
        if (!$mode) $mode = 'lance';

        error_log("FC DME Orders: Platform={$selected_platform}, Mode={$mode}");

        $token = self::generate_token();
        error_log("FC DME Orders: Token gerado: {$token}");

        global $wpdb;
        $table = self::table_name();

        $user_id = get_current_user_id();
        $now = current_time('mysql');

        $min_cents = defined('FC_DME_MIN_TOTAL_BRL_CENTS') ? intval(FC_DME_MIN_TOTAL_BRL_CENTS) : 1999;

        // ===================================================================
        // NOVO: AGREGAR DADOS AO INVÉS DE LOOP
        // ===================================================================
        
        $item_types = [];
        $item_names = [];
        $face_urls = [];
        $quantities = [];
        $total_coins_sum = 0;
        $total_brl_cents_sum = 0;
        $all_items_data = [];

        error_log('FC DME Orders: ========== AGREGANDO DADOS ==========');

        foreach ($items as $item_index => $it) {
            error_log("FC DME Orders: --- Processando item #{$item_index} ---");
            
            if (!is_array($it)) {
                error_log("FC DME Orders: ❌ Item #{$item_index} não é array");
                continue;
            }

            $item_key = isset($it['item_key']) ? sanitize_text_field((string)$it['item_key']) : '';
            $item_type = isset($it['item_type']) ? sanitize_text_field((string)$it['item_type']) : '';
            $item_name = isset($it['item_name']) ? sanitize_text_field((string)$it['item_name']) : '';
            if ($item_name === '') $item_name = 'SBC';

            error_log("FC DME Orders:   Key: {$item_key}");
            error_log("FC DME Orders:   Name: {$item_name}");
            error_log("FC DME Orders:   Type: {$item_type}");

            $face_url = isset($it['face_url']) ? esc_url_raw((string)$it['face_url']) : '';
            $pricing_platform = self::normalize_platform($it['pricing_platform'] ?? $selected_platform);

            $total_coins = isset($it['total_coins']) ? intval($it['total_coins']) : 0;
            $total_brl_cents = isset($it['total_brl_cents']) ? intval($it['total_brl_cents']) : 0;

            error_log("FC DME Orders:   Coins: {$total_coins}");
            error_log("FC DME Orders:   BRL cents: {$total_brl_cents}");

            // Aplica mínimo por item
            if ($total_brl_cents > 0 && $total_brl_cents < $min_cents) {
                error_log("FC DME Orders:   Aplicando mínimo: {$min_cents}");
                $total_brl_cents = $min_cents;
            }

            $quantity = isset($it['quantity']) ? max(1, intval($it['quantity'])) : 1;

            // Acumula para concatenação
            $item_types[] = $item_type;
            $item_names[] = $item_name;
            $face_urls[] = $face_url;
            $quantities[] = $quantity;
            
            // Soma totais (multiplicando pela quantidade)
            $total_coins_sum += $total_coins * $quantity;
            $total_brl_cents_sum += $total_brl_cents * $quantity;

            // Guarda dados completos de cada item para o JSON
            $selection = $it['selection_json'] ?? null;
            
            if (is_array($selection)) {
                if (!isset($selection['selected_platform'])) $selection['selected_platform'] = $selected_platform;
                if (!isset($selection['pricing_platform'])) $selection['pricing_platform'] = $pricing_platform;
            } elseif (is_string($selection) && trim($selection) !== '') {
                $selection = json_decode($selection, true);
                if (!is_array($selection)) {
                    $selection = [
                        'selected_platform' => $selected_platform,
                        'pricing_platform' => $pricing_platform,
                        'selected' => []
                    ];
                }
            } else {
                $selection = [
                    'selected_platform' => $selected_platform,
                    'pricing_platform' => $pricing_platform,
                    'selected' => []
                ];
            }

            $all_items_data[] = [
                'item_key' => $item_key,
                'item_type' => $item_type,
                'item_name' => $item_name,
                'face_url' => $face_url,
                'total_coins' => $total_coins,
                'total_brl_cents' => $total_brl_cents,
                'pricing_platform' => $pricing_platform,
                'selection' => $selection
            ];
        }

        if (empty($all_items_data)) {
            error_log('FC DME Orders: ❌ Nenhum item válido processado');
            wp_send_json_error(['message' => 'Nenhum item válido para salvar']);
        }

        // Concatena com vírgulas
        $item_types_str = implode(',', $item_types);
        $item_names_str = implode(',', $item_names);
        $face_urls_str = implode(',', $face_urls);
        $quantidade_str = implode(',', $quantities);

        // Formata totais
        $total_k = self::format_coins_to_k_string($total_coins_sum);
        $total_brl = round($total_brl_cents_sum / 100, 2);

        // Monta selection_json com todos os items
        $selection_json_final = wp_json_encode([
            'selected_platform' => $selected_platform,
            'mode' => $mode,
            'items' => $all_items_data
        ]);

        error_log("FC DME Orders: Total coins: {$total_coins_sum}");
        error_log("FC DME Orders: Total BRL: R$ {$total_brl}");
        error_log("FC DME Orders: Items concatenados: {$item_names_str}");

        // ===================================================================
        // INSERÇÃO ÚNICA NO BANCO
        // ===================================================================

        error_log("FC DME Orders: Inserindo registro único...");

        $ok = $wpdb->insert(
            $table,
            [
                'token'          => $token,
                'status'         => 'draft',
                'item_type'      => $item_types_str,
                'item_name'      => $item_names_str,
                'face_url'       => $face_urls_str,
                'platform'       => $selected_platform,
                'mode'           => $mode,
                'total_k'        => $total_k,
                'total_brl'      => $total_brl,
                'selection_json' => $selection_json_final,
                'quantidade'     => $quantidade_str,
                'user_id'        => $user_id ? intval($user_id) : 0,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                '%s','%s','%s','%s','%s','%s','%s','%s','%f','%s','%s','%d','%s','%s'
            ]
        );

        if ($ok === false) {
            $db_error = $wpdb->last_error ? $wpdb->last_error : 'Erro desconhecido ao inserir';
            error_log("FC DME Orders: ❌ Falha ao inserir: {$db_error}");
            error_log("FC DME Orders: wpdb->last_query: " . $wpdb->last_query);
            
            wp_send_json_error([
                'message' => 'Falha ao salvar pedido: ' . $db_error,
                'total_items' => count($items)
            ]);
        }

        $insert_id = $wpdb->insert_id;
        error_log("FC DME Orders: ✅ Registro inserido com sucesso (ID: {$insert_id})");
        error_log('FC DME Orders: ========== FIM ==========');

        $buy_url = defined('FC_DME_BUY_URL') ? FC_DME_BUY_URL : home_url('/dme-form/');
        $buy_url = trailingslashit($buy_url);
        $redirect = add_query_arg(['token' => $token], $buy_url);

        error_log("FC DME Orders: ✅ Token {$token} criado com " . count($items) . " itens agregados");
        error_log('========================================');

        wp_send_json_success([
            'token' => $token,
            'redirect_url' => $redirect,
            'total_items' => count($items),
            'total_coins' => $total_coins_sum,
            'total_brl' => $total_brl,
            'insert_id' => $insert_id
        ]);
    }
}