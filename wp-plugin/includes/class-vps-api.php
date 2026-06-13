<?php
/**
 * Comunicação com a API da VPS
 */

if ( ! defined( 'WPINC' ) ) die;

class FC_DL_VPS_Api {

    /* ── GET /squads ─────────────────────────────────────────── */
    public static function get_squads() {
        $res = wp_remote_get( FC_DL_API_BASE . '/squads', [ 'timeout' => 10 ] );
        if ( is_wp_error( $res ) ) return false;
        return json_decode( wp_remote_retrieve_body( $res ), true );
    }

    /* ── GET /squad/{label} ──────────────────────────────────── */
    public static function get_squad( string $label ) {
        $res  = wp_remote_get(
            FC_DL_API_BASE . '/squad/' . urlencode( $label ),
            [ 'timeout' => 10 ]
        );
        if ( is_wp_error( $res ) ) return false;
        $code = wp_remote_retrieve_response_code( $res );
        if ( $code === 404 ) return null;   // ainda não scrapeado
        return json_decode( wp_remote_retrieve_body( $res ), true );
    }

    /* ── POST /run-scrape ────────────────────────────────────── */
    public static function run_scrape( string $label, string $url ) {
        $res = wp_remote_post( FC_DL_API_BASE . '/run-scrape', [
            'timeout' => 10,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'label' => $label, 'url' => $url ] ),
        ] );
        if ( is_wp_error( $res ) ) return false;
        return json_decode( wp_remote_retrieve_body( $res ), true );
    }

    /* ── GET /status/{label} ─────────────────────────────────── */
    public static function get_status( string $label ) {
        $res = wp_remote_get(
            FC_DL_API_BASE . '/status/' . urlencode( $label ),
            [ 'timeout' => 5 ]
        );
        if ( is_wp_error( $res ) ) return false;
        $code = wp_remote_retrieve_response_code( $res );
        if ( $code === 404 ) return [ 'status' => 'not_started' ];
        return json_decode( wp_remote_retrieve_body( $res ), true );
    }

    /* ── POST /refresh-squads ───────────────────────────────── */
    public static function refresh_squads() {
        $res = wp_remote_post( FC_DL_API_BASE . '/refresh-squads', [
            'timeout' => 90,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => '{}',
        ] );
        if ( is_wp_error( $res ) ) return false;
        if ( wp_remote_retrieve_response_code( $res ) !== 200 ) return false;
        return json_decode( wp_remote_retrieve_body( $res ), true );
    }

    /* ── helpers ─────────────────────────────────────────────── */
    public static function label_from_url( string $url ): string {
        return basename( rtrim( $url, '/' ) );
    }
}
