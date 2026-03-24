<?php

namespace CacheParty\Warmer;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cache Warmer Connection Module.
 *
 * Connects to the external Railway-hosted cache warmer.
 * Does NOT warm caches itself — sends requests to the warmer service.
 * Auto-registers the site when an API key is saved.
 */
class Warmer_Client {

    private $settings;

    public function __construct() {
        $this->settings = [
            'api_url' => self::API_URL,
            'api_key' => get_option( 'cache_party_api_key', '' ),
        ];

        // Purge hooks — notify warmer on content changes.
        if ( ! empty( $this->settings['api_key'] ) ) {
            new Purge_Hooks( $this->settings );
        }

        // Admin: AJAX handlers + API key save hook.
        if ( is_admin() ) {
            add_action( 'wp_ajax_cache_party_test_warmer', [ $this, 'ajax_test_connection' ] );
            add_action( 'wp_ajax_cache_party_trigger_warm', [ $this, 'ajax_trigger_warm' ] );
            add_action( 'update_option_cache_party_api_key', [ $this, 'on_api_key_saved' ], 10, 2 );
        }

        // REST endpoint: expose cache info for the warmer.
        add_action( 'rest_api_init', [ $this, 'register_rest_route' ] );
    }

    const API_URL = 'https://api.cacheparty.com';

    // ─── Auto-Registration ───────────────────────────────────

    /**
     * Called when cache_party_api_key option is saved.
     * Registers or deregisters the site based on key changes.
     */
    public function on_api_key_saved( $old_key, $new_key ) {
        // Key added or changed — register.
        if ( ! empty( $new_key ) && $new_key !== $old_key ) {
            $settings = [ 'api_url' => self::API_URL, 'api_key' => $new_key ];
            $result = $this->register_site( $settings );
            if ( $result ) {
                add_settings_error( 'cache_party_general', 'registered',
                    'Site registered with Cache Party warmer.', 'success' );
            } else {
                add_settings_error( 'cache_party_general', 'register_failed',
                    'Could not register site with warmer. Check your API key.', 'error' );
            }
        }

        // Key removed — deregister.
        if ( empty( $new_key ) && ! empty( $old_key ) ) {
            $settings = [ 'api_url' => self::API_URL, 'api_key' => $old_key ];
            $this->remove_site( $settings );
        }
    }

    /**
     * Register this site with the warmer service.
     *
     * @param array|null $settings Optional settings override.
     * @return bool
     */
    public function register_site( $settings = null ) {
        $settings = $settings ?: $this->settings;
        $api_url  = $settings['api_url'] ?? '';
        $api_key  = $settings['api_key'] ?? '';

        if ( empty( $api_url ) || empty( $api_key ) ) {
            return false;
        }

        $response = wp_remote_post( rtrim( $api_url, '/' ) . '/api/sites/register', [
            'timeout' => 10,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'url' => home_url(),
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        return ( $code === 200 || $code === 201 );
    }

    /**
     * Remove this site from the warmer service.
     *
     * @param array|null $settings Optional settings override.
     * @return bool
     */
    public function remove_site( $settings = null ) {
        $settings = $settings ?: $this->settings;
        $api_url  = $settings['api_url'] ?? '';
        $api_key  = $settings['api_key'] ?? '';

        if ( empty( $api_url ) || empty( $api_key ) ) {
            return false;
        }

        $response = wp_remote_request( rtrim( $api_url, '/' ) . '/api/sites/remove', [
            'method'  => 'DELETE',
            'timeout' => 10,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'url' => home_url(),
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        return wp_remote_retrieve_response_code( $response ) === 200;
    }

    // ─── Warm Requests ───────────────────────────────────────

    /**
     * Send a warm request for a specific URL.
     */
    public function warm_url( $url ) {
        $api_url = $this->settings['api_url'] ?? '';
        $api_key = $this->settings['api_key'] ?? '';

        if ( ! $api_url || ! $api_key ) {
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
                'site' => wp_parse_url( home_url(), PHP_URL_HOST ),
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

        if ( ! $api_url || ! $api_key ) {
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
                'site' => wp_parse_url( home_url(), PHP_URL_HOST ),
            ] ),
        ] );
    }

    // ─── REST Endpoint ───────────────────────────────────────

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

    // ─── AJAX Handlers ───────────────────────────────────────

    /**
     * AJAX: Test warmer connection + registration status.
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'cache_party_warmer', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $api_url = self::API_URL;
        $api_key = get_option( 'cache_party_api_key', '' );

        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => 'No API key configured. Add it on the General tab.' ] );
        }

        // Step 1: Health check.
        $response = wp_remote_get( rtrim( $api_url, '/' ) . '/health', [
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || ! isset( $body['status'] ) || $body['status'] !== 'ok' ) {
            wp_send_json_error( [ 'message' => sprintf( 'HTTP %d — unexpected response.', $code ) ] );
        }

        // Step 2: Check if site is registered.
        if ( ! empty( $api_key ) ) {
            $sites_response = wp_remote_get( rtrim( $api_url, '/' ) . '/api/sites', [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                ],
            ] );

            if ( ! is_wp_error( $sites_response ) ) {
                $sites_body = json_decode( wp_remote_retrieve_body( $sites_response ), true );
                $site_url   = home_url();
                $registered = false;

                if ( ! empty( $sites_body['sites'] ) ) {
                    foreach ( $sites_body['sites'] as $site ) {
                        if ( rtrim( $site['url'] ?? '', '/' ) === rtrim( $site_url, '/' ) ) {
                            $registered = true;
                            break;
                        }
                    }
                }

                if ( $registered ) {
                    wp_send_json_success( [ 'message' => 'Connected — site registered.' ] );
                } else {
                    wp_send_json_success( [ 'message' => 'Connected — site NOT registered. Save settings to register.' ] );
                }
            }
        }

        wp_send_json_success( [ 'message' => 'Connected! Warmer is healthy.' ] );
    }

    /**
     * AJAX: Trigger manual warm.
     */
    public function ajax_trigger_warm() {
        check_ajax_referer( 'cache_party_warmer', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $api_url = self::API_URL;
        $api_key = get_option( 'cache_party_api_key', '' );

        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => 'No API key configured. Add it on the General tab.' ] );
        }

        $response = wp_remote_post( rtrim( $api_url, '/' ) . '/api/warm', [
            'timeout' => 10,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'site' => wp_parse_url( home_url(), PHP_URL_HOST ),
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
