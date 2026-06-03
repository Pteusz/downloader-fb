<?php
/**
 * API interna do FC Card Renderer
 * Permite que outros plugins consumam dados normalizados
 * Versão 2.0 - Adicionado suporte para conversão de preços
 *
 * @package FCCardRenderer
 */

if (!defined('WPINC')) {
    die;
}

class FC_Card_Renderer_API {
    
    /**
     * Inicializar hooks da API
     */
    public static function init() {
        // Registrar filtros para outros plugins consumirem
        add_filter('fc_card_normalize_data', array(__CLASS__, 'normalize_data'), 10, 2);
        add_filter('fc_card_get_player', array(__CLASS__, 'get_player_data'), 10, 3);
        
        // Filtros de conversão de preços (NOVO)
        add_filter('fc_card_convert_price', array(__CLASS__, 'convert_price'), 10, 4);
        add_filter('fc_card_format_coins', array(__CLASS__, 'format_coins'), 10, 1);
        add_filter('fc_card_get_config', array(__CLASS__, 'get_price_config'), 10, 2);
        
        // Registrar ações para logging e debug
        add_action('fc_card_data_normalized', array(__CLASS__, 'log_normalization'), 10, 2);
        
        // Adicionar script de debug no footer (apenas para admins)
        if (current_user_can('manage_options')) {
            add_action('wp_footer', array(__CLASS__, 'add_debug_script'), 999);
        }
    }
    
    /**
     * Normaliza dados brutos
     *
     * @param array $raw_data Dados brutos do Futbin
     * @param array $options Opções de normalização (default_mode, default_platform)
     * @return array Dados normalizados
     */
    public static function normalize_data($raw_data, $options = array()) {
        if (empty($raw_data)) {
            return array(
                'error' => true,
                'message' => 'Dados brutos vazios ou inválidos',
            );
        }
        
        try {
            $normalized = FC_Card_Normalizer::normalize($raw_data, $options);
            
            // Disparar ação após normalização (para outros plugins reagirem)
            do_action('fc_card_data_normalized', $normalized, $raw_data);
            
            return $normalized;
        } catch (Exception $e) {
            return array(
                'error' => true,
                'message' => 'Erro ao normalizar dados: ' . $e->getMessage(),
            );
        }
    }
    
    /**
     * Busca dados de um jogador e normaliza
     * 
     * Aceita duas fontes:
     * 1. Array/objeto com dados brutos (passados diretamente)
     * 2. ID do jogador (para buscar via API Futbin - implementação futura)
     *
     * @param mixed $default Valor padrão (ignorado, requerido pelo WordPress filter)
     * @param mixed $source Dados brutos ou ID do jogador
     * @param array $options Opções de normalização
     * @return array|null Dados normalizados ou null
     */
    public static function get_player_data($default, $source, $options = array()) {
        // Se $source é array ou objeto, considerar como dados brutos
        if (is_array($source) || is_object($source)) {
            return self::normalize_data($source, $options);
        }
        
        // Se é numérico, considerar como ID (implementação futura de busca via API)
        if (is_numeric($source)) {
            // TODO: Implementar busca via API Futbin
            // Por enquanto, retornar null indicando que precisa passar dados brutos
            return array(
                'error' => true,
                'message' => 'Busca por ID ainda não implementada. Passe os dados brutos diretamente.',
                'requested_id' => $source,
            );
        }
        
        return null;
    }
    
    /**
     * Converte coins para reais
     * Filtro: apply_filters('fc_card_convert_price', null, $coins, $mode, $platform)
     *
     * @param mixed $default Valor padrão (ignorado)
     * @param int|string $coins Quantidade de coins
     * @param string $mode Modo (lance ou sniper)
     * @param string $platform Plataforma (ps, xbox, pc)
     * @return float|null Valor em reais ou null
     */
    public static function convert_price($default, $coins, $mode, $platform) {
        if (!class_exists('FC_Card_Price_Converter')) {
            return null;
        }
        
        return FC_Card_Price_Converter::convert_to_brl($coins, $mode, $platform);
    }
    
    /**
     * Formata coins para padrão EA FC
     * Filtro: apply_filters('fc_card_format_coins', $coins)
     *
     * @param int|string $coins Quantidade de coins
     * @return string Preço formatado (ex: 35.1k)
     */
    public static function format_coins($coins) {
        if (!class_exists('FC_Card_Price_Converter')) {
            return strval($coins);
        }
        
        return FC_Card_Price_Converter::format_coins_ea_fc($coins);
    }
    
    /**
     * Obtém configuração de preços
     * Filtro: apply_filters('fc_card_get_config', null, $mode, $platform)
     *
     * @param mixed $default Valor padrão (ignorado)
     * @param string $mode Modo (lance ou sniper)
     * @param string $platform Plataforma (ps, xbox, pc)
     * @return array|null Configuração ou null
     */
    public static function get_price_config($default, $mode, $platform) {
        if (!class_exists('FC_Card_Price_Converter')) {
            return null;
        }
        
        return FC_Card_Price_Converter::get_config($mode, $platform);
    }
    
    /**
     * Log de normalização (para debug)
     *
     * @param array $normalized Dados normalizados
     * @param array $raw Dados brutos
     */
    public static function log_normalization($normalized, $raw) {
        // Apenas em modo debug
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        // Log opcional
        error_log('FC Card Renderer: Dados normalizados para jogador ID ' . 
                  ($normalized['id'] ?? 'desconhecido'));
        
        // Se houver chaves não mapeadas, logar
        if (!empty($normalized['meta']['unmapped_keys'])) {
            error_log('FC Card Renderer: Chaves não mapeadas encontradas: ' . 
                      implode(', ', $normalized['meta']['unmapped_keys']));
        }
        
        // Log de conversão de preços
        if (isset($normalized['prices_meta']['config_loaded'])) {
            error_log('FC Card Renderer: Conversão de preços: ' . 
                      ($normalized['prices_meta']['config_loaded'] ? 'OK' : 'Config não carregada'));
        }
    }
    
    /**
     * Adiciona script de debug no footer para visualização rápida
     * Apenas para administradores
     */
    public static function add_debug_script() {
        ?>
        <script>
        // Função global para testar normalização via console
        window.FCCardRenderer = {
            version: '<?php echo FC_CARD_RENDERER_VERSION; ?>',
            
            /**
             * Testa normalização de dados
             * Uso: FCCardRenderer.test(dadosBrutos)
             */
            test: function(rawData) {
                console.group('🎮 FC Card Renderer - Teste de Normalização');
                console.log('Dados brutos:', rawData);
                
                // Em um ambiente real, isso seria uma chamada AJAX
                // Por enquanto, apenas mostra que a API está disponível
                console.info('✅ API disponível');
                console.info('Use apply_filters("fc_card_get_player", null, dados) no PHP');
                console.groupEnd();
                
                return {
                    status: 'API disponível',
                    version: this.version,
                    message: 'Use a função apply_filters no PHP para normalizar dados'
                };
            },
            
            /**
             * Mostra campos mapeados
             */
            showMappedFields: function() {
                console.info('🗺️ Campos mapeados disponíveis no backend');
                console.info('Use FC_Card_Normalizer::get_mapped_fields() no PHP');
            },
            
            /**
             * Testa conversão de preços
             * Uso: FCCardRenderer.testPrice(35100, 'lance', 'ps')
             */
            testPrice: function(coins, mode, platform) {
                console.group('💰 FC Card Renderer - Teste de Conversão de Preços');
                console.log('Coins:', coins);
                console.log('Modo:', mode || 'lance');
                console.log('Plataforma:', platform || 'ps');
                console.info('✅ Conversor de preços disponível');
                console.info('Use apply_filters("fc_card_convert_price", null, coins, mode, platform) no PHP');
                console.info('Use apply_filters("fc_card_format_coins", coins) para formato EA FC');
                console.groupEnd();
                
                return {
                    status: 'Conversor disponível',
                    version: this.version,
                    message: 'Use os filtros no PHP para conversão'
                };
            }
        };
        
        console.info('%c🎮 FC Card Renderer carregado', 'color: #2ecc71; font-weight: bold;');
        console.info('%cVersão: ' + window.FCCardRenderer.version, 'color: #3498db;');
        console.info('%cUse FCCardRenderer.test(dados) para testar', 'color: #95a5a6;');
        console.info('%cUse FCCardRenderer.testPrice(coins, mode, platform) para testar preços', 'color: #95a5a6;');
        </script>
        <?php
    }
    
    /**
     * Obtém estatísticas do sistema
     * 
     * @return array
     */
    public static function get_system_stats() {
        $stats = array(
            'version' => FC_CARD_RENDERER_VERSION,
            'normalizer_active' => class_exists('FC_Card_Normalizer'),
            'price_converter_active' => class_exists('FC_Card_Price_Converter'),
            'mapped_fields_count' => 0,
            'default_values_count' => 0,
            'price_configs_loaded' => 0,
        );
        
        if (class_exists('FC_Card_Normalizer')) {
            $stats['mapped_fields_count'] = count(FC_Card_Normalizer::get_mapped_fields());
            $stats['default_values_count'] = count(FC_Card_Normalizer::get_default_values());
        }
        
        if (class_exists('FC_Card_Price_Converter')) {
            $configs = FC_Card_Price_Converter::get_all_configs();
            $stats['price_configs_loaded'] = count($configs);
        }
        
        return $stats;
    }
    
    /**
     * Endpoint AJAX para testar conversão (opcional, para futuro)
     * Permite testar conversões via admin-ajax.php
     */
    public static function ajax_test_conversion() {
        // Verificar permissões
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sem permissão');
            return;
        }
        
        $coins = isset($_POST['coins']) ? intval($_POST['coins']) : 0;
        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'lance';
        $platform = isset($_POST['platform']) ? sanitize_text_field($_POST['platform']) : 'ps';
        
        if (!class_exists('FC_Card_Price_Converter')) {
            wp_send_json_error('Conversor não disponível');
            return;
        }
        
        $formatted = FC_Card_Price_Converter::format_coins_ea_fc($coins);
        $brl = FC_Card_Price_Converter::convert_to_brl($coins, $mode, $platform);
        $config = FC_Card_Price_Converter::get_config($mode, $platform);
        
        wp_send_json_success(array(
            'coins' => $coins,
            'formatted' => $formatted,
            'brl' => $brl,
            'mode' => $mode,
            'platform' => $platform,
            'config' => $config,
        ));
    }
}

// Registrar endpoint AJAX (opcional)
add_action('wp_ajax_fc_card_test_conversion', array('FC_Card_Renderer_API', 'ajax_test_conversion'));