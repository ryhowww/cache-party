<?php

namespace CacheParty\Warmer;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cache Warmer Connection Module.
 *
 * Connects to the external Railway-hosted cache warmer.
 * Does NOT warm caches itself — sends requests to the warmer service.
 */
class Warmer_Client {

    private $settings;

    public function __construct() {
        $this->settings = get_option( 'cache_party_warmer', self::defaults() );

        // Purge hooks — notify warmer on content changes.
        if ( ! empty( $this->settings['api_url'] ) ) {
            new Purge_Hooks( $this->settings );
        }

        // Admin: AJAX handler for connection test.
        if ( is_admin() ) {
            add_action( 'wp_ajax_cache_party_test_warmer', [ $this, 'ajax_test_connection' ] );
            add_action( 'wp_ajax_cache_party_trigger_warm', [ $this, 'ajax_trigger_warm' ] );
        }

        // REST endpoint: expose cache info for the warmer.
        add_action( 'rest_api_init', [ $this, 'register_rest_route' ] );
    }

    const DEFAULT_API_URL = 'https://api.cacheparty.com';

    public static function defaults() {
        return [
            'api_url'   => self::DEFAULT_API_URL,
            'api_key'   => '',
            'site_name' => '',
        ];
    }

    /**
     * Send a warm request for a specific URL.
     *
     * @param string $url URL to warm.
     */
    public function warm_url( $url ) {
        $api_url = $this->settings['api_url'] ?? '';
        $api_key = $this->settings['api_key'] ?? '';

        if ( ! $api_url ) {
            return;
        }

        wp_remote_post( rtrim( $api_url, '/' ) . '/api/warm', [
            'blocking' => false,
            'timeout'  => 1,
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'site' => $this->settings['site_name'] ?: wp_parse_url( home_url(), PHP_URL_HOST ),
                'url'  => $url,
            ] ),
        ] );
    }

    /**
     * Send a full site warm request.
     */
    public function warm_site() {
        $api_url = $this->settings['api_url'] ?? '';
        $api_key = $this->settings['api_key'] ?? '';

        if ( ! $api_url ) {
            return;
        }

        wp_remote_post( rtrim( $api_url, '/' ) . '/api/warm', [
            'blocking' => false,
            'timeout'  => 1,
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'site' => $this->settings['site_name'] ?: wp_parse_url( home_url(), PHP_URL_HOST ),
            ] ),
        ] );
    }

    /**
     * REST endpoint: GET /wp-json/cache-party/v1/cache-info
     */
    public function register_rest_route() {
        register_rest_route( 'cache-party/v1', '/cache-info', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_cache_info' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function rest_cache_info( $request ) {
        return rest_ensure_response( [
            'site'      => home_url(),
            'version'   => CACHE_PARTY_VERSION,
            'modules'   => get_option( 'cache_party_modules', [ 'images' ] ),
            'templates' => array_map( function( $t ) {
                return $t['template'];
            }, \CacheParty\Assets\Critical_CSS::list_templates() ),
        ] );
    }

    /**
     * AJAX: Test warmer connection.
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'cache_party_warmer', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $settings = get_option( 'cache_party_warmer', self::defaults() );
        $api_url  = $settings['api_url'] ?? '';

        if ( ! $api_url ) {
            wp_send_json_error( [ 'message' => 'No API URL configured.' ] );
        }

        $response = wp_remote_get( rtrim( $api_url, '/' ) . '/health', [
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && isset( $body['status'] ) && $body['status'] === 'ok' ) {
            wp_send_json_success( [ 'message' => 'Connected! Warmer is healthy.' ] );
        } else {
            wp_send_json_error( [ 'message' => sprintf( 'HTTP %d — unexpected response.', $code ) ] );
        }
    }

    /**
     * AJAX: Trigger manual warm.
     */
    public function ajax_trigger_warm() {
        check_ajax_referer( 'cache_party_warmer', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $settings = get_option( 'cache_party_warmer', self::defaults() );
        $api_url  = $settings['api_url'] ?? '';
        $api_key  = $settings['api_key'] ?? '';

        if ( ! $api_url ) {
            wp_send_json_error( [ 'message' => 'No API URL configured.' ] );
        }

        $response = wp_remote_post( rtrim( $api_url, '/' ) . '/api/warm', [
            'timeout' => 10,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'site' => $settings['site_name'] ?: wp_parse_url( home_url(), PHP_URL_HOST ),
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 300 ) {
            update_option( 'cache_party_last_warm', current_time( 'mysql' ) );
            wp_send_json_success( [ 'message' => 'Warm triggered!' ] );
        } else {
            wp_send_json_error( [ 'message' => sprintf( 'HTTP %d', $code ) ] );
        }
    }
}
