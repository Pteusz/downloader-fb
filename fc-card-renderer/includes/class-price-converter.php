<?php
/**
 * Classe responsável por converter e normalizar preços de jogadores
 * - Normaliza formato de coins (35,100 → 35100)
 * - Formata para padrão EA FC (35100 → 35.1k)
 * - Converte coins para reais baseado em wp_cs_config
 *
 * @package FCCardRenderer
 * @version 1.0.0
 */

if (!defined('WPINC')) {
    die;
}

class FC_Card_Price_Converter {
    
    /**
     * Cache de configurações carregadas do banco
     * @var array
     */
    private static $config_cache = array();
    
    /**
     * Flag para verificar se cache foi inicializado
     * @var bool
     */
    private static $cache_initialized = false;
    
    /**
     * Plataformas disponíveis
     * @var array
     */
    private static $platforms = array('ps', 'xbox', 'pc');
    
    /**
     * Modos disponíveis
     * @var array
     */
    private static $modes = array('lance', 'sniper');
    
    /**
     * Inicializa o cache de configurações
     * Carrega todas as configs de uma vez para otimizar
     */
    private static function init_cache() {
        if (self::$cache_initialized) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cs_config';
        
        // Verificar se tabela existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
        
        if (!$table_exists) {
            self::$cache_initialized = true;
            return;
        }
        
        // Carregar todas as configurações de uma vez
        $results = $wpdb->get_results(
            "SELECT mode, platform, price_100k FROM {$table}",
            ARRAY_A
        );
        
        if ($results) {
            foreach ($results as $row) {
                $key = $row['mode'] . '_' . $row['platform'];
                self::$config_cache[$key] = array(
                    'mode' => $row['mode'],
                    'platform' => $row['platform'],
                    'price_100k' => floatval($row['price_100k']),
                );
            }
        }
        
        self::$cache_initialized = true;
    }
    
    /**
     * Obtém configuração específica do banco de dados
     *
     * @param string $mode Modo (lance ou sniper)
     * @param string $platform Plataforma (ps, xbox, pc)
     * @return array|null Configuração ou null se não encontrada
     */
    public static function get_config($mode, $platform) {
        self::init_cache();
        
        $key = $mode . '_' . $platform;
        return isset(self::$config_cache[$key]) ? self::$config_cache[$key] : null;
    }
    
    /**
     * Obtém todas as configurações disponíveis
     *
     * @return array Array associativo com todas as configs
     */
    public static function get_all_configs() {
        self::init_cache();
        return self::$config_cache;
    }
    
    /**
     * Normaliza string de preço para número inteiro
     * Exemplos: "35,100" → 35100, "1.5kk" → 1500000, "500k" → 500000
     *
     * @param string|int|float $price_string String de preço
     * @return int|null Valor em coins ou null se inválido
     */
    public static function normalize_price($price_string) {
        if (is_null($price_string) || $price_string === '') {
            return null;
        }
        
        // Se já for número, retornar
        if (is_numeric($price_string)) {
            return intval($price_string);
        }
        
        $price_string = trim(strtolower($price_string));
        
        // Detectar formato kk (milhões)
        if (strpos($price_string, 'kk') !== false) {
            $number = floatval(preg_replace('/[^0-9.]/', '', $price_string));
            return intval($number * 1000000);
        }
        
        // Detectar formato k (milhares)
        if (strpos($price_string, 'k') !== false) {
            $number = floatval(preg_replace('/[^0-9.]/', '', $price_string));
            return intval($number * 1000);
        }
        
        // Formato com vírgulas/pontos (35,100 ou 35.100)
        $cleaned = preg_replace('/[^0-9]/', '', $price_string);
        
        if ($cleaned === '') {
            return null;
        }
        
        return intval($cleaned);
    }
    
    /**
     * Formata coins para o padrão EA FC
     * Exemplos: 35100 → "35.1k", 1500000 → "1.5kk", 500 → "500"
     *
     * @param int|string $coins Quantidade de coins
     * @return string Preço formatado
     */
    public static function format_coins_ea_fc($coins) {
        $coins = self::normalize_price($coins);
        
        if ($coins === null || $coins === 0) {
            return '0';
        }
        
        // 1 milhão ou mais: usar kk
        if ($coins >= 1000000) {
            $millions = $coins / 1000000;
            
            // Se for número inteiro, não mostrar decimais
            if ($millions == floor($millions)) {
                return intval($millions) . 'kk';
            }
            
            // Arredondar para 1 casa decimal
            $rounded = round($millions, 1);
            
            // Se após arredondar virar inteiro, não mostrar decimal
            if ($rounded == floor($rounded)) {
                return intval($rounded) . 'kk';
            }
            
            return number_format($rounded, 1, '.', '') . 'kk';
        }
        
        // 1000 ou mais: usar k
        if ($coins >= 1000) {
            $thousands = $coins / 1000;
            
            // Se for número inteiro, não mostrar decimais
            if ($thousands == floor($thousands)) {
                return intval($thousands) . 'k';
            }
            
            // Arredondar para 1 casa decimal
            $rounded = round($thousands, 1);
            
            // Se após arredondar virar inteiro, não mostrar decimal
            if ($rounded == floor($rounded)) {
                return intval($rounded) . 'k';
            }
            
            return number_format($rounded, 1, '.', '') . 'k';
        }
        
        // Menos de 1000: retornar direto
        return strval($coins);
    }
    
    /**
     * Converte coins para reais baseado na configuração
     *
     * @param int|string $coins Quantidade de coins
     * @param string $mode Modo (lance ou sniper)
     * @param string $platform Plataforma (ps, xbox, pc)
     * @return float|null Valor em reais ou null se config não encontrada
     */
    public static function convert_to_brl($coins, $mode, $platform) {
        $coins = self::normalize_price($coins);
        
        if ($coins === null || $coins === 0) {
            return 0.0;
        }
        
        $config = self::get_config($mode, $platform);
        
        if (!$config || !isset($config['price_100k'])) {
            return null;
        }
        
        $price_100k = floatval($config['price_100k']);
        
        // Fórmula: (coins / 100000) * price_100k
        $brl = ($coins / 100000) * $price_100k;
        
        // Arredondar para 2 casas decimais
        return round($brl, 2);
    }
    
    /**
     * Processa array de preços e retorna versão completa
     * com formato EA FC e conversões para reais
     *
     * @param array $prices Array de preços (console, pc, estimated)
     * @param string|null $default_mode Modo padrão (opcional)
     * @param string|null $default_platform Plataforma padrão (opcional)
     * @return array Array processado com formatted e brl
     */
    public static function process_prices($prices, $default_mode = null, $default_platform = null) {
        if (empty($prices) || !is_array($prices)) {
            return array(
                'original' => array(),
                'formatted' => array(),
                'brl' => array(),
                'meta' => array(
                    'config_loaded' => false,
                    'available_platforms' => self::$platforms,
                    'available_modes' => self::$modes,
                )
            );
        }
        
        $result = array(
            'original' => $prices,
            'formatted' => array(),
            'brl' => array(),
            'meta' => array(
                'config_loaded' => !empty(self::$config_cache),
                'available_platforms' => self::$platforms,
                'available_modes' => self::$modes,
                'conversion_timestamp' => current_time('mysql'),
                'default_mode' => $default_mode,
                'default_platform' => $default_platform,
            )
        );
        
        // Processar cada tipo de preço
        foreach ($prices as $price_type => $price_value) {
            if ($price_value === null || $price_value === '') {
                continue;
            }
            
            $normalized_coins = self::normalize_price($price_value);
            
            if ($normalized_coins === null) {
                continue;
            }
            
            // Formato EA FC
            $result['formatted'][$price_type] = self::format_coins_ea_fc($normalized_coins);
            
            // Conversões para reais (todas as combinações modo/plataforma)
            $result['brl'][$price_type] = array();
            
            foreach (self::$modes as $mode) {
                $result['brl'][$price_type][$mode] = array();
                
                foreach (self::$platforms as $platform) {
                    $brl_value = self::convert_to_brl($normalized_coins, $mode, $platform);
                    $result['brl'][$price_type][$mode][$platform] = $brl_value;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Obtém preço específico em formato específico
     * Atalho para uso simplificado
     *
     * @param array $prices Array de preços processados
     * @param string $type Tipo de preço (console, pc, estimated)
     * @param string $format Formato desejado (original, formatted, brl)
     * @param string|null $mode Modo para BRL (lance/sniper)
     * @param string|null $platform Plataforma para BRL (ps/xbox/pc)
     * @return mixed Valor solicitado ou null
     */
    public static function get_price($prices, $type, $format = 'formatted', $mode = null, $platform = null) {
        if (empty($prices) || !isset($prices[$format])) {
            return null;
        }
        
        if (!isset($prices[$format][$type])) {
            return null;
        }
        
        $value = $prices[$format][$type];
        
        // Se for BRL e modo/plataforma especificados
        if ($format === 'brl' && $mode !== null && $platform !== null) {
            return isset($value[$mode][$platform]) ? $value[$mode][$platform] : null;
        }
        
        return $value;
    }
    
   /**
 * Retorna melhor preço disponível
 * PRIORIDADE: console > pc > estimated
 *
 * @param array $prices Array de preços processados
 * @param string $format Formato (formatted ou brl)
 * @param string|null $mode Modo para BRL
 * @param string|null $platform Plataforma para BRL
 * @return array Array com tipo e valor do melhor preço
 */
public static function get_best_price($prices, $format = 'formatted', $mode = null, $platform = null) {
    if (empty($prices) || !isset($prices['original'])) {
        return null;
    }
    
    // ✅ PRIORIDADE: sempre console (PS) primeiro
    $priority_order = array('console', 'pc', 'estimated');
    
    $best_type = null;
    $best_value = null;
    
    // Percorre na ordem de prioridade
    foreach ($priority_order as $type) {
        if (!isset($prices['original'][$type])) {
            continue;
        }
        
        $value = $prices['original'][$type];
        $normalized = self::normalize_price($value);
        
        // Primeiro válido encontrado é o "melhor"
        if ($normalized !== null && $normalized > 0) {
            $best_type = $type;
            $best_value = $normalized;
            break; // ✅ Para no primeiro válido (console)
        }
    }
    
    if ($best_type === null) {
        return null;
    }
    
    $formatted_value = self::get_price($prices, $best_type, $format, $mode, $platform);
    
    return array(
        'type' => $best_type,
        'value' => $formatted_value,
        'coins' => $best_value,
    );
}
    
    /**
     * Limpa cache de configurações
     * Útil para forçar reload após mudanças no banco
     */
    public static function clear_cache() {
        self::$config_cache = array();
        self::$cache_initialized = false;
    }
    
    /**
     * Verifica se uma plataforma é válida
     *
     * @param string $platform
     * @return bool
     */
    public static function is_valid_platform($platform) {
        return in_array($platform, self::$platforms);
    }
    
    /**
     * Verifica se um modo é válido
     *
     * @param string $mode
     * @return bool
     */
    public static function is_valid_mode($mode) {
        return in_array($mode, self::$modes);
    }
}