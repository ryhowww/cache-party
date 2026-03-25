<?php

namespace CacheParty;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Unified "Clear Caches" admin bar button.
 *
 * Clears all available cache layers in order:
 * 1. CP aggregation cache (CSS/JS)
 * 2. WP Engine page cache (Varnish + memcached) — if available
 * 3. Cloudflare cache — if credentials configured
 * 4. Fires cp_caches_cleared action for warmer re-prime
 */
class Cache_Clear {

    public function __construct() {
        add_action( 'admin_bar_menu', [ $this, 'admin_bar_button' ], 90 );
        add_action( 'wp_ajax_cache_party_clear_all', [ $this, 'ajax_clear_all' ] );
        add_action( 'admin_footer', [ $this, 'admin_bar_script' ] );
        add_action( 'wp_footer', [ $this, 'admin_bar_script' ] );
    }

    public function admin_bar_button( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $wp_admin_bar->add_node( [
            'id'    => 'cache-party-clear-all',
            'title' => '<span class="ab-icon dashicons dashicons-trash" style="font-family:dashicons;font-size:16px;line-height:1;margin-right:4px;position:relative;top:3px;"></span>Clear Caches',
            'href'  => '#',
            'meta'  => [
                'onclick' => 'cachePartyClearAll(); return false;',
            ],
        ] );
    }

    public function admin_bar_script() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <script>
        function cachePartyClearAll() {
            var bar = document.getElementById('wp-admin-bar-cache-party-clear-all');
            if (bar) bar.querySelector('a').innerHTML = 'Clearing...';
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>');
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                var msg = 'Failed';
                try { var r = JSON.parse(xhr.responseText); msg = r.data ? r.data.message : msg; } catch(e) {}
                if (bar) bar.querySelector('a').innerHTML = msg;
                setTimeout(function() {
                    if (bar) bar.querySelector('a').innerHTML = '<span class="ab-icon dashicons dashicons-trash" style="font-family:dashicons;font-size:16px;line-height:1;margin-right:4px;position:relative;top:3px;"></span>Clear Caches';
                }, 3000);
            };
            xhr.send('action=cache_party_clear_all&nonce=<?php echo esc_js( wp_create_nonce( 'cache_party_clear_all' ) ); ?>');
        }
        </script>
        <?php
    }

    public function ajax_clear_all() {
        check_ajax_referer( 'cache_party_clear_all', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $cleared = [];

        // 1. CP aggregation cache.
        if ( class_exists( '\CacheParty\Assets\Cache_Manager' ) ) {
            Assets\Cache_Manager::clearall( 'css' );
            $cleared[] = 'CP cache';
        }

        // 2. WP Engine page cache.
        if ( class_exists( 'WpeCommon' ) ) {
            if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
                \WpeCommon::purge_memcached();
            }
            if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
                \WpeCommon::purge_varnish_cache();
            }
            $cleared[] = 'WP Engine';
        }

        // 3. Cloudflare.
        $cf_result = $this->purge_cloudflare();
        if ( $cf_result === true ) {
            $cleared[] = 'Cloudflare';
        } elseif ( $cf_result === false ) {
            $cleared[] = 'Cloudflare (failed)';
        }
        // null = not configured, skip silently.

        // 4. Notify extensions.
        do_action( 'cp_caches_cleared' );

        if ( empty( $cleared ) ) {
            $cleared[] = 'No caches found';
        }

        wp_send_json_success( [
            'message' => 'Cleared: ' . implode( ', ', $cleared ),
        ] );
    }

    /**
     * Purge Cloudflare cache using Cache Party's stored credentials.
     *
     * @return bool|null true = success, false = failed, null = not configured.
     */
    private function purge_cloudflare() {
        $settings = get_option( 'cache_party_cloudflare', [] );
        $email    = defined( 'CLOUDFLARE_EMAIL' ) ? CLOUDFLARE_EMAIL : ( $settings['email'] ?? '' );
        $api_key  = defined( 'CLOUDFLARE_API_KEY' ) ? CLOUDFLARE_API_KEY : ( $settings['api_key'] ?? '' );
        $domain   = defined( 'CLOUDFLARE_DOMAIN_NAME' ) ? CLOUDFLARE_DOMAIN_NAME : ( $settings['domain'] ?? '' );

        if ( ! $api_key ) {
            return null;
        }

        if ( ! $domain ) {
            $domain = wp_parse_url( home_url(), PHP_URL_HOST );
        }

        // Auto-detect auth: 37 hex chars = Global API Key, otherwise scoped Token.
        $is_global_key = strlen( $api_key ) === 37 && preg_match( '/^[0-9a-f]+$/', $api_key );
        if ( $is_global_key && ! $email ) {
            return null; // Global key requires email.
        }

        $headers = [ 'Content-Type' => 'application/json' ];
        if ( $is_global_key ) {
            $headers['X-Auth-Email'] = $email;
            $headers['X-Auth-Key']   = $api_key;
        } else {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        // Get zone ID.
        $zone_id = get_transient( 'cache_party_cf_zone_id' );
        if ( ! $zone_id ) {
            $response = wp_remote_get( 'https://api.cloudflare.com/client/v4/zones?name=' . $domain, [
                'headers' => $headers,
                'timeout' => 10,
            ] );

            if ( is_wp_error( $response ) ) {
                return false;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $body['result'][0]['id'] ) ) {
                return false;
            }

            $zone_id = $body['result'][0]['id'];
            set_transient( 'cache_party_cf_zone_id', $zone_id, DAY_IN_SECONDS );
        }

        // Purge everything.
        $response = wp_remote_request( 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache', [
            'method'  => 'DELETE',
            'headers' => $headers,
            'body'    => wp_json_encode( [ 'purge_everything' => true ] ),
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return ! empty( $body['success'] );
    }
}
