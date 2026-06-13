<?php
/**
 * Classe de Renderização de Cards
 * Sistema de 3 camadas para montar cards visuais de jogadores
 * V3.4 - Hexágono horizontal para extra_info + futbin_rating
 *
 * @package FCCardRenderer
 */

if (!defined('WPINC')) {
    die;
}

class FC_Card_Visual_Renderer {

    public static function render_card($normalized, $options = array()) {
        $defaults = array(
            'width' => 300,
            'show_playstyles' => true,
            'show_extra_info' => true,
            'responsive' => true,
        );

        $options = array_merge($defaults, $options);

        ob_start();

        $card_id = 'fc-card-' . uniqid();
        ?>
        <div class="fc-player-card"
             id="<?php echo esc_attr($card_id); ?>"
             data-player-id="<?php echo esc_attr($normalized['id'] ?? ''); ?>"
             style="width: <?php echo esc_attr($options['width']); ?>px; <?php echo $options['responsive'] ? 'max-width: 100%;' : ''; ?>">

            <?php echo self::render_layer_0_background($normalized); ?>
            <?php echo self::render_layer_1_main($normalized); ?>

            <?php if ($options['show_playstyles'] || $options['show_extra_info']): ?>
                <?php echo self::render_layer_2_extras($normalized, $options); ?>
            <?php endif; ?>
        </div>
        
        <?php
        return ob_get_clean();
        require_once FC_CARD_RENDERER_PLUGIN_DIR . 'includes/class-price-converter.php';
    }

    private static function render_layer_0_background($normalized) {
        ob_start();
        ?>
        <div class="fc-card-layer fc-card-layer-0-bg">
            <?php if (!empty($normalized['bg'])): ?>
                <img src="<?php echo esc_url($normalized['bg']); ?>"
                     alt="Card Background"
                     class="fc-card-bg-image"
                     loading="lazy">
            <?php else: ?>
                <div class="fc-card-bg-fallback"
                     style="background: linear-gradient(135deg,
                            <?php echo esc_attr($normalized['colors']['card']); ?>,
                            <?php echo esc_attr($normalized['colors']['line']); ?>);"></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_layer_1_main($normalized) {
        // Obter cor do extra_info_border para usar nos textos
        $text_color = $normalized['extra_info_css_vars']['extra-info-border'] ?? '#ffffff';
        
        ob_start();
        ?>
        <div class="fc-card-layer fc-card-layer-1-main">

            <div class="fc-card-rating-position">
                <div class="fc-card-rating"
                     style="color: <?php echo esc_attr($text_color); ?>;">
                    <?php echo esc_html($normalized['rating']); ?>
                </div>
                <div class="fc-card-position"
                     style="color: <?php echo esc_attr($text_color); ?>;">
                    <?php echo esc_html($normalized['position']); ?>
                </div>
                <?php if (!empty($normalized['role_plus'])): ?>
                    <div class="fc-card-role-plus" style="color: <?php echo esc_attr($text_color); ?>;"><?php echo esc_html($normalized['role_plus']); ?></div>
                <?php endif; ?>
            </div>

            <div class="fc-card-player-image">
                <?php if (!empty($normalized['face'])): ?>
<img src="<?php echo esc_url($normalized['face']); ?>"
     alt="<?php echo esc_attr($normalized['name']); ?>"
     class="fc-player-face"
     loading="lazy"
onload="if(this.naturalWidth && this.naturalHeight && this.naturalWidth===this.naturalHeight){var p=this.closest('.fc-card-player-image'); if(p){p.classList.add('fc-face-square');}}"
     onerror="this.style.opacity='0.3';">

                <?php endif; ?>
            </div>

            <div class="fc-card-player-name"
                 style="color: <?php echo esc_attr($text_color); ?>;">
                <?php echo esc_html($normalized['name']); ?>
            </div>

            <div class="fc-card-stats-grid">
                <?php
                $stats_order = array('PAC', 'SHO', 'PAS', 'DRI', 'DEF', 'PHY');
                foreach ($stats_order as $stat_key):
                    $stat_value = $normalized['stats'][$stat_key] ?? '0';
                ?>
                    <div class="fc-card-stat"
                         style="color: <?php echo esc_attr($text_color); ?>;">
                        <div class="fc-stat-label"><?php echo esc_html($stat_key); ?></div>
                        <div class="fc-stat-value"><?php echo esc_html($stat_value); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="fc-card-icons-footer">
                <?php /* Ordem original EA FC: liga → clube → nação (esquerda p/ direita) */ ?>
                <?php if (!empty($normalized['info']['league']['icon'])): ?>
                    <img src="<?php echo esc_url($normalized['info']['league']['icon']); ?>"
                         alt="<?php echo esc_attr($normalized['info']['league']['name']); ?>"
                         class="fc-icon fc-icon-league"
                         title="<?php echo esc_attr($normalized['info']['league']['name']); ?>">
                <?php endif; ?>

                <?php if (!empty($normalized['info']['club']['icon'])): ?>
                    <img src="<?php echo esc_url($normalized['info']['club']['icon']); ?>"
                         alt="<?php echo esc_attr($normalized['info']['club']['name']); ?>"
                         class="fc-icon fc-icon-club"
                         title="<?php echo esc_attr($normalized['info']['club']['name']); ?>">
                <?php endif; ?>

                <?php if (!empty($normalized['info']['nation']['icon'])): ?>
                    <img src="<?php echo esc_url($normalized['info']['nation']['icon']); ?>"
                         alt="<?php echo esc_attr($normalized['info']['nation']['name']); ?>"
                         class="fc-icon fc-icon-nation"
                         title="<?php echo esc_attr($normalized['info']['nation']['name']); ?>">
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_layer_2_extras($normalized, $options) {
        ob_start();
        ?>
        <div class="fc-card-layer fc-card-layer-2-extras">

            <?php if ($options['show_playstyles'] && !empty($normalized['playstyles_items'])): ?>
                <div class="fc-card-playstyles">
                    <?php foreach ($normalized['playstyles_items'] as $idx => $playstyle): ?>
                        <?php
                        $style_vars = '';
                        if (!empty($playstyle['css_vars'])) {
                            foreach ($playstyle['css_vars'] as $var_name => $var_value) {
                                $style_vars .= '--' . $var_name . ': ' . $var_value . '; ';
                            }
                        }
                        ?>
                        <div class="fc-playstyle-item"
                             data-class="<?php echo esc_attr($playstyle['class'] ?? ''); ?>"
                             style="<?php echo esc_attr($style_vars); ?>"
                             title="<?php echo esc_attr($playstyle['class'] ?? 'Playstyle'); ?>">
                            <?php if (!empty($playstyle['svg_raw'])): ?>
                                <div class="fc-playstyle-svg">
                                    <?php echo $playstyle['svg_raw']; ?>
                                </div>
                            <?php else: ?>
                                <div class="fc-playstyle-placeholder"></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($options['show_extra_info']): ?>
                <?php
                // Construir array de items para o extra_info
                $extra_items = array();
                
                // Guardar SVG do pé para usar no weak_foot (último item)
                $foot_svg = '';
                if (!empty($normalized['extra_info_foot_svg']['svg_raw'])) {
                    $foot_svg = $normalized['extra_info_foot_svg']['svg_raw'];
                }

                // 1º item: foot (R ou L) - TEXTO SIMPLES, sem SVG
                if ($normalized['foot'] !== null) {
                    $extra_items[] = array(
                        'type' => 'foot',
                        'value' => $normalized['foot'],
                        'svg' => '', // SEM SVG aqui
                    );
                }

                // 2º item: skill_moves - número com estrela
                if ($normalized['skill_moves'] !== null) {
                    $extra_items[] = array(
                        'type' => 'skill',
                        'value' => $normalized['skill_moves'],
                        'svg' => '',
                    );
                }

                // 3º item: weak_foot - número com SVG do pé
                if ($normalized['weak_foot'] !== null) {
                    $extra_items[] = array(
                        'type' => 'weak_foot',
                        'value' => $normalized['weak_foot'],
                        'svg' => $foot_svg, // SVG DO PÉ VAI AQUI
                    );
                }

                // Adicionar futbin_rating se existir
                $futbin_rating = null;
                if (!empty($normalized['futbin_rating'])) {
                    $futbin_rating = $normalized['futbin_rating'];
                }

                // Obter CSS vars para cores
                $css_vars = $normalized['extra_info_css_vars'] ?? array();

                // Gerar SVG do hexágono horizontal com todos os items
                if (!empty($extra_items) || $futbin_rating !== null) {
                    $svg = self::generate_extra_info_hex_svg($extra_items, $futbin_rating, $css_vars);
                    ?>
                    <div class="fc-card-extra-info">
                        <?php echo $svg; ?>
                    </div>
                    <?php
                }
                ?>
            <?php endif; ?>

            <?php echo self::render_alt_positions_hexagon($normalized); ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Gera SVG do hexágono horizontal para extra_info
     * 
     * @param array $items Array com foot, skill_moves, weak_foot
     * @param string|null $futbin_rating Rating do Futbin (opcional)
     * @param array $css_vars CSS vars com cores (extra-info-bg e extra-info-border)
     * @return string SVG do hexágono horizontal
     */
    private static function generate_extra_info_hex_svg($items, $futbin_rating, $css_vars) {
        $bg_color = $css_vars['extra-info-bg'] ?? '#6b4d29';
        $border_color = $css_vars['extra-info-border'] ?? '#ffffff';
        
        // Calcular dimensões baseado no número de items
        $num_items = count($items);
        $has_rating = ($futbin_rating !== null);
        
        // Dimensões base
        $height = 24;
        $item_width = 28;
        $rating_width = 32; // Um pouco mais largo para o rating
        $tip_width = 8; // Largura das pontas do hexágono
        
        // Calcular largura total
        $content_width = ($num_items * $item_width);
        if ($has_rating) {
            $content_width += $rating_width + 6; // +6 para o divider
        }
        $total_width = $content_width + ($tip_width * 2);
        
        $border = 1.5;
        
        $style = "--extra-info-bg: {$bg_color}; --extra-info-border: {$border_color};";
        
        // Pontos do hexágono horizontal (esticado para os lados)
        $half_height = $height / 2;
        
        $outer_points = array(
            array($tip_width, 0),                              // Topo esquerdo
            array($total_width - $tip_width, 0),              // Topo direito
            array($total_width, $half_height),                // Ponta direita
            array($total_width - $tip_width, $height),        // Base direita
            array($tip_width, $height),                       // Base esquerda
            array(0, $half_height),                           // Ponta esquerda
        );
        
        // Inset para criar borda
        $inner_points = self::inset_convex_polygon($outer_points, $border);
        
        // Converter pontos em paths
        $outer_path = self::path_from_points($outer_points);
        $inner_path = self::path_from_points($inner_points);
        
        // Começar SVG
        $svg = '<svg viewBox="0 0 ' . $total_width . ' ' . $height . '" class="fc-hex-extra-info-svg" style="' . esc_attr($style) . '" shape-rendering="geometricPrecision">';
        
        // Borda (externo)
        $svg .= '<path class="hex-border" d="' . esc_attr($outer_path) . '" fill="var(--extra-info-border)" />';
        
        // Miolo (interno)
        $svg .= '<path class="hex-fill" d="' . esc_attr($inner_path) . '" fill="var(--extra-info-bg)" />';
        
        // Se tem rating, adicionar fundo colorido na área do rating
        if ($has_rating) {
            $rating_start_x = $tip_width + ($num_items * $item_width) + 3; // Onde começa o V
            $rating_bg_color = '#55CCA2'; // Cor do fundo do rating
            
            // Calcular pontos da área colorida respeitando o border interno
            // A área vai do V até a ponta direita, seguindo a forma do hexágono interno
            $v_tip_x = $rating_start_x + 5; // Fim do V
            
            // Pontos da área colorida (seguindo o hexágono interno)
            $rating_area_path = 'M ' . self::f($rating_start_x) . ',' . self::f($half_height); // Ponta do V (centro)
            $rating_area_path .= ' L ' . self::f($v_tip_x) . ',' . self::f($border + 1); // V superior (respeitando border)
            $rating_area_path .= ' L ' . self::f($total_width - $tip_width - $border) . ',' . self::f($border + 1); // Linha superior interna
            $rating_area_path .= ' L ' . self::f($total_width - $border - 1) . ',' . self::f($half_height); // Ponta direita interna
            $rating_area_path .= ' L ' . self::f($total_width - $tip_width - $border) . ',' . self::f($height - $border - 1); // Linha inferior interna
            $rating_area_path .= ' L ' . self::f($v_tip_x) . ',' . self::f($height - $border - 1); // V inferior (respeitando border)
            $rating_area_path .= ' Z'; // Fechar path
            
            $svg .= '<path class="rating-bg" d="' . esc_attr($rating_area_path) . '" fill="' . esc_attr($rating_bg_color) . '" />';
        }
        
        // Conteúdo
        $svg .= '<g class="hex-content" shape-rendering="geometricPrecision">';
        
        // Adicionar linhas divisórias entre items
        $x_position = $tip_width + ($item_width / 2);
        
        foreach ($items as $index => $item) {
            // Adicionar linha divisória DEPOIS do item
            // EXCETO: no último item se tiver rating (não colocar linha antes do V)
            $is_last_item = ($index === count($items) - 1);
            $should_add_divider = !$is_last_item || !$has_rating;
            
            if ($should_add_divider && $index < count($items) - 1) {
                $divider_x = $x_position + ($item_width / 2);
                $svg .= '<line x1="' . self::f($divider_x) . '" y1="' . self::f($border + 2) . '" ';
                $svg .= 'x2="' . self::f($divider_x) . '" y2="' . self::f($height - $border - 2) . '" ';
                $svg .= 'class="hex-divider" stroke="var(--extra-info-border)" stroke-width="1" opacity="0.4" />';
            }
            
            $x_position += $item_width;
        }
        
        // Se tem rating, adicionar divider especial (V de lado apontando para ESQUERDA)
        if ($has_rating) {
            $rating_divider_x = $tip_width + ($num_items * $item_width) + 3;
            
            // Criar "V" de lado apontando para ESQUERDA: <
            $v_tip_width = 5;  // Largura da ponta
            $v_center_y = $height / 2;
            
            // Duas linhas formando o V de lado: <
            // Linha superior (de ponta para cima-direita)
            $svg .= '<line x1="' . self::f($rating_divider_x) . '" y1="' . self::f($v_center_y) . '" ';
            $svg .= 'x2="' . self::f($rating_divider_x + $v_tip_width) . '" y2="' . self::f(3) . '" ';
            $svg .= 'stroke="var(--extra-info-border)" stroke-width="1.2" stroke-linecap="round" opacity="0.6" />';
            
            // Linha inferior (de ponta para baixo-direita)
            $svg .= '<line x1="' . self::f($rating_divider_x) . '" y1="' . self::f($v_center_y) . '" ';
            $svg .= 'x2="' . self::f($rating_divider_x + $v_tip_width) . '" y2="' . self::f($height - 3) . '" ';
            $svg .= 'stroke="var(--extra-info-border)" stroke-width="1.2" stroke-linecap="round" opacity="0.6" />';
        }
        
        // Adicionar textos dos items
        $x_position = $tip_width + ($item_width / 2);
        $text_y = $half_height;
        
        foreach ($items as $index => $item) {
            $value = $item['value'];
            
            // Identificar qual é o tipo de item
            $is_foot = ($item['type'] === 'foot');
            $is_skill = ($item['type'] === 'skill');
            $is_weak_foot = ($item['type'] === 'weak_foot');
            
            // ORDEM CORRETA:
            // 1º: foot (R ou L) - TEXTO SIMPLES, sem SVG, sem estrela
            // 2º: skill_moves - número com estrela
            // 3º: weak_foot - número COM SVG do pé AO LADO DIREITO
            
            if ($is_foot) {
                // Primeiro item: apenas R ou L (texto simples)
                $svg .= '<text x="' . self::f($x_position) . '" y="' . self::f($text_y + 1) . '" ';
                $svg .= 'class="hex-extra-text" text-anchor="middle" dominant-baseline="middle" ';
                $svg .= 'fill="var(--extra-info-border)" font-family="Montserrat,Arial,sans-serif" font-size="11" font-weight="700">';
                $svg .= esc_html($value);
                $svg .= '</text>';
            } elseif ($is_skill) {
                // Segundo item: número com estrela
                $svg .= '<text x="' . self::f($x_position) . '" y="' . self::f($text_y + 1) . '" ';
                $svg .= 'class="hex-extra-text" text-anchor="middle" dominant-baseline="middle" ';
                $svg .= 'fill="var(--extra-info-border)" font-family="Montserrat,Arial,sans-serif" font-size="11" font-weight="700">';
                $svg .= esc_html($value . '★');
                $svg .= '</text>';
            } elseif ($is_weak_foot) {
                // Terceiro item: número COM SVG do pé AO LADO
                // Posicionar número um pouco à esquerda
                $number_x = $x_position - 4;
                
                $svg .= '<text x="' . self::f($number_x) . '" y="' . self::f($text_y + 1) . '" ';
                $svg .= 'class="hex-extra-text" text-anchor="middle" dominant-baseline="middle" ';
                $svg .= 'fill="var(--extra-info-border)" font-family="Montserrat,Arial,sans-serif" font-size="11" font-weight="700">';
                $svg .= esc_html($value);
                $svg .= '</text>';
                
                // SVG do pé AO LADO DIREITO do número
                if (!empty($item['svg'])) {
                    $foot_x = $x_position + 4; // Posição à direita do número
                    $foot_y = $text_y - 6; // Centralizado verticalmente
                    
                    $foot_svg_modified = str_replace(
                        '<svg',
                        '<svg x="' . $foot_x . '" y="' . $foot_y . '" width="12" height="12"',
                        $item['svg']
                    );
                    $svg .= $foot_svg_modified;
                }
            }
            
            $x_position += $item_width;
        }
        
        // Se tem rating, adicionar ele
        if ($has_rating) {
            $rating_x = $tip_width + ($num_items * $item_width) + ($rating_width / 2) + 6;
            
            // Usar cor escura para o texto do rating (contraste com fundo verde)
            $rating_text_color = 'var(--extra-info-bg)'; // Usar a cor de fundo como cor do texto
            
            $svg .= '<text x="' . self::f($rating_x) . '" y="' . self::f($text_y + 1) . '" ';
            $svg .= 'class="hex-extra-text hex-rating-text" text-anchor="middle" dominant-baseline="middle" ';
            $svg .= 'fill="' . $rating_text_color . '" font-family="Montserrat,Arial,sans-serif" font-size="11" font-weight="700">';
            $svg .= esc_html($futbin_rating);
            $svg .= '</text>';
        }
        
        $svg .= '</g>';
        $svg .= '</svg>';
        
        return $svg;
    }

    private static function render_alt_positions_hexagon($normalized) {
        $positions = array();
        $css_vars = array();

        if (!empty($normalized['alt_sidebar_full']['right']['positions'])) {
            $positions = $normalized['alt_sidebar_full']['right']['positions'];
            $css_vars = $normalized['alt_sidebar_full']['right']['css_vars'] ?? array();
        } elseif (!empty($normalized['alt_sidebar_positions'])) {
            $positions = $normalized['alt_sidebar_positions'];
            $css_vars = $normalized['alt_sidebar_css_vars'] ?? array();
        }

        if (empty($positions)) {
            return '';
        }

        // Fallback: se o alt_sidebar não tiver as chaves de cor esperadas,
        // usa o extra_info_css_vars — ambos hexágonos devem ter a mesma cor do card
        $has_alt_colors = !empty($css_vars['alt-pos-background']) || !empty($css_vars['extra-info-bg']);
        if (!$has_alt_colors) {
            $css_vars = $normalized['extra_info_css_vars'] ?? array();
        }

        $svg = self::generate_hexagon_svg($positions, $css_vars);

        ob_start();
        ?>
        <div class="fc-card-alt-positions-hex">
            <?php echo $svg; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Gera SVG do hexágono vertical com borda perfeita e linhas divisórias
     */
    private static function generate_hexagon_svg($positions, $css_vars) {
        $num_positions = count($positions);

        $width = 60;
        $base_height = 50;
        $spacing_per_pos = 28;
        $total_height = $base_height + (($num_positions - 1) * $spacing_per_pos);

        // hex "vertical regular" (slants ~30°)
        $tip_height = $width * 0.289;

        // Aceita tanto 'alt-pos-*' (chaves do renderer) quanto 'extra-info-*' (chaves do scraper)
        $bg_color     = $css_vars['alt-pos-background'] ?? $css_vars['extra-info-bg']     ?? '#6b4d29';
        $border_color = $css_vars['alt-pos-border']     ?? $css_vars['extra-info-border'] ?? '#ffffff';

        // espessura real da borda (em unidades do viewBox)
        $border = 2.5;

        $style = "--alt-pos-background: {$bg_color}; --alt-pos-border: {$border_color};";

        // Pontos do hex externo
        $outer_points = self::hex_points($width, $total_height, $tip_height);

        // Pontos do hex interno (inset matemático por offset de linhas + interseção)
        $inner_points = self::inset_convex_polygon($outer_points, $border);

        // Paths
        $outer_path = self::path_from_points($outer_points);
        $inner_path = self::path_from_points($inner_points);

        $svg = '<svg viewBox="0 0 ' . $width . ' ' . $total_height . '" class="fc-hex-alt-pos-svg" style="' . esc_attr($style) . '" shape-rendering="geometricPrecision">';

        // Borda (externo)
        $svg .= '<path class="hex-border" d="' . esc_attr($outer_path) . '" fill="var(--alt-pos-border)" />';

        // Miolo (interno)
        $svg .= '<path class="hex-fill" d="' . esc_attr($inner_path) . '" fill="var(--alt-pos-background)" />';

        // Conteúdo
        $svg .= '<g class="hex-content" shape-rendering="geometricPrecision">';

        $text_area_start = $tip_height;
        $text_area_end   = $total_height - $tip_height;
        $text_area_height = $text_area_end - $text_area_start;

        // Adicionar linhas divisórias entre as posições
        if ($num_positions > 1) {
            $svg .= '<g class="hex-divider-lines">';
            
            for ($i = 1; $i < $num_positions; $i++) {
                // Posição Y da linha divisória (entre item i-1 e item i)
                $line_y = $text_area_start + ($text_area_height / $num_positions) * $i;
                
                // Calcular os limites X nas bordas do hexágono nesta altura Y
                $x_limits = self::calculate_hex_x_bounds($width, $total_height, $tip_height, $line_y, $border);
                
                // Adicionar margem interna para a linha não tocar as bordas
                $line_margin = 4;
                $x1 = $x_limits['left'] + $line_margin;
                $x2 = $x_limits['right'] - $line_margin;
                
                $svg .= '<line x1="' . self::f($x1) . '" y1="' . self::f($line_y) . '" ';
                $svg .= 'x2="' . self::f($x2) . '" y2="' . self::f($line_y) . '" ';
                $svg .= 'class="hex-divider" stroke="var(--alt-pos-border)" stroke-width="1.5" opacity="0.4" />';
            }
            
            $svg .= '</g>';
        }

        // Textos das posições
        foreach ($positions as $index => $pos) {
            $text_y = $text_area_start + ($text_area_height / $num_positions) * ($index + 0.5);

            $pos_text = $pos['pos'] ?? '';
            $plus_plus = (!empty($pos['plus_plus'])) ? '++' : '';

            $svg .= '<text x="' . ($width / 2) . '" y="' . round($text_y, 2) . '" class="hex-pos-text" text-anchor="middle" dominant-baseline="middle">';
            $svg .= esc_html($pos_text);
            $svg .= '</text>';

            if ($plus_plus) {
                $svg .= '<text x="' . ($width / 2) . '" y="' . round($text_y + 10, 2) . '" class="hex-pos-plus" text-anchor="middle" dominant-baseline="middle">';
                $svg .= esc_html($plus_plus);
                $svg .= '</text>';
            }
        }

        $svg .= '</g>';
        $svg .= '</svg>';

        return $svg;
    }

    /**
     * Calcula os limites X (esquerda e direita) do hexágono em uma determinada altura Y
     */
    private static function calculate_hex_x_bounds($width, $total_height, $tip_height, $y, $border_inset = 0) {
        $half_width = $width / 2;
        
        // Área de texto útil
        $text_start = $tip_height;
        $text_end = $total_height - $tip_height;
        
        // Se estiver na área reta do meio, a largura é constante
        if ($y >= $text_start && $y <= $text_end) {
            return array(
                'left' => 0 + $border_inset,
                'right' => $width - $border_inset
            );
        }
        
        // Se estiver nas pontas (não deveria acontecer para linhas divisórias)
        // mas vamos tratar por segurança
        if ($y < $text_start) {
            // Parte superior inclinada
            $ratio = $y / $tip_height;
            $current_half_width = $half_width * $ratio;
            return array(
                'left' => $half_width - $current_half_width + $border_inset,
                'right' => $half_width + $current_half_width - $border_inset
            );
        } else {
            // Parte inferior inclinada
            $ratio = ($total_height - $y) / $tip_height;
            $current_half_width = $half_width * $ratio;
            return array(
                'left' => $half_width - $current_half_width + $border_inset,
                'right' => $half_width + $current_half_width - $border_inset
            );
        }
    }

    /**
     * Pontos do hex vertical externo
     */
    private static function hex_points($width, $height, $tip_height) {
        $half_width = $width / 2;
        return array(
            array($half_width, 0),
            array($width, $tip_height),
            array($width, $height - $tip_height),
            array($half_width, $height),
            array(0, $height - $tip_height),
            array(0, $tip_height),
        );
    }

    /**
     * Converte array de pontos em path SVG (linhas retas)
     */
    private static function path_from_points($points) {
        if (empty($points)) return '';
        $p0 = $points[0];
        $path = 'M ' . self::f($p0[0]) . ',' . self::f($p0[1]);
        $n = count($points);
        for ($i = 1; $i < $n; $i++) {
            $p = $points[$i];
            $path .= ' L ' . self::f($p[0]) . ',' . self::f($p[1]);
        }
        $path .= ' Z';
        return $path;
    }

    /**
     * Inset (offset interno) exato para polígono convexo
     */
    private static function inset_convex_polygon($points, $inset) {
        $n = count($points);
        if ($n < 3) return $points;

        // "centro" (suficiente para escolher normal interna em polígono convexo)
        $cx = 0; $cy = 0;
        foreach ($points as $p) { $cx += $p[0]; $cy += $p[1]; }
        $cx /= $n; $cy /= $n;

        // linhas offset (a x + b y = c)
        $lines = array();
        for ($i = 0; $i < $n; $i++) {
            $p1 = $points[$i];
            $p2 = $points[($i + 1) % $n];

            $dx = $p2[0] - $p1[0];
            $dy = $p2[1] - $p1[1];

            // Normais candidatas
            $n1x =  $dy;  $n1y = -$dx;
            $n2x = -$dy;  $n2y =  $dx;

            // midpoint da aresta
            $mx = ($p1[0] + $p2[0]) / 2.0;
            $my = ($p1[1] + $p2[1]) / 2.0;

            // vetor para o "centro"
            $vcx = $cx - $mx;
            $vcy = $cy - $my;

            // escolhe normal que aponta para dentro (dot > 0)
            $dot1 = $n1x * $vcx + $n1y * $vcy;
            $dot2 = $n2x * $vcx + $n2y * $vcy;

            $ax = $n1x; $by = $n1y;
            if ($dot2 > $dot1) { $ax = $n2x; $by = $n2y; }

            // normaliza
            $len = sqrt($ax*$ax + $by*$by);
            if ($len == 0) $len = 1;
            $ax /= $len;
            $by /= $len;

            // linha original: ax*x + by*y = c
            $c = $ax * $p1[0] + $by * $p1[1];

            // desloca para dentro por inset => c' = c + inset
            $c2 = $c + $inset;

            $lines[] = array($ax, $by, $c2);
        }

        // interseção de linhas adjacentes
        $new_points = array();
        for ($i = 0; $i < $n; $i++) {
            $l_prev = $lines[($i - 1 + $n) % $n];
            $l_curr = $lines[$i];

            $x = 0; $y = 0;
            $ok = self::intersect_lines($l_prev, $l_curr, $x, $y);
            if (!$ok) {
                $new_points[] = $points[$i];
            } else {
                $new_points[] = array($x, $y);
            }
        }

        return $new_points;
    }

    /**
     * Interseção de 2 linhas: a1 x + b1 y = c1 e a2 x + b2 y = c2
     */
    private static function intersect_lines($l1, $l2, &$outX, &$outY) {
        $a1 = $l1[0]; $b1 = $l1[1]; $c1 = $l1[2];
        $a2 = $l2[0]; $b2 = $l2[1]; $c2 = $l2[2];

        $det = $a1*$b2 - $a2*$b1;
        if (abs($det) < 1e-9) return false;

        $outX = ($c1*$b2 - $c2*$b1) / $det;
        $outY = ($a1*$c2 - $a2*$c1) / $det;
        return true;
    }

    /**
     * Formata float curto (evita strings enormes no d="")
     */
    private static function f($v) {
        return rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
    }

    public static function get_card_css() {
        ob_start();
        ?>
        <style>
        
/* Forçar layout apenas no mobile */
@media (max-width: 768px) {

    .fc-card-layer.fc-card-layer-0-bg {
        display: flex;
        justify-content: center;
    }

.fc-card-stats-grid{gap:3%}
}

        .fc-player-card {
            position: relative;
            aspect-ratio: 2 / 3;
            border-radius: 12px;
            overflow: hidden;
            font-family: 'Montserrat', Arial, sans-serif;
            font-size: 16px;
            margin: 0 auto;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .fc-player-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.6);
        }

        .fc-card-layer {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }

        .fc-card-layer-0-bg { z-index: 0; }

        .fc-card-bg-image {
            aspect-ratio: auto 252 / 350;
            width: 100%;
            height: calc(var(--cardWidthPx) * 1.38888889);
            max-height: calc(var(--cardWidthPx) calc(252 * 1px) * 1.38888889);
        }

        .fc-card-bg-fallback { width: 100%; height: 100%; }

        .fc-card-layer-1-main { z-index: 1; }

        .fc-card-rating-position {
            position: absolute;
            top: 20%;
            left: 17%;
            line-height:85%;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            z-index: 2;
        }

        .fc-card-rating {
            font-size: 1.7em;
            font-weight:800;
            line-height: 1;
            text-shadow: 2px 2px 6px rgba(0, 0, 0, 0.38);
            letter-spacing: -1px;
        }

        .fc-card-position {
            font-size: 0.75em;
            font-weight: 700;
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.38);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .fc-card-role-plus {
            font-size: 0.65em;
            font-weight: 600;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.38);
            margin-top: -2px;
        }
        
/* Default: rosto ocupa 100% */
.fc-player-face,
.fc-card-player-image {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: auto;
  z-index: 2;
}

/* Se a imagem for 1:1 (detectado no onload), reposiciona e reduz */
/* Se a imagem for 1:1 (detectado no onload), reposiciona e reduz O CONTAINER */
.fc-card-player-image.fc-face-square {
  position: absolute;
  top: 20%;
  left: 15%;
  width: 70%;
  height: auto;
  z-index: 2;
}


        .fc-card-player-name {
            position: absolute;
            top: 59.5%;
            left: 50%;
            transform: translateX(-50%);
            width: 85%;
            text-align: center;
            font-size: 1.1em;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgb(0 0 0 / 21%);
            letter-spacing: 0.5px;
            line-height: 1.2;
            z-index: 3;
        }

        .fc-card-stats-grid {
            position: absolute;
            top: 67%;
            z-index: 4;
            display: flex;
            text-align: center;
            width: 100%;
            justify-content: center;
            gap: 5px;
            line-height: 1.25em;
        }

        .fc-card-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 0.7em;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.38);
            text-align: center;
        }

        .fc-stat-label {
            font-weight: 500;
            font-size: 0.95em;
            line-height: 1.2;
        }

        .fc-stat-value {
            font-weight: 700;
            font-size: 1.3em;
            line-height: 1;
            margin-top: 2px;
        }

        .fc-card-icons-footer {
            position: absolute;
            bottom: 19%;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 8px;
            align-items: center;
            z-index: 3;
        }

        .fc-icon {
            width: 18px;
            height: 18px;
            object-fit: contain;
            filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.5));
        }

        .fc-card-layer-2-extras { z-index: 4; }

        .fc-card-playstyles {
            position: absolute;
            top: 22%;
            left: 3%;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .fc-playstyle-item {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .fc-playstyle-svg {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .fc-playstyle-svg svg { width: 100%; height: 100%; }

        .fc-playstyle-svg svg .diamondBackground {
            fill: var(--diamondBackgroundColor, black);
        }

        .fc-playstyle-svg svg path[fill="black"]:not(.diamondBackground) {
            fill: var(--diamondBackgroundColor, black);
        }

        .fc-playstyle-svg svg path[fill="white"] {
            fill: var(--diamondForegroundColor, white);
        }

        .fc-playstyle-placeholder {
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 5px;
        }

        /* ===================================
           HEXÁGONO HORIZONTAL - EXTRA INFO
           =================================== */

        .fc-card-extra-info {
            position: absolute;
            bottom: 7.5%;
            left: 50%;
            width: 45%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .fc-hex-extra-info-svg {
            width: auto;
            height: 24px;
        }


        .hex-rating-text {
            font-size: 10px !important;
        }

        /* ===================================
           HEX ALT POS - BORDA PERFEITA COM LINHAS DIVISÓRIAS
           =================================== */

        .fc-card-alt-positions-hex {
            position: absolute;
            top: 24%;
            display: flex;
            right: 4%;
            z-index: 4;
            justify-content: flex-end;
        }

        .fc-hex-alt-pos-svg {
            width: 13%;
            height: auto;
        }

        .hex-border { /* fill via var no SVG */ }
        .hex-fill   { /* fill via var no SVG */ }

        .hex-divider {
            /* Linhas divisórias entre posições */
            stroke-linecap: round;
        }

        .hex-pos-text {
            font-family: 'Montserrat', Arial, Helvetica, sans-serif;
            font-size: 16px;
            font-weight: 600;
            fill: var(--alt-pos-border, #ffffff);
            letter-spacing: 1px;
            text-rendering: geometricPrecision;
        }

        .hex-pos-plus {
            font-family: 'Montserrat', Arial, Helvetica, sans-serif;
            font-size: 10px;
            font-weight: 700;
            fill: var(--alt-pos-border, #ffffff);
            opacity: 0.9;
            text-rendering: geometricPrecision;
        }

        @media (max-width: 768px) {
            .hex-pos-plus { font-size: 9px; }
        }

        @media (max-width: 480px) {
            .hex-pos-plus { font-size: 8px; }
        }

        @media (max-width: 768px) {
            .fc-card-rating { font-size: 1.8em; }
            .fc-card-position { font-size: 0.7em; }
            .fc-card-role-plus { font-size: 0.55em; } /* mantém hierarquia em tablet */
            .fc-card-player-name {font-size: 1em; top:63%; }
            .fc-card-stat { font-size: 0.5em; }
            .fc-stat-label {
    font-weight: 600; }
    .fc-card-rating-position{ top: 23%; left: 17%;}
    .fc-hex-alt-pos-svg {width: 16%;}
.hex-pos-text{ font-size: 18px;}
.fc-playstyle-svg svg{width: 78%;}
.fc-card-playstyles{gap: 1%;    left: 1%;}
    .fc-card-extra-info{width: 55%;    bottom: 0.1%;}
    .fc-card-icons-footer{bottom:12%}
    .fc-icon{width: 15px;}
        }

        @media (max-width: 480px) {
            .fc-hex-alt-pos-svg {width: 16%;}
.hex-pos-text{ font-size: 18px;}
.fc-card-rating-position{ top: 23%; left: 17%;}
.fc-playstyle-svg svg{width: 78%;}
.fc-card-playstyles{gap: 1%;    left: 1%;}
            /* Fix hierarquia para PNG (Puppeteer a 290px viewport < 480px):
               rating precisa dominar, role-plus deve ser menor que position */
            .fc-card-rating { font-size: 1.4em; }   /* era 0.9em — muito comprimido */
            .fc-card-position { font-size: 0.6em; }  /* era 0.5em — muito pequeno */
            .fc-card-role-plus { font-size: 0.45em; } /* sem override = 0.65em > position: errado */
            .fc-card-player-name { font-size: 1em; top:63%;    text-shadow: 1px 1px 2px rgb(0 0 0 / 4%); }
            .fc-card-stat { font-size: 0.5em; text-shadow: 1px 1px 2px rgb(0 0 0 / 4%);}
            .fc-card-stats-grid{gap:3%; top:71%;}
            .fc-stat-label {
    font-weight: 600; }
    .fc-card-icons-footer{bottom:12%}
    .fc-icon{width: 15px;}
    .fc-card-extra-info{width: 55%;    bottom: 0.1%;}
        }
        </style>
        <?php
        return ob_get_clean();
    }
}