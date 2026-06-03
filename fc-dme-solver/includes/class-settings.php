<?php
/**
 * Settings management
 */

if (!defined('ABSPATH')) exit;

class FC_DME_Settings {
    
    const OPTION_API_URL          = 'fc_dme_api_url';
    const OPTION_CACHE_TTL        = 'fc_dme_cache_ttl';
    const OPTION_DEFAULT_PLATFORM = 'fc_dme_default_platform';
    const OPTION_ITEM_OVERRIDES   = 'fc_dme_item_overrides';

   const DEFAULT_API_URL = 'https://chamacoins.com.br/wp-json/fhub/v1/sbc/feed';
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_fc_dme_save_override',   [$this, 'ajax_save_override']);
        add_action('wp_ajax_fc_dme_delete_override', [$this, 'ajax_delete_override']);
    }
    
    public function add_settings_page() {
        add_options_page(
            'FC DME Solver',
            'FC DME Solver',
            'manage_options',
            'fc-dme-solver',
            [$this, 'render_settings_page']
        );
    }
    
    public function register_settings() {
        register_setting('fc_dme_group', self::OPTION_API_URL, [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => self::DEFAULT_API_URL,
        ]);

        register_setting('fc_dme_group', self::OPTION_CACHE_TTL, [
            'type'              => 'integer',
            'sanitize_callback' => function($v) {
                return max(30, min(3600, intval($v)));
            },
            'default' => 600,
        ]);
        
        register_setting('fc_dme_group', self::OPTION_DEFAULT_PLATFORM, [
            'type'              => 'string',
            'sanitize_callback' => function($v) {
                return in_array($v, ['console', 'pc']) ? $v : 'console';
            },
            'default' => 'console',
        ]);
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        
        $api_url          = self::get_api_url();
        $cache_ttl        = self::get_cache_ttl();
        $default_platform = self::get_default_platform();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('fc_dme_group'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(self::OPTION_API_URL); ?>">
                                API Endpoint
                            </label>
                        </th>
                        <td>
                            <input type="url" 
                                   class="regular-text" 
                                   name="<?php echo esc_attr(self::OPTION_API_URL); ?>" 
                                   value="<?php echo esc_attr($api_url); ?>" />
                            <p class="description">
                                URL da API de SBCs. Padrão: <code><?php echo esc_html(self::DEFAULT_API_URL); ?></code>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(self::OPTION_CACHE_TTL); ?>">
                                Cache TTL (segundos)
                            </label>
                        </th>
                        <td>
                            <input type="number" 
                                   min="30" 
                                   max="3600" 
                                   name="<?php echo esc_attr(self::OPTION_CACHE_TTL); ?>" 
                                   value="<?php echo esc_attr($cache_ttl); ?>" />
                            <p class="description">Tempo de cache (30–3600 segundos)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(self::OPTION_DEFAULT_PLATFORM); ?>">
                                Plataforma Padrão
                            </label>
                        </th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_DEFAULT_PLATFORM); ?>">
                                <option value="console" <?php selected($default_platform, 'console'); ?>>
                                    Console (PS/Xbox)
                                </option>
                                <option value="pc" <?php selected($default_platform, 'pc'); ?>>
                                    PC
                                </option>
                            </select>
                            <p class="description">Plataforma padrão ao carregar a página</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Salvar Configurações'); ?>
            </form>
            
            <hr/>
            
            <h2>Shortcode</h2>
            <p>Use o shortcode abaixo para exibir os SBCs:</p>
            <code>[fc_dme_solver]</code>
            
            <h3>Parâmetros opcionais:</h3>
            <ul>
                <li><code>limit="80"</code> — Número máximo de SBCs a exibir (padrão: 80)</li>
                <li><code>only="all"</code> — Filtro: all | players | rewards (padrão: all)</li>
            </ul>
            
            <p><strong>Exemplo:</strong> <code>[fc_dme_solver limit="50" only="players"]</code></p>

            <hr/>
            <h2>Regras por Item</h2>
            <p>Defina quantidade máxima, preço e mínimo obrigatório para itens específicos.</p>

            <?php
            $fc_overrides = self::get_item_overrides();
            $fc_sbcs = class_exists('FC_DME_API') ? FC_DME_API::fetch_sbcs(true) : [];
            if (is_wp_error($fc_sbcs) || !is_array($fc_sbcs)) $fc_sbcs = [];
            ?>

            <?php if (!empty($fc_overrides)): ?>
            <h3>Overrides ativos</h3>
            <table class="widefat striped" style="max-width:860px; margin-bottom:24px;">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qtd máx</th>
                        <th>Preço/unidade</th>
                        <th>Qtd mínima p/ comprar</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($fc_overrides as $ov_key => $ov): ?>
                    <tr>
                        <td><strong><?php echo esc_html($ov['name'] ?? $ov_key); ?></strong></td>
                        <td><?php echo intval($ov['max_qty'] ?? 0); ?></td>
                        <td>
                            <?php if (!empty($ov['price_brl_cents'])): ?>
                                R$ <?php echo number_format(intval($ov['price_brl_cents']) / 100, 2, ',', '.'); ?>
                            <?php else: ?>
                                <em style="opacity:.5">original</em>
                            <?php endif; ?>
                        </td>
                        <td><?php echo intval($ov['min_qty'] ?? 0) ?: '<em style="opacity:.5">sem mínimo</em>'; ?></td>
                        <td>
                            <button type="button" class="button button-small fc-dme-del-override"
                                    data-key="<?php echo esc_attr($ov_key); ?>">
                                Remover
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="opacity:.6">Nenhum override configurado ainda.</p>
            <?php endif; ?>

            <h3>Adicionar / Editar Override</h3>
            <?php if (empty($fc_sbcs)): ?>
                <div class="notice notice-warning inline"><p>⚠️ Cache vazio. Carregue a página com o shortcode primeiro (ou clique em Atualizar no frontend).</p></div>
            <?php else: ?>
            <table class="form-table" style="max-width:860px;">
                <tr>
                    <th scope="row">Item</th>
                    <td>
                        <select id="fc-ov-key" style="min-width:320px; max-width:100%;">
                            <optgroup label="Jogadores">
                            <?php foreach ($fc_sbcs as $fc_idx => $fc_sbc):
                                if (empty($fc_sbc['is_player'])) continue;
                                $fc_ok = md5($fc_sbc['full_url'] ?? ($fc_sbc['name'] . 'player'));
                            ?>
                                <option value="<?php echo esc_attr($fc_ok); ?>"
                                        data-name="<?php echo esc_attr($fc_sbc['name']); ?>">
                                    <?php echo esc_html($fc_sbc['name']); ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Recompensas">
                            <?php foreach ($fc_sbcs as $fc_idx => $fc_sbc):
                                if (!empty($fc_sbc['is_player'])) continue;
                                $fc_ok = md5($fc_sbc['full_url'] ?? ($fc_sbc['name'] . 'reward'));
                            ?>
                                <option value="<?php echo esc_attr($fc_ok); ?>"
                                        data-name="<?php echo esc_attr($fc_sbc['name']); ?>">
                                    <?php echo esc_html($fc_sbc['name']); ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Quantidade máxima (x/N)</th>
                    <td>
                        <input type="number" id="fc-ov-max-qty" min="1" max="9999" value="1" style="width:90px;" />
                        <p class="description">Sobrescreve o máximo de adições permitidas para este item</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Preço por unidade (R$)</th>
                    <td>
                        <input type="number" id="fc-ov-price" min="0" step="0.01" value="0.00" style="width:110px;" />
                        <p class="description">0.00 = mantém o preço original da API</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Quantidade mínima para comprar</th>
                    <td>
                        <input type="number" id="fc-ov-min-qty" min="0" max="9999" value="0" style="width:90px;" />
                        <p class="description">0 = sem mínimo obrigatório</p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" id="fc-ov-save" class="button button-primary">Salvar Override</button>
                <span id="fc-ov-msg" style="margin-left:12px; font-weight:600;"></span>
            </p>

            <script>
            (function($){
                var existingOv = <?php echo wp_json_encode($fc_overrides ?: new stdClass()); ?>;
                var adminNonce = '<?php echo wp_create_nonce("fc_dme_admin_nonce"); ?>';

                function prefill() {
                    var key = $('#fc-ov-key').val();
                    if (existingOv[key]) {
                        var ov = existingOv[key];
                        $('#fc-ov-max-qty').val(ov.max_qty || 1);
                        $('#fc-ov-price').val(((ov.price_brl_cents || 0) / 100).toFixed(2));
                        $('#fc-ov-min-qty').val(ov.min_qty || 0);
                    } else {
                        $('#fc-ov-max-qty').val(1);
                        $('#fc-ov-price').val('0.00');
                        $('#fc-ov-min-qty').val(0);
                    }
                }
                $('#fc-ov-key').on('change', prefill);
                prefill();

                $('#fc-ov-save').on('click', function(){
                    var key      = $('#fc-ov-key').val();
                    var name     = $('#fc-ov-key option:selected').data('name') || key;
                    var maxQty   = parseInt($('#fc-ov-max-qty').val(), 10) || 1;
                    var priceCts = Math.round(parseFloat($('#fc-ov-price').val() || '0') * 100);
                    var minQty   = parseInt($('#fc-ov-min-qty').val(), 10) || 0;

                    $('#fc-ov-msg').text('Salvando...').css('color','#888');
                    $.post(ajaxurl, {
                        action: 'fc_dme_save_override',
                        nonce: adminNonce,
                        ov_key: key, name: name,
                        max_qty: maxQty, price_brl_cents: priceCts, min_qty: minQty
                    }, function(r){
                        if (r.success) {
                            $('#fc-ov-msg').text('✓ Salvo!').css('color','green');
                            setTimeout(function(){ location.reload(); }, 700);
                        } else {
                            $('#fc-ov-msg').text('Erro: ' + (r.data || '?')).css('color','red');
                        }
                    });
                });

                $(document).on('click', '.fc-dme-del-override', function(){
                    if (!confirm('Remover este override?')) return;
                    var $b = $(this).prop('disabled', true).text('...');
                    $.post(ajaxurl, {
                        action: 'fc_dme_delete_override',
                        nonce: adminNonce,
                        ov_key: $(this).data('key')
                    }, function(r){
                        if (r.success) { location.reload(); }
                        else { $b.prop('disabled', false).text('Remover'); alert('Erro: ' + (r.data || '?')); }
                    });
                });
            })(jQuery);
            </script>
            <?php endif; ?>

        </div>
        <?php
    }

    public static function get_api_url() {
        return get_option(self::OPTION_API_URL, self::DEFAULT_API_URL);
    }

    public static function get_cache_ttl() {
        return intval(get_option(self::OPTION_CACHE_TTL, 600));
    }
    
    public static function get_default_platform() {
        return get_option(self::OPTION_DEFAULT_PLATFORM, 'console');
    }

    public static function get_item_overrides() {
        $raw = get_option(self::OPTION_ITEM_OVERRIDES, []);
        return is_array($raw) ? $raw : [];
    }

    public function ajax_save_override() {
        check_ajax_referer('fc_dme_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Sem permissao');

        $ov_key = sanitize_text_field($_POST['ov_key'] ?? '');
        if (!$ov_key) wp_send_json_error('Key invalida');

        $overrides = self::get_item_overrides();
        $overrides[$ov_key] = [
            'name'            => sanitize_text_field($_POST['name'] ?? $ov_key),
            'max_qty'         => max(1, intval($_POST['max_qty'] ?? 1)),
            'price_brl_cents' => max(0, intval($_POST['price_brl_cents'] ?? 0)),
            'min_qty'         => max(0, intval($_POST['min_qty'] ?? 0)),
        ];

        update_option(self::OPTION_ITEM_OVERRIDES, $overrides);
        wp_send_json_success('ok');
    }

    public function ajax_delete_override() {
        check_ajax_referer('fc_dme_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Sem permissao');

        $ov_key = sanitize_text_field($_POST['ov_key'] ?? '');
        $overrides = self::get_item_overrides();
        unset($overrides[$ov_key]);
        update_option(self::OPTION_ITEM_OVERRIDES, $overrides);
        wp_send_json_success('ok');
    }
}