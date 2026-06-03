<?php
/**
 * Chama o Puppeteer /render-batch e entrega o ZIP para download.
 */

if ( ! defined( 'WPINC' ) ) die;

class FC_DL_Png_Builder {

    const PUPPETEER_URL = FC_DL_API_BASE . '/render-batch';
    const CARD_WIDTH    = 400;   // px — resolução do PNG gerado

    /**
     * Recebe array de players já renderizados pelo PHP e gera o ZIP.
     *
     * @param array $players  [ ['name'=>'...', 'card_html'=>'...'], ... ]
     * @param string $css     CSS do renderer (sem tags <style>)
     * @return array|WP_Error { url, filename } ou WP_Error
     */
    public static function generate( array $players, string $css ) {

        if ( empty( $players ) ) {
            return new WP_Error( 'empty', 'Nenhum jogador selecionado' );
        }

        // Monta payload para o Puppeteer
        $payload = [
            'width'   => self::CARD_WIDTH,
            'players' => array_map( function ( $p ) use ( $css ) {
                return [
                    'name' => $p['name'],
                    'html' => $p['card_html'],
                    'css'  => $css,
                ];
            }, $players ),
        ];

        // Chama o Puppeteer
        $response = wp_remote_post( self::PUPPETEER_URL, [
            'timeout'  => 120,
            'headers'  => [
                'Content-Type' => 'application/json',
                'X-FC-Token'   => FC_DL_PUPPETEER_TOKEN,
            ],
            'body'     => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'puppeteer_unreachable', $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $body = wp_remote_retrieve_body( $response );
            $msg  = json_decode( $body, true )['error'] ?? "HTTP $code";
            return new WP_Error( 'puppeteer_error', $msg );
        }

        // Salva o ZIP em uploads/fc-exports/
        $zip_bytes = wp_remote_retrieve_body( $response );
        return self::save_zip( $zip_bytes );
    }

    // ── Salva ZIP e retorna URL ───────────────────────────────
    private static function save_zip( string $bytes ) {
        $upload   = wp_upload_dir();
        $dir      = trailingslashit( $upload['basedir'] ) . 'fc-exports';
        $dir_url  = trailingslashit( $upload['baseurl'] ) . 'fc-exports';

        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
            file_put_contents( $dir . '/.htaccess', "Options -Indexes\n" );
        }

        // Limpa exports com mais de 1 hora
        self::cleanup( $dir );

        $filename = 'players_' . date( 'YmdHis' ) . '_' . substr( uniqid(), -4 ) . '.zip';
        $filepath = $dir . '/' . $filename;

        if ( file_put_contents( $filepath, $bytes ) === false ) {
            return new WP_Error( 'save_failed', 'Falha ao salvar ZIP no servidor' );
        }

        return [
            'url'      => $dir_url . '/' . $filename,
            'filename' => $filename,
            'size'     => strlen( $bytes ),
        ];
    }

    // ── Remove ZIPs antigos (> 1h) ────────────────────────────
    private static function cleanup( string $dir ) {
        foreach ( glob( $dir . '/*.zip' ) ?: [] as $file ) {
            if ( filemtime( $file ) < time() - 3600 ) {
                @unlink( $file );
            }
        }
    }

    // ── Extrai CSS puro (sem tags <style>) ────────────────────
    public static function get_card_css(): string {
        if ( ! class_exists( 'FC_Card_Visual_Renderer' ) ) return '';
        $raw = FC_Card_Visual_Renderer::get_card_css();
        return preg_replace( '/<\/?style[^>]*>/i', '', $raw );
    }
}
